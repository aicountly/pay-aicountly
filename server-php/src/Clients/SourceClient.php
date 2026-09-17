<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;

/**
 * One HTTP client, pointed at whichever sibling product an adapter names.
 *
 * WHY ONE CLASS AND NOT FIVE. Billing has a BooksClient, an InventoryClient and
 * a ContactsClient because it calls specific, well-known endpoints on each and
 * the method names document the contract. Pay's relationship with its siblings
 * is the opposite shape: it asks all of them the same two questions — what is
 * this document, who is this customer — and posts them all the same event.
 *
 * So the per-product knowledge lives in the ADAPTER, where it is one small
 * class per product, and the transport is shared. Adding an Appointments
 * product later means writing an adapter, not a client.
 *
 * The base URL is derived from our own hostname exactly as the rest of the
 * fleet does it, so sandbox talks to sandbox with nothing to configure.
 */
final class SourceClient extends ApiClient
{
    private string $authorization = '';
    private string $serviceKey = '';
    private string $actorUuid = '';

    public function __construct(
        private readonly string $app,
        private readonly string $productionBase,
        private readonly string $sandboxBase,
        private readonly string $baseEnvKey,
    ) {
    }

    public function service(): string
    {
        return strtolower($this->app);
    }

    protected function productionBase(): string
    {
        return $this->productionBase;
    }

    protected function sandboxBase(): string
    {
        return $this->sandboxBase;
    }

    protected function baseEnvKey(): string
    {
        return $this->baseEnvKey;
    }

    /** Call as the signed-in human, so THEIR permissions apply over there. */
    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;
        $this->serviceKey = '';

        return $this;
    }

    /** Call as Pay itself — for a webhook or a worker, where no human is present. */
    public function withService(string $actorUuid, string $serviceKeyEnv): self
    {
        $this->serviceKey = Env::get($serviceKeyEnv);
        $this->actorUuid = $actorUuid;
        $this->authorization = '';

        return $this;
    }

    /** Whether this client can authenticate at all. */
    public function hasCredentials(): bool
    {
        return $this->authorization !== '' || $this->serviceKey !== '';
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        if ($this->serviceKey !== '') {
            return ['X-Service-Key' => $this->serviceKey, 'X-Actor-Uuid' => $this->actorUuid];
        }

        return ['Authorization' => $this->authorization];
    }

    /** @param array<string, mixed>|null $body */
    public function call(string $method, string $path, ?array $body = null, bool $required = false, array $extraHeaders = []): array
    {
        return $this->request($method, $path, $body, $this->authHeaders() + $extraHeaders, $required);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->call('GET', $path . self::query($query));
    }

    /**
     * Deliver an event.
     *
     * `required` is true so this gets the 20-second budget: the outbox worker
     * is not a screen anybody is waiting on, and giving up after six seconds
     * would retry events that were actually being processed.
     *
     * @param array<string, mixed> $payload
     */
    public function deliver(string $path, array $payload, string $signature, string $eventId): array
    {
        return $this->call('POST', $path, $payload, true, [
            'X-Pay-Signature' => $signature,
            'X-Pay-Event-Id'  => $eventId,
            // The receiver's own idempotency handle, so a redelivery is a
            // no-op over there rather than a second receipt.
            'Idempotency-Key' => $eventId,
        ]);
    }

    /** Exposed so an adapter can build a link to a document without a second base-URL rule. */
    public function documentUrl(string $path): string
    {
        return $this->base() . '/' . ltrim($path, '/');
    }
}
