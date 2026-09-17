<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Providers\ProviderRegistry;
use Aicountly\Api\Payments\Routing\RoutingEngine;
use Aicountly\Api\Permissions;

/**
 * Smart routing rules — hybrid mode's control surface.
 *
 * EVERY CHANGE IS AUDITED, because routing decides which provider a merchant's
 * money travels through, and so which settlement account it lands in and at
 * what commercial rate. A rule edited without a trail is a question nobody can
 * answer later.
 *
 * A rule naming a provider that cannot serve it is REFUSED at save time rather
 * than silently ignored at payment time. Saving "route UPI to Stripe" and
 * finding out at a customer's checkout that Stripe does not do UPI is the worst
 * possible moment to learn it.
 */
final class RoutingController extends Controller
{
    public static function index(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'providers.view');

        $rows = Db::all(
            'SELECT r.*, p.display_name AS primary_name, p.connection_uuid AS primary_uuid,
                    f.display_name AS fallback_name, f.connection_uuid AS fallback_uuid
             FROM ' . RoutingEngine::RULES . ' r
             LEFT JOIN pay_provider_connections p ON p.connection_id = r.primary_connection_id
             LEFT JOIN pay_provider_connections f ON f.connection_id = r.fallback_connection_id
             WHERE r.cmp_id = :cmp ORDER BY r.priority ASC, r.rule_id ASC',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'rules' => array_map(static fn (array $row) => self::present($row), $rows),
            'connections' => array_map(static fn (array $entry) => [
                'connection_id' => (string) $entry['connection']['connection_uuid'],
                'name'          => (string) $entry['connection']['display_name'],
                'provider'      => (string) $entry['connection']['provider_code'],
                'mode'          => (string) $entry['connection']['provider_mode'],
                'status'        => (string) $entry['connection']['status'],
                'methods'       => $entry['provider']?->supportedMethods() ?? [],
                'currencies'    => $entry['provider']?->supportedCurrencies() ?? [],
            ], ProviderRegistry::connectionsFor($ctx)),
            'can_manage' => Permissions::allows($ctx, $auth, 'routing.manage'),
        ]);
    }

    public static function create(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'routing.manage');

        $body = Http::body();
        $values = self::validate($ctx, $body);

        $ruleId = (int) Db::insert(RoutingEngine::RULES, $values + [
            'cmp_id'     => $ctx->cmpId,
            'created_by' => $auth->uuid,
            // Recorded when a merchant accepted a Pay Pulse recommendation, so
            // the trail says the AI proposed it and a human chose it.
            'suggested_by_ai' => (bool) ($body['from_recommendation'] ?? false),
            'accepted_by' => ($body['from_recommendation'] ?? false) ? $auth->uuid : null,
        ], 'rule_id');

        Audit::record($ctx, $auth, Audit::ROUTING_CHANGED, 'routing_rule', $ruleId, null, $values);

        $row = Db::first('SELECT * FROM ' . RoutingEngine::RULES . ' WHERE rule_id = :id', ['id' => $ruleId]);

        Http::data(self::present($row ?? []), 201);
    }

    public static function update(int $ruleId): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'routing.manage');

        $before = Db::first(
            'SELECT * FROM ' . RoutingEngine::RULES . ' WHERE rule_id = :id AND cmp_id = :cmp',
            ['id' => $ruleId, 'cmp' => $ctx->cmpId],
        );

        if ($before === null) {
            Http::notFound('That routing rule could not be found.');
        }

        $values = self::validate($ctx, Http::body(), $before);
        $values['updated_at'] = gmdate('Y-m-d H:i:s');

        Db::update(RoutingEngine::RULES, $values, ['rule_id' => $ruleId, 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, Audit::ROUTING_CHANGED, 'routing_rule', $ruleId,
            array_intersect_key($before, $values), $values);

        $row = Db::first('SELECT * FROM ' . RoutingEngine::RULES . ' WHERE rule_id = :id', ['id' => $ruleId]);

        Http::data(self::present($row ?? []));
    }

    public static function delete(int $ruleId): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'routing.manage');

        $before = Db::first(
            'SELECT * FROM ' . RoutingEngine::RULES . ' WHERE rule_id = :id AND cmp_id = :cmp',
            ['id' => $ruleId, 'cmp' => $ctx->cmpId],
        );

        if ($before === null) {
            Http::notFound('That routing rule could not be found.');
        }

        Db::run('DELETE FROM ' . RoutingEngine::RULES . ' WHERE rule_id = :id AND cmp_id = :cmp',
            ['id' => $ruleId, 'cmp' => $ctx->cmpId]);

        Audit::record($ctx, $auth, Audit::ROUTING_CHANGED, 'routing_rule', $ruleId,
            ['rule_name' => (string) $before['rule_name']], ['deleted' => true]);

        Http::data(['deleted' => true]);
    }

    /**
     * Ask the router what it would do, without taking a payment.
     *
     * The most useful screen on the Gateways tab: a merchant can see which
     * provider a ₹50,000 UPI payment would go to, and which candidates were
     * rejected and why, before a customer finds out.
     */
    public static function simulate(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'providers.view');

        $result = RoutingEngine::choose($ctx, [
            'method'       => Http::param('method'),
            'currency'     => (string) (Http::param('currency') ?? 'INR'),
            'amount_minor' => (int) round(((float) (Http::param('amount') ?? 1000)) * 100),
            'source_app'   => Http::param('source_app'),
            'scope'        => Http::param('scope'),
        ]);

        Http::data([
            'ok'         => $result['ok'],
            'chosen'     => ($result['ok'] ?? false) ? [
                'connection_id' => (string) $result['connection']['connection_uuid'],
                'name'          => (string) $result['connection']['display_name'],
                'provider'      => (string) $result['connection']['provider_code'],
                'mode'          => (string) $result['connection']['provider_mode'],
            ] : null,
            'decided_by' => $result['decided_by'] ?? null,
            // Including the ones that lost, with the reason. "Stripe was
            // skipped: it does not take UPI" beats "routing failed".
            'considered' => $result['considered'],
            'error_code' => $result['error_code'] ?? null,
            'error_message' => $result['error_message'] ?? null,
        ]);
    }

    /**
     * Check a rule before it is saved.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private static function validate(\Aicountly\Api\Context $ctx, array $body, ?array $existing = null): array
    {
        $name = trim((string) ($body['name'] ?? ($existing['rule_name'] ?? '')));
        if ($name === '') {
            Http::validationFailed('Give this rule a name.', ['field' => 'name']);
        }

        $method = isset($body['method']) && $body['method'] !== '' ? strtoupper((string) $body['method']) : null;
        $currency = isset($body['currency']) && $body['currency'] !== '' ? strtoupper((string) $body['currency']) : null;

        $primary = self::resolveConnection($ctx, $body['primary_connection_id'] ?? null, 'primary_connection_id');
        $fallback = isset($body['fallback_connection_id']) && $body['fallback_connection_id'] !== ''
            ? self::resolveConnection($ctx, $body['fallback_connection_id'], 'fallback_connection_id')
            : null;

        if ($primary !== null && $fallback !== null
            && (int) $primary['connection_id'] === (int) $fallback['connection_id']) {
            Http::validationFailed(
                'The fallback has to be a different provider from the primary.',
                ['field' => 'fallback_connection_id'],
            );
        }

        // The check that saves a merchant from discovering this at a customer's
        // checkout.
        foreach ([['primary_connection_id', $primary], ['fallback_connection_id', $fallback]] as [$field, $connection]) {
            if ($connection === null || $method === null) {
                continue;
            }

            $provider = ProviderRegistry::forConnection($connection);
            $supported = Db::jsonColumn($connection['supported_methods'] ?? null) ?: ($provider?->supportedMethods() ?? []);

            if ($supported !== [] && !in_array($method, $supported, true)) {
                Http::validationFailed(
                    (string) $connection['display_name'] . ' does not take ' . strtolower($method) . ' payments, so it cannot be the '
                    . (str_starts_with($field, 'primary') ? 'primary' : 'fallback') . ' for this rule.',
                    ['field' => $field, 'supported_methods' => $supported],
                );
            }

            if ($currency !== null) {
                $currencies = Db::jsonColumn($connection['supported_currencies'] ?? null) ?: ($provider?->supportedCurrencies() ?? []);
                if ($currencies !== [] && !in_array($currency, $currencies, true)) {
                    Http::validationFailed(
                        (string) $connection['display_name'] . ' does not take ' . $currency . '.',
                        ['field' => $field, 'supported_currencies' => $currencies],
                    );
                }
            }
        }

        return [
            'rule_name'        => substr($name, 0, 120),
            'priority'         => max(1, min(999, (int) ($body['priority'] ?? ($existing['priority'] ?? 100)))),
            'is_active'        => (bool) ($body['is_active'] ?? ($existing['is_active'] ?? true)),
            'match_method'     => $method,
            'match_currency'   => $currency,
            'match_scope'      => isset($body['scope']) && $body['scope'] !== '' ? strtoupper((string) $body['scope']) : null,
            'match_source_app' => isset($body['source_app']) && $body['source_app'] !== '' ? strtoupper((string) $body['source_app']) : null,
            'match_min_minor'  => isset($body['min_amount']) && $body['min_amount'] !== ''
                ? (int) round(((float) $body['min_amount']) * 100) : null,
            'match_max_minor'  => isset($body['max_amount']) && $body['max_amount'] !== ''
                ? (int) round(((float) $body['max_amount']) * 100) : null,
            'primary_connection_id'  => $primary === null ? null : (int) $primary['connection_id'],
            'fallback_connection_id' => $fallback === null ? null : (int) $fallback['connection_id'],
            'allow_failover'   => (bool) ($body['allow_failover'] ?? ($existing['allow_failover'] ?? true)),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function resolveConnection(\Aicountly\Api\Context $ctx, mixed $uuid, string $field): ?array
    {
        if ($uuid === null || $uuid === '') {
            if ($field === 'primary_connection_id') {
                Http::validationFailed('Choose which provider this rule sends payments to.', ['field' => $field]);
            }

            return null;
        }

        $row = Db::first(
            'SELECT * FROM pay_provider_connections WHERE connection_uuid = :uuid AND cmp_id = :cmp',
            ['uuid' => (string) $uuid, 'cmp' => $ctx->cmpId],
        );

        if ($row === null) {
            Http::validationFailed('That provider connection could not be found.', ['field' => $field]);
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function present(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'rule_id'    => (int) $row['rule_id'],
            'name'       => (string) $row['rule_name'],
            'priority'   => (int) $row['priority'],
            'is_active'  => $row['is_active'] === true || $row['is_active'] === 't',
            'method'     => $row['match_method'] === null ? null : (string) $row['match_method'],
            'currency'   => $row['match_currency'] === null ? null : (string) $row['match_currency'],
            'scope'      => $row['match_scope'] === null ? null : (string) $row['match_scope'],
            'source_app' => $row['match_source_app'] === null ? null : (string) $row['match_source_app'],
            'min_amount' => $row['match_min_minor'] === null ? null : ((int) $row['match_min_minor']) / 100,
            'max_amount' => $row['match_max_minor'] === null ? null : ((int) $row['match_max_minor']) / 100,
            'primary'    => ($row['primary_uuid'] ?? null) === null ? null : [
                'connection_id' => (string) $row['primary_uuid'],
                'name'          => (string) ($row['primary_name'] ?? ''),
            ],
            'fallback'   => ($row['fallback_uuid'] ?? null) === null ? null : [
                'connection_id' => (string) $row['fallback_uuid'],
                'name'          => (string) ($row['fallback_name'] ?? ''),
            ],
            'allow_failover' => $row['allow_failover'] === true || $row['allow_failover'] === 't',
            'suggested_by_ai' => ($row['suggested_by_ai'] ?? false) === true || ($row['suggested_by_ai'] ?? false) === 't',
        ];
    }
}
