/**
 * The public payment page — the only part of Pay a stranger ever sees.
 *
 * NO LOGIN, NO COMPANY CONTEXT, NO SHELL. It is reached by a token in the URL,
 * and everything it knows comes off that token.
 *
 * MOBILE FIRST, and not as a slogan: most of these links are opened from a
 * WhatsApp message on a phone, one-handed, often on a slow connection. So the
 * amount is the largest thing on the screen, the pay button is within thumb
 * reach, and nothing is behind a hover.
 *
 * THE AMOUNT IS NOT NEGOTIABLE HERE. The field is shown only where the request
 * allows part payment, and whatever is typed is checked on the server against
 * what is actually outstanding. A page that posts amount=1 gets refused, not
 * charged.
 */

import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { AlertCircle, CheckCircle2, Clock, Lock, ShieldCheck } from 'lucide-react'
import { getApiBaseUrl } from '../config'
import { date, money } from '../services/format'

interface CheckoutData {
  merchant: {
    name: string
    logo_url: string | null
    support_email: string | null
    support_phone: string | null
    terms_url: string | null
  }
  payment: {
    token: string
    reference: string | null
    description: string | null
    amount: number
    paid: number
    outstanding: number
    currency: string
    formatted: { amount: string; outstanding: string }
    allow_partial: boolean
    min_partial: number | null
    expires_at: string | null
    status: string
  }
  payer: { name: string | null; email_hint: string | null }
  payable: boolean
  reason: string | null
  methods: Array<{ method: string; label: string }>
  branding: { powered_by: string }
}

/**
 * The public endpoints are called without the app's normal API client, because
 * that one mints a portal session key — and there is no session here. A plain
 * fetch is the whole of it.
 */
async function publicFetch<T>(path: string, options?: RequestInit): Promise<T> {
  const response = await fetch(`${getApiBaseUrl()}/${path}`, {
    ...options,
    headers: { Accept: 'application/json', ...(options?.body ? { 'Content-Type': 'application/json' } : {}), ...options?.headers },
    credentials: 'omit',
  })

  const text = await response.text()
  const payload = text === '' ? null : JSON.parse(text)

  if (!response.ok) {
    const error = (payload as { error?: { message?: string } })?.error
    throw new Error(error?.message ?? 'This payment could not be loaded.')
  }

  return (payload as { data: T }).data
}

