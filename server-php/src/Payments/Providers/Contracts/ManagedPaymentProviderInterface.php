<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers\Contracts;

/**
 * A provider that can also ONBOARD merchants, for Aicountly Managed Payments.
 *
 * THE BOUNDARY THIS INTERFACE DRAWS. In Managed mode a merchant signs up inside
 * Aicountly and never visits the provider's site. That makes it tempting to
 * present Aicountly as the party that approves them. It is not, and this
 * interface is shaped so the code cannot drift into pretending otherwise:
 *
 *   * there is no `approveMerchant()`. Aicountly cannot approve anybody;
 *   * `submitKyc()` SENDS documents and returns the provider's verdict;
 *   * `fetchOnboardingStatus()` READS a state we did not decide.
 *
 * Pay collects what the partner asks for, forwards it, and shows what comes
 * back. A KYC status in this product only ever changes because the partner
 * said so, and the integration test asserts that no code path sets VERIFIED
 * on its own.
 *
 * DOCUMENTS ARE NOT STORED HERE. uploadKycDocument() streams to the provider
 * and Pay keeps the provider's document id, its kind and the time — never the
 * file. A PAN scan sitting in a SaaS database is a liability with no upside.
 */
interface ManagedPaymentProviderInterface extends PaymentProviderInterface
{
    /**
     * Create the merchant account at the partner.
     *
     * @param array<string, mixed> $payload
     * @return array{ok:bool, provider_merchant_id?:string, status?:string,
     *     error_code?:string, error_message?:string}
     */
    public function createMerchant(array $payload): array;

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool, status?:string, error_code?:string, error_message?:string}
     */
    public function updateMerchant(string $merchantId, array $payload): array;

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool, status?:string, required_actions?:list<array<string,mixed>>,
     *     error_code?:string, error_message?:string}
     */
    public function submitKyc(string $merchantId, array $payload): array;

    /**
     * @param array{kind:string, filename:string, mime:string, contents:string} $document
     * @return array{ok:bool, provider_document_id?:string, status?:string,
     *     error_code?:string, error_message?:string}
     */
    public function uploadKycDocument(string $merchantId, array $document): array;

    /**
     * The partner's current verdict, in the partner's own words.
     *
     * `status` is mapped onto Pay's vocabulary (NOT_STARTED, IN_PROGRESS,
     * ACTION_REQUIRED, UNDER_REVIEW, VERIFIED, REJECTED, SUSPENDED) because a
     * merchant should not have to learn four providers' state names. `detail`
     * and `required_actions` stay verbatim, because those are what the merchant
     * has to act on and a paraphrase could send them to fix the wrong thing.
     *
     * @return array{ok:bool, status?:string, detail?:string,
     *     required_actions?:list<array<string,mixed>>,
     *     error_code?:string, error_message?:string}
     */
    public function fetchOnboardingStatus(string $merchantId): array;

    /**
     * The bank account the partner will settle into, masked.
     *
     * @return array{ok:bool, last4?:string, ifsc?:string, bank_name?:string,
     *     verified?:bool, error_code?:string, error_message?:string}
     */
    public function fetchSettlementAccount(string $merchantId): array;
}
