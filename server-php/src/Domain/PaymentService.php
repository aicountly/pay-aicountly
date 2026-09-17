<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Events\Outbox;
use Aicountly\Api\Payments\Routing\RoutingEngine;

/**
 * Payment attempts: one journey through one provider.
 *
 * THE TWO THINGS THIS FILE GETS RIGHT THAT PAYMENT CODE USUALLY GETS WRONG.
 *
 * 1. THE AMOUNT IS NEVER TAKEN FROM THE BROWSER. `start()` reads what is
 *    outstanding on the request out of the database and charges that. Where a
 *    partial payment is allowed the caller may propose a smaller amount, and
 *    that proposal is checked against the request's own rules before anything
 *    reaches a provider. A checkout page that posts `amount=1` must end up
 *    charging the full invoice or being refused — never charging ₹1.
 *
 * 2. A SUCCESS IS RECORDED ONCE, WHATEVER ARRIVES TWICE. `applyProviderState()`
 *    is written so that the same provider payment reaching us through a
 *    webhook, a status poll and a browser redirect — which all happen, often
 *    within the same second — produces one row and one set of totals. The
 *    UNIQUE index on (provider, provider_payment_id) is the backstop; the
 *    transition rules are the logic.
 */
final class PaymentService
{
    public const TABLE = 'pay_payment_attempts';

