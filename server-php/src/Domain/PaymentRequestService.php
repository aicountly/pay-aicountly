<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Idempotency;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Events\Outbox;
use Aicountly\Api\Payments\Sources\SourceRegistry;
use InvalidArgumentException;

/**
 * Payment requests: what the merchant asked for, and where it stands.
 *
 * THE ONE INVARIANT THIS FILE PROTECTS: a request's status and its paid total
 * are DERIVED from its attempts and refunds, always, by recalculate(). Nothing
 * else in the codebase writes `status` or `paid_minor` on a request row.
 *
 * Why that is worth a whole class. A webhook arrives saying a payment
 * succeeded. The obvious code marks the request PAID. Then a second webhook
 * arrives for a different attempt on the same request, out of order, saying
 * that one failed — and the obvious code marks the request back to ACTIVE, with
 * the customer's money already taken and the merchant's screen saying nothing
 * was paid. Recomputing from the attempts cannot produce that: it sums what
 * actually succeeded, whatever order the news arrived in.
 *
 * It also means the request row is repairable. If a webhook is lost and later
 * replayed, or an attempt is corrected by hand, recalculate() puts the request
 * back in step. A hand-maintained status has no such property.
 */
final class PaymentRequestService
{
    public const TABLE = 'pay_payment_requests';

