<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Pay roles — this product's permissions, layered over the portal identity.
 *
 * The problem this solves, in the merchant's words: "My accounts clerk should
 * see what came in and chase what didn't. She should not be able to refund a
 * customer, change which gateway my money goes through, or read my Razorpay
 * secret."
 *
 * Those three are the whole reason this file exists, and they are why the
 * permissions are not one `pay.admin` flag:
 *
 *   * a refund moves money OUT and is the one action here that cannot be undone;
 *   * routing decides which provider — and so which settlement account and which
 *     commercial rate — every rupee travels through;
 *   * a provider credential is a bearer secret for the merchant's own gateway
 *     account, and whoever holds it can collect money outside Aicountly entirely.
 *
 * ENFORCED IN THE BACKEND, on every route. Hiding a menu item in React tells the
 * user what they may do; it does not stop them, and the person who most wants to
 * reach the refund button they were not shown is exactly the person who will try
 * the URL.
 */
final class Permissions
{
    public const TABLE_ROLES       = 'pay_roles';
    public const TABLE_ASSIGNMENTS = 'pay_role_assignments';

    /** @var array<string, array<string, string>> */
    public const CATALOG = [
        'Dashboards' => [
            'pay.view'                => 'Open Pay',
            'dashboard.overview'      => 'See the collections overview',
            'dashboard.collections'   => 'See request conversion',
            'dashboard.gateways'      => 'See gateway performance',
            'dashboard.settlements'   => 'See settlements and reconciliation',
            'dashboard.pay_pulse'     => 'See Pay Pulse',
        ],
        'Collecting' => [
            'payments.view'            => 'See payments',
            'payment_requests.view'    => 'See payment requests',
            'payment_requests.create'  => 'Raise a payment request',
            'payment_requests.cancel'  => 'Cancel or expire a payment request',
            'links.manage'             => 'Create payment links and QR codes',
            'customers.view'           => 'See payers and their payment history',
        ],
        'Money out' => [
            // The two that move money in the wrong direction. Kept apart so a
            // merchant can let a clerk ASK for a refund without letting them
            // send one.
            'refunds.request' => 'Request a refund',
            'refunds.approve' => 'Approve and send a refund',
            'disputes.manage' => 'Work on chargebacks and disputes',
        ],
        'Recording' => [
            'external_payment.record' => 'Record a payment collected outside Pay',
        ],
        'Gateways' => [
            'providers.view'   => 'See connected payment providers',
            'providers.manage' => 'Connect, disable or re-key a payment provider',
            'managed.onboard'  => 'Apply for Aicountly Managed Payments',
            'routing.manage'   => 'Change which provider handles which payment',
        ],
        'Settlement' => [
            'settlements.view'       => 'See settlements',
            'reconciliation.view'    => 'See reconciliation exceptions',
            'reconciliation.resolve' => 'Resolve a reconciliation exception',
        ],
        'Recurring' => [
            'mandates.view'   => 'See recurring mandates',
            'mandates.manage' => 'Pause or cancel a mandate',
        ],
        'Platform' => [
            'pay_pulse.view'      => 'See Pay Pulse recommendations',
            'pay_pulse.act'       => 'Act on a Pay Pulse recommendation',
            'developer.view'      => 'See API clients and webhook endpoints',
            'developer.manage'    => 'Create or revoke API keys and webhook secrets',
            'settings.manage'     => 'Change Pay settings',
            'access.manage'       => 'Manage Pay roles',
            'audit.view'          => 'Read the Pay audit trail',
            'export.data'         => 'Export a list to a file',
        ],
    ];

