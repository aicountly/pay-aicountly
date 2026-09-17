/**
 * Dashboard 3 — Gateways & Routing.
 *
 * "How are my payment providers performing?"
 *
 * SCOPED TO THIS COMPANY, ALWAYS. This screen shows the merchant's own
 * providers, their own KYC and their own traffic. There is deliberately nothing
 * here about other Aicountly merchants, no platform-wide activation queue and
 * no cross-tenant statistics — that is Aicountly's internal administration and
 * has no place in a customer's dashboard.
 */

import { useNavigate } from 'react-router-dom'
import {
  Activity,
  AlertTriangle,
  CheckCircle2,
  Gauge,
  Link2,
  Plug,
  ShieldCheck,
  Timer,
} from 'lucide-react'
import { moneyWhole, percent } from '../services/format'
import type { GatewaysDashboard } from '../services/types'
import { DashboardLayout } from './DashboardLayout'
import { useDashboard } from './useDashboard'
import { Badge, EmptyState, ErrorState, MetricRow, Panel, PulseStrip, ShareBar, SkeletonRows, StatusBadge, Unavailable } from './kit'

const ICONS = {
  connected_providers: <Plug size={18} />,
  managed_status: <ShieldCheck size={18} />,
  managed_kyc: <ShieldCheck size={18} />,
  success_rate: <Gauge size={18} />,
  avg_processing_ms: <Timer size={18} />,
  webhook_health: <Activity size={18} />,
}