    /**
     * Raise a payment request.
     *
     * Idempotent on two levels, because both failure modes are real:
     *   * the caller's Idempotency-Key, replayed verbatim on a retry;
     *   * a UNIQUE index on (company, source app, type, id), which stops two
     *     different callers raising two live requests for one invoice.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function create(Context $ctx, Auth $auth, array $input): array
    {
        $sourceApp = strtoupper(trim((string) ($input['source_app'] ?? 'PAY')));
        $sourceType = self::nullableString($input['source_type'] ?? null);
        $sourceId = self::nullableString($input['source_id'] ?? null);

        // A machine caller may only raise requests for ITS OWN app. A service
        // key issued to Books that posts source_app=BILLING is either a bug or
        // somebody minting another product's documents, and both are a 403.
        if ($auth->isMachine() && $sourceApp !== strtoupper($auth->sourceApp) && $sourceApp !== 'PAY') {
            Http::forbidden('This caller cannot raise payment requests on behalf of ' . $sourceApp . '.');
        }

        $currency = Money::normaliseCurrency((string) ($input['currency'] ?? Money::DEFAULT_CURRENCY));

        try {
            $amount = Money::fromMajor($input['amount'] ?? $input['amount_minor_raw'] ?? null, $currency);
        } catch (InvalidArgumentException $e) {
            Http::validationFailed($e->getMessage(), ['field' => 'amount']);
        }

        if (!$amount->isPositive()) {
            Http::validationFailed('A payment request needs an amount greater than zero.', ['field' => 'amount']);
        }

        $settings = Settings::for($ctx);
        $allowPartial = array_key_exists('allow_partial_payment', $input)
            ? (bool) $input['allow_partial_payment']
            : (bool) $settings['allow_partial_by_default'];

        $minPartial = null;
        if ($allowPartial) {
            $minPartial = isset($input['min_partial_amount'])
                ? Money::fromMajor($input['min_partial_amount'], $currency)->minor
                : (int) $settings['min_partial_minor'];

            if ($minPartial > $amount->minor) {
                Http::validationFailed(
                    'The minimum part payment cannot be more than the amount being asked for.',
                    ['field' => 'min_partial_amount'],
                );
            }
        }

        $expiresAt = self::resolveExpiry($input['expires_at'] ?? null, (int) $settings['default_link_expiry_hours']);

        $key = Idempotency::fromRequestOr(
            $sourceId === null ? [] : [$sourceApp, (string) $sourceType, $sourceId, $amount->minor],
        );

        [$result] = Idempotency::once($ctx, 'payment_request.create', $key, static function () use (
            $ctx, $auth, $input, $sourceApp, $sourceType, $sourceId, $amount, $currency,
            $allowPartial, $minPartial, $expiresAt
        ): array {
            return Db::transaction(static function () use (
                $ctx, $auth, $input, $sourceApp, $sourceType, $sourceId, $amount, $currency,
                $allowPartial, $minPartial, $expiresAt
            ): array {
                // The source-document guard. A second live request for the same
                // invoice would let a customer pay it twice through two links.
                if ($sourceId !== null) {
                    $existing = Db::first(
                        'SELECT * FROM ' . self::TABLE . '
                         WHERE cmp_id = :cmp AND source_app = :app
                           AND source_type IS NOT DISTINCT FROM :type AND source_id = :id
                           AND status <> :cancelled
                         LIMIT 1',
                        [
                            'cmp' => $ctx->cmpId, 'app' => $sourceApp, 'type' => $sourceType,
                            'id' => $sourceId, 'cancelled' => States::REQUEST_CANCELLED,
                        ],
                    );

                    if ($existing !== null) {
                        // Handing back the existing request is the right answer
                        // to a retry AND to a genuine duplicate: in both cases
                        // the caller wanted a way to collect this invoice, and
                        // there already is one.
                        return self::present($ctx, $existing) + ['already_existed' => true];
                    }
                }

                $payerId = PayerService::resolve($ctx, [
                    'source_app'   => $sourceApp,
                    'customer_ref' => $input['customer_ref'] ?? null,
                    'name'         => $input['payer_name'] ?? null,
                    'email'        => $input['payer_email'] ?? null,
                    'mobile'       => $input['payer_mobile'] ?? null,
                ]);

                $reference = self::nullableString($input['reference'] ?? null) ?? Ids::humanReference();

                $requestId = (int) Db::insert(self::TABLE, [
                    'request_uuid'      => Ids::mint(Ids::REQUEST),
                    'cmp_id'            => $ctx->cmpId,
                    'bo_id'             => $ctx->boId,
                    'source_app'        => $sourceApp,
                    'source_type'       => $sourceType,
                    'source_id'         => $sourceId,
                    'source_reference'  => $reference,
                    // Carried, never scoped on. Books needs it back on the
                    // callback; Pay never filters by it.
                    'source_fy_id'      => $ctx->fyId > 0 ? $ctx->fyId : null,
                    'payer_id'          => $payerId,
                    // The payment-time snapshot. Part of the transaction record.
                    'payer_name'        => self::nullableString($input['payer_name'] ?? null),
                    'payer_email'       => self::nullableString($input['payer_email'] ?? null),
                    'payer_mobile'      => self::nullableString($input['payer_mobile'] ?? null),
                    'description'       => self::nullableString($input['description'] ?? null),
                    'amount_minor'      => $amount->minor,
                    'currency'          => $currency,
                    'allow_partial'     => $allowPartial,
                    'min_partial_minor' => $minPartial,
                    'status'            => (bool) ($input['as_draft'] ?? false) ? States::REQUEST_DRAFT : States::REQUEST_ACTIVE,
                    'allowed_methods'   => self::normaliseMethods($input['allowed_methods'] ?? []),
                    'expires_at'        => $expiresAt,
                    'return_url'        => self::safeUrl($input['return_url'] ?? null),
                    'callback_reference' => self::nullableString($input['callback_reference'] ?? null),
                    'notes'             => self::nullableString($input['notes'] ?? null),
                    'created_by'        => $auth->uuid,
                ], 'request_id');

                $row = self::require($ctx, $requestId);

                Outbox::queue($ctx, EventNames::PAYMENT_REQUEST_CREATED, [
                    'request' => $row,
                ], merchantOnly: true);

                return self::present($ctx, $row);
            });
        });

        return $result;
    }

    /**
     * Recompute a request's money and status from its own payments.
     *
     * CALLED INSIDE THE SAME TRANSACTION as whatever changed an attempt or a
     * refund, always. Called afterwards, there is a window in which the
     * attempts say one thing and the request says another, and a dashboard
     * reading in that window shows a merchant an invoice that is paid and
     * outstanding at once.
     *
     * @return array<string, mixed> the refreshed request row
     */
    public static function recalculate(Context $ctx, int $requestId): array
    {
        $row = self::require($ctx, $requestId);

        // Successful money in, from the attempts. CAPTURED counts as well as
        // SUCCESS: the money has been taken, whatever the provider still calls
        // the record.
        $totals = Db::first(
            'SELECT
                COALESCE(SUM(amount_minor)   FILTER (WHERE status = ANY(:settled)), 0) AS paid,
                COALESCE(SUM(refunded_minor) FILTER (WHERE status = ANY(:settled)), 0) AS refunded,
                MAX(paid_at)                 FILTER (WHERE status = ANY(:settled))     AS last_paid_at
             FROM ' . PaymentService::TABLE . '
             WHERE request_id = :id AND cmp_id = :cmp',
            [
                'settled' => '{' . implode(',', States::ATTEMPT_SETTLED_MONEY) . '}',
                'id'      => $requestId,
                'cmp'     => $ctx->cmpId,
            ],
        ) ?? ['paid' => 0, 'refunded' => 0, 'last_paid_at' => null];

        $currency = (string) $row['currency'];
        $asked = Money::minor((int) $row['amount_minor'], $currency);
        $paid = Money::minor((int) $totals['paid'], $currency);
        $refunded = Money::minor((int) $totals['refunded'], $currency);
        $net = $paid->minus($refunded);

        $status = self::deriveStatus((string) $row['status'], $asked, $net, $row['expires_at']);

        Db::update(self::TABLE, [
            'paid_minor'     => $paid->minor,
            'refunded_minor' => $refunded->minor,
            'status'         => $status,
            'paid_at'        => $status === States::REQUEST_PAID ? ($totals['last_paid_at'] ?? gmdate('Y-m-d H:i:s')) : null,
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ], ['request_id' => $requestId, 'cmp_id' => $ctx->cmpId]);

        return self::require($ctx, $requestId);
    }

