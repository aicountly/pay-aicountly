<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Http;
use Aicountly\Api\ManageAccess;
use Aicountly\Api\Payments\Providers\ProviderRegistry;
use Aicountly\Api\Payments\Sources\SourceRegistry;
use Aicountly\Api\Permissions;

/**
 * Session, settings, roles, and the company switcher.
 *
 * `session` is the endpoint the whole React app boots from: who the user is,
 * what they may do, and which menu that produces. THE MENU IS DECIDED HERE and
 * not in the browser — a menu computed from a permission list the browser was
 * handed is a menu the browser can edit, and the person most interested in the
 * refund screen they were not shown is exactly the one who will try the URL.
 */
final class SettingsController extends Controller
{
    /** The navigation, in the order the product presents it. */
    private const MENU = [
        ['key' => 'dashboard',  'label' => 'Dashboard',          'path' => '/',                'permission' => 'pay.view'],
        ['key' => 'payments',   'label' => 'Payments',           'path' => '/payments',        'permission' => 'payments.view'],
        ['key' => 'requests',   'label' => 'Payment Requests',   'path' => '/requests',        'permission' => 'payment_requests.view'],
        ['key' => 'links',      'label' => 'Payment Links & QR', 'path' => '/links',           'permission' => 'links.manage'],
        ['key' => 'customers',  'label' => 'Customers',          'path' => '/customers',       'permission' => 'customers.view'],
        ['key' => 'mandates',   'label' => 'Recurring & Mandates', 'path' => '/mandates',      'permission' => 'mandates.view'],
        ['key' => 'refunds',    'label' => 'Refunds & Disputes', 'path' => '/refunds',         'permission' => 'refunds.request'],
        ['key' => 'settlements', 'label' => 'Settlements',       'path' => '/settlements',     'permission' => 'settlements.view'],
        ['key' => 'reconciliation', 'label' => 'Reconciliation', 'path' => '/reconciliation',  'permission' => 'reconciliation.view'],
        ['key' => 'gateways',   'label' => 'Gateways',           'path' => '/gateways',        'permission' => 'providers.view'],
        ['key' => 'pay_pulse',  'label' => 'Pay Pulse',          'path' => '/pay-pulse',       'permission' => 'pay_pulse.view', 'badge' => 'AI'],
        ['key' => 'developers', 'label' => 'Developers',         'path' => '/developers',      'permission' => 'developer.view'],
        ['key' => 'settings',   'label' => 'Settings',           'path' => '/settings',        'permission' => 'settings.manage'],
    ];

    /** The five dashboards, and who may open each. */
    private const DASHBOARDS = [
        ['key' => 'overview',    'label' => 'Overview',    'permission' => 'dashboard.overview'],
        ['key' => 'collections', 'label' => 'Collections', 'permission' => 'dashboard.collections'],
        ['key' => 'gateways',    'label' => 'Gateways',    'permission' => 'dashboard.gateways'],
        ['key' => 'settlements', 'label' => 'Settlements', 'permission' => 'dashboard.settlements'],
        ['key' => 'pay_pulse',   'label' => 'Pay Pulse',   'permission' => 'dashboard.pay_pulse'],
    ];

    public static function session(): never
    {
        [$auth, $ctx] = self::enter();

        // First visit for this company creates its settings and shipped roles.
        Settings::ensure($ctx);
        Permissions::seed($ctx);

        $granted = Permissions::granted($ctx, $auth);
        $can = static fn (string $permission): bool => Permissions::allows($ctx, $auth, $permission);

        $menu = [];
        foreach (self::MENU as $entry) {
            if ($can($entry['permission'])) {
                $menu[] = $entry;
            }
        }

        $dashboards = [];
        foreach (self::DASHBOARDS as $dashboard) {
            if ($can($dashboard['permission'])) {
                $dashboards[] = $dashboard;
            }
        }

        Http::data([
            'user' => [
                'uuid'         => $auth->uuid,
                'name'         => $auth->displayName(),
                // So the avatar shows initials rather than the first character
                // of a uuid.
                'has_name'     => $auth->hasDisplayName(),
                'is_owner'     => $ctx->isOwner($auth),
            ],
            'company' => [
                'cmp_id' => $ctx->cmpId,
                'bo_id'  => $ctx->boId,
                // Read live from Manage on this request and passed straight
                // through. Pay has nowhere to keep it and wants nowhere.
                'name'   => $ctx->companyName($auth),
            ],
            'permissions' => $granted,
            'menu'        => $menu,
            'dashboards'  => $dashboards,
            // Where the app should land. A user with no overview permission
            // must not be sent to a 403 on login.
            'landing'     => $dashboards[0]['key'] ?? ($menu[0]['path'] ?? '/payments'),
            'settings'    => self::presentSettings(Settings::for($ctx)),
            'capabilities' => [
                'managed'     => ProviderRegistry::managedReadiness(),
                'providers'   => ProviderRegistry::available(),
                'sources'     => SourceRegistry::catalog(),
                'pay_pulse'   => \Aicountly\Api\PayPulse\InsightEngine::enabled($ctx),
                'encryption_ready' => \Aicountly\Api\Crypto::isConfigured(),
            ],
        ]);
    }

