<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Sources;

use Aicountly\Api\Clients\SourceClient;
use Aicountly\Api\Context;
use Aicountly\Api\Env;

/**
 * The parts every source adapter shares.
 *
 * Chiefly the CALLBACK SHAPE, which is deliberately identical for all of them.
 * An app receiving a payment event from Pay sees the same fields whether it is
 * Books, a POS terminal or a merchant's own website, and the integration guide
 * documents one contract rather than five.
 *
 * Adapters override shapeCallback() only to ADD, never to rename or remove: an
 * integration written against the common fields must keep working when a new
 * app is added to the fleet.
 */
abstract class AbstractSourceAdapter implements SourceAppAdapterInterface
{
    public function isAvailable(): bool
    {
        // A product is available when this deployment knows where it lives.
        // Derived from our own hostname by default, which is why most
        // deployments configure nothing — but an explicit "off" is honoured so
        // a fleet that has not deployed Sales yet does not queue callbacks for
        // it forever.
        $flag = strtolower(Env::get(strtoupper($this->app()) . '_ENABLED'));
        if ($flag !== '' && in_array($flag, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return true;
    }

    public function callbackPath(string $event): ?string
    {
        // One endpoint per app, and the event name is in the body. A path per
        // event would mean every app had to add a route before it could receive
        // a new event type, and the events are additive by design.
        return 'v1/pay/events';
    }

    /**
     * The common callback body.
     *
     * `event_id` is the receiver's idempotency key and is stable across every
     * retry — that is the contract that lets Books deduplicate a redelivered
     * callback rather than filing two receipts.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function shapeCallback(array $event): array
    {
        return [
            'event'    => $event['event'] ?? null,
            'event_id' => $event['event_uuid'] ?? null,
            'sent_at'  => gmdate('c'),

            'payment_request_id' => $event['request_uuid'] ?? null,
            'payment_id'         => $event['attempt_uuid'] ?? null,
            'refund_id'          => $event['refund_uuid'] ?? null,

            'source_app'       => $event['source_app'] ?? null,
            'source_type'      => $event['source_type'] ?? null,
            'source_id'        => $event['source_id'] ?? null,
            'source_reference' => $event['source_reference'] ?? null,

            'cmp_id' => $event['cmp_id'] ?? null,
            'bo_id'  => $event['bo_id'] ?? null,

            // Both the amount of THIS payment and where the request now stands.
            // A receiver that only reads `amount` would file a part payment as
            // a full one; one that only reads the totals could not tell two
            // part payments apart.
            'amount'          => $event['amount'] ?? null,
            'amount_minor'    => $event['amount_minor'] ?? null,
            'currency'        => $event['currency'] ?? 'INR',
            'request_total'   => $event['request_total'] ?? null,
            'request_paid'    => $event['request_paid'] ?? null,
            'request_outstanding' => $event['request_outstanding'] ?? null,
            'request_status'  => $event['request_status'] ?? null,

            'provider_mode'       => $event['provider_mode'] ?? null,
            'provider'            => $event['provider'] ?? null,
            'provider_payment_id' => $event['provider_payment_id'] ?? null,
            'payment_method'      => $event['payment_method'] ?? null,

            'paid_at'  => $event['paid_at'] ?? null,
            'payer'    => $event['payer'] ?? null,

            // Whatever the source app gave us when it raised the request. Pay
            // never parses it; it is theirs.
            'callback_reference' => $event['callback_reference'] ?? null,
        ];
    }

    /**
     * Read a document through the generic source client.
     *
     * @param array<string, mixed> $options
     */
    protected function client(array $options): SourceClient
    {
        $client = new SourceClient($this->app(), $this->productionBase(), $this->sandboxBase(), $this->baseEnvKey());

        $sesKey = (string) ($options['ses_key'] ?? '');
        if ($sesKey !== '') {
            return $client->withSession($sesKey);
        }

        return $client->withService((string) ($options['actor_uuid'] ?? ''), $this->serviceKeyEnv());
    }

    abstract protected function productionBase(): string;

    abstract protected function sandboxBase(): string;

    abstract protected function baseEnvKey(): string;

    protected function serviceKeyEnv(): string
    {
        return strtoupper($this->app()) . '_SERVICE_KEY';
    }

    /**
     * A refusal in the shape the payment engine expects.
     *
     * `retryable` decides whether the outbox will try again. "Books was
     * unreachable" should; "Books says that invoice does not exist" should not,
     * because the sixth attempt will get the same answer and the merchant needs
     * to be told rather than waited on.
     */
    protected function failure(string $code, string $message): array
    {
        return ['ok' => false, 'error_code' => $code, 'error_message' => $message];
    }

    /** Rupees (or the API's own decimal) into minor units, tolerating either shape. */
    protected function toMinor(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value) || is_string($value)) {
            return (int) round(((float) $value) * 100);
        }

        return null;
    }

    /** The first of several keys an API might have used. Fleet payloads have drifted over the years. */
    protected function pick(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '' && $row[$key] !== null) {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * The row inside whatever envelope the product used.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function unwrap(?array $body): array
    {
        if ($body === null) {
            return [];
        }

        foreach ([$body['data'] ?? null, $body] as $candidate) {
            if (is_array($candidate) && !array_is_list($candidate)) {
                return $candidate;
            }
            if (is_array($candidate) && array_is_list($candidate) && isset($candidate[0]) && is_array($candidate[0])) {
                return $candidate[0];
            }
        }

        return [];
    }
}
