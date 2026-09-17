/**
 * Dashboard 1 — Overview / Collection Command Centre.
 *
 * "What happened with my payments?"
 *
 * The one screen a merchant opens first thing. Everything on it is a fact about
 * money that has already moved, plus the short list of things somebody has to
 * do about it.
 */

import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  Banknote,
  Building2,
  FileText,
  Percent,
  RotateCcw,
} from 'lucide-react'
import { usePay } from '../context/PayContext'
import { money, moneyWhole, time } from '../services/format'
import type { OverviewDashboard } from '../services/types'
import { MethodDonut, TrendChart } from './Chart'
import { DashboardLayout, greetingFor } from './DashboardLayout'
import { useDashboard } from './useDashboard'
import { Badge, EmptyState, ErrorState, MetricRow, Panel, PulseStrip, ShareBar, SkeletonRows, StatusBadge } from './kit'

const ICONS = {
  collected: <Banknote size={18} />,
  success_rate: <Percent size={18} />,
  open_requests: <FileText size={18} />,
  settlement_awaited: <Building2 size={18} />,
  failed: <AlertTriangle size={18} />,
  refund_exceptions: <RotateCcw size={18} />,
}

const TONES = {
  failed: 'danger',
  refund_exceptions: 'warning',
} as const

export default function Overview() {
  const { session } = usePay()
  const navigate = useNavigate()
  const { data, loading, error, retryable, period, setPeriod, reload } = useDashboard<OverviewDashboard>('v1/dashboards/overview')

  return (
    <DashboardLayout
      title={greetingFor(session?.user.name ?? null, session?.user.has_name ?? false)}
      subtitle="Overview / Collection Command Centre"
      banner={{
        title: 'More payments. Happier customers. A healthier business.',
        detail: 'Pay Pulse reads your own payment data to help you collect, reconcile and grow.',
      }}
      period={period}
      onPeriodChange={setPeriod}
      description={data?.period ?? null}
    >
      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

      <MetricRow metrics={data?.metrics ?? []} loading={loading} icons={ICONS} tones={TONES} />

      <div className="pay-grid">
        <Panel
          title="Collections trend"
          description="Grouped by when the money moved, not when the request was raised"
          span={2}
        >
          {loading && !data ? <SkeletonRows rows={6} /> : <TrendChart points={data?.panels.trend ?? []} />}
        </Panel>

        <Panel title="Payment method mix">
          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : (
            <MethodDonut
              slices={data?.panels.method_mix ?? []}
              total={(data?.panels.method_mix ?? []).reduce((sum, slice) => sum + slice.amount, 0)}
            />
          )}
        </Panel>

        <Panel
          title="Provider performance"
          description="Aicountly Managed and your own gateways, side by side"
        >
          {loading && !data ? (
            <SkeletonRows rows={4} />
          ) : (data?.panels.providers ?? []).length === 0 ? (
            <EmptyState title="No payments through a provider yet">
              Connect a payment gateway to start collecting.
            </EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                {/* Two columns, not three. This panel is a quarter of the grid,
                    and latency is secondary to the success rate — as a column of
                    its own it pushed the table past the panel edge, so it reads
                    better on the provider's own line. */}
                <thead>
                  <tr>
                    <th>Provider</th>
                    <th>Success rate</th>
                  </tr>
                </thead>
                <tbody>
                  {data?.panels.providers.map((provider) => (
                    <tr key={`${provider.connection_id}-${provider.provider}`}>
                      <td>
                        <strong style={{ fontSize: 13 }}>{provider.name}</strong>
                        <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>
                          {moneyWhole(provider.collected)} · {provider.payments} payments
                          {provider.avg_latency_ms !== null && ` · ${provider.avg_latency_ms} ms`}
                        </span>
                      </td>
                      <td style={{ minWidth: 92 }}>
                        <ShareBar value={provider.success_rate} tone={(provider.success_rate ?? 100) < 90 ? 'warning' : 'default'} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <Panel
          title="Open payment requests by source"
          description="Every app that asks Pay to collect"
          action={
            <span className="pay-count pay-count--neutral">
              {(data?.panels.open_by_source ?? []).reduce((sum, row) => sum + row.count, 0)} open
            </span>
          }
        >
          {loading && !data ? (
            <SkeletonRows rows={4} />
          ) : (data?.panels.open_by_source ?? []).length === 0 ? (
            <EmptyState title="Nothing outstanding">Every payment request has been settled.</EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr><th>Source</th><th className="num">Count</th><th className="num">Outstanding</th></tr>
                </thead>
                <tbody>
                  {data?.panels.open_by_source.map((row) => (
                    <tr key={row.source}>
                      <td>
                        <button type="button" className="pay-table__link" onClick={() => navigate(`/requests?source_app=${row.source}`)}>
                          {row.label}
                        </button>
                      </td>
                      <td className="num">{row.count}</td>
                      <td className="num">{moneyWhole(row.amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <Panel
          title="Recent collections activity"
          span={2}
          action={
            <button type="button" className="pay-button pay-button--ghost pay-button--small" onClick={() => navigate('/payments')}>
              View all
            </button>
          }
        >
          {loading && !data ? (
            <SkeletonRows rows={6} />
          ) : (data?.panels.recent_activity ?? []).length === 0 ? (
            <EmptyState title="No payments yet" action={
              <button type="button" className="pay-button pay-button--small" onClick={() => navigate('/requests/new')}>
                Create your first payment request
              </button>
            }>
              Payments appear here the moment one comes in.
            </EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Payer</th>
                    <th className="num">Amount</th>
                    <th>Status</th>
                    <th>Source</th>
                  </tr>
                </thead>
                <tbody>
                  {data?.panels.recent_activity.map((row) => (
                    <tr key={row.payment_id}>
                      <td style={{ color: 'var(--pay-muted)', whiteSpace: 'nowrap' }}>{time(row.at)}</td>
                      <td>
                        <button type="button" className="pay-table__link" onClick={() => navigate(`/payments/${row.payment_id}`)}>
                          {row.payer}
                        </button>
                        {row.reference && (
                          <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>{row.reference}</span>
                        )}
                      </td>
                      <td className="num">{money(row.amount, row.currency)}</td>
                      <td><StatusBadge status={row.status} label={row.status_label} /></td>
                      <td><span className="pay-tag pay-tag--neutral">{row.source}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <Panel
          title="Action centre"
          description="What needs somebody"
          action={
            (data?.panels.action_centre ?? []).length > 0
              ? <span className="pay-count">{data?.panels.action_centre.length} items</span>
              : undefined
          }
        >
          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : (data?.panels.action_centre ?? []).length === 0 ? (
            <EmptyState title="Nothing needs attention">Every payment, settlement and refund is where it should be.</EmptyState>
          ) : (
            data?.panels.action_centre.map((item) => (
              <button key={item.key} type="button" className="pay-action-row" onClick={() => navigate(item.path)}>
                <span style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                  <span className={`pay-action-row__mark pay-action-row__mark--${item.tone}`} aria-hidden>
                    <AlertTriangle size={15} />
                  </span>
                  <span style={{ minWidth: 0 }}>
                    <span className="pay-action-row__label">{item.label}</span>
                    {item.detail && <span className="pay-action-row__detail">{item.detail}</span>}
                  </span>
                </span>
                <Badge tone={item.tone === 'danger' ? 'danger' : item.tone === 'warning' ? 'warning' : 'info'}>
                  {item.count}
                </Badge>
              </button>
            ))
          )}
        </Panel>
      </div>

      <PulseStrip insights={data?.pulse ?? []} />
    </DashboardLayout>
  )
}
