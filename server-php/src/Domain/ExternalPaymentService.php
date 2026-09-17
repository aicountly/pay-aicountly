<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Idempotency;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Events\Outbox;
use Aicountly\Api\Permissions;

/**
 * Money that arrived outside Pay: cash, a cheque, a bank transfer, a manual UPI.
 *
 * WHY THIS IS NOT A BUTTON CALLED "MARK AS COLLECTED".
 *
 * A one-click "mark paid" is the most requested feature in every collections
 * product and the most dangerous. It closes an invoice with no evidence, no
 * traceable reference and, usually, no record of who pressed it. When the money
 * turns out not to have arrived — the cheque bounced, the clerk mis-keyed, the
 * customer paid a different invoice — there is nothing to reconstruct.
 *
 * So every row here NEEDS a method, a date, a reference and a person, and the
 * validation below refuses without them. A UTR, a cheque number or a terminal
 * reference is not bureaucracy: it is the only thing tying this claim to
 * something a bank statement can confirm.
 *
 * It creates a real payment attempt with `provider_mode = EXTERNAL`, so the
 * request's totals, the payments list and the source callback all work exactly
 * as they do for a gateway payment. The difference is that it can never settle
 * — no provider will ever confirm it — which is why its settlement status is
 * NOT_APPLICABLE rather than PENDING, and why it does not show up in the
 * "unsettled payments" sweep as an exception forever.
 *
 * A CHEQUE THAT BOUNCES IS REVERSED, NEVER DELETED. The original record and the
 * reversal both stand.
 */
final class ExternalPaymentService
{
    public const TABLE = 'pay_external_payments';

    /** @var array<string, string> */
    public const METHODS = [
        'CASH'          => 'Cash',
        'BANK_TRANSFER' => 'Bank transfer (NEFT / RTGS / IMPS)',
        'CHEQUE'        => 'Cheque',
        'UPI_MANUAL'    => 'UPI, paid directly',
        'CARD_MACHINE'  => 'Card machine (outside Pay)',
        'OTHER'         => 'Other',
    ];

    /**
     * What each method's reference has to be, in the words the form should use.
     *
     * Method-specific because "reference" means something different every time,
     * and a generic prompt gets a generic answer — which is how a table fills
     * up with rows whose reference is the word "cash".
     */
    private const REFERENCE_PROMPTS = [
        'CASH'          => 'a receipt number or counter reference',
        'BANK_TRANSFER' => 'the UTR from the bank',
        'CHEQUE'        => 'the cheque number',
        'UPI_MANUAL'    => 'the UPI transaction id',
        'CARD_MACHINE'  => 'the terminal or batch reference',
        'OTHER'         => 'a reference somebody could trace this by',
    ];