    public static function show(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settings.manage');

        Http::data(self::presentSettings(Settings::for($ctx)));
    }

    public static function update(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settings.manage');

        $body = Http::body();
        $before = Settings::for($ctx);
        $updates = [];

        foreach (['checkout_display_name', 'checkout_logo_url', 'checkout_support_email',
                  'checkout_support_phone', 'checkout_terms_url'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = trim((string) $body[$field]);
                $updates[$field] = $value === '' ? null : substr($value, 0, 500);
            }
        }

        if (isset($body['default_link_expiry_hours'])) {
            // Zero means "never expires", which some merchants genuinely want
            // for a standing donation or membership link.
            $updates['default_link_expiry_hours'] = max(0, min(8760, (int) $body['default_link_expiry_hours']));
        }

        if (array_key_exists('allow_partial_by_default', $body)) {
            $updates['allow_partial_by_default'] = (bool) $body['allow_partial_by_default'];
        }

        if (isset($body['min_partial_amount'])) {
            $updates['min_partial_minor'] = self::money($body['min_partial_amount'], 'INR', 'min_partial_amount')->minor;
        }

        if (array_key_exists('refund_requires_approval', $body)) {
            $updates['refund_requires_approval'] = (bool) $body['refund_requires_approval'];
        }

        if (array_key_exists('refund_approval_threshold', $body)) {
            $updates['refund_approval_threshold_minor'] = $body['refund_approval_threshold'] === null || $body['refund_approval_threshold'] === ''
                ? null
                : self::money($body['refund_approval_threshold'], 'INR', 'refund_approval_threshold')->minor;
        }

        if (array_key_exists('auto_routing_enabled', $body)) {
            // The one setting that lets software change where a merchant's
            // money goes. Audited separately, and never on by default.
            $updates['auto_routing_enabled'] = (bool) $body['auto_routing_enabled'];
        }

        if (array_key_exists('pay_pulse_enabled', $body)) {
            $updates['pay_pulse_enabled'] = (bool) $body['pay_pulse_enabled'];
        }

        if ($updates === []) {
            Http::data(self::presentSettings($before));
        }

        $after = Settings::update($ctx, $updates);

        Audit::record($ctx, $auth, Audit::SETTINGS_CHANGED, 'settings', $ctx->cmpId,
            array_intersect_key($before, $updates), $updates);

        Http::data(self::presentSettings($after));
    }

    // ------------------------------------------------------------------ roles

