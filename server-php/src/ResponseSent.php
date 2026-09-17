<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * The response Http would have sent, raised instead of exiting.
 *
 * Under a web SAPI, Http::json() writes the response and exits — which is the
 * right thing for a front controller and impossible to assert on. Under CLI it
 * throws this instead, so the test suite can exercise the real controllers and
 * services and check the status and payload they produced.
 *
 * The switch is the SAPI, not a flag somebody has to set: a test that forgot to
 * set the flag would pass by exiting silently.
 */
final class ResponseSent extends \RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $status,
        public readonly array $payload,
    ) {
        parent::__construct(
            (string) ($payload['error']['message'] ?? $payload['message'] ?? 'HTTP ' . $status),
            $status,
        );
    }
}