    /**
     * Record it.
     *
     * @param array{request_id?:?int, source_app?:string, source_type?:string, source_id?:string,
     *     amount:mixed, currency?:string, method:string, external_reference:string,
     *     received_at:string, note?:string, attachment_ref?:string,
     *     payer_name?:string, payer_email?:string, payer_mobile?:string} $input
     * @return array<string, mixed>
     */
    public static function record(Context $ctx, Auth $auth, array $input): array
    {
        Permissions::assert($ctx, $auth, 'external_payment.record');

        $method = strtoupper(trim((string) ($input['method'] ?? '')));
        if (!isset(self::METHODS[$method])) {
            Http::validationFailed(
                'Choose how the money was received.',
                ['field' => 'method', 'options' => array_keys(self::METHODS)],
            );
        }

        $reference = trim((string) ($input['external_reference'] ?? ''));
        if ($reference === '') {
            Http::validationFailed(
                'Enter ' . self::REFERENCE_PROMPTS[$method] . '. A payment nobody can trace is one nobody can confirm later.',
                ['field' => 'external_reference'],
            );
        }

        $receivedAt = trim((string) ($input['received_at'] ?? ''));
        $receivedTs = $receivedAt === '' ? false : strtotime($receivedAt);
        if ($receivedTs === false) {
            Http::validationFailed('Enter the date the money was received.', ['field' => 'received_at']);
        }
        if ($receivedTs > strtotime('+1 day')) {
            // Tomorrow is allowed for a timezone edge; next week is a typo.
            Http::validationFailed('The date received cannot be in the future.', ['field' => 'received_at']);
        }

        $request = null;
        if (isset($input['request_id']) && $input['request_id'] !== null && $input['request_id'] !== '') {
            $request = PaymentRequestService::findByUuid($ctx, (string) $input['request_id']);
            if ($request === null) {
                Http::notFound('That payment request could not be found.');
            }
        }

        $currency = $request !== null
            ? (string) $request['currency']
            : Money::normaliseCurrency((string) ($input['currency'] ?? Money::DEFAULT_CURRENCY));

        $amount = Money::fromMajor($input['amount'] ?? null, $currency);
        if (!$amount->isPositive()) {
            Http::validationFailed('Enter an amount greater than zero.', ['field' => 'amount']);
        }

        // Against a request, the same rules apply as to a gateway payment. A
        // clerk must not be able to record ₹2,00,000 against a ₹50,000 invoice
        // simply because they typed it rather than a customer paying it.
        if ($request !== null) {
            $payable = PaymentRequestService::assertPayable($request, $amount);
            if (!$payable['ok']) {
                Http::conflict($payable['error_message'], ['code' => $payable['error_code']]);
            }
        }

        $key = Idempotency::fromRequestOr([$method, $reference, $amount->minor, gmdate('Y-m-d', $receivedTs)]);

        [$result] = Idempotency::once($ctx, 'external_payment.record', $key, static function () use (
            $ctx, $auth, $input, $request, $amount, $currency, $method, $reference, $receivedTs
        ): array {
            return Db::transaction(static function () use (
                $ctx, $auth, $input, $request, $amount, $currency, $method, $reference, $receivedTs
            ): array {
                $payerId = $request !== null && $request['payer_id'] !== null
                    ? (int) $request['payer_id']
                    : PayerService::resolve($ctx, [
                        'source_app'   => $input['source_app'] ?? null,
                        'customer_ref' => $input['customer_ref'] ?? null,
                        'name'         => $input['payer_name'] ?? null,
                        'email'        => $input['payer_email'] ?? null,
                        'mobile'       => $input['payer_mobile'] ?? null,
                    ]);

                // A real attempt row, so this payment behaves like every other
                // one on every screen and in every total.
                $attemptId = (int) Db::insert(PaymentService::TABLE, [
                    'attempt_uuid'   => Ids::mint(Ids::ATTEMPT),
                    'cmp_id'         => $ctx->cmpId,
                    'bo_id'          => $request === null ? $ctx->boId : (int) $request['bo_id'],
                    'request_id'     => $request === null ? null : (int) $request['request_id'],
                    'payer_id'       => $payerId,
                    'amount_minor'   => $amount->minor,
                    'currency'       => $currency,
                    'status'         => States::ATTEMPT_SUCCESS,
                    'payment_method' => 'OTHER',
                    'method_network' => $method,
                    'provider_mode'  => 'EXTERNAL',
                    'provider_code'  => 'EXTERNAL',
                    'provider_payment_id' => null,
                    'channel'        => 'EXTERNAL',
                    // Nothing will ever settle this: no provider took it, so no
                    // provider will ever pay it out. Marking it PENDING would
                    // leave it in the unsettled sweep forever.
                    'settlement_status' => 'NOT_APPLICABLE',
                    'source_sync_status' => $request !== null && (string) $request['source_app'] !== 'PAY' ? 'PENDING'
                        : (isset($input['source_app']) && strtoupper((string) $input['source_app']) !== 'PAY' ? 'PENDING' : 'NOT_APPLICABLE'),
                    'paid_at'        => gmdate('Y-m-d H:i:s', $receivedTs),
                    'captured_at'    => gmdate('Y-m-d H:i:s', $receivedTs),
                ], 'attempt_id');

                $externalId = (int) Db::insert(self::TABLE, [
                    'external_uuid'      => Ids::mint(Ids::EXTERNAL),
                    'cmp_id'             => $ctx->cmpId,
                    'bo_id'              => $request === null ? $ctx->boId : (int) $request['bo_id'],
                    'request_id'         => $request === null ? null : (int) $request['request_id'],
                    'attempt_id'         => $attemptId,
                    'payer_id'           => $payerId,
                    'source_app'         => $request !== null ? (string) $request['source_app'] : (isset($input['source_app']) ? strtoupper((string) $input['source_app']) : null),
                    'source_type'        => $request !== null ? $request['source_type'] : ($input['source_type'] ?? null),
                    'source_id'          => $request !== null ? $request['source_id'] : ($input['source_id'] ?? null),
                    'source_reference'   => $request !== null ? $request['source_reference'] : ($input['source_reference'] ?? null),
                    'amount_minor'       => $amount->minor,
                    'currency'           => $currency,
                    'method'             => $method,
                    'external_reference' => substr($reference, 0, 200),
                    'received_at'        => gmdate('Y-m-d', $receivedTs),
                    'note'               => isset($input['note']) ? substr((string) $input['note'], 0, 1000) : null,
                    'attachment_ref'     => isset($input['attachment_ref']) ? substr((string) $input['attachment_ref'], 0, 500) : null,
                    'recorded_by'        => $auth->uuid,
                ], 'external_id');

                if ($request !== null) {
                    PaymentRequestService::recalculate($ctx, (int) $request['request_id']);
                }

                if ($payerId !== null) {
                    PayerService::recordPayment($ctx, $payerId, $amount->minor, 'OTHER', gmdate('Y-m-d H:i:s', $receivedTs));
                }

                $row = self::require($ctx, $externalId);
                $attempt = PaymentService::require($ctx, $attemptId);
                $freshRequest = $request === null ? null : PaymentRequestService::find($ctx, (int) $request['request_id']);

                Outbox::queue($ctx, EventNames::EXTERNAL_PAYMENT_RECORDED, [
                    'request' => $freshRequest,
                    'attempt' => $attempt,
                ]);

                Audit::record($ctx, $auth, Audit::EXTERNAL_PAYMENT, 'external_payment', $externalId, null, [
                    'amount'    => $amount->toMajor(),
                    'currency'  => $currency,
                    'method'    => $method,
                    'reference' => $reference,
                    'received'  => gmdate('Y-m-d', $receivedTs),
                ], (string) ($input['note'] ?? ''));

                return self::present($row);
            });
        });

        return $result;
    }

