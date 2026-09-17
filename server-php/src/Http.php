<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Request parsing and JSON responses.
 *
 * The envelopes match the rest of the AICOUNTLY fleet so a client written
 * against Books or Inventory reads this API without a second set of rules:
 *
 *   single  {"data": {...}}
 *   list    {"data": [...], "meta": {"total", "limit", "offset"}}
 *   error   {"error": {"code", "message", "details"}, "message"}
 */
final class Http
{
    /** Largest page a list endpoint will serve, however large a client asks for. */
    public const MAX_LIMIT = 200;

    private static ?array $body = null;

    /**
     * Send the response and stop.
     *
     * Under CLI it throws ResponseSent instead of exiting, so the test suite can
     * assert on what a real controller produced. The web path is unchanged.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(int $status, array $payload): never
    {
        if (PHP_SAPI === 'cli') {
            throw new ResponseSent($status, $payload);
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function data(array $data, int $status = 200): never
    {
        self::json($status, ['data' => $data]);
    }

    /**
     * @param list<mixed>          $rows
     * @param array<string, mixed> $extra merged into meta (counts, totals, filters echoed back)
     */
    public static function list(array $rows, int $total, int $limit, int $offset, array $extra = []): never
    {
        self::json(200, [
            'data' => $rows,
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset] + $extra,
        ]);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $code, string $message, array $details = []): never
    {
        self::json($status, [
            'error'   => ['code' => $code, 'message' => $message, 'details' => $details],
            'message' => $message,
        ]);
    }

    /** @param array<string, mixed> $details */
    public static function validationFailed(string $message, array $details = []): never
    {
        self::error(422, 'validation_failed', $message, $details);
    }

    public static function notFound(string $message = 'Not found.'): never
    {
        self::error(404, 'not_found', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): never
    {
        self::error(403, 'forbidden', $message);
    }

    public static function unauthorized(string $message = 'Sign in again to continue.'): never
    {
        self::error(401, 'unauthorized', $message);
    }

    public static function conflict(string $message, array $details = []): never
    {
        self::error(409, 'conflict', $message, $details);
    }

    /**
     * The decoded JSON request body, or an empty array.
     *
     * Parsed once: reading php://input twice returns nothing the second time on
     * some SAPIs, which turns a perfectly good request into a validation error.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return self::$body = [];
        }
        $decoded = json_decode($raw, true);

        return self::$body = is_array($decoded) ? $decoded : [];
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function header(string $name): string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        $value = $_SERVER[$key] ?? $_SERVER['REDIRECT_' . $key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** Query parameter, falling back to the JSON body so POST callers need not duplicate context in the URL. */
    public static function param(string $name, ?string $default = null): ?string
    {
        if (isset($_GET[$name]) && is_scalar($_GET[$name])) {
            return trim((string) $_GET[$name]);
        }
        $body = self::body();
        if (isset($body[$name]) && is_scalar($body[$name])) {
            return trim((string) $body[$name]);
        }

        return $default;
    }

    public static function intParam(string $name, ?int $default = null): ?int
    {
        $raw = self::param($name);

        return ($raw === null || $raw === '') ? $default : (int) $raw;
    }

    /**
     * limit / offset / sort / order / q, clamped.
     *
     * `limit` is bounded at MAX_LIMIT rather than honoured: an unbounded page is
     * a denial-of-service one query string long.
     *
     * @param list<string> $sortable columns a caller may sort by; anything else falls back to the first
     * @return array{limit:int, offset:int, sort:string, order:string, q:string}
     */
    public static function listParams(array $sortable, string $defaultSort = '', string $defaultOrder = 'desc'): array
    {
        $limit = (int) (self::param('limit') ?? 50);
        $limit = max(1, min(self::MAX_LIMIT, $limit === 0 ? 50 : $limit));

        $offset = max(0, (int) (self::param('offset') ?? 0));
        $page = (int) (self::param('page') ?? 0);
        if ($page > 1 && $offset === 0) {
            $offset = ($page - 1) * $limit;
        }

        $sort = (string) (self::param('sort') ?? '');
        if (!in_array($sort, $sortable, true)) {
            $sort = $defaultSort !== '' ? $defaultSort : ($sortable[0] ?? 'id');
        }

        $order = strtolower((string) (self::param('order') ?? $defaultOrder));
        $order = $order === 'asc' ? 'ASC' : 'DESC';

        return [
            'limit'  => $limit,
            'offset' => $offset,
            'sort'   => $sort,
            'order'  => $order,
            'q'      => trim((string) (self::param('q') ?? '')),
        ];
    }
}