    public static function roles(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        Permissions::seed($ctx);

        $rows = Db::all(
            'SELECT r.*, (SELECT COUNT(*) FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a WHERE a.role_id = r.role_id) AS members
             FROM ' . Permissions::TABLE_ROLES . ' r WHERE r.cmp_id = :cmp ORDER BY r.is_system DESC, r.role_name',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'roles' => array_map(static fn (array $row) => [
                'role_id'     => (int) $row['role_id'],
                'code'        => (string) $row['role_code'],
                'name'        => (string) $row['role_name'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'permissions' => Db::jsonColumn($row['permissions'] ?? null),
                'is_system'   => $row['is_system'] === true || $row['is_system'] === 't',
                'is_active'   => $row['is_active'] === true || $row['is_active'] === 't',
                'members'     => (int) $row['members'],
            ], $rows),
            'catalog' => Permissions::CATALOG,
        ]);
    }

    public static function assignRole(int $roleId): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $uuid = trim((string) (Http::param('user_uuid') ?? ''));
        if ($uuid === '') {
            Http::validationFailed('Name the user this role is for.', ['field' => 'user_uuid']);
        }

        $role = Db::first(
            'SELECT * FROM ' . Permissions::TABLE_ROLES . ' WHERE role_id = :id AND cmp_id = :cmp',
            ['id' => $roleId, 'cmp' => $ctx->cmpId],
        );
        if ($role === null) {
            Http::notFound('That role could not be found.');
        }

        Db::run(
            'INSERT INTO ' . Permissions::TABLE_ASSIGNMENTS . ' (cmp_id, user_uuid, role_id, created_by)
             VALUES (:cmp, :uuid, :role, :by) ON CONFLICT DO NOTHING',
            ['cmp' => $ctx->cmpId, 'uuid' => $uuid, 'role' => $roleId, 'by' => $auth->uuid],
        );

        Permissions::forget();
        Audit::record($ctx, $auth, Audit::ROLE_CHANGED, 'role_assignment', $roleId, null,
            ['user_uuid' => $uuid, 'role' => (string) $role['role_name']]);

        Http::data(['assigned' => true]);
    }

    // -------------------------------------------------------- company context

    /**
     * The company switcher. Live from Manage, and deliberately NOT scoped —
     * this is what a caller uses to choose the company in the first place.
     */
    public static function companies(): never
    {
        $auth = Auth::require();

        if ($auth->isMachine()) {
            Http::forbidden('The company list is for signed-in users.');
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companies([
            'filter'   => (string) (Http::param('filter') ?? 'all'),
            'page'     => (int) (Http::intParam('page', 1) ?? 1),
            'per_page' => min(100, (int) (Http::intParam('per_page', 50) ?? 50)),
        ]);

        if (!$result['ok']) {
            Http::error(503, 'manage_unavailable', 'The company list could not be read right now. Please retry.');
        }

        $rows = ManageAccess::listRows($result['body'] ?? null);

        Http::data(array_map(static fn (array $row) => [
            'cmp_id'   => (int) ($row['comp_id'] ?? $row['cmp_id'] ?? $row['id'] ?? 0),
            'name'     => (string) ($row['company_name'] ?? $row['cmp_name'] ?? $row['name'] ?? 'Company'),
            'is_owner' => ManageAccess::resolve($row) === ManageAccess::OWNER,
        ], $rows));
    }

    public static function companyInfo(): never
    {
        $auth = Auth::require();
        $cmpId = (int) (Http::intParam('cmp_id', 0) ?? 0);

        if ($cmpId <= 0) {
            Http::validationFailed('Which company?', ['field' => 'cmp_id']);
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($cmpId);
        if (!$result['ok']) {
            Http::error(503, 'manage_unavailable', 'That company could not be read right now. Please retry.');
        }

        Http::data(ManageAccess::companyRow($result['body'] ?? null));
    }

    /** @param array<string, mixed> $settings */
    private static function presentSettings(array $settings): array
    {
        return [
            'checkout' => [
                'display_name'  => $settings['checkout_display_name'] ?? null,
                'logo_url'      => $settings['checkout_logo_url'] ?? null,
                'support_email' => $settings['checkout_support_email'] ?? null,
                'support_phone' => $settings['checkout_support_phone'] ?? null,
                'terms_url'     => $settings['checkout_terms_url'] ?? null,
            ],
            'payment_requests' => [
                'default_expiry_hours'  => (int) ($settings['default_link_expiry_hours'] ?? 168),
                'allow_partial_default' => ($settings['allow_partial_by_default'] ?? false) === true
                    || ($settings['allow_partial_by_default'] ?? false) === 't',
                'min_partial_amount'    => Money::minor((int) ($settings['min_partial_minor'] ?? 0))->toMajor(),
            ],
            'refunds' => [
                'requires_approval'  => ($settings['refund_requires_approval'] ?? false) === true
                    || ($settings['refund_requires_approval'] ?? false) === 't',
                'approval_threshold' => ($settings['refund_approval_threshold_minor'] ?? null) === null
                    ? null : Money::minor((int) $settings['refund_approval_threshold_minor'])->toMajor(),
            ],
            'pay_pulse' => [
                'enabled'      => ($settings['pay_pulse_enabled'] ?? true) === true
                    || ($settings['pay_pulse_enabled'] ?? true) === 't',
                'auto_routing' => ($settings['auto_routing_enabled'] ?? false) === true
                    || ($settings['auto_routing_enabled'] ?? false) === 't',
                // On the screen, so the choice is made with its consequence in
                // view rather than as an unlabelled switch.
                'auto_routing_note' => 'With this on, Pay Pulse may change which provider takes a payment, within your own fallback rules. Every change is recorded in the audit trail.',
            ],
            'currency' => (string) ($settings['default_currency'] ?? 'INR'),
        ];
    }
}
