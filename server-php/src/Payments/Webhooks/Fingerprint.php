<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Webhooks;

/**
 * The identity of an inbound event, for telling a redelivery from a new fact.
 *
 * GETTING THIS WRONG GOES BOTH WAYS, and both ways are expensive:
 *
 *   TOO NARROW (hashing the whole raw body): a provider that adds a timestamp,
 *   a retry counter or a reordered key to a redelivery produces a different
 *   hash, the duplicate is not recognised, and the payment is captured twice.
 *
 *   TOO BROAD (hashing only the payment id): two genuinely different events
 *   about the same payment — authorized, then captured — collapse into one, and
 *   the second is dropped. The payment stays AUTHORIZED forever and the money
 *   is never taken.
 *
 * So the fingerprint is over the identity of the EVENT: the provider, its own
 * event id where it gives one, the event type, and the subject it concerns.
 * That is stable across redelivery and different between real events.
 *
 * The raw body is the last resort, for providers that send no event id at all.
 */
final class Fingerprint
{
    /** @param array<string, mixed> $normalized */
    public static function of(string $providerCode, array $normalized, string $rawPayload): string
    {
        $providerEventId = self::str($normalized['provider_event_id'] ?? null);

        // A provider-issued event id is the strongest signal there is: the
        // provider itself is telling us "this is the same delivery".
        if ($providerEventId !== null) {
            return hash('sha256', implode('|', [
                $providerCode,
                'event',
                $providerEventId,
                self::str($normalized['provider_event_type'] ?? null) ?? '',
            ]));
        }

        // No event id. Fall back to the subject plus the state being reported,
        // which distinguishes authorized-then-captured while still collapsing a
        // redelivery of either.
        $subject = self::str($normalized['provider_payment_id'] ?? null)
            ?? self::str($normalized['provider_refund_id'] ?? null)
            ?? self::str($normalized['provider_settlement_id'] ?? null)
            ?? self::str($normalized['provider_dispute_id'] ?? null)
            ?? self::str($normalized['provider_order_id'] ?? null);

        if ($subject !== null) {
            return hash('sha256', implode('|', [
                $providerCode,
                'subject',
                $subject,
                self::str($normalized['event'] ?? null) ?? '',
                self::str($normalized['status'] ?? null) ?? '',
                // The amount, so a second ₹500 payment on the same order is not
                // mistaken for a redelivery of the first.
                (string) ($normalized['amount_minor'] ?? ''),
            ]));
        }

        // Nothing identifiable. Hash the body, which at least deduplicates an
        // exact redelivery, and let the processing step decide it is unusable.
        return hash('sha256', $providerCode . '|raw|' . $rawPayload);
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
