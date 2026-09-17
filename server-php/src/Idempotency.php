<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Do this once, however many times you are asked.
 *
 * WHY A PAYMENT SYSTEM NEEDS THIS AND A REPORTING SYSTEM DOES NOT. Every path
 * into this product can deliver the same instruction twice, and none of them is
 * a bug:
 *
 *   * a provider redelivers a webhook it did not see acknowledged;
 *   * a browser retries a POST that timed out after the row was written;
 *   * our own outbox retries a callback whose response was lost;
 *   * a merchant's integration retries on a 502 from a load balancer.
 *
 * A duplicated report is a wasted query. A duplicated refund is money gone
 * twice, and it is gone to a customer who is not going to send it back.
 *
 * HOW IT WORKS. The caller names an operation and a key. The first caller
 * inserts a row and runs the work; a second caller with the same key finds the
 * row and is handed the FIRST caller's recorded result instead of running
 * anything. The insert is the lock — a UNIQUE constraint, not a SELECT then an
 * INSERT, because two requests arriving in the same millisecond both read "no
 * row" and both proceed.
 *
 * IN-FLIGHT is a distinct state from done. A second request that arrives while
 * the first is still working gets a 409 telling it to retry, not a made-up
 * success and not a second attempt.
 */
final class Idempotency
{
    public const TABLE = 'pay_idempotency_keys';

    public const STATE_IN_FLIGHT = 'IN_FLIGHT';
    public const STATE_DONE      = 'DONE';
    public const STATE_FAILED    = 'FAILED';

    /**
     * A stuck IN_FLIGHT row is re-claimable after this long.
     *
     * Without it, one request that died mid-flight — a PHP fatal, a worker
     * killed — would block that key forever, and the operation it was holding
     * could never be retried. Five minutes is comfortably longer than the
     * 20-second outbound budget any single operation is allowed.
     */
    private const STALE_AFTER_SECONDS = 300;

    /**
     * Run $work once for this key.
     *
     * @template T of array<string, mixed>
     * @param callable(): T $work
     * @return array{0: array<string, mixed>, 1: bool} the result, and whether this call was the one that did the work
     */
    public static function once(Context $ctx, string $operation, string $key, callable $work): array
    {
        $key = trim($key);
        if ($key === '') {
            // No key means the caller accepted the risk of a duplicate. That is
            // allowed for reads and for anything a human confirms on screen; it
            // is refused by the endpoints that move money, which require one.
            return [$work(), true];
        }

        $fingerprint = self::fingerprint($ctx, $operation, $key);

        $claimed = self::claim($ctx, $operation, $key, $fingerprint);

        if (!$claimed) {
            $existing = self::lookup($fingerprint);

            if ($existing === null) {
                // Claimed by somebody, then vanished. Racing again would be
                // worse than saying so.
                Http::conflict('That request is already being processed. Please retry in a moment.');
            }

            $state = (string) $existing['state'];

            if ($state === self::STATE_DONE) {
                $result = Db::jsonColumn($existing['result'] ?? null);
                $result['idempotent_replay'] = true;

                return [$result, false];
            }

            if ($state === self::STATE_FAILED) {
                // A previous attempt failed cleanly. Let this one try again —
                // the failure was recorded, not the outcome.
                Db::update(self::TABLE, [
                    'state'      => self::STATE_IN_FLIGHT,
                    'claimed_at' => gmdate('Y-m-d H:i:s'),
                    'result'     => null,
                ], ['fingerprint' => $fingerprint]);
            } else {
                Http::conflict('That request is already being processed. Please retry in a moment.');
            }
        }

        try {
            $result = $work();
        } catch (\Throwable $e) {
            self::markFailed($fingerprint, $e->getMessage());
            throw $e;
        }

        self::markDone($fingerprint, $result);

        return [$result, true];
    }

    /**
     * Claim the key, returning false when somebody else already holds it.
     *
     * The stale takeover is expressed in the same statement as the insert, so
     * two requests cannot both decide a row is stale and both take it.
     */
    private static function claim(Context $ctx, string $operation, string $key, string $fingerprint): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $staleBefore = gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS);

        $rows = Db::all(
            'INSERT INTO ' . self::TABLE . ' (fingerprint, cmp_id, operation, idempotency_key, state, claimed_at)
             VALUES (:fp, :cmp, :op, :key, :state, :now)
             ON CONFLICT (fingerprint) DO UPDATE
                SET state = :state, claimed_at = :now, result = NULL
              WHERE ' . self::TABLE . '.state = :in_flight
                AND ' . self::TABLE . '.claimed_at < :stale
             RETURNING fingerprint',
            [
                'fp'        => $fingerprint,
                'cmp'       => $ctx->cmpId,
                'op'        => $operation,
                'key'       => $key,
                'state'     => self::STATE_IN_FLIGHT,
                'now'       => $now,
                'in_flight' => self::STATE_IN_FLIGHT,
                'stale'     => $staleBefore,
            ],
        );

        return $rows !== [];
    }

    /** @return array<string, mixed>|null */
    private static function lookup(string $fingerprint): ?array
    {
        return Db::first('SELECT state, result FROM ' . self::TABLE . ' WHERE fingerprint = :fp', ['fp' => $fingerprint]);
    }

    /** @param array<string, mixed> $result */
    private static function markDone(string $fingerprint, array $result): void
    {
        Db::update(self::TABLE, [
            'state'        => self::STATE_DONE,
            'result'       => $result,
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ], ['fingerprint' => $fingerprint]);
    }

    private static function markFailed(string $fingerprint, string $reason): void
    {
        Db::update(self::TABLE, [
            'state'        => self::STATE_FAILED,
            // The reason is for an operator reading the table, so it is trimmed
            // and never contains a payload: an exception message from a provider
            // client can carry a request body.
            'result'       => ['error' => substr($reason, 0, 300)],
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ], ['fingerprint' => $fingerprint]);
    }

    /**
     * The stored key, scoped so one company's key cannot collide with another's.
     *
     * A merchant choosing "invoice-1042" as their idempotency key must not
     * silently replay a different merchant's answer, and two different
     * operations sharing a key must not either.
     */
    private static function fingerprint(Context $ctx, string $operation, string $key): string
    {
        return hash('sha256', $ctx->cmpId . '|' . $operation . '|' . $key);
    }

    /**
     * The key a caller supplied, or one derived from what they asked for.
     *
     * The derived form is the important half. A source app that forgets the
     * header still must not create two payment requests for one invoice, so the
     * request's own identity — source app, type and id — becomes the key.
     *
     * @param list<string|int> $parts
     */
    public static function fromRequestOr(array $parts): string
    {
        $header = Http::header('Idempotency-Key');
        if ($header !== '') {
            return substr($header, 0, 190);
        }

        $derived = implode('|', array_map(static fn ($p) => (string) $p, $parts));

        return $derived === '' ? '' : 'derived:' . hash('sha256', $derived);
    }

    /** Require a key, because this operation moves money. */
    public static function required(): string
    {
        $header = Http::header('Idempotency-Key');
        if ($header === '') {
            Http::validationFailed(
                'This request needs an Idempotency-Key header.',
                ['header' => 'Idempotency-Key', 'why' => 'It is what stops a retry from moving the money twice.'],
            );
        }

        return substr($header, 0, 190);
    }
}
