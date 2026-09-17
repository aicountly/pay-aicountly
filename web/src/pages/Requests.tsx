/**
 * Payment requests: the list, one request in full, and the form that raises one.
 *
 * THE FORM IS WHERE THE PARTIAL-PAYMENT RULES ARE EXPLAINED, because that is
 * where somebody decides them. A minimum part payment set below what a gateway
 * fee costs is a decision a merchant should be able to reconsider before they
 * make it, not after.
 */

import { useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Copy, FileText, Link2, QrCode, XCircle } from 'lucide-react'
import { useAction, useApi } from '../hooks/useApi'
import { usePay } from '../context/PayContext'
import { api } from '../services/api'
import { date, dateTime, humanise, money } from '../services/format'
import type { LinkRow, PaymentRequestRow } from '../services/types'
import { EmptyState, ErrorState, Panel, SkeletonRows, StatusBadge, Unavailable } from '../dashboards/kit'
import { Facts, Pager, Select } from './Payments'

const REQUEST_STATUSES = ['ACTIVE', 'PARTIALLY_PAID', 'PAID', 'EXPIRED', 'CANCELLED', 'DRAFT']

export function RequestsList() {
  const navigate = useNavigate()
  const { can } = usePay()
  const [params, setParams] = useSearchParams()
  const [offset, setOffset] = useState(0)

  const filters = {
    q: params.get('q') ?? '',
    status: params.get('status') ?? '',
    source_app: params.get('source_app') ?? '',
  }

  const query = { ...filters, limit: 50, offset }
  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.list<PaymentRequestRow>('v1/payment-requests', query, signal),
    [JSON.stringify(query)],
  )

  const sources = usePay().session?.capabilities.sources ?? []
  const total = data?.meta.total ?? 0

  function setFilter(name: string, value: string) {
    const next = new URLSearchParams(params)
    if (value === '') next.delete(name)
    else next.set(name, value)
    setParams(next, { replace: true })
    setOffset(0)
  }

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Payment requests</h1>
          <p>{loading && !data ? 'Loading…' : `${total.toLocaleString('en-IN')} request${total === 1 ? '' : 's'}`}</p>
        </div>

        {can('payment_requests.create') && (
          <button type="button" className="pay-button" onClick={() => navigate('/requests/new')}>
            <FileText size={16} aria-hidden /> New request
          </button>
        )}
      </section>

      <Panel title="Filters">
        <div className="pay-row">
          <label className="pay-field">
            <span className="pay-field__label">Search</span>
            <input
              className="pay-input"
              type="search"
              defaultValue={filters.q}
              placeholder="Reference, description or payer"
              onKeyDown={(event) => {
                if (event.key === 'Enter') setFilter('q', (event.target as HTMLInputElement).value.trim())
              }}
              onBlur={(event) => setFilter('q', event.target.value.trim())}
            />
          </label>

          <Select label="Status" value={filters.status} options={REQUEST_STATUSES} onChange={(v) => setFilter('status', v)} />
          <Select
            label="Source"
            value={filters.source_app}
            options={sources.map((s) => s.app)}
            labels={Object.fromEntries(sources.map((s) => [s.app, s.name]))}
            onChange={(v) => setFilter('source_app', v)}
          />
        </div>
      </Panel>

      <div className="pay-grid" style={{ marginTop: 14 }}>
        <Panel title="Requests" span={4}>
          {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

          {loading && !data ? (
            <SkeletonRows rows={8} />
          ) : (data?.data ?? []).length === 0 ? (
            <EmptyState
              title="No payment requests yet"
              action={
                can('payment_requests.create') ? (
                  <button type="button" className="pay-button pay-button--small" onClick={() => navigate('/requests/new')}>
                    Create your first request
                  </button>
                ) : undefined
              }
            >
              Books, Billing, Sales and POS raise these automatically, and you can raise one here.
            </EmptyState>
          ) : (
            <>
              <div className="pay-table-wrap">
                <table className="pay-table">
                  <thead>
                    <tr>
                      <th>Reference</th>
                      <th>Payer</th>
                      <th>Source</th>
                      <th className="num">Amount</th>
                      <th className="num">Outstanding</th>
                      <th>Status</th>
                      <th>Expires</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.map((row) => (
                      <tr key={row.payment_request_id}>
                        <td>
                          <button type="button" className="pay-table__link" onClick={() => navigate(`/requests/${row.payment_request_id}`)}>
                            {row.reference ?? row.payment_request_id.split('-').slice(-1)[0]}
                          </button>
                          {row.description && (
                            <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>{row.description}</span>
                          )}
                        </td>
                        <td>{row.payer?.name ?? '—'}</td>
                        <td><span className="pay-tag pay-tag--neutral">{row.source_app}</span></td>
                        <td className="num">{money(row.amount, row.currency)}</td>
                        <td className="num">{money(row.outstanding, row.currency)}</td>
                        <td><StatusBadge status={row.status} label={row.status_label} /></td>
                        <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>
                          {row.expires_at ? date(row.expires_at) : 'No expiry'}
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

export function RequestDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { can } = usePay()
  const [copied, setCopied] = useState<string | null>(null)

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.one<PaymentRequestRow>(`v1/payment-requests/${id}`, undefined, signal),
    [id],
  )

  const request = data?.data

  const createLink = useAction(async (channel: string) => {
    await api.post(`v1/payment-requests/${id}/link`, { channel })
    reload()
  })

  const createQr = useAction(async () => {
    await api.post(`v1/payment-requests/${id}/qr`, { reusable: false })
    reload()
  })

  const cancel = useAction(async (reason: string) => {
    await api.post(`v1/payment-requests/${id}/cancel`, { reason, acknowledge_collected: 1 })
    reload()
  })

  async function copy(url: string) {
    try {
      await navigator.clipboard.writeText(url)
      setCopied(url)
      window.setTimeout(() => setCopied(null), 2000)
    } catch {
      // Clipboard refused — a permissions policy, or an insecure origin. The
      // link is on screen and selectable, so this is a convenience, not a
      // failure worth an alert.
    }
  }

  const collectable = request && ['ACTIVE', 'PARTIALLY_PAID'].includes(request.status)

  return (
    <>
      <section className="pay-heading">
        <div>
          <button type="button" className="pay-button pay-button--ghost pay-button--small" onClick={() => navigate('/requests')} style={{ marginBottom: 10 }}>
            <ArrowLeft size={15} aria-hidden /> Requests
          </button>
          <h1>{request ? money(request.amount, request.currency) : 'Payment request'}</h1>
          <p>{request ? `${request.reference ?? 'No reference'} · ${request.status_label}` : 'Loading…'}</p>
        </div>

        {collectable && can('links.manage') && (
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button type="button" className="pay-button pay-button--soft" disabled={createLink.busy} onClick={() => createLink.run('LINK')}>
              <Link2 size={16} aria-hidden /> New link
            </button>
            <button type="button" className="pay-button pay-button--soft" disabled={createQr.busy} onClick={() => createQr.run()}>
              <QrCode size={16} aria-hidden /> New QR
            </button>
          </div>
        )}
      </section>

      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}
      {createLink.error && <ErrorState message={createLink.error} />}
      {createQr.error && <ErrorState message={createQr.error} />}
      {cancel.error && <ErrorState message={cancel.error} />}

      {loading && !request ? (
        <SkeletonRows rows={8} />
      ) : request ? (
        <div className="pay-grid">
          <Panel title="Request" span={2}>
            <Facts
              rows={[
                ['Reference', request.reference ?? '—'],
                ['Description', request.description ?? '—'],
                ['Status', <StatusBadge key="s" status={request.status} label={request.status_label} />],
                ['Amount', money(request.amount, request.currency)],
                ['Paid', money(request.paid, request.currency)],
                ['Refunded', request.refunded > 0 ? money(request.refunded, request.currency) : '—'],
                ['Outstanding', money(request.outstanding, request.currency)],
                [
                  'Part payment',
                  request.allow_partial_payment
                    ? `Allowed${request.min_partial_amount ? `, at least ${money(request.min_partial_amount, request.currency)}` : ''}`
                    : 'Must be paid in full',
                ],
                ['Expires', request.expires_at ? dateTime(request.expires_at) : 'No expiry'],
                ['Raised', dateTime(request.created_at)],
                ['Views', String(request.views)],
              ]}
            />

            {request.cancelled_reason && (
              <div className="pay-unavailable" style={{ marginTop: 12 }}>
                <strong>Cancelled</strong>
                {request.cancelled_reason}
              </div>
            )}
          </Panel>

          <Panel title="Payer">
            <Facts
              rows={[
                ['Name', request.payer?.name ?? '—'],
                ['Email', request.payer?.email ?? '—'],
                ['Mobile', request.payer?.mobile ?? '—'],
              ]}
            />
            <p style={{ margin: '10px 0 0', color: 'var(--pay-muted)', fontSize: 11.5 }}>
              These are the details the request was sent to, kept as part of this payment&rsquo;s record.
            </p>
          </Panel>

          <Panel title="Source document" description="Read live from the app that owns it">
            {/* The source panel is the one place Pay calls another product on a
                screen — and only here, never on a list, where it would be one
                HTTP call per row. */}
            {!request.source ? (
              <EmptyState title="Raised in Pay">This request has no document behind it in another app.</EmptyState>
            ) : !request.source.available ? (
              <Unavailable title={`${request.source.app ?? 'The source app'} could not be reached`}>
                {request.source.reason}
              </Unavailable>
            ) : (
              <Facts
                rows={[
                  ['App', request.source.app ?? '—'],
                  ['Reference', String(request.source.document?.reference ?? '—')],
                  ['Document total', request.source.document?.amount_minor ? money(Number(request.source.document.amount_minor) / 100) : '—'],
                  ['Outstanding there', request.source.document?.outstanding_minor !== null && request.source.document?.outstanding_minor !== undefined
                    ? money(Number(request.source.document.outstanding_minor) / 100) : '—'],
                  ['Customer', String(request.source.document?.customer_name ?? '—')],
                ]}
              />
            )}
          </Panel>

          <Panel title="Links and QR codes" span={2}>
            {(request.links ?? []).length === 0 ? (
              <EmptyState title="No links yet">Create one to send this request to the payer.</EmptyState>
            ) : (
              (request.links ?? []).map((link: LinkRow) => (
                <div key={link.link_id} className="pay-action-row" style={{ cursor: 'default' }}>
                  <span style={{ minWidth: 0, flex: 1 }}>
                    <span className="pay-action-row__label">
                      {link.kind === 'QR' ? 'QR code' : humanise(link.channel)}
                      {link.reusable && <span className="pay-tag" style={{ marginLeft: 7 }}>Reusable</span>}
                    </span>
                    <span className="pay-action-row__detail" style={{ wordBreak: 'break-all' }}>
                      {link.url} · {link.views} views · {link.checkout_starts} checkouts
                    </span>
                  </span>
                  <span style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
                    <StatusBadge status={link.status === 'ACTIVE' ? 'ACTIVE' : link.status} />
                    <button type="button" className="pay-button pay-button--ghost pay-button--small" onClick={() => copy(link.url)}>
                      <Copy size={14} aria-hidden /> {copied === link.url ? 'Copied' : 'Copy'}
                    </button>
                  </span>
                </div>
              ))
            )}
          </Panel>

          <Panel title="Payments against this request">
            {(request.payments ?? []).length === 0 ? (
              <EmptyState title="Nothing paid yet" />
            ) : (
              (request.payments ?? []).map((payment) => (
                <button
                  key={payment.payment_id}
                  type="button"
                  className="pay-action-row"
                  onClick={() => navigate(`/payments/${payment.payment_id}`)}
                >
                  <span style={{ minWidth: 0 }}>
                    <span className="pay-action-row__label">{money(payment.amount, payment.currency)}</span>
                    <span className="pay-action-row__detail">
                      Attempt #{payment.attempt_no} · {payment.method ?? 'method not recorded'}
                      {payment.paid_at && ` · ${dateTime(payment.paid_at)}`}
                    </span>
                  </span>
                  <StatusBadge status={payment.status} label={payment.status_label} />
                </button>
              ))
            )}
          </Panel>

          {collectable && can('payment_requests.cancel') && (
            <Panel title="Cancel this request" span={4}>
              <p style={{ margin: '0 0 12px', color: 'var(--pay-muted)', fontSize: 12.5 }}>
                Cancelling stops every link working. Money already collected is <strong>not</strong> refunded — that is a
                separate, deliberate act with its own approval.
              </p>

              <form
                onSubmit={(event) => {
                  event.preventDefault()
                  const reason = new FormData(event.currentTarget).get('reason')
                  if (typeof reason === 'string' && reason.trim() !== '') cancel.run(reason.trim())
                }}
                style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}
              >
                <label className="pay-field" style={{ flex: 1, minWidth: 240, marginBottom: 0 }}>
                  <span className="pay-field__label">Why<span aria-hidden> *</span></span>
                  <input className="pay-input" name="reason" required placeholder="Customer cancelled the order" />
                </label>
                <button type="submit" className="pay-button pay-button--ghost" disabled={cancel.busy}>
                  <XCircle size={16} aria-hidden /> Cancel request
                </button>
              </form>
            </Panel>
          )}
        </div>
      ) : null}
    </>
  )
}
