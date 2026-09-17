/**
 * The remaining module screens.
 *
 * Nine lists that share one shape — filter, table, act — so they share one file
 * rather than nine near-identical ones. Anything with real behaviour behind it
 * (raising a request, recording an external payment, connecting a provider)
 * lives in its own file.
 */

import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { AlertTriangle, Check, Copy, RefreshCw, X } from 'lucide-react'
import { useAction, useApi } from '../hooks/useApi'
import { usePay } from '../context/PayContext'
import { api } from '../services/api'
import { date, dateTime, humanise, money, relativeDays } from '../services/format'
import type {
  CustomerRow,
  DisputeRow,
  LinkRow,
  MandateRow,
  ReconciliationCase,
  RefundRow,
  SettlementRow,
} from '../services/types'
import { Badge, EmptyState, ErrorState, Panel, SkeletonRows, StatusBadge } from '../dashboards/kit'
import { Pager, Select } from './Payments'

// ---------------------------------------------------------------------- links

export function Links() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [offset, setOffset] = useState(0)
  const [copied, setCopied] = useState<string | null>(null)

  const kind = params.get('kind') ?? ''
  const query = { kind, expiring: params.get('expiring') ?? '', limit: 50, offset }

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.list<LinkRow>('v1/links', query, signal),
    [JSON.stringify(query)],
  )

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Payment links &amp; QR</h1>
          <p>Every way a request has been sent out. One request can have several — they all collect against the same balance.</p>
        </div>
      </section>

      <nav className="pay-switcher" aria-label="Link kind">
        {[
          { key: '', label: 'All' },
          { key: 'LINK', label: 'Payment links' },
          { key: 'QR', label: 'QR payments' },
        ].map((tab) => (
          <a
            key={tab.key}
            href="#"
            className={kind === tab.key ? 'is-active' : ''}
            onClick={(event) => {
              event.preventDefault()
              const next = new URLSearchParams(params)
              if (tab.key === '') next.delete('kind')
              else next.set('kind', tab.key)
              setParams(next, { replace: true })
              setOffset(0)
            }}
          >
            {tab.label}
          </a>
        ))}
      </nav>

      <div className="pay-grid">
        <Panel title={kind === 'QR' ? 'QR payments' : kind === 'LINK' ? 'Payment links' : 'Links and QR codes'} span={4}>
          {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

          {loading && !data ? (
            <SkeletonRows rows={6} />
          ) : (data?.data ?? []).length === 0 ? (
            <EmptyState title="No links yet">Open a payment request and create a link or a QR code from it.</EmptyState>
          ) : (
            <>
              <div className="pay-table-wrap">
                <table className="pay-table">
                  <thead>
                    <tr>
                      <th>Reference</th>
                      <th>Payer</th>
                      <th>Channel</th>
                      <th className="num">Amount</th>
                      <th className="num">Outstanding</th>
                      <th className="num">Views</th>
                      <th>Status</th>
                      <th>Expires</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.map((link) => (
                      <tr key={link.link_id}>
                        <td>
                          <button
                            type="button"
                            className="pay-table__link"
                            onClick={() => navigate(`/requests/${link.payment_request_id}`)}
                          >
                            {link.reference ?? link.link_id.split('-').slice(-1)[0]}
                          </button>
                        </td>
                        <td>{link.payer ?? '—'}</td>
                        <td>
                          {humanise(link.channel)}
                          {link.reusable && <span className="pay-tag" style={{ marginLeft: 6 }}>Reusable</span>}
                        </td>
                        <td className="num">{link.amount === undefined ? '—' : money(link.amount)}</td>
                        <td className="num">{link.outstanding === undefined ? '—' : money(link.outstanding)}</td>
                        <td className="num">{link.views}</td>
                        <td><StatusBadge status={link.status} /></td>
                        <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>
                          {link.expires_at ? date(link.expires_at) : 'No expiry'}
                        </td>
                        <td>
                          <button
                            type="button"
                            className="pay-button pay-button--ghost pay-button--small"
                            onClick={async () => {
                              try {
                                await navigator.clipboard.writeText(link.url)
                                setCopied(link.link_id)
                                window.setTimeout(() => setCopied(null), 2000)
                              } catch {
                                /* clipboard refused; the URL is still on the request screen */
                              }
                            }}
                          >
                            <Copy size={13} aria-hidden /> {copied === link.link_id ? 'Copied' : 'Copy'}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <Pager total={data?.meta.total ?? 0} offset={offset} limit={50} onChange={setOffset} />
            </>
          )}
        </Panel>
      </div>
    </>
  )
}

// ------------------------------------------------------------------ customers

export function Customers() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [offset, setOffset] = useState(0)

  const query = { q: params.get('q') ?? '', filter: params.get('filter') ?? '', limit: 50, offset }

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.list<CustomerRow>('v1/customers', query, signal),
    [JSON.stringify(query)],
  )

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Customers</h1>
          {/* Said on the screen, so nobody mistakes this for the customer master
              and starts editing here. */}
          <p>The people who have paid you through Pay. Their master record lives in the app that owns them.</p>
        </div>
      </section>

      <div className="pay-grid">
        <Panel title="Payers" span={4}>
          <div className="pay-row" style={{ marginBottom: 14 }}>
            <label className="pay-field" style={{ marginBottom: 0 }}>
              <span className="pay-field__label">Search</span>
              <input
                className="pay-input"
                type="search"
                defaultValue={query.q}
                placeholder="Name, email or mobile"
                onBlur={(event) => {
                  const next = new URLSearchParams(params)
                  if (event.target.value.trim() === '') next.delete('q')
                  else next.set('q', event.target.value.trim())
                  setParams(next, { replace: true })
                }}
              />
            </label>
          </div>

          {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

          {loading && !data ? (
            <SkeletonRows rows={6} />
          ) : (data?.data ?? []).length === 0 ? (
            <EmptyState title="No payers yet">Somebody appears here the first time they pay you.</EmptyState>
          ) : (
            <>
              <div className="pay-table-wrap">
                <table className="pay-table">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Known from</th>
                      <th className="num">Payments</th>
                      <th className="num">Total paid</th>
                      <th>Prefers</th>
                      <th>Last paid</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.map((payer) => (
                      <tr key={payer.payer_id}>
                        <td>
                          <button type="button" className="pay-table__link" onClick={() => navigate(`/customers/${payer.payer_id}`)}>
                            {payer.name}
                          </button>
                          {payer.mobile && (
                            <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>{payer.mobile}</span>
                          )}
                        </td>
                        <td>
                          {payer.source_app ? (
                            <span className="pay-tag pay-tag--neutral">{payer.source_app}</span>
                          ) : (
                            <span style={{ color: 'var(--pay-muted)', fontSize: 12 }}>Pay only</span>
                          )}
                        </td>
                        <td className="num">{payer.payment_count}</td>
                        <td className="num">{money(payer.total_paid)}</td>
                        <td>{payer.preferred_method ?? '—'}</td>
                        <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>{date(payer.last_paid_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <Pager total={data?.meta.total ?? 0} offset={offset} limit={50} onChange={setOffset} />
            </>
          )}
        </Panel>
      </div>
    </>
  )
}

// -------------------------------------------------------------------- refunds

export function Refunds() {
  const navigate = useNavigate()
  const { can } = usePay()
  const [params, setParams] = useSearchParams()
  const [offset, setOffset] = useState(0)
  const [tab, setTab] = useState<'refunds' | 'disputes'>('refunds')

  const query = { status: params.get('status') ?? '', limit: 50, offset }

  const refunds = useApi(
    (signal) => api.list<RefundRow>('v1/refunds', query, signal),
    [JSON.stringify(query), tab],
    tab === 'refunds',
  )

  const disputes = useApi(
    (signal) => api.list<DisputeRow>('v1/disputes', { limit: 50, offset }, signal),
    [offset, tab],
    tab === 'disputes',
  )

  const approve = useAction(async (id: string) => {
    await api.post(`v1/refunds/${id}/approve`)
    refunds.reload()
  })

  const reject = useAction(async (id: string, reason: string) => {
    await api.post(`v1/refunds/${id}/reject`, { reason })
    refunds.reload()
  })

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Refunds &amp; disputes</h1>
          <p>Money going back out. A refund cannot be undone, which is why asking and sending are separate rights.</p>
        </div>
      </section>

      <nav className="pay-switcher" aria-label="Section">
        <a href="#" className={tab === 'refunds' ? 'is-active' : ''} onClick={(e) => { e.preventDefault(); setTab('refunds') }}>
          Refunds
        </a>
        <a href="#" className={tab === 'disputes' ? 'is-active' : ''} onClick={(e) => { e.preventDefault(); setTab('disputes') }}>
          Disputes &amp; chargebacks
        </a>
      </nav>

      <div className="pay-grid">
        {tab === 'refunds' ? (
          <Panel
            title="Refunds"
            span={4}
            action={
              <Select
                label=""
                value={query.status}
                options={['REQUESTED', 'APPROVED', 'PROCESSING', 'REFUNDED', 'FAILED', 'REJECTED']}
                onChange={(value) => {
                  const next = new URLSearchParams(params)
                  if (value === '') next.delete('status')
                  else next.set('status', value)
                  setParams(next, { replace: true })
                }}
              />
            }
          >
            {refunds.error && <ErrorState message={refunds.error} onRetry={refunds.reload} retryable={refunds.retryable} />}
            {approve.error && <ErrorState message={approve.error} />}
            {reject.error && <ErrorState message={reject.error} />}

            {refunds.loading && !refunds.data ? (
              <SkeletonRows rows={6} />
            ) : (refunds.data?.data ?? []).length === 0 ? (
              <EmptyState title="No refunds">Nothing has been sent back.</EmptyState>
            ) : (
              <>
                <div className="pay-table-wrap">
                  <table className="pay-table">
                    <thead>
                      <tr>
                        <th>Requested</th>
                        <th>Payer</th>
                        <th className="num">Amount</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Requested by</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {refunds.data?.data.map((refund) => (
                        <tr key={refund.refund_id}>
                          <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>{dateTime(refund.requested_at)}</td>
                          <td>
                            {refund.payment_id ? (
                              <button type="button" className="pay-table__link" onClick={() => navigate(`/payments/${refund.payment_id}`)}>
                                {refund.payer ?? 'View payment'}
                              </button>
                            ) : (
                              (refund.payer ?? '—')
                            )}
                          </td>
                          <td className="num">{money(refund.amount, refund.currency)}</td>
                          <td style={{ maxWidth: 240, fontSize: 12.5 }}>{refund.reason}</td>
                          <td><StatusBadge status={refund.status} label={refund.status_label} /></td>
                          <td style={{ fontSize: 12, color: 'var(--pay-muted)' }}>{refund.requested_by}</td>
                          <td>
                            {refund.status === 'REQUESTED' && can('refunds.approve') && (
                              <div style={{ display: 'flex', gap: 6 }}>
                                <button
                                  type="button"
                                  className="pay-button pay-button--small"
                                  disabled={approve.busy}
                                  onClick={() => approve.run(refund.refund_id)}
                                >
                                  <Check size={13} aria-hidden /> Approve
                                </button>
                                <button
                                  type="button"
                                  className="pay-button pay-button--ghost pay-button--small"
                                  disabled={reject.busy}
                                  onClick={() => {
                                    const reason = window.prompt('Why is this refund being rejected?')
                                    if (reason && reason.trim() !== '') reject.run(refund.refund_id, reason.trim())
                                  }}
                                >
                                  <X size={13} aria-hidden />
                                </button>
                              </div>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <Pager total={refunds.data?.meta.total ?? 0} offset={offset} limit={50} onChange={setOffset} />
              </>
            )}
          </Panel>
        ) : (
          <Panel title="Disputes and chargebacks" span={4} footnote="Reason codes are the card network's own words — they are what you have to quote when you respond.">
            {disputes.error && <ErrorState message={disputes.error} onRetry={disputes.reload} retryable={disputes.retryable} />}

            {disputes.loading && !disputes.data ? (
              <SkeletonRows rows={5} />
            ) : (disputes.data?.data ?? []).length === 0 ? (
              <EmptyState title="No disputes">Nothing has been charged back.</EmptyState>
            ) : (
              <div className="pay-table-wrap">
                <table className="pay-table">
                  <thead>
                    <tr>
                      <th>Opened</th>
                      <th className="num">Amount</th>
                      <th>Reason</th>
                      <th>Evidence due</th>
                      <th>Status</th>
                      <th>Recorded</th>
                    </tr>
                  </thead>
                  <tbody>
                    {disputes.data?.data.map((dispute) => (
                      <tr key={dispute.dispute_id}>
                        <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>{date(dispute.opened_at)}</td>
                        <td className="num">{money(dispute.amount, dispute.currency)}</td>
                        <td style={{ maxWidth: 260, fontSize: 12.5 }}>
                          {dispute.reason_code && <span className="pay-tag pay-tag--neutral" style={{ marginRight: 6 }}>{dispute.reason_code}</span>}
                          {dispute.reason ?? '—'}
                        </td>
                        <td>
                          {/* The number that decides whether this is winnable. */}
                          {dispute.days_to_respond === null ? (
                            '—'
                          ) : (
                            <Badge tone={dispute.days_to_respond <= 2 ? 'danger' : dispute.days_to_respond <= 5 ? 'warning' : 'neutral'}>
                              {relativeDays(dispute.days_to_respond)}
                            </Badge>
                          )}
                        </td>
                        <td><StatusBadge status={dispute.status} label={dispute.status_label} /></td>
                        <td style={{ fontSize: 12, color: 'var(--pay-muted)' }}>
                          {dispute.source === 'PROVIDER' ? 'From the provider' : 'By hand'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
        )}
      </div>
    </>
  )
}

// ------------------------------------------------------------------- mandates

export function Mandates() {
  const [offset, setOffset] = useState(0)

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.list<MandateRow>('v1/mandates', { limit: 50, offset }, signal),
    [offset],
  )

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Recurring &amp; mandates</h1>
          {/* The boundary, stated where somebody would otherwise go looking for
              plan management. */}
          <p>Pay holds the payer&rsquo;s authority to be debited. The plan, its price and its renewal belong to the app that raised the subscription.</p>
        </div>
      </section>

      <div className="pay-grid">
        <Panel title="Mandates" span={4}>
          {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : (data?.data ?? []).length === 0 ? (
            <EmptyState title="No mandates yet">
              A mandate appears when a payer authorises a recurring debit through one of your providers.
            </EmptyState>
          ) : (
            <>
              <div className="pay-table-wrap">
                <table className="pay-table">
                  <thead>
                    <tr>
                      <th>Payer</th>
                      <th>Type</th>
                      <th className="num">Up to</th>
                      <th>Frequency</th>
                      <th>Last debit</th>
                      <th>Status</th>
                      <th>Source</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.map((mandate) => (
                      <tr key={mandate.mandate_id}>
                        <td>{mandate.payer ?? '—'}</td>
                        <td>{humanise(mandate.type)}</td>
                        <td className="num">{mandate.max_amount === null ? '—' : money(mandate.max_amount, mandate.currency)}</td>
                        <td>{mandate.frequency ? humanise(mandate.frequency) : '—'}</td>
                        <td>
                          {mandate.last_debit_at ? date(mandate.last_debit_at) : '—'}
                          {mandate.consecutive_failures > 0 && (
                            <span style={{ display: 'block', color: 'var(--pay-danger)', fontSize: 11.5 }}>
                              {mandate.consecutive_failures} failed in a row
                            </span>
                          )}
                        </td>
                        <td><StatusBadge status={mandate.status} label={mandate.status_label} /></td>
                        <td>
                          {mandate.source_app ? <span className="pay-tag pay-tag--neutral">{mandate.source_app}</span> : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <Pager total={data?.meta.total ?? 0} offset={offset} limit={50} onChange={setOffset} />
            </>
          )}
        </Panel>
      </div>
    </>
  )
}

// ---------------------------------------------------------------- settlements

export function SettlementsList() {
  const [offset, setOffset] = useState(0)
  const { can } = usePay()

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.list<SettlementRow>('v1/settlements', { limit: 50, offset }, signal),
    [offset],
  )

  const importNow = useAction(async () => {
    await api.post('v1/settlements/import')
    reload()
  })

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Settlements</h1>
          <p>Every batch a provider said they would send, and whether it reached your bank.</p>
        </div>

        {can('reconciliation.manage') && (
          <button type="button" className="pay-button pay-button--soft" disabled={importNow.busy} onClick={() => importNow.run()}>
            <RefreshCw size={16} aria-hidden /> {importNow.busy ? 'Pulling…' : 'Pull from providers'}
          </button>
        )}
      </section>

      <div className="pay-grid">
        <Panel title="Settlement batches" span={4} footnote="Fees and tax are exactly what each provider reported. Pay never computes a rate of its own.">
          {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}
          {importNow.error && <ErrorState message={importNow.error} />}

          {loading && !data ? (
            <SkeletonRows rows={6} />
          ) : (data?.data ?? []).length === 0 ? (
            <EmptyState title="No settlements yet">
              Settlements appear after your first provider settlement, or when you pull them.
            </EmptyState>
          ) : (
            <>
              <div className="pay-table-wrap">
                <table className="pay-table">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Provider</th>
                      <th className="num">Gross</th>
                      <th className="num">Fees</th>
                      <th className="num">Expected</th>
                      <th className="num">Credited</th>
                      <th className="num">Difference</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.map((row) => (
                      <tr key={row.settlement_id}>
                        <td style={{ whiteSpace: 'nowrap' }}>{date(row.settlement_date)}</td>
                        <td><strong style={{ fontSize: 13 }}>{row.provider_name}</strong></td>
                        <td className="num">{money(row.gross, row.currency)}</td>
                        <td className="num">
                          {money((row.provider_fee ?? 0) + (row.provider_tax ?? 0), row.currency)}
                        </td>
                        <td className="num">{money(row.expected_net, row.currency)}</td>
                        <td className="num">{row.settled_net === null ? '—' : money(row.settled_net, row.currency)}</td>
                        <td className="num" style={{ color: (row.difference ?? 0) !== 0 ? 'var(--pay-danger)' : undefined }}>
                          {row.difference === null || row.difference === 0 ? '—' : money(row.difference, row.currency)}
                        </td>
                        <td><StatusBadge status={row.status} label={row.status_label} /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <Pager total={data?.meta.total ?? 0} offset={offset} limit={50} onChange={setOffset} />
            </>
          )}
        </Panel>
      </div>
    </>
  )
}

// ------------------------------------------------------------- reconciliation

export function Reconciliation() {
  const { can } = usePay()
  const [params, setParams] = useSearchParams()
  const [offset, setOffset] = useState(0)

  const query = { kind: params.get('kind') ?? '', status: params.get('status') ?? '', limit: 50, offset }

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.list<ReconciliationCase>('v1/reconciliation', query, signal),
    [JSON.stringify(query)],
  )

  const resolve = useAction(async (id: string, status: string, note: string) => {
    await api.post(`v1/reconciliation/${id}/resolve`, { status, note })
    reload()
  })

  const run = useAction(async () => {
    await api.post('v1/reconciliation/run')
    reload()
  })

  const summary = (data?.meta.summary ?? []) as Array<{ kind: string; label: string; count: number; amount: number; severity: string }>

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Reconciliation</h1>
          <p>Differences between what was collected, what a provider said they would send, and what the bank credited.</p>
        </div>

        {can('reconciliation.manage') && (
          <button type="button" className="pay-button pay-button--soft" disabled={run.busy} onClick={() => run.run()}>
            <RefreshCw size={16} aria-hidden /> {run.busy ? 'Checking…' : 'Run the reconciler'}
          </button>
        )}
      </section>

      <div className="pay-grid">
        <Panel title="By kind" description="Each has a different fix">
          {summary.length === 0 ? (
            <EmptyState title="Everything reconciles">Nothing is outstanding.</EmptyState>
          ) : (
            summary.map((row) => (
              <button
                key={row.kind}
                type="button"
                className="pay-action-row"
                onClick={() => {
                  const next = new URLSearchParams(params)
                  next.set('kind', row.kind)
                  setParams(next, { replace: true })
                  setOffset(0)
                }}
              >
                <span style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                  <span
                    className={`pay-action-row__mark ${row.severity === 'HIGH' ? 'pay-action-row__mark--danger' : 'pay-action-row__mark--warning'}`}
                    aria-hidden
                  >
                    <AlertTriangle size={15} />
                  </span>
                  <span style={{ minWidth: 0 }}>
                    <span className="pay-action-row__label">{row.label}</span>
                    {row.amount > 0 && <span className="pay-action-row__detail">{money(row.amount)}</span>}
                  </span>
                </span>
                <strong className="num">{row.count}</strong>
              </button>
            ))
          )}
        </Panel>

        <Panel
          title="Open cases"
          span={3}
          action={
            query.kind ? (
              <button
                type="button"
                className="pay-button pay-button--ghost pay-button--small"
                onClick={() => {
                  const next = new URLSearchParams(params)
                  next.delete('kind')
                  setParams(next, { replace: true })
                }}
              >
                Show all kinds
              </button>
            ) : undefined
          }
        >
          {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}
          {resolve.error && <ErrorState message={resolve.error} />}

          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : (data?.data ?? []).length === 0 ? (
            <EmptyState title="Nothing open">Every difference has been explained or resolved.</EmptyState>
          ) : (
            <>
              {data?.data.map((item) => (
                <div key={item.case_id} className="pay-insight">
                  <span
                    className={`pay-insight__mark ${item.severity === 'HIGH' ? 'pay-action-row__mark--danger' : 'pay-action-row__mark--warning'}`}
                    aria-hidden
                  >
                    <AlertTriangle size={15} />
                  </span>

                  <div className="pay-insight__body">
                    <strong>{item.summary}</strong>
                    <p>
                      {item.label} · opened {date(item.opened_at)}
                      {item.difference !== null && ` · ${money(item.difference, item.currency)} difference`}
                    </p>
                  </div>

                  {can('reconciliation.resolve') && (
                    <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
                      <button
                        type="button"
                        className="pay-button pay-button--small"
                        disabled={resolve.busy}
                        onClick={() => {
                          const note = window.prompt('What was it? (optional)') ?? ''
                          resolve.run(item.case_id, 'RESOLVED', note)
                        }}
                      >
                        Resolve
                      </button>
                      <button
                        type="button"
                        className="pay-button pay-button--ghost pay-button--small"
                        disabled={resolve.busy}
                        onClick={() => {
                          // A note is REQUIRED here, because writing money off
                          // with no explanation is exactly what an auditor asks
                          // about.
                          const note = window.prompt('Why is this being written off?')
                          if (note && note.trim() !== '') resolve.run(item.case_id, 'WRITTEN_OFF', note.trim())
                        }}
                      >
                        Write off
                      </button>
                    </div>
                  )}
                </div>
              ))}

              <Pager total={data?.meta.total ?? 0} offset={offset} limit={50} onChange={setOffset} />
            </>
          )}
        </Panel>
      </div>
    </>
  )
}
