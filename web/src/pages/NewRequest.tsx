/**
 * Raising a payment request, and recording money that came in another way.
 *
 * TWO FORMS, DELIBERATELY DIFFERENT IN TONE.
 *
 * The request form is quick: an amount, who it is for, and send. The external
 * payment form asks more, and says why it is asking — a method, a traceable
 * reference and a date. That is not friction for its own sake. A payment nobody
 * can trace is the one a merchant most needs to be able to trace later, when a
 * customer says they paid and the bank statement says otherwise.
 *
 * There is no "mark as collected" anywhere in this product.
 */

import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, Info } from 'lucide-react'
import { useAction } from '../hooks/useApi'
import { usePay } from '../context/PayContext'
import { api } from '../services/api'
import { money } from '../services/format'
import { ErrorState, Panel } from '../dashboards/kit'

export function NewRequest() {
  const navigate = useNavigate()
  const { session } = usePay()
  const [allowPartial, setAllowPartial] = useState(session?.settings.payment_requests.allow_partial_default ?? false)

  const create = useAction(async (form: FormData) => {
    const response = await api.post<{ payment_request_id: string }>('v1/payment-requests', {
      source_app: 'PAY',
      reference: (form.get('reference') as string)?.trim() || undefined,
      description: (form.get('description') as string)?.trim() || undefined,
      amount: form.get('amount'),
      currency: session?.settings.currency ?? 'INR',
      payer_name: (form.get('payer_name') as string)?.trim() || undefined,
      payer_email: (form.get('payer_email') as string)?.trim() || undefined,
      payer_mobile: (form.get('payer_mobile') as string)?.trim() || undefined,
      allow_partial_payment: allowPartial,
      min_partial_amount: allowPartial ? form.get('min_partial_amount') || undefined : undefined,
      expires_at: (form.get('expires_at') as string) || undefined,
      notes: (form.get('notes') as string)?.trim() || undefined,
    })

    navigate(`/requests/${response.data.payment_request_id}`)
    return response.data
  })

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    create.run(new FormData(event.currentTarget))
  }

  const defaultExpiry = session?.settings.payment_requests.default_expiry_hours ?? 168

  return (
    <>
      <section className="pay-heading">
        <div>
          <button type="button" className="pay-button pay-button--ghost pay-button--small" onClick={() => navigate('/requests')} style={{ marginBottom: 10 }}>
            <ArrowLeft size={15} aria-hidden /> Requests
          </button>
          <h1>New payment request</h1>
          <p>Ask somebody to pay. Books, Billing, Sales and POS raise these automatically from their own documents.</p>
        </div>
      </section>

      <form onSubmit={submit}>
        <div className="pay-grid">
          <Panel title="What is being asked for" span={2}>
            {create.error && <ErrorState message={create.error} />}

            <div className="pay-row">
              <label className="pay-field">
                <span className="pay-field__label">Amount<span aria-hidden> *</span></span>
                <input
                  className="pay-input"
                  name="amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  required
                  placeholder="0.00"
                  aria-invalid={create.field === 'amount'}
                />
                {create.field === 'amount' && <span className="pay-field__error">{create.error}</span>}
              </label>

              <label className="pay-field">
                <span className="pay-field__label">Reference</span>
                <input className="pay-input" name="reference" placeholder="INV-2026-1042" maxLength={120} />
                <span className="pay-field__hint">Shown to the payer. One is generated if you leave it empty.</span>
              </label>
            </div>

            <label className="pay-field">
              <span className="pay-field__label">Description</span>
              <input className="pay-input" name="description" placeholder="Sales invoice INV-2026-1042" maxLength={300} />
            </label>

            <label className="pay-field">
              <span className="pay-field__label">Internal note</span>
              <textarea className="pay-input" name="notes" placeholder="Only your team sees this" maxLength={1000} />
            </label>
          </Panel>

          <Panel title="Who is paying">
            <label className="pay-field">
              <span className="pay-field__label">Name</span>
              <input className="pay-input" name="payer_name" placeholder="Priya Mehta" maxLength={180} />
            </label>

            <label className="pay-field">
              <span className="pay-field__label">Mobile</span>
              <input className="pay-input" name="payer_mobile" type="tel" placeholder="9876543210" maxLength={20} />
            </label>

            <label className="pay-field">
              <span className="pay-field__label">Email</span>
              <input className="pay-input" name="payer_email" type="email" placeholder="priya@example.com" maxLength={180} />
              {create.field === 'payer_email' && <span className="pay-field__error">{create.error}</span>}
            </label>

            <p style={{ margin: 0, color: 'var(--pay-muted)', fontSize: 11.5 }}>
              These are kept as part of the payment record — where the request was actually sent — not as a customer master.
            </p>
          </Panel>

          <Panel title="How it may be paid" span={2}>
            <label className="pay-check">
              <input type="checkbox" checked={allowPartial} onChange={(event) => setAllowPartial(event.target.checked)} />
              <span>
                <strong>Allow part payments</strong>
                <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 12 }}>
                  The payer can settle this in instalments. Every payment is recorded separately and the outstanding balance
                  updates as each one arrives.
                </span>
              </span>
            </label>

            {allowPartial && (
              <label className="pay-field" style={{ maxWidth: 280 }}>
                <span className="pay-field__label">Smallest part payment</span>
                <input
                  className="pay-input"
                  name="min_partial_amount"
                  type="number"
                  step="0.01"
                  min="1"
                  defaultValue={session?.settings.payment_requests.min_partial_amount ?? 100}
                />
                {/* The consequence, stated where the decision is made. */}
                <span className="pay-field__hint">
                  Below about {money(100)} a gateway fee eats most of the payment. A payment that clears the whole balance is
                  always allowed, whatever this says.
                </span>
              </label>
            )}

            <label className="pay-field" style={{ maxWidth: 280 }}>
              <span className="pay-field__label">Expires</span>
              <input className="pay-input" name="expires_at" type="datetime-local" />
              <span className="pay-field__hint">
                Leave empty for the company default of {defaultExpiry} hours.
              </span>
            </label>
          </Panel>

          <Panel title="What happens next">
            <ol style={{ margin: 0, paddingLeft: 18, color: 'var(--pay-text-soft)', fontSize: 13, lineHeight: 1.7 }}>
              <li>The request is raised and can take money straight away.</li>
              <li>Create a link or a QR code to send it.</li>
              <li>The payer chooses a method; Pay routes it to the right provider.</li>
              <li>The money lands, and the request updates itself.</li>
            </ol>

            <button type="submit" className="pay-button pay-button--block" style={{ marginTop: 16 }} disabled={create.busy}>
              {create.busy ? 'Creating…' : 'Create payment request'}
            </button>
          </Panel>
        </div>
      </form>
    </>
  )
}