    /**
     * What state does this money put the request in?
     *
     * The order of these checks is the whole of the logic, and each line is
     * there because the obvious ordering gets it wrong:
     *
     *   * CANCELLED and DRAFT are left alone. A cancelled request that somehow
     *     received money does not quietly become PAID — that is a
     *     reconciliation case, not a state change.
     *   * Fully covered wins over expiry. A customer who paid in the last
     *     minute before a link expired has paid, and a sweep running later must
     *     not take it away from them.
     *   * Expiry is checked before "partly paid", so a half-paid request that
     *     ran out of time reads EXPIRED — which is what it is, and what tells
     *     the merchant to chase the balance.
     */
    private static function deriveStatus(string $current, Money $asked, Money $net, mixed $expiresAt): string
    {
        if ($current === States::REQUEST_CANCELLED || $current === States::REQUEST_DRAFT) {
            return $current;
        }

        if ($net->atLeast($asked)) {
            return States::REQUEST_PAID;
        }

        $expired = $expiresAt !== null && strtotime((string) $expiresAt) !== false && strtotime((string) $expiresAt) < time();
        if ($expired) {
            return States::REQUEST_EXPIRED;
        }

        if ($net->isPositive()) {
            return States::REQUEST_PARTIALLY_PAID;
        }

        // Back to ACTIVE, which is how a fully-refunded request becomes
        // collectable again rather than sitting at PAID with nothing in it.
        return States::REQUEST_ACTIVE;
    }

    /**
     * What may still be collected against this request.
     *
     * Refunds are deducted, so a request that was paid and then refunded is
     * collectable again — which is the correct answer, and the one a merchant
     * expects after they refund a customer who then wants to pay properly.
     */
    public static function outstanding(array $row): Money
    {
        $currency = (string) $row['currency'];

        return Money::minor((int) $row['amount_minor'], $currency)
            ->minus(Money::minor((int) $row['paid_minor'], $currency))
            ->plus(Money::minor((int) $row['refunded_minor'], $currency))
            ->clampToZero();
    }

