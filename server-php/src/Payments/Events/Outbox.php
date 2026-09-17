<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Events;

use Aicountly\Api\Clients\SourceClient;
use Aicountly\Api\Context;
use Aicountly\Api\Crypto;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Ids;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Env;
use Aicountly\Api\Payments\Sources\SourceRegistry;

/**
 * What Pay owes the outside world, and the promise that it gets there.
 *
 * READ THIS BEFORE CALLING IT A SYNC TABLE — it is the opposite of one. It
 * holds no other product's data. It holds MESSAGES: "payment PAY-X succeeded
 * against invoice 92841". The message is delivered, acknowledged, and finished
 * with. A mirror exists so you never have to ask; an outbox exists so you never
 * have to hold.
 *
 * WHY IT EXISTS AT ALL. When a payment succeeds, two things must happen: the
 * payment must be recorded, and Books must be told. They cannot be one
 * operation. A synchronous POST to Books inside the payment transaction gives
 * you two ways to lose:
 *
 *   * Books is down → the transaction rolls back → the customer's money was
 *     taken and Pay has no record of it. This is the worst outcome in the whole
 *     product.
 *   * Books is slow → the transaction holds a row lock for twenty seconds →
 *     every other payment on that request queues behind it.
 *
 * So the event is written in the SAME transaction as the payment — which is
 * what makes it impossible to have one without the other — and delivered
 * afterwards by a worker that can take as long as it needs.
 *
 * THE DELIVERY IS A POST TO THAT APP'S OWN API. Pay never writes a row in
 * another product's database. Books receives "this happened" and decides for
 * itself what document that becomes.
 */
final class Outbox
{
    public const TABLE = 'pay_outbound_events';

    public const PENDING   = 'PENDING';
    public const SENDING   = 'SENDING';
    public const DELIVERED = 'DELIVERED';
    public const FAILED    = 'FAILED';
    /** Automatic attempts are spent. A human decides now. */
    public const EXHAUSTED = 'EXHAUSTED';
    public const ABANDONED = 'ABANDONED';

    /**
     * Queue an event.
     *
     * CALL THIS INSIDE THE TRANSACTION that made the thing happen. Outside it,
     * there is a window in which the payment is committed and the event is not,
     * and a crash in that window loses the notification permanently.
     *
     * @param array{request?:?array<string,mixed>, attempt?:?array<string,mixed>,
     *     refund?:?array<string,mixed>, reason?:string} $subject
     * @param bool $merchantOnly true for events a source app has no use for —
     *        they still reach a merchant's own webhook endpoint.
     */
    public static function queue(Context $ctx, string $event, array $subject, bool $merchantOnly = false): void
    {
        try {
            $payload = self::buildPayload($ctx, $event, $subject);

            $targets = [];

            if (!$merchantOnly) {
                $sourceApp = strtoupper((string) ($payload['source_app'] ?? 'PAY'));
                if ($sourceApp !== 'PAY' && in_array($event, EventNames::deliverableToSourceApps(), true)) {
                    $adapter = SourceRegistry::for($sourceApp);
                    $path = $adapter->callbackPath($event);

                    if ($path !== null && $adapter->isAvailable()) {
                        $targets[] = ['app' => $sourceApp, 'url' => $path, 'endpoint_id' => null, 'shaped' => $adapter->shapeCallback($payload)];
                    }
                }
            }

            // Merchant endpoints. A merchant's own integration and the fleet's
            // are the same mechanism with different addresses, which is what
            // makes the public webhook contract genuinely the same one Books
            // gets rather than a cut-down version of it.
            foreach (self::subscribedEndpoints($ctx, $event) as $endpoint) {
                $targets[] = [
                    'app'         => 'WEBHOOK',
                    'url'         => (string) $endpoint['target_url'],
                    'endpoint_id' => (int) $endpoint['endpoint_id'],
                    'shaped'      => SourceRegistry::for('EXTERNAL')->shapeCallback($payload),
                ];
            }

            foreach ($targets as $target) {
                Db::insert(self::TABLE, [
                    'event_uuid'  => Ids::mint(Ids::EVENT),
                    'cmp_id'      => $ctx->cmpId,
                    'event_name'  => $event,
                    'target_app'  => $target['app'],
                    'target_url'  => $target['url'],
                    'endpoint_id' => $target['endpoint_id'],
                    'request_id'  => isset($subject['request']) && $subject['request'] !== null ? (int) $subject['request']['request_id'] : null,
                    'attempt_id'  => isset($subject['attempt']) && $subject['attempt'] !== null ? (int) $subject['attempt']['attempt_id'] : null,
                    'refund_id'   => isset($subject['refund']) && $subject['refund'] !== null ? (int) $subject['refund']['refund_id'] : null,
                    'payload'     => $target['shaped'],
                    'max_attempts' => count(Settings::retrySchedule($ctx)) + 1,
                ], 'outbound_id');
            }
        } catch (\Throwable $e) {
            // Queueing must never be the reason a payment fails to record. This
            // is logged loudly because a lost event is a source app that never
            // learns about a payment, which somebody will notice at month end.
            error_log('[outbox] could not queue ' . $event . ' for company ' . $ctx->cmpId . ': ' . $e->getMessage());
        }
    }