export function Checkout() {
  const { token } = useParams<{ token: string }>()
  const [data, setData] = useState<CheckoutData | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<string | null>(null)
  const [paying, setPaying] = useState(false)
  const [outcome, setOutcome] = useState<{ status: string; message: string } | null>(null)

  const load = useCallback(async () => {
    if (!token) return

    setLoading(true)
    try {
      const result = await publicFetch<CheckoutData>(`v1/public/pay/${token}`)
      setData(result)
      setAmount(result.payment.outstanding.toFixed(2))
      setMethod(result.methods[0]?.method ?? null)
      setError(null)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setLoading(false)
    }
  }, [token])

  useEffect(() => {
    void load()
  }, [load])

  // The page title is the merchant and the amount, because this tab sits open
  // among a dozen others while somebody finds their card.
  useEffect(() => {
    if (data) {
      document.title = `${data.payment.formatted.outstanding} to ${data.merchant.name}`
    }
    return () => {
      document.title = 'Aicountly Pay'
    }
  }, [data])

  async function pay() {
    if (!token || !data) return

    setPaying(true)
    setError(null)

    try {
      const started = await publicFetch<{ payment_id: string; provider: { code: string; checkout: Record<string, unknown>; checkout_url: string | null } }>(
        `v1/public/pay/${token}/start`,
        {
          method: 'POST',
          body: JSON.stringify({ amount: data.payment.allow_partial ? amount : undefined, method }),
          headers: { 'Idempotency-Key': `${token}-${Date.now()}` },
        },
      )

      // A provider-hosted page takes over from here. Otherwise the provider's
      // own widget is opened with what it needs — an order id and a PUBLIC key,
      // a client secret, or a signed form. None of those is a credential.
      if (started.provider.checkout_url) {
        window.location.href = started.provider.checkout_url
        return
      }

      // Without a hosted page, poll for the outcome. The provider's widget
      // would normally be mounted here; this keeps the page honest until it is.
      await poll(started.payment_id)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setPaying(false)
    }
  }

  async function poll(paymentId: string) {
    if (!token) return

    for (let attempt = 0; attempt < 20; attempt++) {
      await new Promise((resolve) => window.setTimeout(resolve, 2000))

      try {
        const status = await publicFetch<{ status: string; message: string; request_status: string | null }>(
          `v1/public/pay/${token}/status/${paymentId}`,
        )

        if (['SUCCESS', 'CAPTURED', 'FAILED', 'CANCELLED'].includes(status.status)) {
          setOutcome({ status: status.status, message: status.message })
          void load()
          return
        }
      } catch {
        // A transient failure while polling is not the payment failing. Keep
        // trying; the webhook is the authority either way.
      }
    }

    setOutcome({ status: 'PENDING', message: 'Your bank is still confirming this payment. It can take a few minutes.' })
  }

  if (loading) {
    return (
      <CheckoutFrame>
        <span className="pay-skeleton pay-skeleton--line" style={{ height: 22, width: '60%' }} />
        <span className="pay-skeleton pay-skeleton--line" style={{ height: 44, marginTop: 14 }} />
        <span className="pay-skeleton pay-skeleton--line" style={{ height: 44 }} />
      </CheckoutFrame>
    )
  }

  if (error && !data) {
    return (
      <CheckoutFrame>
        <div style={{ textAlign: 'center', padding: '20px 0' }}>
          <AlertCircle size={38} aria-hidden style={{ color: 'var(--pay-danger)' }} />
          <h1 style={{ fontSize: 19, margin: '14px 0 6px' }}>This link is not valid</h1>
          <p style={{ margin: 0, color: 'var(--pay-muted)', fontSize: 13.5 }}>{error}</p>
        </div>
      </CheckoutFrame>
    )
  }

  if (!data) return null

  if (outcome) {
    const good = ['SUCCESS', 'CAPTURED'].includes(outcome.status)

    return (
      <CheckoutFrame merchant={data.merchant}>
        <div style={{ textAlign: 'center', padding: '16px 0' }}>
          {good ? (
            <CheckCircle2 size={44} aria-hidden style={{ color: 'var(--pay-success)' }} />
          ) : outcome.status === 'PENDING' ? (
            <Clock size={44} aria-hidden style={{ color: 'var(--pay-warning)' }} />
          ) : (
            <AlertCircle size={44} aria-hidden style={{ color: 'var(--pay-danger)' }} />
          )}

          <h1 style={{ fontSize: 21, margin: '14px 0 6px' }}>{outcome.message}</h1>

          {good && data.payment.outstanding > 0 && (
            <p style={{ margin: '10px 0 0', color: 'var(--pay-muted)', fontSize: 13.5 }}>
              {money(data.payment.outstanding, data.payment.currency)} is still outstanding on this request.
            </p>
          )}

          {!good && outcome.status !== 'PENDING' && (
            <button type="button" className="pay-button" style={{ marginTop: 18 }} onClick={() => setOutcome(null)}>
              Try again
            </button>
          )}
        </div>
      </CheckoutFrame>
    )
  }

  const payment = data.payment
  const amountNumber = Number(amount)
  const amountValid =
    !payment.allow_partial ||
    (amountNumber > 0 &&
      amountNumber <= payment.outstanding &&
      (payment.min_partial === null || amountNumber >= payment.min_partial || amountNumber === payment.outstanding))

  return (
    <CheckoutFrame merchant={data.merchant}>
      {payment.reference && (
        <p style={{ margin: '0 0 4px', color: 'var(--pay-muted)', fontSize: 12.5 }}>{payment.reference}</p>
      )}
      {payment.description && (
        <p style={{ margin: '0 0 16px', fontSize: 14 }}>{payment.description}</p>
      )}

      {/* The largest thing on the screen, because it is the thing somebody is
          deciding about. */}
      <div style={{ textAlign: 'center', padding: '18px 0 20px', borderBottom: '1px solid var(--pay-border)' }}>
        <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 12.5, marginBottom: 4 }}>Amount due</span>
        <strong style={{ fontSize: 'clamp(30px, 9vw, 40px)', letterSpacing: '-0.03em', fontVariantNumeric: 'tabular-nums' }}>
          {payment.formatted.outstanding}
        </strong>

        {payment.paid > 0 && (
          <span style={{ display: 'block', marginTop: 6, color: 'var(--pay-muted)', fontSize: 12.5 }}>
            {money(payment.paid, payment.currency)} of {payment.formatted.amount} already paid
          </span>
        )}
      </div>

      {!data.payable ? (
        <div className="pay-unavailable" style={{ marginTop: 18 }} role="status">
          <strong>This cannot be paid</strong>
          {data.reason}
        </div>
      ) : (
        <>
          {payment.allow_partial && (
            <label className="pay-field" style={{ marginTop: 18 }}>
              <span className="pay-field__label">How much would you like to pay?</span>
              <input
                className="pay-input"
                type="number"
                step="0.01"
                min={payment.min_partial ?? 1}
                max={payment.outstanding}
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
                inputMode="decimal"
                aria-invalid={!amountValid}
              />
              {payment.min_partial !== null && (
                <span className="pay-field__hint">
                  At least {money(payment.min_partial, payment.currency)}, or the whole {payment.formatted.outstanding}.
                </span>
              )}
              {!amountValid && (
                <span className="pay-field__error">
                  Enter between {money(payment.min_partial ?? 1, payment.currency)} and {payment.formatted.outstanding}.
                </span>
              )}
            </label>
          )}

          {data.methods.length > 0 ? (
            <fieldset style={{ border: 0, padding: 0, margin: '18px 0 0' }}>
              <legend className="pay-field__label">How would you like to pay?</legend>
              <div style={{ display: 'grid', gap: 8, marginTop: 8 }}>
                {data.methods.map((option) => (
                  <label
                    key={option.method}
                    className="pay-check"
                    style={{
                      margin: 0,
                      padding: '13px 14px',
                      border: `1px solid ${method === option.method ? 'var(--pay-action)' : 'var(--pay-border)'}`,
                      borderRadius: 11,
                      background: method === option.method ? 'var(--pay-mint)' : 'var(--pay-surface)',
                      cursor: 'pointer',
                    }}
                  >
                    <input
                      type="radio"
                      name="method"
                      value={option.method}
                      checked={method === option.method}
                      onChange={() => setMethod(option.method)}
                      style={{ accentColor: 'var(--pay-action)' }}
                    />
                    <span style={{ fontWeight: 600 }}>{option.label}</span>
                  </label>
                ))}
              </div>
            </fieldset>
          ) : (
            <div className="pay-unavailable" style={{ marginTop: 18 }} role="status">
              <strong>No payment method is available</strong>
              Please contact {data.merchant.name} — their payment provider is not accepting payments right now.
            </div>
          )}

          {error && (
            <div className="pay-error" role="alert" style={{ marginTop: 16 }}>
              <AlertCircle size={17} aria-hidden style={{ flexShrink: 0, marginTop: 1 }} />
              <span>{error}</span>
            </div>
          )}

          <button
            type="button"
            className="pay-button pay-button--block"
            style={{ marginTop: 20, minHeight: 50, fontSize: 15 }}
            disabled={paying || !amountValid || data.methods.length === 0}
            onClick={pay}
          >
            <Lock size={16} aria-hidden />
            {paying ? 'Taking you to pay…' : `Pay ${payment.allow_partial ? money(amountNumber || 0, payment.currency) : payment.formatted.outstanding}`}
          </button>

          {payment.expires_at && (
            <p style={{ margin: '12px 0 0', textAlign: 'center', color: 'var(--pay-muted)', fontSize: 12 }}>
              <Clock size={12} aria-hidden style={{ verticalAlign: -1, marginRight: 4 }} />
              This link is valid until {date(payment.expires_at)}
            </p>
          )}
        </>
      )}
    </CheckoutFrame>
  )
}