    /**
     * May this request take a payment of this amount right now?
     *
     * Every one of these refusals has a specific, non-generic message, because
     * the person reading it is a customer on a payment page and "this request
     * cannot be paid" tells them nothing about whether to call the merchant.
     *
     * @return array{ok:bool, error_code?:string, error_message?:string}
     */
    public static function assertPayable(array $row, ?Money $amount = null): array
    {
        $status = (string) $row['status'];

        if ($status === States::REQUEST_DRAFT) {
            return ['ok' => false, 'error_code' => 'request_draft', 'error_message' => 'This payment request has not been sent out yet.'];
        }
        if ($status === States::REQUEST_CANCELLED) {
            return ['ok' => false, 'error_code' => 'request_cancelled', 'error_message' => 'This payment request was cancelled.'];
        }
        if ($status === States::REQUEST_PAID) {
            return ['ok' => false, 'error_code' => 'request_already_paid', 'error_message' => 'This has already been paid in full.'];
        }

        // Expiry is checked against the clock, not against the stored status:
        // the sweep that marks requests expired runs periodically, and a link
        // that expired two minutes ago must already refuse to take money.
        if ($row['expires_at'] !== null) {
            $expiresAt = strtotime((string) $row['expires_at']);
            if ($expiresAt !== false && $expiresAt < time()) {
                return ['ok' => false, 'error_code' => 'request_expired', 'error_message' => 'This payment link has expired. Ask for a new one.'];
            }
        }
        if ($status === States::REQUEST_EXPIRED) {
            return ['ok' => false, 'error_code' => 'request_expired', 'error_message' => 'This payment link has expired. Ask for a new one.'];
        }

        if ($amount === null) {
            return ['ok' => true];
        }

        if (!$amount->isPositive()) {
            return ['ok' => false, 'error_code' => 'amount_invalid', 'error_message' => 'Enter an amount greater than zero.'];
        }

        $outstanding = self::outstanding($row);

        if ($amount->greaterThan($outstanding)) {
            return [
                'ok'            => false,
                'error_code'    => 'amount_exceeds_outstanding',
                'error_message' => 'Only ' . $outstanding->format() . ' is outstanding on this request.',
            ];
        }

        $allowPartial = self::truthy($row['allow_partial'] ?? false);

        if (!$allowPartial && !$amount->atLeast($outstanding)) {
            return [
                'ok'            => false,
                'error_code'    => 'partial_not_allowed',
                'error_message' => 'This request must be paid in full: ' . $outstanding->format() . '.',
            ];
        }

        if ($allowPartial && $row['min_partial_minor'] !== null) {
            $minimum = Money::minor((int) $row['min_partial_minor'], (string) $row['currency']);
            // The minimum does not apply to a payment that clears the balance:
            // a ₹200 closing payment on a request with a ₹500 minimum is the
            // customer finishing, not a part payment.
            if (!$amount->atLeast($minimum) && !$amount->atLeast($outstanding)) {
                return [
                    'ok'            => false,
                    'error_code'    => 'below_minimum_partial',
                    'error_message' => 'Part payments on this request must be at least ' . $minimum->format() . '.',
                ];
            }
        }

        return ['ok' => true];
    }

    public static function cancel(Context $ctx, Auth $auth, int $requestId, string $reason): array
    {
        return Db::transaction(static function () use ($ctx, $auth, $requestId, $reason): array {
            $row = self::require($ctx, $requestId);
            $status = (string) $row['status'];

            if ($status === States::REQUEST_CANCELLED) {
                return self::present($ctx, $row);
            }

            if (!States::allows('request', $status, States::REQUEST_CANCELLED)) {
                Http::conflict('A request that is ' . strtolower(States::label($status)) . ' cannot be cancelled.');
            }

            // Money already collected is NOT undone by cancelling. The request
            // stops taking more; refunding what came in is a separate, deliberate
            // act with its own approval. Silently reversing payments on cancel
            // would be the most expensive convenience in the product.
            if ((int) $row['paid_minor'] > (int) $row['refunded_minor']) {
                $collected = Money::minor((int) $row['paid_minor'] - (int) $row['refunded_minor'], (string) $row['currency']);
                if (!(bool) Http::param('acknowledge_collected', '0')) {
                    Http::conflict(
                        $collected->format() . ' has already been collected against this request. Cancelling stops further payment but does not refund it.',
                        ['collected' => $collected->toMajor(), 'confirm_with' => 'acknowledge_collected=1'],
                    );
                }
            }

            Db::update(self::TABLE, [
                'status'           => States::REQUEST_CANCELLED,
                'cancelled_at'     => gmdate('Y-m-d H:i:s'),
                'cancelled_reason' => $reason,
                'updated_at'       => gmdate('Y-m-d H:i:s'),
            ], ['request_id' => $requestId, 'cmp_id' => $ctx->cmpId]);

            // Every live link stops working in the same transaction. A
            // cancelled request whose WhatsApp link still opens a checkout is
            // the cancellation not having happened.
            Db::run(
                'UPDATE pay_payment_links SET status = :cancelled, updated_at = NOW()
                 WHERE request_id = :id AND status = :active',
                ['cancelled' => 'CANCELLED', 'id' => $requestId, 'active' => 'ACTIVE'],
            );

            $fresh = self::require($ctx, $requestId);

            Outbox::queue($ctx, EventNames::PAYMENT_REQUEST_CANCELLED, ['request' => $fresh, 'reason' => $reason]);

            return self::present($ctx, $fresh);
        });
    }

