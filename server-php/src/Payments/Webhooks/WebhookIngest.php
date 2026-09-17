<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Webhooks;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Ids;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\RefundService;
use Aicountly\Api\Domain\SettlementService;
use Aicountly\Api\Domain\States;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Providers\ProviderRegistry;

/**
 * Everything a payment provider tells us, and what we do about it.
 *
 * THE ORDER OF OPERATIONS IS THE DESIGN, and it is not the obvious one:
 *
 *   1. read the RAW body — before anything parses it;
 *   2. work out which company and connection it is for;
 *   3. STORE IT, whatever happens next;
 *   4. verify the signature;
 *   5. fingerprint it and stop if we have seen it;
 *   6. process it;
 *   7. answer 200.
 *
 * Storing before verifying is deliberate. A webhook that fails its signature is
 * the most interesting row in the table: it is either a provider whose secret
 * we have wrong — in which case every real payment is being rejected and
 * nobody can see why — or somebody forging callbacks at the endpoint. Both need
 * an operator to be able to look. Answering 401 to nobody teaches us nothing.
 *
 * THE RAW BODY IS NEVER RE-ENCODED. Every provider signs the exact bytes they
 * sent, and `json_encode(json_decode($body))` reorders keys and rewrites
 * unicode escapes. Passing a decoded array to a signature check is the single
 * most common reason a webhook integration fails in a way nobody can reproduce.
 *
 * WE ANSWER FAST. The provider's timeout is seconds, and a provider that does
 * not see a 200 redelivers. Processing that needs a network call — telling
 * Books — goes through the outbox and happens after we have answered.
 */
final class WebhookIngest
{
    public const TABLE = 'pay_webhook_events';

    public const RECEIVED  = 'RECEIVED';
    public const PROCESSED = 'PROCESSED';
    public const DUPLICATE = 'DUPLICATE';
    public const REJECTED  = 'REJECTED';
    /** An event type we do not act on. Not a failure. */
    public const IGNORED   = 'IGNORED';
    public const FAILED    = 'FAILED';

    /**
     * Handle one inbound webhook.
     *
     * @param array<string, string> $headers lower-cased header names
     * @return array{status:int, body:array<string, mixed>}
     */
    public static function handle(string $providerCode, string $rawPayload, array $headers): array
    {
        $startedAt = microtime(true);
        $providerCode = strtoupper(trim($providerCode));

        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            // PayU posts a form rather than JSON, which is not an error — it is
            // that provider's contract. Anything else that is neither is.
            parse_str($rawPayload, $formDecoded);
            $decoded = is_array($formDecoded) && $formDecoded !== [] ? $formDecoded : [];
        }

        $connection = self::resolveConnection($providerCode, $decoded, $headers);

        // Stored first, always — including an unattributable payload, which is
        // stored with a null company so an operator can see it and a merchant
        // cannot.
        $eventId = self::store($providerCode, $connection, $rawPayload, $headers, $decoded);

        if ($connection === null) {
            self::finish($eventId, self::REJECTED, 'No connected provider matches this callback.', $startedAt);

            // 200, not 404. A provider that gets an error redelivers for hours.
            // The payload is stored and an operator can see it; making the
            // provider retry a callback we will never be able to place just
            // fills their queue and ours.
            return ['status' => 200, 'body' => ['received' => true, 'processed' => false]];
        }

        $provider = ProviderRegistry::forConnection($connection);
        if ($provider === null) {
            self::finish($eventId, self::FAILED, 'The provider for this connection could not be prepared.', $startedAt);

            // 500 here IS right: this is our problem and a redelivery may well
            // succeed once the credentials are fixed.
            return ['status' => 500, 'body' => ['received' => true, 'processed' => false]];
        }

        if (!$provider->verifyWebhook($headers, $rawPayload)) {
            self::markSignature($eventId, false, 'Signature did not verify.');
            self::finish($eventId, self::REJECTED, 'Signature did not verify.', $startedAt);

            // 401 and no retry. A forged callback should not be retried, and a
            // genuine one with a wrong secret will keep failing until somebody
            // fixes the secret — the stored rows are how they find out.
            return ['status' => 401, 'body' => ['error' => 'signature_invalid']];
        }

        self::markSignature($eventId, true, null);

        $normalized = $provider->normalizeWebhook($decoded);
        $fingerprint = Fingerprint::of($providerCode, $normalized, $rawPayload);

        // The duplicate check is a UNIQUE index, not a SELECT. Two deliveries
        // arriving in the same millisecond both read "not seen" and both
        // capture the payment.
        if (!self::claimFingerprint($eventId, $fingerprint)) {
            self::finish($eventId, self::DUPLICATE, 'Already processed.', $startedAt);

            // 200: the provider did its job, we had already done ours.
            return ['status' => 200, 'body' => ['received' => true, 'duplicate' => true]];
        }