function CheckoutFrame({ merchant, children }: { merchant?: CheckoutData['merchant']; children: React.ReactNode }) {
  return (
    <main
      style={{
        minHeight: '100dvh',
        display: 'grid',
        placeItems: 'center',
        padding: '20px 16px 40px',
        background:
          'radial-gradient(circle at 50% 0%, rgb(37 176 3 / 7%), transparent 45%), var(--pay-bg)',
      }}
    >
      <div style={{ width: 'min(430px, 100%)' }}>
        <div
          style={{
            padding: 'clamp(20px, 5vw, 28px)',
            background: 'var(--pay-surface)',
            border: '1px solid var(--pay-border)',
            borderRadius: 18,
            boxShadow: 'var(--pay-shadow-lg)',
          }}
        >
          {merchant && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 18 }}>
              {merchant.logo_url ? (
                <img
                  src={merchant.logo_url}
                  alt=""
                  style={{ width: 42, height: 42, borderRadius: 11, objectFit: 'contain', background: 'var(--pay-surface-2)' }}
                />
              ) : (
                <span
                  aria-hidden
                  style={{
                    display: 'grid',
                    placeItems: 'center',
                    width: 42,
                    height: 42,
                    borderRadius: 11,
                    background: 'var(--pay-mint)',
                    color: 'var(--pay-action)',
                    fontWeight: 800,
                    fontSize: 17,
                  }}
                >
                  {merchant.name.slice(0, 1).toUpperCase()}
                </span>
              )}

              <div style={{ minWidth: 0 }}>
                <strong style={{ display: 'block', fontSize: 15.5, letterSpacing: '-0.01em' }}>{merchant.name}</strong>
                <span style={{ color: 'var(--pay-muted)', fontSize: 12 }}>is requesting a payment</span>
              </div>
            </div>
          )}

          {children}
        </div>

        <footer style={{ marginTop: 16, textAlign: 'center' }}>
          <p style={{ margin: '0 0 6px', color: 'var(--pay-muted)', fontSize: 12 }}>
            <ShieldCheck size={13} aria-hidden style={{ verticalAlign: -2, marginRight: 4, color: 'var(--pay-action)' }} />
            Secure payments powered by Aicountly Pay
          </p>

          {merchant && (merchant.support_email || merchant.support_phone) && (
            <p style={{ margin: 0, color: 'var(--pay-muted)', fontSize: 11.5 }}>
              Questions? Contact {merchant.name} at{' '}
              {merchant.support_email && <a href={`mailto:${merchant.support_email}`}>{merchant.support_email}</a>}
              {merchant.support_email && merchant.support_phone && ' or '}
              {merchant.support_phone && <a href={`tel:${merchant.support_phone}`}>{merchant.support_phone}</a>}
            </p>
          )}

          {merchant?.terms_url && (
            <p style={{ margin: '6px 0 0', fontSize: 11.5 }}>
              <a href={merchant.terms_url} target="_blank" rel="noreferrer noopener">Terms and conditions</a>
            </p>
          )}
        </footer>
      </div>
    </main>
  )
}