    /**
     * Begin a payment against a request.
     *
     * @param array{amount?:mixed, method?:?string, channel?:string, link_id?:?int,
     *     scope?:?string, exclude_connection_ids?:list<int>, idempotency_key?:string} $input
     * @return array{ok:bool, attempt?:array<string,mixed>, provider?:array<string,mixed>,
     *     error_code?:string, error_message?:string, retryable?:bool}
     */
    public static function start(Context $ctx, Auth $auth, array $request, array $input): array
    {
        $currency = (string) $request['currency'];
        $outstanding = PaymentRequestService::outstanding($request);

        // What the caller asked for, or everything still owed. Either way it is
        // checked below — a proposed amount is a request, not an instruction.
        $amount = isset($input['amount']) && $input['amount'] !== null && $input['amount'] !== ''
            ? Money::fromMajor($input['amount'], $currency)
            : $outstanding;

        $payable = PaymentRequestService::assertPayable($request, $amount);
        if (!$payable['ok']) {
            return ['ok' => false, 'error_code' => $payable['error_code'], 'error_message' => $payable['error_message']];
        }

        $method = isset($input['method']) && $input['method'] !== null && $input['method'] !== ''
            ? strtoupper((string) $input['method']) : null;

        // A request that names its own acceptable methods overrides whatever
        // the checkout offered: the merchant said card-only for a reason.
        $allowed = Db::jsonColumn($request['allowed_methods'] ?? null);
        if ($method !== null && $allowed !== [] && !in_array($method, $allowed, true)) {
            return [
                'ok'            => false,
                'error_code'    => 'method_not_allowed',
                'error_message' => 'This payment request does not accept ' . strtolower($method) . ' payments.',
            ];
        }

        $routed = RoutingEngine::choose($ctx, [
            'method'       => $method,
            'currency'     => $currency,
            'amount_minor' => $amount->minor,
            'source_app'   => (string) $request['source_app'],
            'scope'        => $input['scope'] ?? null,
            'exclude_connection_ids' => $input['exclude_connection_ids'] ?? [],
        ]);

        if (!$routed['ok']) {
            return [
                'ok'            => false,
                'error_code'    => $routed['error_code'],
                'error_message' => $routed['error_message'],
                'considered'    => $routed['considered'],
            ];
        }

        $connection = $routed['connection'];
        $provider = $routed['provider'];

        // The attempt row exists BEFORE the provider is called. If the call
        // times out, there is still a record of what we tried, which is what
        // the reconciler later matches against the provider's own list. An
        // attempt created only on success loses exactly the payments that are
        // hardest to trace.
        $attemptId = Db::transaction(static function () use ($ctx, $request, $amount, $currency, $method, $connection, $input): int {
            $attemptNo = (int) Db::scalar(
                'SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM ' . self::TABLE . ' WHERE request_id = :id',
                ['id' => (int) $request['request_id']],
            );

            return (int) Db::insert(self::TABLE, [
                'attempt_uuid'   => Ids::mint(Ids::ATTEMPT),
                'cmp_id'         => $ctx->cmpId,
                'bo_id'          => (int) $request['bo_id'],
                'request_id'     => (int) $request['request_id'],
                'payer_id'       => $request['payer_id'] === null ? null : (int) $request['payer_id'],
                'amount_minor'   => $amount->minor,
                'currency'       => $currency,
                'status'         => States::ATTEMPT_CREATED,
                'payment_method' => $method,
                'connection_id'  => (int) $connection['connection_id'],
                'provider_mode'  => (string) $connection['provider_mode'],
                'provider_code'  => (string) $connection['provider_code'],
                'channel'        => strtoupper((string) ($input['channel'] ?? 'LINK')),
                'attempt_no'     => $attemptNo,
                'retry_of_attempt_id' => $input['retry_of_attempt_id'] ?? null,
                // Set as soon as we know there is a source app to tell. The
                // outbox flips it to SENT; a payment that never reaches this
                // point cannot silently skip the callback.
                'source_sync_status' => (string) $request['source_app'] === 'PAY'
                    ? 'NOT_APPLICABLE' : 'PENDING',
            ], 'attempt_id');
        });

        RoutingEngine::recordDecision(
            $ctx,
            $attemptId,
            $routed['rule_id'],
            (int) $connection['connection_id'],
            $routed['decided_by'],
            $routed['considered'],
        );

        $attempt = self::require($ctx, $attemptId);

        $result = $provider->createPayment([
            'amount_minor'    => $amount->minor,
            'currency'        => $currency,
            'reference'       => (string) ($request['source_reference'] ?? $request['request_uuid']),
            'description'     => (string) ($request['description'] ?? 'Payment'),
            'payer'           => [
                'name'   => $request['payer_name'] ?? null,
                'email'  => $request['payer_email'] ?? null,
                'mobile' => $request['payer_mobile'] ?? null,
            ],
            'method'          => $method,
            'return_url'      => $request['return_url'] ?? null,
            'request_uuid'    => (string) $request['request_uuid'],
            'attempt_uuid'    => (string) $attempt['attempt_uuid'],
            'source_app'      => (string) $request['source_app'],
            'allow_partial'   => false,
            // The attempt uuid is stable for this attempt, so a retry of the
            // same attempt reaches the same key and the provider replays rather
            // than creating a second order.
            'idempotency_key' => (string) ($input['idempotency_key'] ?? $attempt['attempt_uuid']),
        ]);

        if (!($result['ok'] ?? false)) {
            self::markFailed(
                $ctx,
                $attemptId,
                (string) ($result['error_code'] ?? 'provider_error'),
                (string) ($result['error_message'] ?? 'The payment could not be started.'),
            );

            return [
                'ok'            => false,
                'error_code'    => (string) ($result['error_code'] ?? 'provider_error'),
                'error_message' => (string) ($result['error_message'] ?? 'The payment could not be started.'),
                // Handed up so the caller can decide whether to try the
                // fallback provider. A decline is not retryable and must not be.
                'retryable'     => (bool) ($result['retryable'] ?? false),
                'attempt'       => self::present($ctx, self::require($ctx, $attemptId)),
                'fallback_connection_id' => $routed['fallback_connection_id'],
            ];
        }

        Db::update(self::TABLE, array_filter([
            // INITIATED, not whatever the provider calls its own brand-new
            // order. From Pay's side the payment HAS been sent to a provider,
            // and that is what this column records. A provider that says
            // "created" and one that says "pending" are describing their own
            // bookkeeping, not ours.
            'status'              => self::mapStartStatus((string) ($result['status'] ?? 'CREATED')),
            'provider_payment_id' => $result['provider_payment_id'] ?? null,
            'provider_order_id'   => $result['provider_order_id'] ?? null,
            'initiated_at'        => gmdate('Y-m-d H:i:s'),
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null), ['attempt_id' => $attemptId, 'cmp_id' => $ctx->cmpId]);

        PaymentRequestService::recordCheckoutStarted((int) $request['request_id'], $input['link_id'] ?? null);

        return [
            'ok'       => true,
            'attempt'  => self::present($ctx, self::require($ctx, $attemptId)),
            'provider' => [
                'code'    => (string) $connection['provider_code'],
                'mode'    => (string) $connection['provider_mode'],
                'name'    => (string) $connection['display_name'],
                // Whatever the browser needs to complete: an order id and public
                // key, a client secret, a redirect URL, or a signed form. Never a
                // provider secret — each adapter is responsible for that, and
                // the integration test checks the shape.
                'checkout' => $result['raw'] ?? [],
                'checkout_url' => $result['checkout_url'] ?? null,
            ],
        ];
    }

    /**
     * Apply what a provider says about a payment.
     *
     * THE SINGLE ENTRY POINT for a webhook, a status poll and a browser return.
     * All three go through here so the ordering rules live in one place:
     *
     *   * an unknown provider payment id creates the attempt, because a
     *     provider can tell us about a payment we never saw started — a
     *     customer who paid a provider-hosted link, or an attempt whose
     *     creation response was lost;
     *   * a transition the state machine forbids is ignored, not applied,
     *     because webhooks arrive out of order and "pending" after "captured"
     *     is stale news rather than a reversal;
     *   * a contradiction of something terminal opens a reconciliation case,
     *     because "you said this succeeded and now you say it failed" is about
     *     money and needs a person.
     *
     * @param array<string, mixed> $normalized from the provider adapter
     * @return array{applied:bool, reason:string, attempt_id:?int}
     */
    public static function applyProviderState(Context $ctx, array $normalized, array $connection): array
    {
        $providerPaymentId = self::str($normalized['provider_payment_id'] ?? null);
        $providerOrderId = self::str($normalized['provider_order_id'] ?? null);
        $newStatus = self::str($normalized['status'] ?? null);

        if ($newStatus === null) {
            return ['applied' => false, 'reason' => 'no_status', 'attempt_id' => null];
        }

        return Db::transaction(static function () use ($ctx, $normalized, $connection, $providerPaymentId, $providerOrderId, $newStatus): array {
            $attempt = self::locate($ctx, $connection, $providerPaymentId, $providerOrderId, $normalized);

            if ($attempt === null) {
                return ['applied' => false, 'reason' => 'attempt_not_found', 'attempt_id' => null];
            }

            $attemptId = (int) $attempt['attempt_id'];
            $current = (string) $attempt['status'];

            if ($current === $newStatus) {
                // A redelivery. Not an error, not a second capture — the most
                // common thing that happens to a webhook endpoint.
                return ['applied' => false, 'reason' => 'already_in_state', 'attempt_id' => $attemptId];
            }

            if (States::isTerminalConflict('attempt', $current, $newStatus)) {
                ReconciliationService::openCase($ctx, [
                    'case_kind'  => 'PROVIDER_STATE_CONFLICT',
                    'severity'   => 'HIGH',
                    'attempt_id' => $attemptId,
                    'summary'    => sprintf(
                        '%s now reports this payment as %s, but Pay recorded it as %s.',
                        (string) $connection['display_name'],
                        States::label($newStatus),
                        States::label($current),
                    ),
                    'detail'     => [
                        'recorded' => $current,
                        'reported' => $newStatus,
                        'provider_payment_id' => $providerPaymentId,
                    ],
                ]);

                return ['applied' => false, 'reason' => 'terminal_conflict', 'attempt_id' => $attemptId];
            }

            if (!States::allows('attempt', $current, $newStatus)) {
                // Out-of-order news about an attempt that has moved on.
                return ['applied' => false, 'reason' => 'transition_not_allowed', 'attempt_id' => $attemptId];
            }

            $now = gmdate('Y-m-d H:i:s');
            $paidAt = self::str($normalized['occurred_at'] ?? null);
            $paidAt = $paidAt === null ? $now : gmdate('Y-m-d H:i:s', strtotime($paidAt) ?: time());

            $updates = array_filter([
                'status'              => $newStatus,
                'payment_method'      => self::str($normalized['method'] ?? null),
                'method_network'      => self::str($normalized['network'] ?? null),
                'method_last4'        => self::str($normalized['last4'] ?? null),
                'payer_vpa_masked'    => self::str($normalized['vpa'] ?? null),
                'provider_payment_id' => $providerPaymentId,
                'provider_order_id'   => $providerOrderId,
                'failure_code'        => self::str($normalized['error_code'] ?? null),
                'failure_reason'      => self::str($normalized['error_message'] ?? null),
                'updated_at'          => $now,
            ], static fn ($v) => $v !== null);

            // The provider is the authority on WHEN the money moved, and the
            // dashboards group by that rather than by when we heard.
            if (in_array($newStatus, States::ATTEMPT_SETTLED_MONEY, true)) {
                $updates['paid_at'] = $paidAt;
                $updates['captured_at'] = $paidAt;
            }
            if ($newStatus === States::ATTEMPT_AUTHORIZED) {
                $updates['authorized_at'] = $paidAt;
            }
            if ($newStatus === States::ATTEMPT_FAILED || $newStatus === States::ATTEMPT_CANCELLED) {
                $updates['failed_at'] = $now;
                // Money that never moved is not awaiting settlement. The column
                // defaults to PENDING because that is right for an attempt in
                // flight, and leaving it there once the attempt has failed puts
                // "Settlement: Pending" beside "Failed" on every screen that
                // shows both — which reads as money on its way that is not.
                $updates['settlement_status'] = 'NOT_APPLICABLE';
            }

            // A provider that reports a different amount than we asked for is
            // NOT corrected silently. The amount is what the request was for;
            // a mismatch is a reconciliation case, because the alternative is
            // a customer paying ₹100 against a ₹10,000 invoice and the invoice
            // closing.
            $reportedAmount = isset($normalized['amount_minor']) ? (int) $normalized['amount_minor'] : null;
            if ($reportedAmount !== null && $reportedAmount !== (int) $attempt['amount_minor']
                && in_array($newStatus, States::ATTEMPT_SETTLED_MONEY, true)) {
                ReconciliationService::openCase($ctx, [
                    'case_kind'  => 'SETTLEMENT_AMOUNT_MISMATCH',
                    'severity'   => 'HIGH',
                    'attempt_id' => $attemptId,
                    'expected_minor' => (int) $attempt['amount_minor'],
                    'actual_minor'   => $reportedAmount,
                    'difference_minor' => $reportedAmount - (int) $attempt['amount_minor'],
                    'currency'   => (string) $attempt['currency'],
                    'summary'    => 'The provider reported a different amount than this payment was started for.',
                    'detail'     => ['provider_payment_id' => $providerPaymentId],
                ]);
            }

            Db::update(self::TABLE, $updates, ['attempt_id' => $attemptId, 'cmp_id' => $ctx->cmpId]);

            $fresh = self::require($ctx, $attemptId);

            // The request's totals, recomputed in THIS transaction. See the
            // comment on PaymentRequestService::recalculate.
            $request = null;
            if ($fresh['request_id'] !== null) {
                $request = PaymentRequestService::recalculate($ctx, (int) $fresh['request_id']);
            }

            if (in_array($newStatus, States::ATTEMPT_SETTLED_MONEY, true)) {
                if ($fresh['payer_id'] !== null) {
                    PayerService::recordPayment(
                        $ctx,
                        (int) $fresh['payer_id'],
                        (int) $fresh['amount_minor'],
                        (string) ($fresh['payment_method'] ?? 'OTHER'),
                        $paidAt,
                    );
                }

                // A part payment gets its own event name. The receiving app's
                // action differs — Books files a part receipt and leaves the
                // bill open — and making it do arithmetic on two numbers to
                // work that out is how integrations get it wrong.
                $isPartial = $request !== null && (string) $request['status'] === States::REQUEST_PARTIALLY_PAID;

                Outbox::queue($ctx, $isPartial ? EventNames::PARTIAL_PAYMENT_RECEIVED : EventNames::PAYMENT_SUCCESS, [
                    'request' => $request,
                    'attempt' => $fresh,
                ]);
            } elseif ($newStatus === States::ATTEMPT_FAILED) {
                Outbox::queue($ctx, EventNames::PAYMENT_FAILED, ['request' => $request, 'attempt' => $fresh]);
            } elseif ($newStatus === States::ATTEMPT_PENDING) {
                Outbox::queue($ctx, EventNames::PAYMENT_PENDING, ['request' => $request, 'attempt' => $fresh], merchantOnly: true);
            }

            return ['applied' => true, 'reason' => 'applied', 'attempt_id' => $attemptId];
        });
    }

    /**
     * Find the attempt a provider is talking about.
     *
     * Four ways, in order of how much we trust them. The last one matters more
     * than it looks: a provider-hosted payment link can be paid by somebody we
     * never created an attempt for, and without the fallback that money would
     * arrive with nowhere to put it.
     *
     * @return array<string, mixed>|null
     */
    private static function locate(Context $ctx, array $connection, ?string $providerPaymentId, ?string $providerOrderId, array $normalized): ?array
    {
        $providerCode = (string) $connection['provider_code'];

        if ($providerPaymentId !== null) {
            $row = Db::first(
                'SELECT * FROM ' . self::TABLE . ' WHERE provider_code = :code AND provider_payment_id = :pid FOR UPDATE',
                ['code' => $providerCode, 'pid' => $providerPaymentId],
            );
            if ($row !== null) {
                return $row;
            }
        }

        if ($providerOrderId !== null) {
            $row = Db::first(
                'SELECT * FROM ' . self::TABLE . '
                 WHERE cmp_id = :cmp AND provider_code = :code AND provider_order_id = :oid
                 ORDER BY attempt_id DESC LIMIT 1 FOR UPDATE',
                ['cmp' => $ctx->cmpId, 'code' => $providerCode, 'oid' => $providerOrderId],
            );
            if ($row !== null) {
                return $row;
            }
        }

        // Our own uuid, which the adapters put in the provider's notes/metadata
        // precisely so a callback that lost its order id can still be placed.
        $attemptUuid = self::str($normalized['attempt_uuid'] ?? null);
        if ($attemptUuid !== null) {
            $row = Db::first(
                'SELECT * FROM ' . self::TABLE . ' WHERE attempt_uuid = :uuid AND cmp_id = :cmp FOR UPDATE',
                ['uuid' => $attemptUuid, 'cmp' => $ctx->cmpId],
            );
            if ($row !== null) {
                return $row;
            }
        }

        // Money that arrived without us starting it. Recorded against the
        // request when the provider named one, and as an orphan otherwise —
        // which the reconciler picks up. Dropping it would be losing a payment.
        $requestUuid = self::str($normalized['request_uuid'] ?? null);
        if ($requestUuid === null && $providerPaymentId === null) {
            return null;
        }

        $request = $requestUuid === null ? null : PaymentRequestService::findByUuid($ctx, $requestUuid);
        $amountMinor = isset($normalized['amount_minor']) ? (int) $normalized['amount_minor'] : 0;
        if ($amountMinor <= 0) {
            return null;
        }

        $attemptId = (int) Db::insert(self::TABLE, [
            'attempt_uuid'   => Ids::mint(Ids::ATTEMPT),
            'cmp_id'         => $ctx->cmpId,
            'bo_id'          => $request === null ? 0 : (int) $request['bo_id'],
            'request_id'     => $request === null ? null : (int) $request['request_id'],
            'payer_id'       => $request === null || $request['payer_id'] === null ? null : (int) $request['payer_id'],
            'amount_minor'   => $amountMinor,
            'currency'       => (string) ($normalized['currency'] ?? 'INR'),
            'status'         => States::ATTEMPT_INITIATED,
            'connection_id'  => (int) $connection['connection_id'],
            'provider_mode'  => (string) $connection['provider_mode'],
            'provider_code'  => $providerCode,
            'provider_payment_id' => $providerPaymentId,
            'provider_order_id'   => $providerOrderId,
            'channel'        => 'LINK',
            'source_sync_status' => $request === null || (string) $request['source_app'] === 'PAY' ? 'NOT_APPLICABLE' : 'PENDING',
        ], 'attempt_id');

        if ($request === null) {
            ReconciliationService::openCase($ctx, [
                'case_kind'  => 'UNRECONCILED_TRANSACTION',
                'severity'   => 'HIGH',
                'attempt_id' => $attemptId,
                'actual_minor' => $amountMinor,
                'currency'   => (string) ($normalized['currency'] ?? 'INR'),
                'summary'    => 'A payment arrived that Pay has no request for. It has been recorded so the money is not lost.',
                'detail'     => ['provider_payment_id' => $providerPaymentId, 'provider' => $providerCode],
            ]);
        }

        return self::find($ctx, $attemptId);
    }

    public static function markFailed(Context $ctx, int $attemptId, string $code, string $reason): void
    {
        Db::update(self::TABLE, [
            'status'         => States::ATTEMPT_FAILED,
            'failure_code'   => substr($code, 0, 100),
            'failure_reason' => substr($reason, 0, 500),
            'failed_at'      => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ], ['attempt_id' => $attemptId, 'cmp_id' => $ctx->cmpId]);
    }

    /**
     * What is still refundable on this payment.
     *
     * Refunds that are merely REQUESTED count against it, so two clerks cannot
     * each request a full refund of the same payment and have both go out. The
     * check that matters is here rather than only in the UI.
     */
    public static function refundable(Context $ctx, array $attempt): Money
    {
        $currency = (string) $attempt['currency'];

        $reserved = (int) Db::scalar(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM ' . RefundService::TABLE . '
             WHERE attempt_id = :id AND status = ANY(:reserving)',
            [
                'id'        => (int) $attempt['attempt_id'],
                'reserving' => '{' . implode(',', States::REFUND_RESERVES_MONEY) . '}',
            ],
        );

        return Money::minor((int) $attempt['amount_minor'], $currency)
            ->minus(Money::minor($reserved, $currency))
            ->clampToZero();
    }

    /**
     * What the attempt's status becomes once the provider has accepted it.
     *
     * Anything the provider calls "created" becomes INITIATED here, because we
     * have in fact initiated it. Where the provider already reports something
     * further along — a mandate debit that succeeded synchronously — that is
     * honoured instead.
     */
    private static function mapStartStatus(string $providerStatus): string
    {
        $upper = strtoupper($providerStatus);

        if ($upper === States::ATTEMPT_CREATED || !in_array($upper, States::all('attempt'), true)) {
            return States::ATTEMPT_INITIATED;
        }

        return $upper;
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $attemptId): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE attempt_id = :id AND cmp_id = :cmp',
            ['id' => $attemptId, 'cmp' => $ctx->cmpId],
        );
    }

    /** @return array<string, mixed>|null */
    public static function findByUuid(Context $ctx, string $uuid): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE attempt_uuid = :uuid AND cmp_id = :cmp',
            ['uuid' => $uuid, 'cmp' => $ctx->cmpId],
        );
    }

    /** @return array<string, mixed> */
    public static function require(Context $ctx, int $attemptId): array
    {
        $row = self::find($ctx, $attemptId);
        if ($row === null) {
            Http::notFound('That payment could not be found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(Context $ctx, array $row, bool $technical = false): array
    {
        $currency = (string) $row['currency'];

        $out = [
            'payment_id'     => (string) $row['attempt_uuid'],
            'status'         => (string) $row['status'],
            'status_label'   => States::label((string) $row['status']),
            'amount'         => Money::minor((int) $row['amount_minor'], $currency)->toMajor(),
            'refunded'       => Money::minor((int) $row['refunded_minor'], $currency)->toMajor(),
            'currency'       => $currency,
            'method'         => $row['payment_method'] === null ? null : (string) $row['payment_method'],
            'method_detail'  => self::methodDetail($row),
            'provider'       => $row['provider_code'] === null ? null : (string) $row['provider_code'],
            'provider_mode'  => (string) $row['provider_mode'],
            'channel'        => (string) $row['channel'],
            'attempt_no'     => (int) $row['attempt_no'],
            'settlement_status' => (string) $row['settlement_status'],
            'source_sync_status' => (string) $row['source_sync_status'],
            'paid_at'        => $row['paid_at'] === null ? null : (string) $row['paid_at'],
            'created_at'     => (string) $row['created_at'],
            'failure_reason' => $row['failure_reason'] === null ? null : (string) $row['failure_reason'],
        ];

        // Provider ids and failure codes are permission-gated: they are what a
        // developer needs and what a collections clerk has no use for, and a
        // provider payment id is enough to look a customer up in the
        // provider's own dashboard.
        if ($technical) {
            $out['technical'] = [
                'provider_payment_id' => $row['provider_payment_id'] === null ? null : (string) $row['provider_payment_id'],
                'provider_order_id'   => $row['provider_order_id'] === null ? null : (string) $row['provider_order_id'],
                'failure_code'        => $row['failure_code'] === null ? null : (string) $row['failure_code'],
                'connection_id'       => $row['connection_id'] === null ? null : (int) $row['connection_id'],
                'initiated_at'        => $row['initiated_at'] === null ? null : (string) $row['initiated_at'],
                'authorized_at'       => $row['authorized_at'] === null ? null : (string) $row['authorized_at'],
                'captured_at'         => $row['captured_at'] === null ? null : (string) $row['captured_at'],
            ];
        }

        return $out;
    }

    /** "VISA •••• 4821" or "te••@okhdfcbank" — enough to recognise, never enough to use. */
    private static function methodDetail(array $row): ?string
    {
        if ($row['payer_vpa_masked'] !== null) {
            return (string) $row['payer_vpa_masked'];
        }

        $network = $row['method_network'] === null ? null : (string) $row['method_network'];
        $last4 = $row['method_last4'] === null ? null : (string) $row['method_last4'];

        if ($network !== null && $last4 !== null) {
            return $network . ' •••• ' . $last4;
        }

        return $network ?? ($last4 === null ? null : '•••• ' . $last4);
    }

    private static function str(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