        $ctx = Context::forBackground((int) $connection['cmp_id']);
        $event = (string) ($normalized['event'] ?? EventNames::UNKNOWN);

        Db::update(self::TABLE, ['normalized_event' => $event], ['event_id' => $eventId]);

        if ($event === EventNames::UNKNOWN) {
            // A provider added an event type. Stored, not acted on, not an
            // error — their new feature must not be able to stop a merchant's
            // payments being recorded.
            self::finish($eventId, self::IGNORED, 'No handler for this provider event type.', $startedAt);

            return ['status' => 200, 'body' => ['received' => true, 'processed' => false, 'reason' => 'unhandled_event_type']];
        }

        try {
            $outcome = self::process($ctx, $connection, $event, $normalized);
        } catch (\Throwable $e) {
            error_log('[webhook] processing failed for event ' . $eventId . ': ' . $e->getMessage());
            self::finish($eventId, self::FAILED, substr($e->getMessage(), 0, 400), $startedAt);

            // 500 so the provider redelivers. The fingerprint row is released
            // below so the redelivery is not dismissed as a duplicate of a
            // delivery that never actually completed.
            self::releaseFingerprint($eventId);

            return ['status' => 500, 'body' => ['error' => 'processing_failed']];
        }

        Db::update(self::TABLE, array_filter([
            'attempt_id' => $outcome['attempt_id'] ?? null,
            'refund_id'  => $outcome['refund_id'] ?? null,
        ], static fn ($v) => $v !== null), ['event_id' => $eventId]);

        self::finish($eventId, self::PROCESSED, $outcome['reason'] ?? null, $startedAt);