    /**
     * The roles a new company starts with.
     *
     * COLLECTIONS_CLERK is the important one: everything needed to chase and
     * record money coming in, and nothing that sends money out or touches a
     * gateway credential.
     *
     * @var array<string, array{name:string, description:string, permissions:list<string>}>
     */
    public const TEMPLATES = [
        'collections_clerk' => [
            'name' => 'Collections clerk',
            'description' => 'Raises requests, chases payment and records what comes in. Cannot refund, route or see a gateway secret.',
            'permissions' => [
                'pay.view', 'dashboard.overview', 'dashboard.collections',
                'payments.view', 'payment_requests.view', 'payment_requests.create', 'payment_requests.cancel',
                'links.manage', 'customers.view', 'external_payment.record', 'pay_pulse.view',
            ],
        ],
        'finance' => [
            'name' => 'Finance',
            'description' => 'Everything a collections clerk does, plus settlements, reconciliation and requesting refunds.',
            'permissions' => [
                'pay.view', 'dashboard.overview', 'dashboard.collections', 'dashboard.settlements', 'dashboard.pay_pulse',
                'payments.view', 'payment_requests.view', 'payment_requests.create', 'payment_requests.cancel',
                'links.manage', 'customers.view', 'external_payment.record',
                'refunds.request', 'disputes.manage',
                'settlements.view', 'reconciliation.view', 'reconciliation.resolve',
                'mandates.view', 'pay_pulse.view', 'export.data',
            ],
        ],
        'payments_manager' => [
            'name' => 'Payments manager',
            'description' => 'Runs the payment operation: gateways, routing, refund approval and the developer surface.',
            'permissions' => [
                'pay.view', 'dashboard.overview', 'dashboard.collections', 'dashboard.gateways',
                'dashboard.settlements', 'dashboard.pay_pulse',
                'payments.view', 'payment_requests.view', 'payment_requests.create', 'payment_requests.cancel',
                'links.manage', 'customers.view', 'external_payment.record',
                'refunds.request', 'refunds.approve', 'disputes.manage',
                'providers.view', 'providers.manage', 'routing.manage',
                'settlements.view', 'reconciliation.view', 'reconciliation.resolve',
                'mandates.view', 'mandates.manage',
                'pay_pulse.view', 'pay_pulse.act', 'developer.view', 'export.data', 'audit.view',
            ],
        ],
        'developer' => [
            'name' => 'Developer',
            'description' => 'Builds against the Pay API. Sees technical detail and keys, not the money screens.',
            'permissions' => [
                'pay.view', 'dashboard.gateways',
                'payments.view', 'payment_requests.view',
                'providers.view', 'developer.view', 'developer.manage', 'audit.view',
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Reads the dashboards and the payment list. Changes nothing.',
            'permissions' => [
                'pay.view', 'dashboard.overview', 'dashboard.collections', 'dashboard.settlements',
                'payments.view', 'payment_requests.view', 'customers.view', 'settlements.view', 'pay_pulse.view',
            ],
        ],
        'owner' => [
            'name' => 'Owner',
            'description' => 'Everything in Pay.',
            'permissions' => [], // filled from the catalog at seed time
        ],
    ];

    /**
     * What an API key may hold.
     *
     * A deliberately short list, and deliberately NOT the same vocabulary as the
     * human catalog above. A merchant's server integration needs to raise a
     * request, read its status and refund against it. It has no business
     * re-keying a gateway or reading an audit trail, so those scopes do not
     * exist to be asked for.
     *
     * @var array<string, string>
     */
    public const API_SCOPES = [
        'payment_requests.read'  => 'Read payment requests',
        'payment_requests.write' => 'Create and cancel payment requests',
        'payments.read'          => 'Read payments and their status',
        'links.write'            => 'Create payment links and QR codes',
        'refunds.write'          => 'Request refunds',
        'settlements.read'       => 'Read settlements',
    ];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    /** Test seam: the memo is per request in production and must not leak between cases. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    public static function assert(Context $ctx, Auth $auth, string $permission): void
    {
        if (!self::allows($ctx, $auth, $permission)) {
            Http::forbidden('You cannot ' . self::describe($permission) . ' with your Pay role.');
        }
    }

    public static function allows(Context $ctx, Auth $auth, string $permission): bool
    {
        if ($auth->isService()) {
            return true;
        }

        // An API client holds SCOPES, not roles, and the two vocabularies do not
        // overlap. Mapping one onto the other is how a read-only integration key
        // ends up able to approve a refund, so a permission is simply not
        // something an API client can hold: the routes it may reach check
        // scopes, and the rest are closed to it.
        if ($auth->isApiClient()) {
            return false;
        }

        // Owner OF THIS COMPANY, per Manage — not per the portal session, which
        // does not know which company is open and so cannot answer it.
        if ($ctx->isOwner($auth)) {
            return true;
        }

        return in_array($permission, self::granted($ctx, $auth), true);
    }

    /** The API-key equivalent: assert a scope, or 403. */
    public static function assertScope(Auth $auth, string $scope): void
    {
        if ($auth->isService()) {
            return;
        }
        if (!$auth->isApiClient() || !$auth->hasScope($scope)) {
            Http::forbidden('This API key does not hold the "' . $scope . '" scope.');
        }
    }

    /** @return list<string> */
    public static function granted(Context $ctx, Auth $auth): array
    {
        if ($auth->isApiClient()) {
            return [];
        }

        $key = $ctx->cmpId . ':' . $auth->uuid;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if ($auth->isService() || $ctx->isOwner($auth)) {
            return self::$cache[$key] = self::all();
        }

        try {
            $rows = Db::all(
                'SELECT r.permissions
                 FROM ' . self::TABLE_ASSIGNMENTS . ' a
                 JOIN ' . self::TABLE_ROLES . ' r ON r.role_id = a.role_id
                 WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid AND r.is_active = TRUE',
                ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
            );
        } catch (\Throwable $e) {
            error_log('[permissions] lookup failed: ' . $e->getMessage());

            return self::$cache[$key] = [];
        }

        $granted = [];
        foreach ($rows as $row) {
            foreach (Db::jsonColumn($row['permissions'] ?? null) as $permission) {
                if (is_string($permission)) {
                    $granted[$permission] = true;
                }
            }
        }

        return self::$cache[$key] = array_keys($granted);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOG as $group) {
            foreach (array_keys($group) as $permission) {
                $out[] = $permission;
            }
        }

        return $out;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /** Create the shipped roles for a company that has none. */
    public static function seed(Context $ctx): void
    {
        $existing = (int) Db::scalar('SELECT COUNT(*) FROM ' . self::TABLE_ROLES . ' WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
        if ($existing > 0) {
            return;
        }

        foreach (self::TEMPLATES as $code => $template) {
            Db::insert(self::TABLE_ROLES, [
                'cmp_id'      => $ctx->cmpId,
                'role_code'   => $code,
                'role_name'   => $template['name'],
                'description' => $template['description'],
                'template_key' => $code,
                'permissions' => $code === 'owner' ? self::all() : $template['permissions'],
                'is_system'   => true,
            ], 'role_id');
        }
    }

    private static function describe(string $permission): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$permission])) {
                return strtolower($group[$permission]);
            }
        }

        return 'do that';
    }
}
