<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Server-to-server calls to the AICOUNTLY auth portal.
 *
 * The portal owns every token. This API never mints, signs or stores one — it
 * relays the browser's session-bootstrap calls and asks the portal whether a
 * ses_key is still good.
 */
final class Portal
{
    /**
     * Always my.aicountly.com, in sandbox as well as production.
     *
     * sandbox.aicountly.com serves the *login redirect* only; seskey, refresh
     * and validatesession live on my.aicountly.com for every environment.
     * See docs/auth/AICOUNTLY_AUTH_WORKFLOW.md.
     */
    private const DEFAULT_AUTH_BASE = 'https://my.aicountly.com';

    private const CONNECT_TIMEOUT_SECONDS = 8;
    private const REQUEST_TIMEOUT_SECONDS = 15;

    public static function base(): string
    {
        return rtrim(Env::get('PORTAL_AUTH_BASE', self::DEFAULT_AUTH_BASE), '/');
    }

    /**
     * Forward one request to the portal and return its raw answer.
     *
     * @param array<int, string> $headers
     * @return array{status: int, body: string, contentType: string}
     */
    public static function forward(string $method, string $path, array $headers, string $body): array
    {
        $url = self::base() . '/api/' . ltrim($path, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 504, 'body' => '', 'contentType' => 'application/json'];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_HEADER => false,
        ];

        // Set the body even when it is empty: a bodiless CURLOPT_CUSTOMREQUEST
        // POST goes out with no Content-Length, and the seskey call has no body.
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $failed = $response === false;
        curl_close($ch);

        if ($failed || $status === 0) {
            return ['status' => 504, 'body' => '', 'contentType' => 'application/json'];
        }

        return [
            'status' => $status,
            'body' => (string) $response,
            'contentType' => $contentType !== '' ? $contentType : 'application/json',
        ];
    }

    /**
     * Validate a Bearer ses_key.
     *
     * The portal answers with `status: 1` and the caller's uuid when the key is
     * live. Anything else — a transport failure included — is treated as "not
     * authenticated", so a portal outage denies access rather than granting it.
     *
     * @return array<string, mixed>|null
     */
    public static function validateSesKey(string $sesKey): ?array
    {
        $result = self::forward('POST', 'validatesession', [
            'Authorization: Bearer ' . $sesKey,
            'Content-Type: application/json',
        ], '');

        if ($result['status'] !== 200 || $result['body'] === '') {
            return null;
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data) || (int) ($data['status'] ?? 0) !== 1) {
            return null;
        }

        return $data;
    }
}
