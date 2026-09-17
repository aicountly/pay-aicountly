<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Routing;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Payments\Providers\ProviderMetrics;
use Aicountly\Api\Payments\Providers\ProviderRegistry;

/**
 * Which provider takes this payment, and which one takes it if that fails.
 *
 * HYBRID MODE IS THIS CLASS. A merchant with Aicountly Managed for UPI, their
 * own Razorpay for domestic cards and Stripe for international ones is not
 * running three integrations — they are running one, with a routing table.
 *
 * THE RULE THAT OVERRIDES EVERY RULE: a routing rule is a PREFERENCE, never a
 * permission. Before any rule is consulted, a connection must be usable — active,
 * credentials that decrypt, the right environment, the method and currency
 * genuinely supported, and not currently failing. A rule that names a
 * disabled Stripe does not send payments to a disabled Stripe; it is skipped,
 * and the reason is recorded so the merchant can be told which and why.
 *
 * That is not defensive coding. A router that honours a stale rule sends a
 * customer to a checkout that 401s, and the merchant's first sign of it is a
 * drop in their success rate two days later.
 *
 * EVERY DECISION IS WRITTEN DOWN, including the candidates that lost and why.
 * "Why did this go through Stripe" is then a lookup rather than an argument.
 */
final class RoutingEngine
{
    public const RULES = 'pay_routing_rules';
    public const DECISIONS = 'pay_routing_decisions';

    /**
     * Below this success rate over the recent window, a provider is considered
     * unhealthy and loses to a healthy alternative.
     *
     * Deliberately low. A provider at 70% is still taking seven payments in ten,
     * and demoting it at the first sign of trouble would have a merchant's
     * traffic oscillating between gateways on ordinary noise.
     */
    private const UNHEALTHY_BELOW_PERCENT = 60.0;

    /**
     * Calls needed before a success rate is believed.
     *
     * Two failures out of two is 0%, and it is also a coincidence. Routing a
     * merchant's whole day away from their primary provider on the strength of
     * two requests is how an outage gets invented.
     */
    private const MIN_CALLS_FOR_HEALTH = 20;

    /**
     * Pick a provider.
     *
     * @param array{method?:?string, currency?:string, amount_minor?:int,
     *     source_app?:?string, scope?:?string, exclude_connection_ids?:list<int>} $request
     * @return array{ok:bool, connection?:array<string,mixed>, provider?:object,
     *     fallback_connection_id?:?int, rule_id?:?int, decided_by?:string,
     *     considered:list<array<string,mixed>>, error_code?:string, error_message?:string}
     */
    public static function choose(Context $ctx, array $request): array
    {
        $method = isset($request['method']) && $request['method'] !== null
            ? strtoupper((string) $request['method']) : null;
        $currency = Money::normaliseCurrency((string) ($request['currency'] ?? Money::DEFAULT_CURRENCY));
        $amountMinor = (int) ($request['amount_minor'] ?? 0);
        $sourceApp = isset($request['source_app']) ? strtoupper((string) $request['source_app']) : null;
        $scope = isset($request['scope']) ? strtoupper((string) $request['scope']) : null;
        $excluded = array_map('intval', $request['exclude_connection_ids'] ?? []);

        $candidates = self::eligible($ctx, $method, $currency, $excluded);
        $considered = $candidates['considered'];

        if ($candidates['usable'] === []) {
            return [
                'ok'            => false,
                'considered'    => $considered,
                'error_code'    => self::noProviderCode($considered),
                'error_message' => self::noProviderMessage($ctx, $considered, $method, $currency),
            ];
        }

        $rules = self::rulesFor($ctx, $method, $currency, $amountMinor, $sourceApp, $scope);

        foreach ($rules as $rule) {
            $primaryId = (int) ($rule['primary_connection_id'] ?? 0);
            $fallbackId = (int) ($rule['fallback_connection_id'] ?? 0);

            $primary = $candidates['usable'][$primaryId] ?? null;
            if ($primary !== null) {
                return [
                    'ok'                     => true,
                    'connection'             => $primary['connection'],
                    'provider'               => $primary['provider'],
                    'fallback_connection_id' => ($rule['allow_failover'] && isset($candidates['usable'][$fallbackId])) ? $fallbackId : null,
                    'rule_id'                => (int) $rule['rule_id'],
                    'decided_by'             => 'RULE',
                    'considered'             => $considered,
                ];
            }

            // The rule's primary is unusable. Its fallback is the merchant's
            // own stated second choice for this kind of payment, so it is tried
            // before falling through to the next rule — which is what the
            // merchant meant by writing a fallback in the first place.
            if ($rule['allow_failover'] && isset($candidates['usable'][$fallbackId])) {
                $fallback = $candidates['usable'][$fallbackId];

                return [
                    'ok'                     => true,
                    'connection'             => $fallback['connection'],
                    'provider'               => $fallback['provider'],
                    'fallback_connection_id' => null,
                    'rule_id'                => (int) $rule['rule_id'],
                    'decided_by'             => 'FALLBACK',
                    'considered'             => $considered,
                ];
            }
        }

        // No rule matched, or every matching rule named a provider that cannot
        // take this payment. Fall back to the merchant's default ordering.
        $ordered = self::rank($candidates['usable']);
        $chosen = array_shift($ordered);

        return [
            'ok'                     => true,
            'connection'             => $chosen['connection'],
            'provider'               => $chosen['provider'],
            'fallback_connection_id' => $ordered === [] ? null : (int) $ordered[0]['connection']['connection_id'],
            'rule_id'                => null,
            'decided_by'             => count($candidates['usable']) === 1 ? 'ONLY_ELIGIBLE' : 'DEFAULT',
            'considered'             => $considered,
        ];
    }

