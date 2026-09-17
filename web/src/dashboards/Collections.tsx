/**
 * Dashboard 2 — Collections.
 *
 * "How well are payment requests turning into money?"
 *
 * The funnel is the screen. Everything else explains a stage of it: which
 * source app's requests convert, which channel gets opened, and which failures
 * are still worth chasing.
 */

import { useNavigate } from 'react-router-dom'
import { CheckCircle2, Eye, FileText, Send, ShoppingCart, XCircle } from 'lucide-react'
import { money, moneyWhole, percent, relativeDays } from '../services/format'
import type { CollectionsDashboard } from '../services/types'
import { Funnel } from './Chart'
import { DashboardLayout } from './DashboardLayout'
import { useDashboard } from './useDashboard'
import { EmptyState, ErrorState, MetricRow, Panel, ShareBar, SkeletonRows } from './kit'

const ICONS = {
  requests_created: <FileText size={18} />,
  delivered: <Send size={18} />,
  viewed: <Eye size={18} />,
  checkout_started: <ShoppingCart size={18} />,
  paid: <CheckCircle2 size={18} />,
  partial_or_failed: <XCircle size={18} />,
}

const TONES = { partial_or_failed: 'warning' } as const

export default function Collections() {
  const navigate = useNavigate()
  const { data, loading, error, retryable, period, setPeriod, reload } =
    useDashboard<CollectionsDashboard>('v1/dashboards/collections', 'last_7')

  const funnel = data?.panels.funnel

  return (
    <DashboardLayout
      title={<>Collections <span>Dashboard</span></>}
      subtitle="Collections / Payment Requests Command Centre"
      banner={{
        title: 'Turn payment requests into faster collections.',
        detail: 'Create, send, track and recover payments — all in one place.',
      }}
      period={period}
      onPeriodChange={setPeriod}
      description={data?.period ?? null}
    >
      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

      <MetricRow metrics={data?.metrics ?? []} loading={loading} icons={ICONS} tones={TONES} />

      <div className="pay-grid">
        <Panel
          title="Payment request conversion funnel"
          description="From the request being raised to the money arriving"
          span={2}
          action={
            funnel?.conversion_rate !== null && funnel?.conversion_rate !== undefined ? (
              <div style={{ textAlign: 'right' }}>
                <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>Conversion rate</span>
                <strong style={{ fontSize: 21, color: 'var(--pay-action)' }}>{percent(funnel.conversion_rate)}</strong>
              </div>
            ) : undefined
          }
          footnote={
            funnel
              ? `${moneyWhole(funnel.collected)} collected of ${moneyWhole(funnel.asked)} asked for. Delivery is counted where Pay sent the link or the page was opened — a link copied elsewhere cannot be seen.`
              : undefined
          }
        >
          {loading && !data ? <SkeletonRows rows={6} /> : <Funnel stages={funnel?.stages ?? []} />}
        </Panel>

        <Panel title="Source app performance" description="Whose requests get paid">
          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : (data?.panels.by_source ?? []).length === 0 ? (
            <EmptyState title="No requests in this period">Requests from Books, Billing, Sales and POS appear here.</EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  {/* "Requests" lives on the source's own line for the same
                      reason latency does on the overview: three columns do not
                      fit a quarter-width panel, and the count matters less than
                      the rate beside it. */}
                  <tr><th>Source</th><th>Success rate</th></tr>
                </thead>
                <tbody>
                  {data?.panels.by_source.map((row) => (
                    <tr key={row.source}>
                      <td>
                        <button type="button" className="pay-table__link" onClick={() => navigate(`/requests?source_app=${row.source}`)}>
                          {row.label}
                        </button>
                        <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>
                          {moneyWhole(row.collected)} of {moneyWhole(row.asked)} · {row.requests}{' '}
                          {row.requests === 1 ? 'request' : 'requests'}
                        </span>
                      </td>
                      <td style={{ minWidth: 92 }}><ShareBar value={row.success_rate} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <Panel title="Payment link and QR effectiveness" description="Which channel gets opened, and paid">
          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : (data?.panels.by_channel ?? []).length === 0 ? (
            <EmptyState title="No links sent yet">Send a payment link and its effectiveness appears here.</EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr><th>Channel</th><th className="num">Sent</th><th className="num">Views</th><th>Paid</th></tr>
                </thead>
                <tbody>
                  {data?.panels.by_channel.map((row) => (
                    <tr key={`${row.kind}-${row.channel}`}>
                      <td>{row.label}</td>
                      <td className="num">{row.sent}</td>
                      <td className="num">{row.views}</td>
                      <td style={{ minWidth: 130 }}><ShareBar value={row.success_rate} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <Panel
          title="Recovery queue"
          description="Failed payments whose request is still collectable"
          span={2}
          footnote="A request whose later attempt succeeded is not listed — chasing somebody who has already paid is the fastest way to lose them."
        >
          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : (data?.panels.recovery ?? []).length === 0 ? (
            <EmptyState title="Nothing to recover">No failed payment is still waiting on a collectable request.</EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr>
                    <th>Payer</th>
                    <th className="num">Outstanding</th>
                    <th className="num">Attempts</th>
                    <th>Last failure</th>
                    <th>Expires</th>
                  </tr>
                </thead>
                <tbody>
                  {data?.panels.recovery.map((row) => (
                    <tr key={row.payment_request_id}>
                      <td>
                        <button
                          type="button"
                          className="pay-table__link"
                          onClick={() => navigate(`/requests/${row.payment_request_id}`)}
                        >
                          {row.payer}
                        </button>
                        {row.reference && (
                          <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>{row.reference}</span>
                        )}
                      </td>
                      <td className="num">{money(row.outstanding, row.currency)}</td>
                      <td className="num">{row.attempts}</td>
                      <td style={{ maxWidth: 260, color: 'var(--pay-muted)', fontSize: 12 }}>
                        {row.last_method && <span className="pay-tag pay-tag--neutral" style={{ marginRight: 6 }}>{row.last_method}</span>}
                        {row.last_failure ?? 'Not stated by the provider'}
                      </td>
                      <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>
                        {row.expires_at
                          ? relativeDays(Math.ceil((new Date(row.expires_at).getTime() - Date.now()) / 86400000))
                          : 'No expiry'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <Panel title="Collect" description="Raise a request, or record money that came in another way">
          <div style={{ display: 'grid', gap: 10 }}>
            <button
              type="button"
              className="pay-button pay-button--block"
              disabled={!data?.panels.can_create}
              onClick={() => navigate('/requests/new')}
            >
              <FileText size={16} aria-hidden /> Create payment request
            </button>

            <button
              type="button"
              className="pay-button pay-button--soft pay-button--block"
              disabled={!data?.panels.can_record_external}
              onClick={() => navigate('/payments/record-external')}
            >
              Record a payment made outside Pay
            </button>

            {!data?.panels.can_record_external && !loading && (
              <p style={{ margin: 0, color: 'var(--pay-muted)', fontSize: 11.5 }}>
                Recording an external payment needs the right Pay role.
              </p>
            )}
          </div>
        </Panel>
      </div>
    </DashboardLayout>
  )
}