        return ['status' => 200, 'body' => ['received' => true, 'processed' => true]];
    }

    /**
     * Do what the event says.
     *
     * @param array<string, mixed> $normalized
     * @return array{reason:string, attempt_id?:?int, refund_id?:?int}
     */
    private static function process(Context $ctx, array $connection, string $event, array $normalized): array
    {
        switch ($event) {
            case EventNames::PAYMENT_SUCCESS:
            case EventNames::PAYMENT_FAILED:
            case EventNames::PAYMENT_PENDING:
                $applied = PaymentService::applyProviderState($ctx, $normalized + [
                    'status' => $normalized['status'] ?? self::statusFor($event),
                ], $connection);

                return ['reason' => $applied['reason'], 'attempt_id' => $applied['attempt_id']];

            case EventNames::REFUND_SUCCESS:
            case EventNames::REFUND_FAILED:
            case EventNames::REFUND_PROCESSING:
                return self::applyRefund($ctx, $event, $normalized);

            case EventNames::SETTLEMENT_RECEIVED:
            case EventNames::SETTLEMENT_MISMATCH:
                SettlementService::importFromWebhook($ctx, $connection, $normalized);

                return ['reason' => 'settlement_recorded'];

            case EventNames::DISPUTE_CREATED:
            case EventNames::DISPUTE_UPDATED:
                \Aicountly\Api\Domain\DisputeService::applyProviderEvent($ctx, $connection, $normalized);

                return ['reason' => 'dispute_recorded'];

            case EventNames::MANDATE_ACTIVE:
            case EventNames::MANDATE_FAILED:
            case EventNames::MANDATE_CANCELLED:
                \Aicountly\Api\Domain\MandateService::applyProviderEvent($ctx, $connection, $normalized);

                return ['reason' => 'mandate_updated'];

            default:
                return ['reason' => 'no_action'];
        }
    }

    /** @param array<string, mixed> $normalized */
    private static function applyRefund(Context $ctx, string $event, array $normalized): array
    {
        $providerRefundId = isset($normalized['provider_refund_id']) ? (string) $normalized['provider_refund_id'] : '';
        if ($providerRefundId === '') {
            return ['reason' => 'refund_not_identified'];
        }

        $refund = Db::first(
            'SELECT * FROM ' . RefundService::TABLE . ' WHERE cmp_id = :cmp AND provider_refund_id = :pid',
            ['cmp' => $ctx->cmpId, 'pid' => $providerRefundId],
        );

        if ($refund === null) {
            // A refund issued in the provider's own dashboard rather than
            // through Pay. Real, and common — the merchant refunded a customer
            // while looking at Razorpay. It is a reconciliation case rather
            // than a silent miss, because Pay's totals are now behind.
            \Aicountly\Api\Domain\ReconciliationService::openCase($ctx, [
                'case_kind' => 'UNRECONCILED_TRANSACTION',
                'severity'  => 'MEDIUM',
                'actual_minor' => isset($normalized['amount_minor']) ? (int) $normalized['amount_minor'] : null,
                'currency'  => (string) ($normalized['currency'] ?? 'INR'),
                'summary'   => 'A refund was made at the provider that Pay has no record of raising.',
                'detail'    => ['provider_refund_id' => $providerRefundId],
            ]);

            return ['reason' => 'refund_not_found'];
        }

        $refundId = (int) $refund['refund_id'];

        if ($event === EventNames::REFUND_SUCCESS) {
            RefundService::markRefunded($ctx, $refundId);

            return ['reason' => 'refund_completed', 'refund_id' => $refundId];
        }

        if ($event === EventNames::REFUND_FAILED && (string) $refund['status'] !== States::REFUND_REFUNDED) {
            Db::update(RefundService::TABLE, [
                'status'         => States::REFUND_FAILED,
                'failure_code'   => isset($normalized['error_code']) ? substr((string) $normalized['error_code'], 0, 100) : 'provider_reported',
                'failure_reason' => isset($normalized['error_message']) ? substr((string) $normalized['error_message'], 0, 500) : 'The provider reported this refund as failed.',
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ], ['refund_id' => $refundId, 'cmp_id' => $ctx->cmpId]);

            return ['reason' => 'refund_failed', 'refund_id' => $refundId];
        }

        return ['reason' => 'refund_no_change', 'refund_id' => $refundId];
    }

    /**
     * Which company's connection is this callback for?
     *
     * Three routes, in order of reliability. The provider's own account
     * identifier is best; our own uuid echoed back in the notes is next; and a
     * single connection for that provider on this deployment is the fallback
     * that makes a small install work without configuration.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array<string, mixed>|null
     */
    private static function resolveConnection(string $providerCode, array $payload, array $headers): ?array
    {
        // 1. The connection id we put in our own webhook URL when the merchant
        //    connected the provider: `/api/v1/webhooks/razorpay?c=<uuid>`.
        //    Unambiguous, and the normal path.
        //
        //    It is a QUERY PARAMETER rather than a header because a provider's
        //    dashboard lets a merchant paste a URL and nothing else — there is
        //    no field for a custom header. A header is still honoured for a
        //    provider that can send one.
        //
        //    It GRANTS NOTHING. Naming a connection only says which signing
        //    secret to check the body against, so a forged value fails
        //    verification a moment later. It cannot create access or a row.
        $connectionUuid = (string) ($_GET['c'] ?? '');
        if ($connectionUuid === '') {
            $fromHeader = $headers['x-pay-connection'] ?? '';
            $connectionUuid = is_string($fromHeader) ? $fromHeader : '';
        }

        if ($connectionUuid !== '') {
            $row = Db::first(
                'SELECT * FROM ' . ProviderRegistry::CONNECTIONS . '
                 WHERE connection_uuid = :uuid AND provider_code = :code',
                ['uuid' => $connectionUuid, 'code' => $providerCode],
            );
            if ($row !== null) {
                return $row;
            }
        }

        // 2. The provider's own merchant/account id in the payload.
        $merchantRef = self::findMerchantRef($payload);
        if ($merchantRef !== null) {
            $row = Db::first(
                'SELECT * FROM ' . ProviderRegistry::CONNECTIONS . '
                 WHERE provider_code = :code AND merchant_ref = :ref',
                ['code' => $providerCode, 'ref' => $merchantRef],
            );
            if ($row !== null) {
                return $row;
            }
        }

        // 3. Exactly one connection for this provider across the deployment.
        //    Correct for a single-merchant install; refused as soon as there
        //    are two, because guessing which merchant a payment belongs to is
        //    the one mistake that cannot be walked back.
        $rows = Db::all(
            'SELECT * FROM ' . ProviderRegistry::CONNECTIONS . ' WHERE provider_code = :code LIMIT 2',
            ['code' => $providerCode],
        );

        if (count($rows) === 1) {
            return $rows[0];
        }

        if (count($rows) > 1) {
            error_log('[webhook] ' . $providerCode . ' callback could not be attributed: several connections exist and the payload named none');
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private static function findMerchantRef(array $payload): ?string
    {
        $keys = ['account_id', 'merchant_id', 'mid', 'x-razorpay-account', 'vendor_id'];

        $search = static function (array $node, int $depth) use (&$search, $keys): ?string {
            if ($depth > 4) {
                return null;
            }
            foreach ($node as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), $keys, true) && (is_string($value) || is_int($value))) {
                    return (string) $value;
                }
                if (is_array($value)) {
                    $found = $search($value, $depth + 1);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }

            return null;
        };

        return $search($payload, 0);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $decoded
     */
    private static function store(string $providerCode, ?array $connection, string $rawPayload, array $headers, array $decoded): int
    {
        return (int) Db::insert(self::TABLE, [
            'event_uuid'    => Ids::mint(Ids::EVENT),
            'cmp_id'        => $connection === null ? null : (int) $connection['cmp_id'],
            'connection_id' => $connection === null ? null : (int) $connection['connection_id'],
            'provider_code' => $providerCode,
            'provider_event_type' => self::eventTypeOf($decoded),
            'provider_event_id'   => self::eventIdOf($decoded),
            // A placeholder until the signature is checked and the payload
            // normalised. Unique, so two in-flight events cannot collide on it.
            'fingerprint'   => 'pending:' . bin2hex(random_bytes(16)),
            // Bounded. A provider that sends a megabyte of payload should not
            // be able to fill the table, and the signature has already been
            // computed over the full body by the time this is stored.
            'raw_payload'   => substr($rawPayload, 0, 65536),
            'headers'       => self::safeHeaders($headers),
            'status'        => self::RECEIVED,
        ], 'event_id');
    }

    /**
     * Headers worth keeping, with the signature kept and the rest dropped.
     *
     * The signature is kept ON PURPOSE: it is not a secret — it is a hash that
     * proves the body was signed with one — and having it stored is what lets
     * an operator re-verify a rejected callback by hand.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function safeHeaders(array $headers): array
    {
        $keep = [
            'content-type', 'user-agent', 'x-razorpay-signature', 'x-razorpay-event-id',
            'x-webhook-signature', 'x-webhook-timestamp', 'stripe-signature',
            'x-pay-connection', 'x-forwarded-for',
        ];

        $out = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (in_array($lower, $keep, true) && is_string($value)) {
                $out[$lower] = substr($value, 0, 500);
            }
        }

        return $out;
    }

    /**
     * Take ownership of this fingerprint, or discover somebody already has.
     *
     * The UPDATE can violate the unique index, which is exactly the signal we
     * want: another delivery of the same event got here first.
     */
    private static function claimFingerprint(int $eventId, string $fingerprint): bool
    {
        try {
            Db::update(self::TABLE, ['fingerprint' => $fingerprint], ['event_id' => $eventId]);

            return true;
        } catch (\PDOException $e) {
            // 23505 is unique_violation. Any other database error is a real
            // problem and must not be mistaken for a duplicate.
            if (($e->getCode() === '23505') || str_contains($e->getMessage(), 'uq_pay_webhook_fingerprint')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Give the fingerprint back after a processing failure.
     *
     * Without this, a transient error during processing would leave the
     * fingerprint claimed, and the provider's redelivery — the thing that would
     * have fixed it — would be dismissed as a duplicate. The payment would
     * never be recorded and nothing would say so.
     */
    private static function releaseFingerprint(int $eventId): void
    {
        try {
            Db::update(self::TABLE, ['fingerprint' => 'released:' . bin2hex(random_bytes(16))], ['event_id' => $eventId]);
        } catch (\Throwable $e) {
            error_log('[webhook] could not release fingerprint for event ' . $eventId . ': ' . $e->getMessage());
        }
    }

    private static function markSignature(int $eventId, bool $valid, ?string $reason): void
    {
        Db::update(self::TABLE, [
            'signature_valid'  => $valid,
            'signature_reason' => $reason,
        ], ['event_id' => $eventId]);
    }

    private static function finish(int $eventId, string $status, ?string $note, float $startedAt): void
    {
        Db::update(self::TABLE, [
            'status'        => $status,
            'process_error' => $status === self::PROCESSED ? null : $note,
            'processing_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'processed_at'  => gmdate('Y-m-d H:i:s'),
        ], ['event_id' => $eventId]);
    }

    private static function statusFor(string $event): string
    {
        return match ($event) {
            EventNames::PAYMENT_SUCCESS => States::ATTEMPT_SUCCESS,
            EventNames::PAYMENT_FAILED  => States::ATTEMPT_FAILED,
            default                     => States::ATTEMPT_PENDING,
        };
    }

    /** @param array<string, mixed> $payload */
    private static function eventTypeOf(array $payload): ?string
    {
        foreach (['event', 'type', 'event_type'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                return substr($payload[$key], 0, 120);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private static function eventIdOf(array $payload): ?string
    {
        foreach (['id', 'event_id', 'webhook_id'] as $key) {
            if (isset($payload[$key]) && (is_string($payload[$key]) || is_int($payload[$key]))) {
                return substr((string) $payload[$key], 0, 120);
            }
        }

        return null;
    }
}
