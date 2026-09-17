/**
 * Pay settings.
 *
 * EVERY SWITCH HERE IS EXPLAINED WHERE IT IS FLIPPED, because two of them carry
 * real consequences a merchant should meet before they decide rather than
 * after: a refund approval threshold decides who can send money back
 * unreviewed, and auto-optimisation decides whether software may change where
 * their money travels.
 */

import { useState, type FormEvent } from 'react'
import { AlertTriangle, Save } from 'lucide-react'
import { useAction, useApi } from '../hooks/useApi'
import { usePay } from '../context/PayContext'
import { api } from '../services/api'
import { money } from '../services/format'
import type { PaySettings } from '../services/types'
import { EmptyState, ErrorState, Panel, SkeletonRows } from '../dashboards/kit'

export function Settings() {
  const { reload: reloadSession } = usePay()
  const [saved, setSaved] = useState(false)

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.one<PaySettings>('v1/settings', undefined, signal),
    [],
  )

  const settings = data?.data

  const [allowPartial, setAllowPartial] = useState<boolean | null>(null)
  const [requiresApproval, setRequiresApproval] = useState<boolean | null>(null)
  const [autoRouting, setAutoRouting] = useState<boolean | null>(null)
  const [payPulse, setPayPulse] = useState<boolean | null>(null)

  const partial = allowPartial ?? settings?.payment_requests.allow_partial_default ?? false
  const approval = requiresApproval ?? settings?.refunds.requires_approval ?? false
  const routing = autoRouting ?? settings?.pay_pulse.auto_routing ?? false
  const pulse = payPulse ?? settings?.pay_pulse.enabled ?? true

  const save = useAction(async (form: FormData) => {
    await api.put('v1/settings', {
      checkout_display_name: form.get('checkout_display_name'),
      checkout_logo_url: form.get('checkout_logo_url'),
      checkout_support_email: form.get('checkout_support_email'),
      checkout_support_phone: form.get('checkout_support_phone'),
      checkout_terms_url: form.get('checkout_terms_url'),
      default_link_expiry_hours: Number(form.get('default_link_expiry_hours') ?? 168),
      allow_partial_by_default: partial,
      min_partial_amount: form.get('min_partial_amount'),
      refund_requires_approval: approval,
      refund_approval_threshold: form.get('refund_approval_threshold') || null,
      auto_routing_enabled: routing,
      pay_pulse_enabled: pulse,
    })

    setSaved(true)
    window.setTimeout(() => setSaved(false), 3000)
    reload()
    reloadSession()
  })

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Settings</h1>
          <p>How Pay behaves for this company.</p>
        </div>
      </section>

      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

      {loading && !settings ? (
        <SkeletonRows rows={8} />
      ) : !settings ? (
        <EmptyState title="Settings could not be read" />
      ) : (
        <form
          onSubmit={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault()
            save.run(new FormData(event.currentTarget))
          }}
        >
          <div className="pay-grid">
            <Panel title="The payment page" description="What a stranger sees before they type their card in" span={2}>
              {save.error && <ErrorState message={save.error} />}

              <div className="pay-row">
                <label className="pay-field">
                  <span className="pay-field__label">Name to show</span>
                  <input className="pay-input" name="checkout_display_name" defaultValue={settings.checkout.display_name ?? ''} placeholder="Your trading name" />
                  <span className="pay-field__hint">
                    Left empty, Pay shows the company name from Manage — read live, so it is never out of date.
                  </span>
                </label>

                <label className="pay-field">
                  <span className="pay-field__label">Logo URL</span>
                  <input className="pay-input" name="checkout_logo_url" type="url" defaultValue={settings.checkout.logo_url ?? ''} />
                </label>

                <label className="pay-field">
                  <span className="pay-field__label">Support email</span>
                  <input className="pay-input" name="checkout_support_email" type="email" defaultValue={settings.checkout.support_email ?? ''} />
                </label>

                <label className="pay-field">
                  <span className="pay-field__label">Support phone</span>
                  <input className="pay-input" name="checkout_support_phone" type="tel" defaultValue={settings.checkout.support_phone ?? ''} />
                </label>

                <label className="pay-field">
                  <span className="pay-field__label">Terms URL</span>
                  <input className="pay-input" name="checkout_terms_url" type="url" defaultValue={settings.checkout.terms_url ?? ''} />
                </label>
              </div>
            </Panel>

            <Panel title="Payment requests">
              <label className="pay-field">
                <span className="pay-field__label">Links expire after</span>
                <input
                  className="pay-input"
                  name="default_link_expiry_hours"
                  type="number"
                  min="0"
                  max="8760"
                  defaultValue={settings.payment_requests.default_expiry_hours}
                />
                <span className="pay-field__hint">Hours. Zero means links never expire.</span>
              </label>

              <label className="pay-check">
                <input type="checkbox" checked={partial} onChange={(event) => setAllowPartial(event.target.checked)} />
                <span>
                  <strong>Allow part payments by default</strong>
                  <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 12 }}>
                    Each request can still override this.
                  </span>
                </span>
              </label>

              {partial && (
                <label className="pay-field">
                  <span className="pay-field__label">Smallest part payment</span>
                  <input
                    className="pay-input"
                    name="min_partial_amount"
                    type="number"
                    step="0.01"
                    min="1"
                    defaultValue={settings.payment_requests.min_partial_amount}
                  />
                  <span className="pay-field__hint">
                    Below about {money(100)} a gateway fee eats most of the payment.
                  </span>
                </label>
              )}
            </Panel>

            <Panel title="Refunds" description="A refund cannot be undone">
              <label className="pay-check">
                <input type="checkbox" checked={approval} onChange={(event) => setRequiresApproval(event.target.checked)} />
                <span>
                  <strong>A second person must approve refunds</strong>
                  <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 12 }}>
                    Whoever asks for a refund cannot be the one who sends it. Leave this off in a business with nobody to
                    check.
                  </span>
                </span>
              </label>

              {approval && (
                <label className="pay-field">
                  <span className="pay-field__label">Only above</span>
                  <input
                    className="pay-input"
                    name="refund_approval_threshold"
                    type="number"
                    step="0.01"
                    min="0"
                    defaultValue={settings.refunds.approval_threshold ?? ''}
                    placeholder="Every refund"
                  />
                  <span className="pay-field__hint">Leave empty to require approval for every refund.</span>
                </label>
              )}
            </Panel>

            <Panel title="Pay Pulse" span={2}>
              <label className="pay-check">
                <input type="checkbox" checked={pulse} onChange={(event) => setPayPulse(event.target.checked)} />
                <span>
                  <strong>Pay Pulse on</strong>
                  <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 12 }}>
                    Reads this company&rsquo;s own payment data to predict, recommend and explain. Nothing is shared with other
                    companies and nothing is read from your ledgers.
                  </span>
                </span>
              </label>

              <label className="pay-check" style={{ marginTop: 14 }}>
                <input type="checkbox" checked={routing} disabled={!pulse} onChange={(event) => setAutoRouting(event.target.checked)} />
                <span>
                  <strong>Let Pay Pulse change routing on its own</strong>
                  <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 12 }}>
                    {settings.pay_pulse.auto_routing_note}
                  </span>
                </span>
              </label>

              {/* The consequence, in front of the switch rather than behind it.
                  Routing decides which provider a merchant's money travels
                  through, and at what commercial rate. */}
              {routing && (
                <div className="pay-error" role="status" style={{ marginTop: 12 }}>
                  <AlertTriangle size={17} aria-hidden style={{ flexShrink: 0, marginTop: 1 }} />
                  <div>
                    <strong>This lets software decide where your money goes.</strong>
                    <span>
                      Only within the fallback rules you have written, and every change is recorded in the audit trail — but
                      it is a real change to which provider takes your payments, and their commercial terms.
                    </span>
                  </div>
                </div>
              )}
            </Panel>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 16 }}>
            <button type="submit" className="pay-button" disabled={save.busy}>
              <Save size={16} aria-hidden /> {save.busy ? 'Saving…' : 'Save settings'}
            </button>
            {saved && <span style={{ color: 'var(--pay-success)', fontWeight: 600, fontSize: 13 }}>Saved.</span>}
          </div>
        </form>
      )}
    </>
  )
}
