/**
 * The payments list, and one payment in full.
 *
 * TECHNICAL DETAIL IS ABSENT UNLESS THE SERVER SENT IT. A provider payment id
 * is enough to look a customer up in the provider's own dashboard, so it is
 * permission-gated on the server and this screen simply renders whatever came
 * back — it does not decide who may see what, and it must not.
 */

import { useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, RotateCcw } from 'lucide-react'
import { useApi } from '../hooks/useApi'
import { api } from '../services/api'
import { date, dateTime, humanise, money } from '../services/format'
import type { PaymentRow, RefundRow } from '../services/types'
import { Badge, EmptyState, ErrorState, Panel, SkeletonRows, StatusBadge } from '../dashboards/kit'

const STATUSES = ['SUCCESS', 'CAPTURED', 'PENDING', 'AUTHORIZED', 'FAILED', 'CANCELLED', 'INITIATED', 'CREATED']
const METHODS = ['UPI', 'CARD', 'NETBANKING', 'WALLET', 'EMI', 'PAYLATER', 'OTHER']
const SETTLEMENT = ['PENDING', 'SETTLED', 'PARTIAL', 'NOT_APPLICABLE']

export function PaymentsList() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [offset, setOffset] = useState(0)

  const filters = {
    q: params.get('q') ?? '',
    status: params.get('status') ?? '',
    payment_method: params.get('payment_method') ?? '',
    provider_mode: params.get('provider_mode') ?? '',
    source_app: params.get('source_app') ?? '',
    settlement_status: params.get('settlement_status') ?? '',
    from: params.get('from') ?? '',
    to: params.get('to') ?? '',
  }

  const query = { ...filters, limit: 50, offset }
  const key = JSON.stringify(query)

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.list<PaymentRow>('v1/payments', query, signal),
    [key],
  )

  function setFilter(name: string, value: string) {
    const next = new URLSearchParams(params)
    if (value === '') next.delete(name)
    else next.set(name, value)
    setParams(next, { replace: true })
    setOffset(0)
  }

  const total = data?.meta.total ?? 0

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Payments</h1>
          <p>
            {loading && !data ? 'Loading…' : `${total.toLocaleString('en-IN')} payment${total === 1 ? '' : 's'}`}
            {filters.q && ` matching “${filters.q}”`}
          </p>
        </div>
      </section>

      <Panel
        title="Filters"
        description="Every filter narrows the list on the server, so a page is always one query"
        action={
          Object.values(filters).some(Boolean) ? (
            <button type="button" className="pay-button pay-button--ghost pay-button--small" onClick={() => setParams({}, { replace: true })}>
              Clear
            </button>
          ) : undefined
        }
      >
        <div className="pay-row">
          <label className="pay-field">
            <span className="pay-field__label">Search</span>
            <input
              className="pay-input"
              type="search"
              defaultValue={filters.q}
              placeholder="Payer, reference, payment id, provider id"
              onBlur={(event) => setFilter('q', event.target.value.trim())}
              onKeyDown={(event) => {
                if (event.key === 'Enter') setFilter('q', (event.target as HTMLInputElement).value.trim())
              }}
            />
          </label>

          <Select label="Status" value={filters.status} options={STATUSES} onChange={(v) => setFilter('status', v)} />
          <Select label="Method" value={filters.payment_method} options={METHODS} onChange={(v) => setFilter('payment_method', v)} />
          <Select label="Mode" value={filters.provider_mode} options={['DIRECT', 'MANAGED', 'EXTERNAL']} onChange={(v) => setFilter('provider_mode', v)} />
          <Select label="Settlement" value={filters.settlement_status} options={SETTLEMENT} onChange={(v) => setFilter('settlement_status', v)} />

          <label className="pay-field">
            <span className="pay-field__label">From</span>
            <input className="pay-input" type="date" value={filters.from} onChange={(event) => setFilter('from', event.target.value)} />
          </label>

          <label className="pay-field">
            <span className="pay-field__label">To</span>
            <input className="pay-input" type="date" value={filters.to} onChange={(event) => setFilter('to', event.target.value)} />
          </label>
        </div>
      </Panel>

      <div className="pay-grid" style={{ marginTop: 14 }}>
        <Panel title="Payments" span={4}>
          {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

          {loading && !data ? (
            <SkeletonRows rows={8} />
          ) : (data?.data ?? []).length === 0 ? (
            <EmptyState title="No payments match">
              {Object.values(filters).some(Boolean)
                ? 'Try widening the filters.'
                : 'Payments appear here the moment one comes in.'}
            </EmptyState>
          ) : (
            <>
              <div className="pay-table-wrap">
                <table className="pay-table">
                  <thead>
                    <tr>
                      <th>Payment</th>
                      <th>Date</th>
                      <th>Payer</th>
                      <th>Source</th>
                      <th className="num">Amount</th>
                      <th>Method</th>
                      <th>Provider</th>
                      <th>Status</th>
                      <th>Settlement</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.map((row) => (
                      <tr key={row.payment_id}>
                        <td>
                          <button type="button" className="pay-table__link" onClick={() => navigate(`/payments/${row.payment_id}`)}>
                            {row.payment_id.split('-').slice(-1)[0]}
                          </button>
                        </td>
                        <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>
                          {row.paid_at ? dateTime(row.paid_at) : dateTime(row.created_at)}
                        </td>
                        <td>
                          {row.payer ?? '—'}
                          {row.reference && (
                            <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>{row.reference}</span>
                          )}
                        </td>
                        <td><span className="pay-tag pay-tag--neutral">{row.source ?? 'PAY'}</span></td>
                        <td className="num">{money(row.amount, row.currency)}</td>
                        <td>
                          {row.method ?? '—'}
                          {row.method_detail && (
                            <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11 }}>{row.method_detail}</span>
                          )}
                        </td>
                        <td>{row.provider_mode === 'MANAGED' ? 'Aicountly Managed' : (row.provider ?? '—')}</td>
                        <td><StatusBadge status={row.status} label={row.status_label} /></td>
                        <td>
                          {row.settlement_status === 'NOT_APPLICABLE' ? (
                            <span style={{ color: 'var(--pay-muted)', fontSize: 12 }}>Not applicable</span>
                          ) : (
                            <StatusBadge status={row.settlement_status === 'SETTLED' ? 'SETTLED' : 'PENDING'} label={humanise(row.settlement_status)} />
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <Pager total={total} offset={offset} limit={50} onChange={setOffset} />
            </>
          )}
        </Panel>
      </div>
    </>
  )
}

// ---------------------------------------------------------------------------

export function PaymentDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.one<PaymentRow & {
      payer: { name: string; email: string | null; mobile: string | null; kind: string; source_app: string | null } | null
      request: { payment_request_id: string; reference: string | null; amount: number; outstanding: number; status: string } | null
      refunds: RefundRow[]
      refundable: number
      settlement: { settlement_id: string; provider_name: string; settlement_date: string | null; expected_net: number | null } | null
      timeline: Array<{ at: string; label: string; detail: string; tone: string }>
      webhooks: Array<{ event_id: string; provider: string; provider_event: string | null; event: string | null; status: string; signature_valid: boolean; processing_ms: number | null; received_at: string }> | null
      source_sync: Array<{ event_id: string; event: string; event_label: string; target: string; status: string; attempts: string; last_error: string | null; delivered_at: string | null; can_retry: boolean }>
      can_refund: boolean
    }>(`v1/payments/${id}`, undefined, signal),
    [id],
  )

  const payment = data?.data

  return (
    <>
      <section className="pay-heading">
        <div>
          <button type="button" className="pay-button pay-button--ghost pay-button--small" onClick={() => navigate('/payments')} style={{ marginBottom: 10 }}>
            <ArrowLeft size={15} aria-hidden /> Payments
          </button>
          <h1>{payment ? money(payment.amount, payment.currency) : 'Payment'}</h1>
          <p>
            {payment ? (
              <>
                {payment.status_label} · {payment.paid_at ? dateTime(payment.paid_at) : dateTime(payment.created_at)}
              </>
            ) : (
              'Loading…'
            )}
          </p>
        </div>

        {payment?.can_refund && payment.refundable > 0 && (
          <button type="button" className="pay-button pay-button--soft" onClick={() => navigate(`/refunds/new?payment=${payment.payment_id}`)}>
            <RotateCcw size={16} aria-hidden /> Refund up to {money(payment.refundable, payment.currency)}
          </button>
        )}
      </section>

      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

      {loading && !payment ? (
        <SkeletonRows rows={8} />
      ) : payment ? (
        <div className="pay-grid">
          <Panel title="Payment" span={2}>
            <Facts
              rows={[
                ['Payment id', payment.payment_id],
                ['Status', <StatusBadge key="s" status={payment.status} label={payment.status_label} />],
                ['Amount', money(payment.amount, payment.currency)],
                ['Refunded', payment.refunded > 0 ? money(payment.refunded, payment.currency) : '—'],
                ['Method', payment.method_detail ? `${payment.method} · ${payment.method_detail}` : (payment.method ?? '—')],
                ['Provider', payment.provider_mode === 'MANAGED' ? 'Aicountly Managed' : (payment.provider ?? '—')],
                ['Mode', payment.provider_mode],
                ['Channel', humanise(payment.channel)],
                ['Attempt', `#${payment.attempt_no}`],
                ...(payment.failure_reason ? [['Failure', payment.failure_reason] as [string, string]] : []),
              ]}
            />
          </Panel>

          <Panel title="Payer">
            {payment.payer ? (
              <Facts
                rows={[
                  ['Name', payment.payer.name],
                  ['Email', payment.payer.email ?? '—'],
                  ['Mobile', payment.payer.mobile ?? '—'],
                  ['Known as', payment.payer.source_app ? `${payment.payer.source_app} customer` : 'Pay payer'],
                ]}
              />
            ) : (
              <EmptyState title="No payer recorded">A payment against a static QR has no identity to record.</EmptyState>
            )}
          </Panel>

          <Panel title="Request">
            {payment.request ? (
              <>
                <Facts
                  rows={[
                    ['Reference', payment.request.reference ?? '—'],
                    ['Asked for', money(payment.request.amount, payment.currency)],
                    ['Outstanding', money(payment.request.outstanding, payment.currency)],
                    ['Status', <StatusBadge key="rs" status={payment.request.status} />],
                  ]}
                />
                <button
                  type="button"
                  className="pay-button pay-button--ghost pay-button--small pay-button--block"
                  style={{ marginTop: 10 }}
                  onClick={() => navigate(`/requests/${payment.request!.payment_request_id}`)}
                >
                  Open the request
                </button>
              </>
            ) : (
              <EmptyState title="No request behind this">The provider reported a payment Pay never raised a request for.</EmptyState>
            )}
          </Panel>

          <Panel title="What happened" description="Built from this payment's own timestamps" span={2}>
            {payment.timeline.length === 0 ? (
              <EmptyState title="Nothing recorded yet" />
            ) : (
              payment.timeline.map((step, index) => (
                <div key={index} className="pay-action-row" style={{ cursor: 'default' }}>
                  <span style={{ minWidth: 0 }}>
                    <span className="pay-action-row__label">{step.label}</span>
                    <span className="pay-action-row__detail">{step.detail}</span>
                  </span>
                  <span style={{ color: 'var(--pay-muted)', fontSize: 12, whiteSpace: 'nowrap' }}>{dateTime(step.at)}</span>
                </div>
              ))
            )}
          </Panel>

          <Panel title="Source sync" description="What Pay owed the app that raised this">
            {payment.source_sync.length === 0 ? (
              <EmptyState title="Nothing to send">This payment was raised in Pay, so there is no other app to tell.</EmptyState>
            ) : (
              payment.source_sync.map((event) => (
                <div key={event.event_id} className="pay-action-row" style={{ cursor: 'default' }}>
                  <span style={{ minWidth: 0 }}>
                    <span className="pay-action-row__label">{event.event_label}</span>
                    <span className="pay-action-row__detail">
                      {event.target} · {event.attempts} attempts
                      {event.last_error && ` · ${event.last_error}`}
                    </span>
                  </span>
                  <Badge tone={event.status === 'DELIVERED' ? 'success' : event.status === 'EXHAUSTED' ? 'danger' : 'warning'}>
                    {humanise(event.status)}
                  </Badge>
                </div>
              ))
            )}
          </Panel>

          <Panel title="Refunds">
            {payment.refunds.length === 0 ? (
              <EmptyState title="No refunds">Nothing has been sent back against this payment.</EmptyState>
            ) : (
              payment.refunds.map((refund) => (
                <div key={refund.refund_id} className="pay-action-row" style={{ cursor: 'default' }}>
                  <span style={{ minWidth: 0 }}>
                    <span className="pay-action-row__label">{money(refund.amount, refund.currency)}</span>
                    <span className="pay-action-row__detail">{refund.reason}</span>
                  </span>
                  <StatusBadge status={refund.status} label={refund.status_label} />
                </div>
              ))
            )}
          </Panel>

          <Panel title="Settlement">
            {payment.settlement ? (
              <Facts
                rows={[
                  ['Provider', payment.settlement.provider_name],
                  ['Date', date(payment.settlement.settlement_date)],
                  ['Expected net', payment.settlement.expected_net === null ? '—' : money(payment.settlement.expected_net)],
                ]}
              />
            ) : payment.settlement_status === 'NOT_APPLICABLE' ? (
              <EmptyState title="Will not settle">
                This payment was recorded outside Pay, so no provider will ever pay it out.
              </EmptyState>
            ) : (
              <EmptyState title="Not settled yet">
                It has not appeared in a provider settlement batch.
              </EmptyState>
            )}
          </Panel>

          {/* Only present when the server decided this user may see it. */}
          {payment.webhooks && (
            <Panel title="Provider callbacks" description="Technical detail, for whoever is debugging" span={2}>
              {payment.webhooks.length === 0 ? (
                <EmptyState title="No callbacks recorded" />
              ) : (
                <div className="pay-table-wrap">
                  <table className="pay-table">
                    <thead>
                      <tr><th>Received</th><th>Provider event</th><th>Read as</th><th>Signature</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                      {payment.webhooks.map((hook) => (
                        <tr key={hook.event_id}>
                          <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>{dateTime(hook.received_at)}</td>
                          <td>{hook.provider_event ?? '—'}</td>
                          <td>{hook.event ?? '—'}</td>
                          <td>
                            <Badge tone={hook.signature_valid ? 'success' : 'danger'}>
                              {hook.signature_valid ? 'Verified' : 'Failed'}
                            </Badge>
                          </td>
                          <td>{humanise(hook.status)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Panel>
          )}

          {payment.technical && (
            <Panel title="Provider references">
              <Facts
                rows={[
                  ['Provider payment id', payment.technical.provider_payment_id ?? '—'],
                  ['Provider order id', payment.technical.provider_order_id ?? '—'],
                  ['Failure code', payment.technical.failure_code ?? '—'],
                  ['Initiated', payment.technical.initiated_at ? dateTime(payment.technical.initiated_at) : '—'],
                  ['Captured', payment.technical.captured_at ? dateTime(payment.technical.captured_at) : '—'],
                ]}
              />
            </Panel>
          )}
        </div>
      ) : null}
    </>
  )
}

// ---------------------------------------------------------------------------
// Shared bits
// ---------------------------------------------------------------------------

export function Facts({ rows }: { rows: Array<[string, React.ReactNode]> }) {
  return (
    <dl style={{ margin: 0, display: 'grid', gap: 0 }}>
      {rows.map(([label, value], index) => (
        <div
          key={index}
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 14,
            padding: '9px 0',
            borderBottom: index === rows.length - 1 ? 0 : '1px solid #f1f5f2',
          }}
        >
          <dt style={{ color: 'var(--pay-muted)', fontSize: 12.5 }}>{label}</dt>
          <dd style={{ margin: 0, fontWeight: 600, fontSize: 13, textAlign: 'right', wordBreak: 'break-word' }}>{value}</dd>
        </div>
      ))}
    </dl>
  )
}

export function Select({
  label,
  value,
  options,
  onChange,
  labels,
}: {
  label: string
  value: string
  options: string[]
  onChange: (value: string) => void
  labels?: Record<string, string>
}) {
  return (
    <label className="pay-field">
      <span className="pay-field__label">{label}</span>
      <select className="pay-input" value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">All</option>
        {options.map((option) => (
          <option key={option} value={option}>
            {labels?.[option] ?? humanise(option)}
          </option>
        ))}
      </select>
    </label>
  )
}

export function Pager({ total, offset, limit, onChange }: { total: number; offset: number; limit: number; onChange: (offset: number) => void }) {
  if (total <= limit) return null

  const page = Math.floor(offset / limit) + 1
  const pages = Math.ceil(total / limit)

  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginTop: 14 }}>
      <span style={{ color: 'var(--pay-muted)', fontSize: 12.5 }}>
        Showing {offset + 1}–{Math.min(offset + limit, total)} of {total.toLocaleString('en-IN')}
      </span>
      <div style={{ display: 'flex', gap: 8 }}>
        <button
          type="button"
          className="pay-button pay-button--ghost pay-button--small"
          disabled={page === 1}
          onClick={() => onChange(Math.max(0, offset - limit))}
        >
          Previous
        </button>
        <button
          type="button"
          className="pay-button pay-button--ghost pay-button--small"
          disabled={page >= pages}
          onClick={() => onChange(offset + limit)}
        >
          Next
        </button>
      </div>
    </div>
  )
}