    /**
     * Deliver what is due.
     *
     * Run by the worker. Claims rows one at a time with a conditional UPDATE so
     * two workers cannot both send the same event — which would be two receipts
     * in Books for one payment.
     *
     * @return array{claimed:int, delivered:int, failed:int, exhausted:int}
     */
    public static function drain(int $limit = 50): array
    {
        $stats = ['claimed' => 0, 'delivered' => 0, 'failed' => 0, 'exhausted' => 0];

        for ($i = 0; $i < $limit; $i++) {
            $row = self::claimNext();
            if ($row === null) {
                break;
            }
            $stats['claimed']++;

            $outcome = self::deliver($row);
            $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Take the next due event, atomically.
     *
     * The status check is inside the UPDATE, not before it. A SELECT then an
     * UPDATE lets two workers both read PENDING and both send.
     *
     * @return array<string, mixed>|null
     */
    private static function claimNext(): ?array
    {
        $rows = Db::all(
            'UPDATE ' . self::TABLE . ' SET status = :sending, last_attempt_at = NOW(), attempt_count = attempt_count + 1
             WHERE outbound_id = (
                 SELECT outbound_id FROM ' . self::TABLE . '
                 WHERE status IN (:pending, :failed) AND next_attempt_at <= NOW()
                 ORDER BY next_attempt_at ASC, outbound_id ASC
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1
             )
             RETURNING *',
            ['sending' => self::SENDING, 'pending' => self::PENDING, 'failed' => self::FAILED],
        );

        return $rows[0] ?? null;
    }

    /**
     * Send one event.
     *
     * @param array<string, mixed> $row
     * @return 'delivered'|'failed'|'exhausted'
     */
    private static function deliver(array $row): string
    {
        $ctx = Context::forBackground((int) $row['cmp_id']);
        $payload = Db::jsonColumn($row['payload'] ?? null);
        $eventUuid = (string) $row['event_uuid'];
        $targetApp = (string) $row['target_app'];

        $signature = self::sign($ctx, $targetApp, (int) ($row['endpoint_id'] ?? 0), $payload);

        if ($targetApp === 'WEBHOOK') {
            $result = self::postToMerchant((string) $row['target_url'], $payload, $signature, $eventUuid);
        } else {
            $adapter = SourceRegistry::for($targetApp);
            $client = (new SourceClient(
                strtolower($targetApp),
                self::baseFor($targetApp, true),
                self::baseFor($targetApp, false),
                strtoupper($targetApp) . '_API_BASE',
            ))->withService('pay:outbox', strtoupper($targetApp) . '_SERVICE_KEY');

            if (!$client->hasCredentials()) {
                // No service key for that product. Retrying six times will not
                // create one, so this is recorded as needing attention now
                // rather than after twelve hours of backoff.
                return self::exhaust($row, 'No service key is configured for ' . $adapter->displayName() . '.');
            }

            $result = $client->deliver((string) $row['target_url'], $payload, $signature, $eventUuid);
        }

        if ($result['ok'] ?? false) {
            Db::update(self::TABLE, [
                'status'          => self::DELIVERED,
                'delivered_at'    => gmdate('Y-m-d H:i:s'),
                'response_status' => (int) ($result['status'] ?? 200),
                'last_error'      => null,
            ], ['outbound_id' => (int) $row['outbound_id']]);

            self::markSubjectSynced($ctx, $row, true);
            self::recordEndpointOutcome($row, true, null);

            return 'delivered';
        }

        $status = (int) ($result['status'] ?? 0);
        $error = (string) ($result['error'] ?? 'delivery failed');

        // A 4xx other than 408 and 429 means the receiver understood and
        // refused. Retrying is pointless and the merchant needs telling — a
        // 422 from Books is "that invoice does not exist", and the sixth
        // attempt will say the same thing twelve hours later.
        $permanent = $status >= 400 && $status < 500 && !in_array($status, [408, 429], true);

        if ($permanent || (int) $row['attempt_count'] >= (int) $row['max_attempts']) {
            return self::exhaust($row, $error, $status, $result['body'] ?? null);
        }

        $delay = self::backoffMinutes($ctx, (int) $row['attempt_count']);

        Db::update(self::TABLE, [
            'status'           => self::FAILED,
            'next_attempt_at'  => gmdate('Y-m-d H:i:s', time() + ($delay * 60)),
            'response_status'  => $status,
            'response_excerpt' => self::excerpt($result['body'] ?? null),
            'last_error'       => substr($error, 0, 500),
        ], ['outbound_id' => (int) $row['outbound_id']]);

        self::recordEndpointOutcome($row, false, $error);

        return 'failed';
    }

    /**
     * @param array<string, mixed> $row
     * @return 'exhausted'
     */
    private static function exhaust(array $row, string $error, int $status = 0, mixed $body = null): string
    {
        Db::update(self::TABLE, [
            'status'           => self::EXHAUSTED,
            'response_status'  => $status,
            'response_excerpt' => self::excerpt($body),
            'last_error'       => substr($error, 0, 500),
        ], ['outbound_id' => (int) $row['outbound_id']]);

        $ctx = Context::forBackground((int) $row['cmp_id']);
        self::markSubjectSynced($ctx, $row, false);

        // A source app that was never told about a payment is a reconciliation
        // problem, not just a failed HTTP call — the merchant's books are now
        // missing a receipt. It gets a case with somebody's name on it.
        if ((string) $row['target_app'] !== 'WEBHOOK') {
            \Aicountly\Api\Domain\ReconciliationService::openCase($ctx, [
                'case_kind'  => 'SOURCE_SYNC_FAILED',
                'severity'   => 'HIGH',
                'attempt_id' => $row['attempt_id'] === null ? null : (int) $row['attempt_id'],
                'refund_id'  => $row['refund_id'] === null ? null : (int) $row['refund_id'],
                'summary'    => sprintf(
                    'Could not tell %s about %s. The payment is safe in Pay; that app has not been updated.',
                    SourceRegistry::for((string) $row['target_app'])->displayName(),
                    EventNames::label((string) $row['event_name']),
                ),
                'detail'     => ['event_id' => (string) $row['event_uuid'], 'last_error' => substr($error, 0, 300)],
            ]);
        }

        self::recordEndpointOutcome($row, false, $error);

        return 'exhausted';
    }

    /**
     * Send an exhausted event again, by hand.
     *
     * The count is reset rather than incremented: a human retrying after fixing
     * the receiving end should get the full schedule again, not one last go.
     */
    public static function retry(Context $ctx, int $outboundId): bool
    {
        $affected = Db::update(self::TABLE, [
            'status'          => self::PENDING,
            'attempt_count'   => 0,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
            'last_error'      => null,
        ], ['outbound_id' => $outboundId, 'cmp_id' => $ctx->cmpId]);

        return $affected > 0;
    }

    /**
     * The signature a receiver checks.
     *
     * HMAC-SHA256 over the exact JSON we send, with a timestamp folded in so a
     * captured callback cannot be replayed later. Fleet apps verify with the
     * shared service key; a merchant's endpoint verifies with the secret we
     * issued them.
     */
    private static function sign(Context $ctx, string $targetApp, int $endpointId, array $payload): string
    {
        $secret = $targetApp === 'WEBHOOK'
            ? self::endpointSecret($ctx, $endpointId)
            : Env::get('PAY_CALLBACK_SIGNING_SECRET');

        if ($secret === '') {
            return '';
        }

        $timestamp = (string) time();
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    private static function endpointSecret(Context $ctx, int $endpointId): string
    {
        if ($endpointId <= 0) {
            return '';
        }

        $row = Db::first(
            'SELECT signing_secret FROM pay_webhook_endpoints WHERE endpoint_id = :id AND cmp_id = :cmp',
            ['id' => $endpointId, 'cmp' => $ctx->cmpId],
        );

        return $row === null ? '' : (Crypto::open((string) $row['signing_secret']) ?? '');
    }

    /**
     * POST to a merchant's own endpoint.
     *
     * Short timeouts and no redirects. A merchant's URL is arbitrary and may be
     * slow, dead, or pointed somewhere that redirects — following that would
     * let a webhook target be turned into a request to anywhere.
     *
     * @param array<string, mixed> $payload
     */
    private static function postToMerchant(string $url, array $payload, string $signature, string $eventId): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'curl_init_failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Pay-Signature: ' . $signature,
                'X-Pay-Event-Id: ' . $eventId,
                'User-Agent: AicountlyPay/1.0',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'ok'     => $raw !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => $raw === false ? null : (string) $raw,
            'error'  => $error !== '' ? $error : ($status === 0 ? 'unreachable' : 'HTTP ' . $status),
        ];
    }

    /** Update the attempt's or refund's own sync status, so a screen can show it. */
    private static function markSubjectSynced(Context $ctx, array $row, bool $ok): void
    {
        if ((string) $row['target_app'] === 'WEBHOOK') {
            return;
        }

        $status = $ok ? 'SENT' : 'FAILED';
        $syncedAt = $ok ? gmdate('Y-m-d H:i:s') : null;

        try {
            if ($row['attempt_id'] !== null) {
                Db::update(\Aicountly\Api\Domain\PaymentService::TABLE, [
                    'source_sync_status' => $status,
                    'source_synced_at'   => $syncedAt,
                ], ['attempt_id' => (int) $row['attempt_id'], 'cmp_id' => $ctx->cmpId]);
            }
            if ($row['refund_id'] !== null) {
                Db::update(\Aicountly\Api\Domain\RefundService::TABLE, [
                    'source_sync_status' => $status,
                    'source_synced_at'   => $syncedAt,
                ], ['refund_id' => (int) $row['refund_id'], 'cmp_id' => $ctx->cmpId]);
            }
        } catch (\Throwable $e) {
            error_log('[outbox] could not update sync status: ' . $e->getMessage());
        }
    }

    /**
     * Track a merchant endpoint's health, and pause one that is plainly dead.
     *
     * Twenty consecutive failures is a URL that has been decommissioned, and
     * retrying it forever fills the queue and delays everybody else's events.
     * The merchant is told on the Developers screen rather than silently
     * dropped.
     */
    private static function recordEndpointOutcome(array $row, bool $ok, ?string $error): void
    {
        if ($row['endpoint_id'] === null) {
            return;
        }

        try {
            if ($ok) {
                Db::run(
                    'UPDATE pay_webhook_endpoints
                     SET consecutive_failures = 0, last_success_at = NOW(), updated_at = NOW()
                     WHERE endpoint_id = :id',
                    ['id' => (int) $row['endpoint_id']],
                );

                return;
            }

            Db::run(
                'UPDATE pay_webhook_endpoints
                 SET consecutive_failures = consecutive_failures + 1,
                     last_failure_at = NOW(),
                     last_failure_reason = :reason,
                     status = CASE WHEN consecutive_failures + 1 >= 20 THEN :paused ELSE status END,
                     updated_at = NOW()
                 WHERE endpoint_id = :id',
                ['reason' => substr((string) $error, 0, 300), 'paused' => 'PAUSED', 'id' => (int) $row['endpoint_id']],
            );
        } catch (\Throwable $e) {
            error_log('[outbox] could not record endpoint health: ' . $e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    private static function subscribedEndpoints(Context $ctx, string $event): array
    {
        if (!in_array($event, EventNames::deliverableToMerchants(), true)) {
            return [];
        }

        try {
            return Db::all(
                'SELECT endpoint_id, target_url, subscribed_events FROM pay_webhook_endpoints
                 WHERE cmp_id = :cmp AND status = :active
                   AND (jsonb_array_length(subscribed_events) = 0 OR subscribed_events ? :event)',
                ['cmp' => $ctx->cmpId, 'active' => 'ACTIVE', 'event' => $event],
            );
        } catch (\Throwable $e) {
            error_log('[outbox] could not read webhook endpoints: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The common event body.
     *
     * Both the amount of THIS payment and where the request now stands, because
     * a receiver that only reads one of them cannot tell a part payment from a
     * full one without doing arithmetic we could have done for them.
     *
     * @param array<string, mixed> $subject
     * @return array<string, mixed>
     */
    private static function buildPayload(Context $ctx, string $event, array $subject): array
    {
        $request = $subject['request'] ?? null;
        $attempt = $subject['attempt'] ?? null;
        $refund = $subject['refund'] ?? null;

        $currency = (string) ($attempt['currency'] ?? $refund['currency'] ?? $request['currency'] ?? 'INR');
        $amountMinor = (int) ($attempt['amount_minor'] ?? $refund['amount_minor'] ?? 0);

        $payload = [
            'event'      => $event,
            'event_uuid' => null, // filled per target when the row is written
            'cmp_id'     => $ctx->cmpId,
            'bo_id'      => (int) ($request['bo_id'] ?? $attempt['bo_id'] ?? 0),

            'source_app'       => $request === null ? 'PAY' : (string) $request['source_app'],
            'source_type'      => $request === null ? null : ($request['source_type'] === null ? null : (string) $request['source_type']),
            'source_id'        => $request === null ? null : ($request['source_id'] === null ? null : (string) $request['source_id']),
            'source_reference' => $request === null ? null : ($request['source_reference'] === null ? null : (string) $request['source_reference']),
            'source_fy_id'     => $request === null ? null : ($request['source_fy_id'] === null ? null : (int) $request['source_fy_id']),
            'callback_reference' => $request === null ? null : ($request['callback_reference'] === null ? null : (string) $request['callback_reference']),

            'request_uuid' => $request === null ? null : (string) $request['request_uuid'],
            'attempt_uuid' => $attempt === null ? null : (string) $attempt['attempt_uuid'],
            'refund_uuid'  => $refund === null ? null : (string) $refund['refund_uuid'],

            'amount'       => Money::minor($amountMinor, $currency)->toMajor(),
            'amount_minor' => $amountMinor,
            'currency'     => $currency,

            'provider_mode'       => $attempt === null ? null : (string) $attempt['provider_mode'],
            'provider'            => $attempt === null ? null : ($attempt['provider_code'] === null ? null : (string) $attempt['provider_code']),
            'provider_payment_id' => $attempt === null ? null : ($attempt['provider_payment_id'] === null ? null : (string) $attempt['provider_payment_id']),
            'payment_method'      => $attempt === null ? null : ($attempt['payment_method'] === null ? null : (string) $attempt['payment_method']),
            'paid_at'             => $attempt === null ? null : ($attempt['paid_at'] === null ? null : (string) $attempt['paid_at']),
        ];

        if ($request !== null) {
            $total = Money::minor((int) $request['amount_minor'], $currency);
            $paid = Money::minor((int) $request['paid_minor'], $currency);
            $refunded = Money::minor((int) $request['refunded_minor'], $currency);

            $payload['request_total'] = $total->toMajor();
            $payload['request_paid'] = $paid->toMajor();
            $payload['request_outstanding'] = $total->minus($paid)->plus($refunded)->clampToZero()->toMajor();
            $payload['request_status'] = (string) $request['status'];
            $payload['payer'] = [
                'name'   => $request['payer_name'] === null ? null : (string) $request['payer_name'],
                'email'  => $request['payer_email'] === null ? null : (string) $request['payer_email'],
                'mobile' => $request['payer_mobile'] === null ? null : (string) $request['payer_mobile'],
            ];
        }

        if (isset($subject['reason'])) {
            $payload['reason'] = (string) $subject['reason'];
        }

        return $payload;
    }

    private static function backoffMinutes(Context $ctx, int $attemptCount): int
    {
        $schedule = Settings::retrySchedule($ctx);
        $index = max(0, $attemptCount - 1);

        return $schedule[$index] ?? (int) end($schedule);
    }

    private static function excerpt(mixed $body): ?string
    {
        if (!is_string($body) || $body === '') {
            return null;
        }

        // 500 characters, and never more. A receiving app's 500 page can carry a
        // stack trace with a DSN in it, and this column is read on screen.
        return substr(strip_tags($body), 0, 500);
    }

    private static function baseFor(string $app, bool $production): string
    {
        $host = strtolower($app);

        return $production
            ? 'https://' . $host . '.aicountly.com'
            : 'https://' . $host . '.gh.aicountly.com';
    }
}