    /**
     * Reverse one — a bounced cheque, a transfer that never cleared, a mis-entry.
     *
     * The original row stays. What changes is its status and the attempt behind
     * it, which stops counting towards the request. Deleting it would remove the
     * evidence that somebody recorded a payment that did not exist, which is
     * exactly the evidence worth keeping.
     */
    public static function reverse(Context $ctx, Auth $auth, int $externalId, string $reason): array
    {
        Permissions::assert($ctx, $auth, 'external_payment.record');

        if (trim($reason) === '') {
            Http::validationFailed('Say why this payment is being reversed.', ['field' => 'reason']);
        }

        return Db::transaction(static function () use ($ctx, $auth, $externalId, $reason): array {
            $row = self::require($ctx, $externalId);

            if ((string) $row['status'] === 'REVERSED') {
                return self::present($row);
            }

            Db::update(self::TABLE, [
                'status'          => 'REVERSED',
                'reversed_by'     => $auth->uuid,
                'reversed_at'     => gmdate('Y-m-d H:i:s'),
                'reversal_reason' => substr($reason, 0, 1000),
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ], ['external_id' => $externalId, 'cmp_id' => $ctx->cmpId]);

            if ($row['attempt_id'] !== null) {
                Db::update(PaymentService::TABLE, [
                    'status'         => States::ATTEMPT_CANCELLED,
                    'failure_code'   => 'external_reversed',
                    'failure_reason' => substr($reason, 0, 500),
                    'updated_at'     => gmdate('Y-m-d H:i:s'),
                ], ['attempt_id' => (int) $row['attempt_id'], 'cmp_id' => $ctx->cmpId]);
            }

            $request = null;
            if ($row['request_id'] !== null) {
                // The request's totals fall back automatically, because
                // recalculate() sums only SUCCESS and CAPTURED attempts.
                $request = PaymentRequestService::recalculate($ctx, (int) $row['request_id']);
            }

            $fresh = self::require($ctx, $externalId);

            Outbox::queue($ctx, EventNames::EXTERNAL_PAYMENT_REVERSED, [
                'request' => $request,
                'attempt' => $row['attempt_id'] === null ? null : PaymentService::find($ctx, (int) $row['attempt_id']),
                'reason'  => $reason,
            ]);

            Audit::record($ctx, $auth, 'external_payment.reversed', 'external_payment', $externalId, [
                'status' => 'ACTIVE',
            ], ['status' => 'REVERSED'], $reason);

            return self::present($fresh);
        });
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $externalId): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE external_id = :id AND cmp_id = :cmp', ['id' => $externalId, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed>|null */
    public static function findByUuid(Context $ctx, string $uuid): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE external_uuid = :uuid AND cmp_id = :cmp', ['uuid' => $uuid, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed> */
    public static function require(Context $ctx, int $externalId): array
    {
        $row = self::find($ctx, $externalId);
        if ($row === null) {
            Http::notFound('That external payment could not be found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function present(array $row): array
    {
        $currency = (string) $row['currency'];

        return [
            'external_payment_id' => (string) $row['external_uuid'],
            'status'      => (string) $row['status'],
            'amount'      => Money::minor((int) $row['amount_minor'], $currency)->toMajor(),
            'currency'    => $currency,
            'method'      => (string) $row['method'],
            'method_label' => self::METHODS[(string) $row['method']] ?? (string) $row['method'],
            'reference'   => (string) $row['external_reference'],
            'received_at' => (string) $row['received_at'],
            'note'        => $row['note'] === null ? null : (string) $row['note'],
            'recorded_by' => (string) $row['recorded_by'],
            'recorded_at' => (string) $row['created_at'],
            'reversed_at' => $row['reversed_at'] === null ? null : (string) $row['reversed_at'],
            'reversal_reason' => $row['reversal_reason'] === null ? null : (string) $row['reversal_reason'],
            'source_reference' => $row['source_reference'] === null ? null : (string) $row['source_reference'],
            'source_sync_status' => null,
        ];
    }
}