export default function Gateways() {
  const navigate = useNavigate()
  const { data, loading, error, retryable, period, setPeriod, reload } =
    useDashboard<GatewaysDashboard>('v1/dashboards/gateways', 'last_7')

  const managed = data?.panels.managed
  const technical = data?.panels.technical

  return (
    <DashboardLayout
      title={<>Gateways & Routing / <span>Payment Operations</span></>}
      subtitle="Your payment providers, their health, and which one takes which payment"
      banner={{
        title: 'Reliable. Flexible. Always on.',
        detail: 'Route each payment to the provider that serves it best, with a fallback when one has a bad minute.',
      }}
      period={period}
      onPeriodChange={setPeriod}
      description={data?.period ?? null}
    >
      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

      <MetricRow metrics={data?.metrics ?? []} loading={loading} icons={ICONS} />

      <div className="pay-grid">
        <Panel
          span={2}
          title="Payment providers"
          description="Aicountly Managed and your own gateway accounts"
          action={
            data?.panels.can_manage ? (
              <button type="button" className="pay-button pay-button--small" onClick={() => navigate('/gateways')}>
                Connect provider
              </button>
            ) : undefined
          }
        >
          {loading && !data ? (
            <SkeletonRows rows={4} />
          ) : (data?.panels.providers ?? []).length === 0 ? (
            <EmptyState
              title="Connect your first payment provider"
              action={
                data?.panels.can_manage ? (
                  <button type="button" className="pay-button pay-button--small" onClick={() => navigate('/gateways')}>
                    Connect a gateway
                  </button>
                ) : undefined
              }
            >
              Connect your own gateway, or activate Aicountly Managed Payments.
            </EmptyState>
          ) : (
            data?.panels.providers.map((provider) => (
              <div key={provider.connection_id} className="pay-provider">
                <span className="pay-provider__mark" aria-hidden>{provider.name.slice(0, 1).toUpperCase()}</span>

                <div className="pay-provider__body">
                  <div className="pay-provider__name">
                    {provider.name}
                    {provider.is_primary && <span className="pay-tag">Primary</span>}
                    {provider.mode === 'MANAGED' && <span className="pay-tag">Aicountly Managed</span>}
                    {provider.environment === 'TEST' && <span className="pay-tag pay-tag--warning">Test</span>}
                  </div>

                  <div className="pay-provider__meta">
                    {provider.methods.length > 0 ? provider.methods.join(' · ') : 'No methods configured'}
                  </div>

                  {/* The reason, not just the state. "Credentials invalid" with
                      nothing after it sends a merchant to support. */}
                  {provider.status_reason && (
                    <p style={{ margin: '6px 0 0', color: 'var(--pay-danger)', fontSize: 12 }}>{provider.status_reason}</p>
                  )}

                  {provider.success_rate !== null && (
                    <div style={{ marginTop: 8, maxWidth: 260 }}>
                      <ShareBar value={provider.success_rate} tone={provider.success_rate < 90 ? 'warning' : 'default'} />
                    </div>
                  )}
                </div>

                <div style={{ textAlign: 'right', flexShrink: 0 }}>
                  <StatusBadge status={provider.status} />
                  {provider.avg_latency_ms !== null && (
                    <span style={{ display: 'block', marginTop: 6, color: 'var(--pay-muted)', fontSize: 11.5 }}>
                      {provider.avg_latency_ms} ms
                    </span>
                  )}
                </div>
              </div>
            ))
          )}
        </Panel>

        <Panel
          span={2}
          title="Smart routing rules"
          description="Which provider takes which payment, and what happens when one fails"
          action={
            data?.panels.can_route ? (
              <button type="button" className="pay-button pay-button--ghost pay-button--small" onClick={() => navigate('/gateways#routing')}>
                Manage rules
              </button>
            ) : undefined
          }
          footnote="A rule is a preference, never a permission. A provider that is disabled, mis-keyed or cannot take the method is skipped whatever a rule says."
        >
          {loading && !data ? (
            <SkeletonRows rows={4} />
          ) : (data?.panels.routing_rules ?? []).length === 0 ? (
            <EmptyState title="No routing rules yet">
              Without rules, Pay uses your primary provider and falls back to the next healthy one.
            </EmptyState>
          ) : (
            data?.panels.routing_rules.map((rule, index) => (
              <div key={rule.rule_id} className="pay-provider">
                <span className="pay-provider__mark" aria-hidden>{index + 1}</span>

                <div className="pay-provider__body">
                  <div className="pay-provider__name">
                    {rule.name}
                    {!rule.is_active && <span className="pay-tag pay-tag--neutral">Off</span>}
                    {rule.suggested_by_ai && <span className="pay-tag">Pay Pulse suggested</span>}
                  </div>
                  <div className="pay-provider__meta">
                    {rule.primary ?? 'No provider'}
                    {rule.fallback && rule.allow_failover && ` → falls back to ${rule.fallback}`}
                  </div>
                </div>

                <div style={{ flexShrink: 0 }}>
                  {rule.method && <span className="pay-tag pay-tag--neutral">{rule.method}</span>}
                </div>
              </div>
            ))
          )}
        </Panel>

        <Panel
          title="Provider health matrix"
          description={`Success rate by method, last ${data?.panels.health_matrix.window_hours ?? 24} hours`}
          span={4}
        >
          {loading && !data ? (
            <SkeletonRows rows={4} />
          ) : (data?.panels.health_matrix.rows ?? []).length === 0 ? (
            <EmptyState title="Not enough traffic yet">
              The matrix fills in once payments have gone through more than one provider.
            </EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr><th>Method</th><th>Provider</th><th>Success rate</th><th className="num">Latency</th><th className="num">Calls</th></tr>
                </thead>
                <tbody>
                  {data?.panels.health_matrix.rows.flatMap((row) =>
                    row.providers.map((cell, index) => (
                      <tr key={`${row.method}-${cell.connection_id}`}>
                        {index === 0 && <td rowSpan={row.providers.length}><strong>{row.label}</strong></td>}
                        <td>{cell.name}</td>
                        <td style={{ minWidth: 150 }}>
                          <ShareBar value={cell.success_rate} tone={(cell.success_rate ?? 100) < 90 ? 'warning' : 'default'} />
                        </td>
                        <td className="num">{cell.avg_latency_ms === null ? '—' : `${cell.avg_latency_ms} ms`}</td>
                        <td className="num">{cell.calls}</td>
                      </tr>
                    )),
                  )}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <Panel title="Aicountly Managed" description="Payments through Aicountly's own arrangement">
          {loading && !data ? (
            <SkeletonRows rows={3} />
          ) : !managed ? null : !managed.can_apply ? (
            // The honest sentence, not a pretend "coming soon". Nothing here
            // claims Managed is live without a partner behind it.
            <Unavailable title="Not configured on this deployment">{managed.detail}</Unavailable>
          ) : (
            <div>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 12 }}>
                <div>
                  <strong style={{ display: 'block', fontSize: 15 }}>{managed.label}</strong>
                  {managed.detail && <span style={{ color: 'var(--pay-muted)', fontSize: 12 }}>{managed.detail}</span>}
                </div>
                <Badge tone={managed.state === 'ACTIVE' ? 'success' : managed.state === 'BLOCKED' ? 'danger' : 'info'}>
                  {managed.kyc_label}
                </Badge>
              </div>

              {/* What the PARTNER says is still missing, in their words. A
                  paraphrase could send the merchant to fix the wrong thing. */}
              {managed.required_actions.length > 0 && (
                <div className="pay-unavailable" style={{ marginBottom: 12 }}>
                  <strong>The payment partner needs:</strong>
                  <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
                    {managed.required_actions.map((action, index) => (
                      <li key={index}>{typeof action === 'string' ? action : JSON.stringify(action)}</li>
                    ))}
                  </ul>
                </div>
              )}

              {managed.bank_last4 && (
                <p style={{ margin: '0 0 12px', color: 'var(--pay-muted)', fontSize: 12.5 }}>
                  Settling to account ending {managed.bank_last4}
                </p>
              )}

              <p style={{ margin: 0, color: 'var(--pay-muted)', fontSize: 11.5 }}>
                Verification is decided by the authorised payment partner. Aicountly collects and forwards what they ask for.
              </p>
            </div>
          )}
        </Panel>

        <Panel title="Technical health" description="Last 24 hours">
          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : !technical ? null : (
            <div>
              <HealthRow
                label="Webhook success rate"
                value={percent(technical.webhook_success_rate)}
                tone={technical.webhook_success_rate !== null && technical.webhook_success_rate < 95 ? 'warning' : 'success'}
                detail={`${technical.webhooks_received} received`}
              />
              <HealthRow
                label="Signature failures"
                value={String(technical.signature_failures)}
                tone={technical.signature_failures > 0 ? 'danger' : 'success'}
                detail={technical.signature_failures > 0 ? 'Check the webhook secret at the provider' : 'All verified'}
              />
              <HealthRow
                label="Duplicate callbacks"
                value={String(technical.duplicate_callbacks)}
                tone="neutral"
                detail="Recognised and ignored — no payment recorded twice"
              />
              <HealthRow
                label="Callbacks awaiting delivery"
                value={String(technical.callbacks_pending)}
                tone={technical.callbacks_pending > 20 ? 'warning' : 'success'}
                detail={
                  technical.callbacks_exhausted > 0
                    ? `${technical.callbacks_exhausted} need a human`
                    : technical.callbacks_pending > 0
                      // "All delivered" beside a count of 7 waiting is a flat
                      // contradiction. Nothing has been given up on — which is
                      // what exhausted === 0 actually means — and that is what
                      // this should say.
                      ? 'Queued and retrying — none given up on'
                      : 'Nothing waiting'
                }
              />
              <HealthRow
                label="Provider error rate"
                value={percent(technical.provider_error_rate)}
                tone={(technical.provider_error_rate ?? 0) > 5 ? 'danger' : 'success'}
                detail={`${technical.provider_calls} calls`}
              />
            </div>
          )}
        </Panel>

        <Panel title="Gateway traffic" description="Where the money actually went" span={2}>
          {loading && !data ? (
            <SkeletonRows rows={4} />
          ) : (data?.panels.traffic ?? []).length === 0 ? (
            <EmptyState title="No traffic in this period">Traffic distribution appears once payments go through.</EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr><th>Provider</th><th>Mode</th><th className="num">Collected</th><th className="num">Payments</th><th>Success rate</th></tr>
                </thead>
                <tbody>
                  {data?.panels.traffic.map((row) => (
                    <tr key={`${row.connection_id}-${row.provider}`}>
                      <td><strong style={{ fontSize: 13 }}>{row.name}</strong></td>
                      <td><span className="pay-tag pay-tag--neutral">{row.mode}</span></td>
                      <td className="num">{moneyWhole(row.collected)}</td>
                      <td className="num">{row.payments}</td>
                      <td style={{ minWidth: 150 }}><ShareBar value={row.success_rate} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      </div>

      <PulseStrip insights={data?.pulse ?? []} />
    </DashboardLayout>
  )
}

function HealthRow({ label, value, tone, detail }: { label: string; value: string; tone: 'success' | 'warning' | 'danger' | 'neutral'; detail: string }) {
  const Icon = tone === 'success' ? CheckCircle2 : tone === 'neutral' ? Link2 : AlertTriangle

  return (
    <div className="pay-action-row" style={{ cursor: 'default' }}>
      <span style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
        <span
          className={`pay-action-row__mark ${tone === 'danger' ? 'pay-action-row__mark--danger' : tone === 'warning' ? 'pay-action-row__mark--warning' : 'pay-action-row__mark--info'}`}
          aria-hidden
        >
          <Icon size={15} />
        </span>
        <span>
          <span className="pay-action-row__label">{label}</span>
          <span className="pay-action-row__detail">{detail}</span>
        </span>
      </span>
      <strong className="num" style={{ fontSize: 14 }}>{value}</strong>
    </div>
  )
}
