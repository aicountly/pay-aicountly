<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Controllers\DashboardsController;
use Aicountly\Api\Controllers\DeveloperController;
use Aicountly\Api\Controllers\MoneyController;
use Aicountly\Api\Controllers\PaymentRequestsController;
use Aicountly\Api\Controllers\PaymentsController;
use Aicountly\Api\Controllers\ProvidersController;
use Aicountly\Api\Controllers\PublicController;
use Aicountly\Api\Controllers\ReconciliationController;
use Aicountly\Api\Controllers\RoutingController;
use Aicountly\Api\Controllers\SettingsController;
use Aicountly\Api\Controllers\WebhooksController;

/**
 * The Pay API.
 *
 * Three kinds of route, and the difference matters more here than in any other
 * product in the fleet:
 *
 *   SCOPED      everything under v1/ that a signed-in user or a service key
 *               reaches. Company scope and the tenant check happen in
 *               Controller::enter(), once, for all of them.
 *
 *   WEBHOOKS    v1/webhooks/*. No session, no company, no CSRF — the caller is
 *               a provider's server. Authentication IS the signature check,
 *               over the raw body.
 *
 *   PUBLIC      v1/public/*. No session either; the caller is a customer with a
 *               link. The company is read off the link's token, never from the
 *               request.
 *
 * The public and webhook routes are declared FIRST so that a future
 * catch-all can never shadow them.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // --- Provider webhooks ------------------------------------------
        // One endpoint per provider, because each signs differently and the
        // adapter has to be chosen before the body is verified.
        $router->post('v1/webhooks/razorpay', [WebhooksController::class, 'razorpay']);
        $router->post('v1/webhooks/cashfree', [WebhooksController::class, 'cashfree']);
        $router->post('v1/webhooks/stripe',   [WebhooksController::class, 'stripe']);
        $router->post('v1/webhooks/payu',     [WebhooksController::class, 'payu']);
        // Development only; ProviderRegistry refuses to build the mock in production.
        $router->post('v1/webhooks/mock',     [WebhooksController::class, 'mock']);

        // --- The public payment page ------------------------------------
        // Unguessable token in, no login, amounts verified server-side.
        $router->get('v1/public/pay/{token}',                    [PublicController::class, 'show']);
        $router->post('v1/public/pay/{token}/start',             [PublicController::class, 'start']);
        $router->get('v1/public/pay/{token}/status/{paymentId}', [PublicController::class, 'status']);

        // --- Session, company switcher and settings ---------------------
        $router->get('v1/session',            [SettingsController::class, 'session']);
        $router->get('v1/manage/companies',   [SettingsController::class, 'companies']);
        $router->get('v1/manage/companyinfo', [SettingsController::class, 'companyInfo']);
        $router->get('v1/settings',           [SettingsController::class, 'show']);
        $router->put('v1/settings',           [SettingsController::class, 'update']);
        $router->get('v1/roles',              [SettingsController::class, 'roles']);
        $router->post('v1/roles/{id}/members', [SettingsController::class, 'assignRole']);

        // --- The five dashboards ----------------------------------------
        // Five endpoints, not one: they answer different questions, are read by
        // different roles, and each checks its own permission before reading.
        $router->get('v1/dashboards/overview',    [DashboardsController::class, 'overview']);
        $router->get('v1/dashboards/collections', [DashboardsController::class, 'collections']);
        $router->get('v1/dashboards/gateways',    [DashboardsController::class, 'gateways']);
        $router->get('v1/dashboards/settlements', [DashboardsController::class, 'settlements']);
        $router->get('v1/dashboards/pay-pulse',   [DashboardsController::class, 'payPulse']);

        // --- Payment requests -------------------------------------------
        // Also the inbound contract every source app and every external
        // integration uses. See docs/PAY_INTEGRATION_GUIDE.md.
        $router->get('v1/payment-requests',                  [PaymentRequestsController::class, 'index']);
        $router->post('v1/payment-requests',                 [PaymentRequestsController::class, 'create']);
        $router->get('v1/payment-requests/{id}',             [PaymentRequestsController::class, 'show']);
        $router->post('v1/payment-requests/{id}/cancel',     [PaymentRequestsController::class, 'cancel']);
        $router->post('v1/payment-requests/{id}/extend',     [PaymentRequestsController::class, 'extend']);
        $router->post('v1/payment-requests/{id}/link',       [PaymentRequestsController::class, 'createLink']);
        $router->post('v1/payment-requests/{id}/qr',         [PaymentRequestsController::class, 'createQr']);

        // --- Payments ----------------------------------------------------
        $router->get('v1/payments',      [PaymentsController::class, 'index']);
        $router->get('v1/payments/{id}', [PaymentsController::class, 'show']);

        // --- Links and QR ------------------------------------------------
        $router->get('v1/links', [ReconciliationController::class, 'links']);

        // --- Customers ---------------------------------------------------
        // Payers as Pay knows them. Not a customer master — see the table
        // comment in migration 001.
        $router->get('v1/customers',      [ReconciliationController::class, 'customers']);
        $router->get('v1/customers/{id}', [ReconciliationController::class, 'customer']);

        // --- Money out ---------------------------------------------------
        $router->get('v1/refunds',                [MoneyController::class, 'refunds']);
        $router->post('v1/refunds',               [MoneyController::class, 'requestRefund']);
        $router->post('v1/refunds/{id}/approve',  [MoneyController::class, 'approveRefund']);
        $router->post('v1/refunds/{id}/reject',   [MoneyController::class, 'rejectRefund']);

        $router->get('v1/disputes',       [MoneyController::class, 'disputes']);
        $router->post('v1/disputes',      [MoneyController::class, 'createDispute']);
        $router->put('v1/disputes/{id}',  [MoneyController::class, 'updateDispute']);

        // --- Recurring ---------------------------------------------------
        // Pay owns the mandate. The plan and its cycle belong to Billing.
        $router->get('v1/mandates',             [MoneyController::class, 'mandates']);
        $router->post('v1/mandates/{id}/status', [MoneyController::class, 'setMandateStatus']);

        // --- Settlements and reconciliation ------------------------------
        $router->get('v1/settlements',                 [MoneyController::class, 'settlements']);
        $router->get('v1/settlements/{id}',            [MoneyController::class, 'showSettlement']);
        $router->post('v1/settlements/import',         [MoneyController::class, 'importSettlements']);
        $router->post('v1/settlements/{id}/confirm',   [MoneyController::class, 'confirmSettlement']);

        $router->get('v1/reconciliation',               [ReconciliationController::class, 'cases']);
        $router->post('v1/reconciliation/run',          [ReconciliationController::class, 'run']);
        $router->post('v1/reconciliation/{id}/resolve', [ReconciliationController::class, 'resolveCase']);

        // Money collected outside Pay. Deliberately not a "mark as collected"
        // button — every row needs a method, a reference and a date.
        $router->get('v1/external-payments',                [MoneyController::class, 'externalPayments']);
        $router->post('v1/external-payments',               [MoneyController::class, 'recordExternal']);
        $router->post('v1/external-payments/{id}/reverse',  [MoneyController::class, 'reverseExternal']);

        // --- Gateways and routing ----------------------------------------
        $router->get('v1/providers',             [ProvidersController::class, 'index']);
        $router->post('v1/providers',            [ProvidersController::class, 'create']);
        $router->put('v1/providers/{id}',        [ProvidersController::class, 'update']);
        $router->delete('v1/providers/{id}',     [ProvidersController::class, 'delete']);
        $router->post('v1/providers/{id}/test',  [ProvidersController::class, 'test']);

        $router->get('v1/routing-rules',           [RoutingController::class, 'index']);
        $router->post('v1/routing-rules',          [RoutingController::class, 'create']);
        $router->put('v1/routing-rules/{id}',      [RoutingController::class, 'update']);
        $router->delete('v1/routing-rules/{id}',   [RoutingController::class, 'delete']);
        // "Where would a ₹50,000 UPI payment go?", answered without taking one.
        $router->get('v1/routing-rules/simulate',  [RoutingController::class, 'simulate']);

        // --- Pay Pulse ----------------------------------------------------
        $router->get('v1/pay-pulse/insights',       [ReconciliationController::class, 'insights']);
        $router->post('v1/pay-pulse/insights/{id}', [ReconciliationController::class, 'updateInsight']);

        // --- Developers ---------------------------------------------------
        $router->get('v1/developer',                     [DeveloperController::class, 'overview']);
        $router->post('v1/developer/clients',            [DeveloperController::class, 'createClient']);
        $router->post('v1/developer/keys',               [DeveloperController::class, 'createKey']);
        $router->delete('v1/developer/keys/{id}',        [DeveloperController::class, 'revokeKey']);
        $router->post('v1/developer/endpoints',          [DeveloperController::class, 'createEndpoint']);
        $router->get('v1/developer/webhook-log',         [DeveloperController::class, 'webhookLog']);
        $router->get('v1/developer/outbound-log',        [DeveloperController::class, 'outboundLog']);
        $router->post('v1/developer/outbound/{id}/retry', [DeveloperController::class, 'retryOutbound']);

        // --- Audit ---------------------------------------------------------
        $router->get('v1/audit', [ReconciliationController::class, 'audit']);
    }
}