    /**
     * Record what was decided, once the attempt row exists.
     *
     * @param list<array<string, mixed>> $considered
     */
    public static function recordDecision(
        Context $ctx,
        int $attemptId,
        ?int $ruleId,
        ?int $connectionId,
        string $decidedBy,
        array $considered,
    ): void {
        try {
            Db::insert(self::DECISIONS, [
                'cmp_id'               => $ctx->cmpId,
                'attempt_id'           => $attemptId,
                'rule_id'              => $ruleId,
                'chosen_connection_id' => $connectionId,
                'decided_by'           => $decidedBy,
                'considered'           => $considered,
            ], 'decision_id');
        } catch (\Throwable $e) {
            // Losing the audit of a routing decision must not lose the payment.
            error_log('[routing] could not record decision for attempt ' . $attemptId . ': ' . $e->getMessage());
        }
    }

    /**
     * Which connections could take this payment, and why the others could not.
     *
     * @param list<int> $excluded
     * @return array{usable:array<int, array{connection:array<string,mixed>, provider:object, health:?array}>, considered:list<array<string,mixed>>}
     */
    public static function eligible(Context $ctx, ?string $method, string $currency, array $excluded = []): array
    {
        $health = ProviderMetrics::window($ctx->cmpId, 60);
        $usable = [];
        $considered = [];

        foreach (ProviderRegistry::connectionsFor($ctx) as $entry) {
            $connection = $entry['connection'];
            $provider = $entry['provider'];
            $id = (int) $connection['connection_id'];
            $name = (string) $connection['display_name'];

            $reject = static function (string $reason, string $detail) use (&$considered, $id, $name): void {
                $considered[] = ['connection_id' => $id, 'name' => $name, 'eligible' => false, 'reason' => $reason, 'detail' => $detail];
            };

            if (in_array($id, $excluded, true)) {
                // Already tried on this payment and failed. Sending the customer
                // back to the provider that just declined them is a second
                // decline on their statement.
                $reject('already_attempted', 'Already tried for this payment.');
                continue;
            }

            if ((string) $connection['status'] !== 'ACTIVE') {
                $reject(strtolower((string) $connection['status']), (string) ($connection['status_reason'] ?? 'This provider is not active.'));
                continue;
            }

            if ($provider === null) {
                $reject('unavailable', 'This provider could not be prepared. Check its credentials.');
                continue;
            }

            $methods = self::listOf($connection['supported_methods'] ?? null);
            if ($methods === []) {
                $methods = $provider->supportedMethods();
            }
            if ($method !== null && !in_array($method, $methods, true)) {
                $reject('method_unsupported', $name . ' does not take ' . self::methodWord($method) . ' payments.');
                continue;
            }

            $currencies = self::listOf($connection['supported_currencies'] ?? null);
            if ($currencies === []) {
                $currencies = $provider->supportedCurrencies();
            }
            if (!in_array($currency, $currencies, true)) {
                $reject('currency_unsupported', $name . ' does not take ' . $currency . '.');
                continue;
            }

            $connectionHealth = $health[$id] ?? null;
            $unhealthy = $connectionHealth !== null
                && $connectionHealth['calls'] >= self::MIN_CALLS_FOR_HEALTH
                && $connectionHealth['success_rate'] !== null
                && $connectionHealth['success_rate'] < self::UNHEALTHY_BELOW_PERCENT;

            $considered[] = [
                'connection_id' => $id,
                'name'          => $name,
                'eligible'      => true,
                'reason'        => $unhealthy ? 'degraded' : 'ok',
                'detail'        => $unhealthy
                    ? sprintf('Succeeding on %.1f%% of recent calls.', $connectionHealth['success_rate'])
                    : 'Available.',
                'success_rate'  => $connectionHealth['success_rate'] ?? null,
            ];

            $usable[$id] = ['connection' => $connection, 'provider' => $provider, 'health' => $connectionHealth, 'degraded' => $unhealthy];
        }

        // A DEGRADED provider is still usable. It is demoted in rank(), not
        // removed: a provider at 50% is better than no provider, and if it is
        // the merchant's only one, refusing the payment helps nobody.
        return ['usable' => $usable, 'considered' => $considered];
    }

