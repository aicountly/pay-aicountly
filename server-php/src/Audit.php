<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Append-only audit of THIS product's own actions.
 *
 * Books audits its vouchers and Billing audits its bills; neither is copied
 * here. What this records is what only Pay knows, and in a payment product that
 * list is exactly the list of things somebody will one day have to answer for:
 * who connected a gateway, who changed the routing that decided where the money
 * went, who approved the refund, who recorded a cash payment that no provider
 * can corroborate.
 *
 * NEVER LOGS A SECRET. Credential changes record that a credential changed and
 * its masked tail, never the value — an audit trail that stores what it is
 * auditing is a second copy of the thing you were protecting.
 */
final class Audit
{
    public const TABLE = 'pay_audit_log';

    // The actions worth naming, so a query for "who touched the gateways" does
    // not depend on somebody having spelled the string the same way twice.
    public const PROVIDER_CONNECTED      = 'provider.connected';
    public const PROVIDER_UPDATED        = 'provider.updated';
    public const PROVIDER_DISABLED       = 'provider.disabled';
    public const PROVIDER_CREDENTIALS_SET = 'provider.credentials_replaced';
    public const ROUTING_CHANGED         = 'routing.changed';
    public const MANAGED_ONBOARDING      = 'managed.onboarding_submitted';
    public const MANAGED_KYC_CHANGED     = 'managed.kyc_status_changed';
    public const REFUND_REQUESTED        = 'refund.requested';
    public const REFUND_APPROVED         = 'refund.approved';
    public const REFUND_REJECTED         = 'refund.rejected';
    public const EXTERNAL_PAYMENT        = 'external_payment.recorded';
    public const API_KEY_CREATED         = 'api_key.created';
    public const API_KEY_REVOKED         = 'api_key.revoked';
    public const WEBHOOK_SECRET_ROTATED  = 'webhook_secret.rotated';
    public const RECONCILIATION_RESOLVED = 'reconciliation.resolved';
    public const REQUEST_CANCELLED       = 'payment_request.cancelled';
    public const CALLBACK_RETRIED        = 'source_callback.retried';
    public const SETTINGS_CHANGED        = 'settings.changed';
    public const ROLE_CHANGED            = 'role.changed';

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(
        Context $ctx,
        Auth $auth,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        string $reason = '',
    ): void {
        try {
            Db::insert(self::TABLE, [
                'cmp_id'       => $ctx->cmpId,
                'bo_id'        => $ctx->boId,
                'actor_uuid'   => $auth->uuid,
                'actor_kind'   => $auth->kind,
                'source_app'   => $auth->sourceApp,
                'action'       => $action,
                'entity_type'  => $entityType,
                'entity_id'    => $entityId === null ? null : (string) $entityId,
                'before_state' => $before === null ? null : self::redact($before),
                'after_state'  => $after === null ? null : self::redact($after),
                'reason'       => $reason !== '' ? $reason : null,
                'ip_address'   => self::clientIp(),
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ], 'audit_id');
        } catch (\Throwable $e) {
            // An audit write must never be the reason a user's save fails. It is
            // logged loudly instead, because a silently missing audit row is
            // worse than a noisy one.
            error_log('[audit] failed to record ' . $action . ' on ' . $entityType . ': ' . $e->getMessage());
        }
    }

    /**
     * Strip anything secret-shaped before it reaches the table.
     *
     * A belt-and-braces pass over what callers hand us. Callers are supposed to
     * pass masked values already; this is what catches the one that did not,
     * because an audit row is exactly the kind of thing that gets exported to a
     * spreadsheet and mailed to an auditor.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function redact(array $state): array
    {
        $secretish = ['secret', 'password', 'token', 'key', 'credential', 'private', 'signature', 'passphrase'];

        $out = [];
        foreach ($state as $key => $value) {
            $name = strtolower((string) $key);
            $looksSecret = false;
            foreach ($secretish as $needle) {
                if (str_contains($name, $needle)) {
                    $looksSecret = true;
                    break;
                }
            }

            // `key_id`, `idempotency_key` and `source_key` are identifiers, not
            // secrets, and redacting them would make the trail unreadable.
            if ($looksSecret && in_array($name, ['key_id', 'idempotency_key', 'source_key', 'api_key_id', 'routing_key'], true)) {
                $looksSecret = false;
            }

            if ($looksSecret) {
                $out[$key] = is_string($value) && $value !== '' ? '[redacted]' : null;
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function clientIp(): ?string
    {
        $candidates = [$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', $_SERVER['REMOTE_ADDR'] ?? ''];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $first = trim(explode(',', $candidate)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return null;
    }
}