    /**
     * Mark requests that have run out of time.
     *
     * Run by the worker. Requests with money on them are included: a half-paid
     * link that expired is expired, and leaving it ACTIVE would let a customer
     * pay the balance weeks after the merchant stopped expecting it.
     *
     * @return int how many were expired
     */
    public static function expireOverdue(Context $ctx, int $limit = 500): int
    {
        $rows = Db::all(
            'SELECT request_id FROM ' . self::TABLE . '
             WHERE cmp_id = :cmp AND expires_at IS NOT NULL AND expires_at < NOW()
               AND status = ANY(:payable)
             ORDER BY expires_at ASC LIMIT :limit',
            [
                'cmp'     => $ctx->cmpId,
                'payable' => '{' . implode(',', States::REQUEST_PAYABLE) . '}',
                'limit'   => $limit,
            ],
        );

        $expired = 0;
        foreach ($rows as $row) {
            $requestId = (int) $row['request_id'];
            Db::transaction(static function () use ($ctx, $requestId): void {
                Db::update(self::TABLE, [
                    'status'     => States::REQUEST_EXPIRED,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ], ['request_id' => $requestId, 'cmp_id' => $ctx->cmpId]);

                Db::run(
                    'UPDATE pay_payment_links SET status = :expired, updated_at = NOW()
                     WHERE request_id = :id AND status = :active',
                    ['expired' => 'EXPIRED', 'id' => $requestId, 'active' => 'ACTIVE'],
                );
            });

            Outbox::queue($ctx, EventNames::PAYMENT_REQUEST_EXPIRED, ['request' => self::require($ctx, $requestId)]);
            $expired++;
        }

        return $expired;
    }

    /** Somebody opened the payment page. Counters only — no visitor record. */
    public static function recordView(int $requestId, ?int $linkId = null): void
    {
        try {
            Db::run(
                'UPDATE ' . self::TABLE . '
                 SET viewed_count = viewed_count + 1,
                     first_viewed_at = COALESCE(first_viewed_at, NOW())
                 WHERE request_id = :id',
                ['id' => $requestId],
            );

            if ($linkId !== null) {
                Db::run(
                    'UPDATE pay_payment_links
                     SET view_count = view_count + 1, last_viewed_at = NOW()
                     WHERE link_id = :id',
                    ['id' => $linkId],
                );
            }
        } catch (\Throwable $e) {
            // A funnel counter is never worth a 500 on a payment page.
            error_log('[payment-request] could not record a view: ' . $e->getMessage());
        }
    }