// ---------------------------------------------------------------------------

export function RecordExternal() {
  const navigate = useNavigate()
  const [method, setMethod] = useState('BANK_TRANSFER')

  const METHODS: Record<string, { label: string; prompt: string }> = {
    CASH: { label: 'Cash', prompt: 'a receipt number or counter reference' },
    BANK_TRANSFER: { label: 'Bank transfer (NEFT / RTGS / IMPS)', prompt: 'the UTR from the bank' },
    CHEQUE: { label: 'Cheque', prompt: 'the cheque number' },
    UPI_MANUAL: { label: 'UPI, paid directly', prompt: 'the UPI transaction id' },
    CARD_MACHINE: { label: 'Card machine (outside Pay)', prompt: 'the terminal or batch reference' },
    OTHER: { label: 'Other', prompt: 'a reference somebody could trace this by' },
  }

  const record = useAction(async (form: FormData) => {
    const response = await api.post<{ external_payment_id: string }>('v1/external-payments', {
      request_id: (form.get('request_id') as string)?.trim() || undefined,
      amount: form.get('amount'),
      method,
      external_reference: (form.get('external_reference') as string)?.trim(),
      received_at: form.get('received_at'),
      note: (form.get('note') as string)?.trim() || undefined,
      payer_name: (form.get('payer_name') as string)?.trim() || undefined,
    })

    navigate('/payments')
    return response.data
  })

  return (
    <>
      <section className="pay-heading">
        <div>
          <button type="button" className="pay-button pay-button--ghost pay-button--small" onClick={() => navigate(-1)} style={{ marginBottom: 10 }}>
            <ArrowLeft size={15} aria-hidden /> Back
          </button>
          <h1>Record a payment made outside Pay</h1>
          <p>Cash at the counter, a bank transfer, a cheque, a UPI payment made directly to you.</p>
        </div>
      </section>

      <form
        onSubmit={(event) => {
          event.preventDefault()
          record.run(new FormData(event.currentTarget))
        }}
      >
        <div className="pay-grid">
          <Panel title="The payment" span={2}>
            {record.error && <ErrorState message={record.error} />}

            <div className="pay-row">
              <label className="pay-field">
                <span className="pay-field__label">Amount<span aria-hidden> *</span></span>
                <input className="pay-input" name="amount" type="number" step="0.01" min="0.01" required aria-invalid={record.field === 'amount'} />
              </label>

              <label className="pay-field">
                <span className="pay-field__label">How it was received<span aria-hidden> *</span></span>
                <select className="pay-input" value={method} onChange={(event) => setMethod(event.target.value)}>
                  {Object.entries(METHODS).map(([key, entry]) => (
                    <option key={key} value={key}>{entry.label}</option>
                  ))}
                </select>
              </label>

              <label className="pay-field">
                <span className="pay-field__label">Date received<span aria-hidden> *</span></span>
                <input
                  className="pay-input"
                  name="received_at"
                  type="date"
                  required
                  max={new Date().toISOString().slice(0, 10)}
                  defaultValue={new Date().toISOString().slice(0, 10)}
                  aria-invalid={record.field === 'received_at'}
                />
              </label>
            </div>

            <label className="pay-field">
              {/* The prompt changes with the method, because "reference" means
                  something different every time and a generic prompt gets a
                  generic answer. */}
              <span className="pay-field__label">Reference<span aria-hidden> *</span></span>
              <input
                className="pay-input"
                name="external_reference"
                required
                placeholder={METHODS[method].prompt}
                aria-invalid={record.field === 'external_reference'}
              />
              <span className="pay-field__hint">Enter {METHODS[method].prompt}.</span>
              {record.field === 'external_reference' && <span className="pay-field__error">{record.error}</span>}
            </label>

            <label className="pay-field">
              <span className="pay-field__label">Against which request</span>
              <input className="pay-input" name="request_id" placeholder="PAYREQ-…" />
              <span className="pay-field__hint">
                Leave empty if this payment does not settle a request Pay knows about.
              </span>
            </label>

            <label className="pay-field">
              <span className="pay-field__label">Who paid</span>
              <input className="pay-input" name="payer_name" placeholder="Priya Mehta" />
            </label>

            <label className="pay-field">
              <span className="pay-field__label">Note</span>
              <textarea className="pay-input" name="note" placeholder="Anything that would help somebody understand this later" />
            </label>
          </Panel>

          <Panel title="Why Pay asks for all this">
            <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start', marginBottom: 12 }}>
              <Info size={17} aria-hidden style={{ color: 'var(--pay-action)', flexShrink: 0, marginTop: 2 }} />
              <p style={{ margin: 0, color: 'var(--pay-text-soft)', fontSize: 12.5, lineHeight: 1.6 }}>
                No provider can confirm a payment that never went through one. The reference is the only thing tying this
                record to something a bank statement can corroborate, which is why it is required rather than optional.
              </p>
            </div>

            <p style={{ margin: 0, color: 'var(--pay-muted)', fontSize: 12 }}>
              The payment is recorded against your name and the app that raised the request is told. A cheque that later
              bounces is <strong>reversed</strong>, not deleted — both records stand.
            </p>

            <button type="submit" className="pay-button pay-button--block" style={{ marginTop: 16 }} disabled={record.busy}>
              {record.busy ? 'Recording…' : 'Record payment'}
            </button>
          </Panel>
        </div>
      </form>
    </>
  )
}