    /**
     * Order candidates when no rule decided.
     *
     * Healthy before degraded, then the merchant's own primary flag, then their
     * priority, then a stable id — so the same inputs always give the same
     * answer and a merchant's traffic does not wander between gateways.
     *
     * @param array<int, array<string, mixed>> $usable
     * @return list<array<string, mixed>>
     */
    private static function rank(array $usable): array
    {
        $ordered = array_values($usable);

        usort($ordered, static function (array $a, array $b): int {
            $degraded = ($a['degraded'] ? 1 : 0) <=> ($b['degraded'] ? 1 : 0);
            if ($degraded !== 0) {
                return $degraded;
            }

            $primary = (($b['connection']['is_primary'] ?? false) ? 1 : 0) <=> (($a['connection']['is_primary'] ?? false) ? 1 : 0);
            if ($primary !== 0) {
                return $primary;
            }

            $priority = ((int) ($a['connection']['priority'] ?? 100)) <=> ((int) ($b['connection']['priority'] ?? 100));
            if ($priority !== 0) {
                return $priority;
            }

            return ((int) $a['connection']['connection_id']) <=> ((int) $b['connection']['connection_id']);
        });

        return $ordered;
    }

    /**
     * Rules that match this payment, most specific first.
     *
     * @return list<array<string, mixed>>
     */
    private static function rulesFor(
        Context $ctx,
        ?string $method,
        string $currency,
        int $amountMinor,
        ?string $sourceApp,
        ?string $scope,
    ): array {
        $rows = Db::all(
            'SELECT * FROM ' . self::RULES . '
             WHERE cmp_id = :cmp AND is_active = TRUE
             ORDER BY priority ASC, rule_id ASC',
            ['cmp' => $ctx->cmpId],
        );

        $matched = [];
        foreach ($rows as $rule) {
            if ($rule['match_method'] !== null && strtoupper((string) $rule['match_method']) !== $method) {
                continue;
            }
            if ($rule['match_currency'] !== null && strtoupper((string) $rule['match_currency']) !== $currency) {
                continue;
            }
            if ($rule['match_scope'] !== null && $scope !== null && strtoupper((string) $rule['match_scope']) !== $scope) {
                continue;
            }
            if ($rule['match_source_app'] !== null && strtoupper((string) $rule['match_source_app']) !== $sourceApp) {
                continue;
            }
            if ($rule['match_min_minor'] !== null && $amountMinor < (int) $rule['match_min_minor']) {
                continue;
            }
            if ($rule['match_max_minor'] !== null && $amountMinor > (int) $rule['match_max_minor']) {
                continue;
            }

            $rule['allow_failover'] = self::truthy($rule['allow_failover'] ?? true);
            $matched[] = $rule;
        }

        return $matched;
    }

    /** @param list<array<string, mixed>> $considered */
    private static function noProviderCode(array $considered): string
    {
        if ($considered === []) {
            return 'no_provider_connected';
        }

        // The most useful reason, not the first. A merchant whose only provider
        // does not do UPI needs to be told that, not that it was "not active".
        foreach (['method_unsupported', 'currency_unsupported', 'credentials_invalid', 'pending'] as $preferred) {
            foreach ($considered as $row) {
                if (($row['reason'] ?? '') === $preferred) {
                    return $preferred;
                }
            }
        }

        return 'no_provider_available';
    }

    /**
     * A sentence the merchant can act on.
     *
     * Never "routing failed". The four cases below are four different problems
     * with four different fixes, and telling them apart is most of the support
     * burden this product would otherwise carry.
     *
     * @param list<array<string, mixed>> $considered
     */
    private static function noProviderMessage(Context $ctx, array $considered, ?string $method, string $currency): string
    {
        if ($considered === []) {
            $readiness = ProviderRegistry::managedReadiness();

            return $readiness['available']
                ? 'No payment provider is connected yet. Connect your own gateway or activate Aicountly Managed Payments.'
                : 'No payment provider is connected yet. Connect your own payment gateway to start collecting.';
        }

        $details = [];
        foreach ($considered as $row) {
            if (($row['eligible'] ?? false) === false && isset($row['detail'])) {
                $details[] = (string) $row['detail'];
            }
        }

        $opening = $method !== null
            ? 'No connected provider can take ' . self::methodWord($method) . ' in ' . $currency . '.'
            : 'No connected provider can take a payment in ' . $currency . '.';

        return $details === [] ? $opening : $opening . ' ' . implode(' ', array_slice(array_unique($details), 0, 3));
    }

    private static function methodWord(string $method): string
    {
        return match ($method) {
            'UPI'        => 'UPI',
            'CARD'       => 'card',
            'NETBANKING' => 'net banking',
            'WALLET'     => 'wallet',
            'EMI'        => 'EMI',
            'PAYLATER'   => 'pay-later',
            default      => strtolower($method),
        };
    }

    /** @return list<string> */
    private static function listOf(mixed $raw): array
    {
        $decoded = Db::jsonColumn($raw);

        return array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? strtoupper($v) : null,
            $decoded,
        )));
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1' || $value === 'true';
    }
}