    public static function recordCheckoutStarted(int $requestId, ?int $linkId = null): void
    {
        try {
            Db::run(
                'UPDATE ' . self::TABLE . '
                 SET checkout_started_at = COALESCE(checkout_started_at, NOW())
                 WHERE request_id = :id',
                ['id' => $requestId],
            );

            if ($linkId !== null) {
                Db::run(
                    'UPDATE pay_payment_links SET checkout_count = checkout_count + 1 WHERE link_id = :id',
                    ['id' => $linkId],
                );
            }
        } catch (\Throwable $e) {
            error_log('[payment-request] could not record a checkout start: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $requestId): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE request_id = :id AND cmp_id = :cmp',
            ['id' => $requestId, 'cmp' => $ctx->cmpId],
        );
    }

    /** @return array<string, mixed>|null */
    public static function findByUuid(Context $ctx, string $uuid): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE request_uuid = :uuid AND cmp_id = :cmp',
            ['uuid' => $uuid, 'cmp' => $ctx->cmpId],
        );
    }

    /** @return array<string, mixed> */
    public static function require(Context $ctx, int $requestId): array
    {
        $row = self::find($ctx, $requestId);
        if ($row === null) {
            Http::notFound('That payment request could not be found.');
        }

        return $row;
    }

    /**
     * The shape that leaves this system.
     *
     * Internal ids are absent by design: `request_id` is a BIGSERIAL and
     * putting it in a response teaches integrations to use it, after which it
     * is a public identifier whether we meant it to be or not.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(Context $ctx, array $row, bool $withPayer = true): array
    {
        $currency = (string) $row['currency'];
        $amount = Money::minor((int) $row['amount_minor'], $currency);
        $paid = Money::minor((int) $row['paid_minor'], $currency);
        $refunded = Money::minor((int) $row['refunded_minor'], $currency);
        $outstanding = self::outstanding($row);

        $out = [
            'payment_request_id' => (string) $row['request_uuid'],
            'status'             => (string) $row['status'],
            'status_label'       => States::label((string) $row['status']),

            'amount'             => $amount->toMajor(),
            'amount_minor'       => $amount->minor,
            'paid'               => $paid->toMajor(),
            'refunded'           => $refunded->toMajor(),
            'outstanding'        => $outstanding->toMajor(),
            'currency'           => $currency,

            'source_app'         => (string) $row['source_app'],
            'source_type'        => $row['source_type'] === null ? null : (string) $row['source_type'],
            'source_id'          => $row['source_id'] === null ? null : (string) $row['source_id'],
            'reference'          => $row['source_reference'] === null ? null : (string) $row['source_reference'],
            'description'        => $row['description'] === null ? null : (string) $row['description'],

            'allow_partial_payment' => self::truthy($row['allow_partial'] ?? false),
            'min_partial_amount' => $row['min_partial_minor'] === null
                ? null : Money::minor((int) $row['min_partial_minor'], $currency)->toMajor(),
            'allowed_methods'    => Db::jsonColumn($row['allowed_methods'] ?? null),

            'expires_at'         => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            'created_at'         => (string) $row['created_at'],
            'paid_at'            => $row['paid_at'] === null ? null : (string) $row['paid_at'],
            'cancelled_reason'   => $row['cancelled_reason'] === null ? null : (string) $row['cancelled_reason'],
            'callback_reference' => $row['callback_reference'] === null ? null : (string) $row['callback_reference'],

            'views'              => (int) $row['viewed_count'],
            'checkout_started'   => $row['checkout_started_at'] !== null,
        ];

        if ($withPayer) {
            $out['payer'] = [
                // The snapshot on the request, which is what was actually sent
                // to, rather than the payer master's current details.
                'name'   => $row['payer_name'] === null ? null : (string) $row['payer_name'],
                'email'  => $row['payer_email'] === null ? null : (string) $row['payer_email'],
                'mobile' => $row['payer_mobile'] === null ? null : (string) $row['payer_mobile'],
            ];
        }

        return $out;
    }

    private static function resolveExpiry(mixed $given, int $defaultHours): ?string
    {
        if (is_string($given) && trim($given) !== '') {
            $parsed = strtotime($given);
            if ($parsed === false) {
                Http::validationFailed('That expiry date could not be understood.', ['field' => 'expires_at']);
            }
            if ($parsed <= time()) {
                Http::validationFailed('The expiry has to be in the future.', ['field' => 'expires_at']);
            }

            return gmdate('Y-m-d H:i:s', $parsed);
        }

        // Zero hours means "never expires", which some merchants genuinely want
        // for a standing donation or membership link.
        if ($defaultHours <= 0) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', time() + ($defaultHours * 3600));
    }

    /** @return list<string> */
    private static function normaliseMethods(mixed $methods): array
    {
        if (!is_array($methods)) {
            return [];
        }

        $allowed = ['UPI', 'CARD', 'NETBANKING', 'WALLET', 'EMI', 'PAYLATER'];
        $out = [];
        foreach ($methods as $method) {
            if (!is_string($method)) {
                continue;
            }
            $upper = strtoupper(trim($method));
            if (in_array($upper, $allowed, true)) {
                $out[$upper] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * A return URL we are willing to send a payer to.
     *
     * Only http(s), and no credentials in the URL. An unchecked return_url is
     * an open redirect on a page the payer already trusts, which is a phishing
     * primitive handed out with every payment link.
     */
    private static function safeUrl(mixed $url): ?string
    {
        $value = self::nullableString($url);
        if ($value === null) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            Http::validationFailed('The return URL must be a full https:// address.', ['field' => 'return_url']);
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            Http::validationFailed('The return URL must use http or https.', ['field' => 'return_url']);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            Http::validationFailed('The return URL must not contain a username or password.', ['field' => 'return_url']);
        }

        return substr($value, 0, 1000);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1' || $value === 'true';
    }
}
