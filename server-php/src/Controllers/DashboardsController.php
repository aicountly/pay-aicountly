<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Dashboards\DashboardService;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Http;
use Aicountly\Api\ManageAccess;
use Aicountly\Api\PayPulse\PulseDashboard;

/**
 * The five dashboards, one endpoint each.
 *
 * Each checks its own permission before it reads anything — inside the service,
 * not here — so the tab bar and the URL agree about who may open what. A
 * dashboard a user cannot see is also a URL they cannot call.
 */
final class DashboardsController extends Controller
{
    public static function overview(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(DashboardService::overview($ctx, $auth, self::period($ctx, $auth)));
    }

    public static function collections(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(DashboardService::collections($ctx, $auth, self::period($ctx, $auth)));
    }

    public static function gateways(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(DashboardService::gateways($ctx, $auth, self::period($ctx, $auth)));
    }

    public static function settlements(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(DashboardService::settlements($ctx, $auth, self::period($ctx, $auth)));
    }

    public static function payPulse(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(PulseDashboard::build($ctx, $auth, self::period($ctx, $auth)));
    }

    /**
     * The window, in the COMPANY'S timezone.
     *
     * Read live from Manage rather than stored, and defaulted to IST when
     * Manage does not say. "Collected today" that begins at 05:30 is a figure
     * nobody at a counter recognises.
     */
    private static function period(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): Period
    {
        $key = (string) (Http::param('period') ?? Period::TODAY);
        if (!in_array($key, Period::available(), true)) {
            $key = Period::TODAY;
        }

        return Period::resolve($key, self::timezoneFor($ctx, $auth));
    }

    private static function timezoneFor(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): string
    {
        static $memo = [];
        if (isset($memo[$ctx->cmpId])) {
            return $memo[$ctx->cmpId];
        }

        // A machine caller has no session to ask Manage with, and a dashboard
        // is not something a machine reads anyway.
        if ($auth->isMachine()) {
            return $memo[$ctx->cmpId] = 'Asia/Kolkata';
        }

        try {
            $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($ctx->cmpId);
            if ($result['ok']) {
                $company = ManageAccess::companyRow($result['body'] ?? null);
                foreach (['timezone', 'time_zone', 'tz'] as $key) {
                    if (isset($company[$key]) && is_string($company[$key]) && $company[$key] !== '') {
                        return $memo[$ctx->cmpId] = (string) $company[$key];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[dashboards] could not read the company timezone: ' . $e->getMessage());
        }

        return $memo[$ctx->cmpId] = 'Asia/Kolkata';
    }
}
