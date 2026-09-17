/**
 * Connecting providers, and deciding which one takes which payment.
 *
 * THE ONE RULE THIS SCREEN KEEPS: a secret goes in and never comes back. Every
 * credential field is write-only. What the screen shows afterwards is
 * "Configured" and a masked tail from the server — there is no endpoint that
 * returns a provider credential, and this form never asks for one back.
 */

import { useState, type FormEvent } from 'react'
import { AlertTriangle, CheckCircle2, Copy, Plug, ShieldCheck, Trash2, Zap } from 'lucide-react'
import { useAction, useApi } from '../hooks/useApi'
import { usePay } from '../context/PayContext'
import { api } from '../services/api'
import { humanise } from '../services/format'
import { Badge, EmptyState, ErrorState, Panel, SkeletonRows, StatusBadge, Unavailable } from '../dashboards/kit'

interface ProvidersResponse {
  connections: Array<{
    connection_id: string
    provider: string
    name: string
    mode: string
    status: string
    status_reason: string | null
    environment: string
    is_primary: boolean
    priority: number
    methods: string[]
    currencies: string[]
    capabilities: Record<string, boolean>
    credentials: Record<string, { configured: boolean; hint: string | null; rotated_at: string | null }>
    required_credentials: Record<string, string>
    webhook_url: string
    webhook_verified: boolean
    merchant_ref: string | null
    connected_at: string | null
    usable: boolean
  }>
  available: Array<{ code: string; name: string; mode: string; managed_capable: boolean }>
  managed: { available: boolean; provider: string | null; reason: string | null }
  credential_requirements: Record<string, Record<string, string>>
  encryption_ready: boolean
}

export function Gateways() {
  const { can } = usePay()
  const [connecting, setConnecting] = useState<string | null>(null)
  const [copied, setCopied] = useState<string | null>(null)

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.one<ProvidersResponse>('v1/providers', undefined, signal),
    [],
  )

  const connect = useAction(async (form: FormData, provider: string) => {
    const required = data?.data.credential_requirements[provider] ?? {}
    const credentials: Record<string, string> = {}

    for (const kind of Object.keys(required)) {
      const value = form.get(kind)
      if (typeof value === 'string' && value.trim() !== '') credentials[kind] = value.trim()
    }

    const response = await api.post<{ ok: boolean; detail: string; webhook_url: string }>('v1/providers', {
      provider,
      environment: form.get('environment'),
      display_name: (form.get('display_name') as string)?.trim() || undefined,
      credentials,
    })

    setConnecting(null)
    reload()
    return response.data
  })

  const test = useAction(async (id: string) => {
    await api.post(`v1/providers/${id}/test`)
    reload()
  })

  const disconnect = useAction(async (id: string) => {
    await api.delete(`v1/providers/${id}`)
    reload()
  })

  const providers = data?.data

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Gateways</h1>
          <p>Your own payment providers, and Aicountly Managed. Connect as many as you need and route between them.</p>
        </div>
      </section>

      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

      {providers && !providers.encryption_ready && (
        <div className="pay-error" role="alert" style={{ marginBottom: 14 }}>
          <AlertTriangle size={18} aria-hidden style={{ flexShrink: 0, marginTop: 1 }} />
          <div>
            {/* Refused outright rather than stored in the clear. */}
            <strong>This deployment cannot store a gateway credential safely.</strong>
            <span>
              No encryption key is configured, so connecting a provider is blocked. Set PAY_ENCRYPTION_KEY in the API
              environment first.
            </span>
          </div>
        </div>
      )}

      <div className="pay-grid">
        <Panel title="Connected providers" span={2}>
          {loading && !providers ? (
            <SkeletonRows rows={4} />
          ) : (providers?.connections ?? []).length === 0 ? (
            <EmptyState title="No providers connected">
              Connect your own gateway below, or activate Aicountly Managed Payments.
            </EmptyState>
          ) : (
            providers?.connections.map((connection) => (
              <div key={connection.connection_id} className="pay-provider">
                <span className="pay-provider__mark" aria-hidden>{connection.name.slice(0, 1).toUpperCase()}</span>

                <div className="pay-provider__body">
                  <div className="pay-provider__name">
                    {connection.name}
                    {connection.is_primary && <span className="pay-tag">Primary</span>}
                    {connection.environment === 'TEST' && <span className="pay-tag pay-tag--warning">Test</span>}
                  </div>

                  <div className="pay-provider__meta">{connection.methods.join(' · ') || 'No methods'}</div>

                  {connection.status_reason && (
                    <p style={{ margin: '6px 0 0', color: 'var(--pay-danger)', fontSize: 12 }}>{connection.status_reason}</p>
                  )}

                  {/* "Configured" and a tail. Never a value. */}
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
                    {Object.entries(connection.required_credentials).map(([kind, label]) => {
                      const stored = connection.credentials[kind]
                      return (
                        <span key={kind} className="pay-insight__fact">
                          {label}: {stored?.configured ? (stored.hint ?? 'Configured') : 'Not set'}
                        </span>
                      )
                    })}
                  </div>

                  <details style={{ marginTop: 10 }}>
                    <summary style={{ cursor: 'pointer', fontSize: 12.5, color: 'var(--pay-action)' }}>Webhook URL</summary>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 8 }}>
                      <code style={{ flex: 1, fontSize: 11, wordBreak: 'break-all', color: 'var(--pay-muted)' }}>
                        {connection.webhook_url}
                      </code>
                      <button
                        type="button"
                        className="pay-button pay-button--ghost pay-button--small"
                        onClick={async () => {
                          try {
                            await navigator.clipboard.writeText(connection.webhook_url)
                            setCopied(connection.connection_id)
                            window.setTimeout(() => setCopied(null), 2000)
                          } catch {
                            /* clipboard refused; the URL is selectable */
                          }
                        }}
                      >
                        <Copy size={13} aria-hidden /> {copied === connection.connection_id ? 'Copied' : 'Copy'}
                      </button>
                    </div>
                    <p style={{ margin: '6px 0 0', color: 'var(--pay-muted)', fontSize: 11.5 }}>
                      Paste this into the provider&rsquo;s dashboard, then put their signing secret above. Until both are set, Pay
                      rejects every callback from them.
                    </p>
                  </details>
                </div>

                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 8, flexShrink: 0 }}>
                  <StatusBadge status={connection.status} />

                  {can('providers.manage') && (
                    <div style={{ display: 'flex', gap: 6 }}>
                      <button
                        type="button"
                        className="pay-button pay-button--ghost pay-button--small"
                        disabled={test.busy}
                        onClick={() => test.run(connection.connection_id)}
                      >
                        <Zap size={13} aria-hidden /> Test
                      </button>
                      <button
                        type="button"
                        className="pay-button pay-button--ghost pay-button--small"
                        disabled={disconnect.busy}
                        onClick={() => {
                          if (window.confirm(`Disconnect ${connection.name}? Payments already taken through it keep their history.`)) {
                            disconnect.run(connection.connection_id)
                          }
                        }}
                      >
                        <Trash2 size={13} aria-hidden />
                      </button>
                    </div>
                  )}
                </div>
              </div>
            ))
          )}

          {test.error && <ErrorState message={test.error} />}
          {disconnect.error && <ErrorState message={disconnect.error} />}
        </Panel>

        <Panel title="Aicountly Managed" description="Collect through Aicountly's own arrangement">
          {loading && !providers ? (
            <SkeletonRows rows={3} />
          ) : providers?.managed.available ? (
            <>
              <p style={{ margin: '0 0 14px', color: 'var(--pay-text-soft)', fontSize: 13 }}>
                Apply once and collect through Aicountly&rsquo;s authorised payment partner. Verification is decided by the
                partner — Aicountly collects and forwards what they ask for.
              </p>
              <button type="button" className="pay-button pay-button--block" disabled={!can('managed.onboard')}>
                <ShieldCheck size={16} aria-hidden /> Activate Aicountly Managed Payments
              </button>
            </>
          ) : (
            // The honest sentence. Nothing here pretends Managed is live.
            <Unavailable title="Not configured on this deployment">
              {providers?.managed.reason ?? 'Aicountly Managed Payments is not available yet.'}
            </Unavailable>
          )}
        </Panel>

        {can('providers.manage') && providers?.encryption_ready && (
          <Panel title="Connect a provider" span={2}>
            {connect.error && <ErrorState message={connect.error} />}

            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
              {providers.available.map((provider) => (
                <button
                  key={provider.code}
                  type="button"
                  className={`pay-button ${connecting === provider.code ? '' : 'pay-button--ghost'}`}
                  onClick={() => setConnecting(connecting === provider.code ? null : provider.code)}
                >
                  <Plug size={15} aria-hidden /> {provider.name}
                </button>
              ))}
            </div>

            {connecting && (
              <form
                onSubmit={(event: FormEvent<HTMLFormElement>) => {
                  event.preventDefault()
                  connect.run(new FormData(event.currentTarget), connecting)
                }}
              >
                <div className="pay-row">
                  <label className="pay-field">
                    <span className="pay-field__label">Name it</span>
                    <input className="pay-input" name="display_name" placeholder={`My ${connecting}`} />
                  </label>

                  <label className="pay-field">
                    <span className="pay-field__label">Environment<span aria-hidden> *</span></span>
                    <select className="pay-input" name="environment" defaultValue="LIVE">
                      <option value="LIVE">Live</option>
                      <option value="TEST">Test</option>
                    </select>
                    <span className="pay-field__hint">
                      Pay checks the key against this. A test key in a live connection is refused.
                    </span>
                  </label>
                </div>

                {Object.entries(providers.credential_requirements[connecting] ?? {}).map(([kind, label]) => (
                  <label key={kind} className="pay-field">
                    <span className="pay-field__label">
                      {label}
                      {kind !== 'webhook_secret' && <span aria-hidden> *</span>}
                    </span>
                    <input
                      className="pay-input"
                      name={kind}
                      // Write-only, always. A password field is not security on
                      // its own, but a credential in a plain input ends up in a
                      // screenshot.
                      type="password"
                      autoComplete="off"
                      required={kind !== 'webhook_secret'}
                      aria-invalid={connect.field === `credentials.${kind}`}
                    />
                    {kind === 'webhook_secret' && (
                      <span className="pay-field__hint">
                        You can add this after creating the webhook at the provider with the URL Pay gives you.
                      </span>
                    )}
                  </label>
                ))}

                <p style={{ margin: '0 0 14px', color: 'var(--pay-muted)', fontSize: 11.5 }}>
                  Credentials are encrypted before they are stored and are never shown again. Pay tests them against the
                  provider before marking the connection active.
                </p>

                <button type="submit" className="pay-button" disabled={connect.busy}>
                  {connect.busy ? 'Testing the connection…' : 'Connect and test'}
                </button>
              </form>
            )}
          </Panel>
        )}

        <RoutingRules canManage={can('routing.manage')} />
      </div>
    </>
  )
}

// ---------------------------------------------------------------------------

interface RoutingResponse {
  rules: Array<{
    rule_id: number
    name: string
    priority: number
    is_active: boolean
    method: string | null
    currency: string | null
    primary: { connection_id: string; name: string } | null
    fallback: { connection_id: string; name: string } | null
    allow_failover: boolean
    suggested_by_ai: boolean
  }>
  connections: Array<{ connection_id: string; name: string; provider: string; mode: string; status: string; methods: string[] }>
  can_manage: boolean
}

function RoutingRules({ canManage }: { canManage: boolean }) {
  const [simulation, setSimulation] = useState<{ method: string; amount: string } | null>(null)

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.one<RoutingResponse>('v1/routing-rules', undefined, signal),
    [],
  )

  const create = useAction(async (form: FormData) => {
    await api.post('v1/routing-rules', {
      name: form.get('name'),
      method: form.get('method') || undefined,
      primary_connection_id: form.get('primary_connection_id'),
      fallback_connection_id: form.get('fallback_connection_id') || undefined,
      priority: Number(form.get('priority') ?? 100),
      allow_failover: true,
    })
    reload()
  })

  const remove = useAction(async (id: number) => {
    await api.delete(`v1/routing-rules/${id}`)
    reload()
  })

  const simulate = useApi(
    (signal) =>
      api.one<{
        ok: boolean
        chosen: { name: string; provider: string; mode: string } | null
        decided_by: string | null
        considered: Array<{ name: string; eligible: boolean; reason: string; detail: string }>
        error_message: string | null
      }>('v1/routing-rules/simulate', { method: simulation?.method, amount: simulation?.amount }, signal),
    [simulation?.method, simulation?.amount],
    simulation !== null,
  )

  return (
    <Panel
      title="Smart routing"
      description="Which provider takes which payment"
      span={4}
      footnote="A rule is a preference, never a permission. Pay refuses a provider that is disabled, mis-keyed, in the wrong environment, or cannot take the method — whatever a rule says."
    >
      <div id="routing" />

      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}
      {create.error && <ErrorState message={create.error} />}
      {remove.error && <ErrorState message={remove.error} />}

      {loading && !data ? (
        <SkeletonRows rows={4} />
      ) : (
        <>
          {(data?.data.rules ?? []).length === 0 ? (
            <EmptyState title="No routing rules">
              Without rules, Pay uses your primary provider and falls back to the next healthy one.
            </EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr>
                    <th className="num">#</th>
                    <th>Rule</th>
                    <th>Applies to</th>
                    <th>Primary</th>
                    <th>Fallback</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {data?.data.rules.map((rule) => (
                    <tr key={rule.rule_id}>
                      <td className="num">{rule.priority}</td>
                      <td>
                        <strong style={{ fontSize: 13 }}>{rule.name}</strong>
                        {rule.suggested_by_ai && <span className="pay-tag" style={{ marginLeft: 7 }}>Pay Pulse suggested</span>}
                      </td>
                      <td>{rule.method ? humanise(rule.method) : 'Every payment'}</td>
                      <td>{rule.primary?.name ?? '—'}</td>
                      <td>{rule.allow_failover ? (rule.fallback?.name ?? '—') : 'No failover'}</td>
                      <td>
                        {canManage && (
                          <button
                            type="button"
                            className="pay-button pay-button--ghost pay-button--small"
                            disabled={remove.busy}
                            onClick={() => remove.run(rule.rule_id)}
                          >
                            <Trash2 size={13} aria-hidden />
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {canManage && (data?.data.connections ?? []).length > 0 && (
            <form
              style={{ marginTop: 16, paddingTop: 16, borderTop: '1px solid var(--pay-border)' }}
              onSubmit={(event) => {
                event.preventDefault()
                create.run(new FormData(event.currentTarget))
                event.currentTarget.reset()
              }}
            >
              <div className="pay-row">
                <label className="pay-field">
                  <span className="pay-field__label">Rule name<span aria-hidden> *</span></span>
                  <input className="pay-input" name="name" required placeholder="UPI through Aicountly Managed" />
                </label>

                <label className="pay-field">
                  <span className="pay-field__label">Applies to</span>
                  <select className="pay-input" name="method">
                    <option value="">Every payment</option>
                    {['UPI', 'CARD', 'NETBANKING', 'WALLET', 'EMI', 'PAYLATER'].map((method) => (
                      <option key={method} value={method}>{humanise(method)}</option>
                    ))}
                  </select>
                </label>

                <label className="pay-field">
                  <span className="pay-field__label">Send it to<span aria-hidden> *</span></span>
                  <select className="pay-input" name="primary_connection_id" required>
                    {data?.data.connections.map((connection) => (
                      <option key={connection.connection_id} value={connection.connection_id}>{connection.name}</option>
                    ))}
                  </select>
                </label>

                <label className="pay-field">
                  <span className="pay-field__label">Fall back to</span>
                  <select className="pay-input" name="fallback_connection_id">
                    <option value="">Nothing</option>
                    {data?.data.connections.map((connection) => (
                      <option key={connection.connection_id} value={connection.connection_id}>{connection.name}</option>
                    ))}
                  </select>
                </label>

                <label className="pay-field">
                  <span className="pay-field__label">Priority</span>
                  <input className="pay-input" name="priority" type="number" min="1" max="999" defaultValue={100} />
                  <span className="pay-field__hint">Lower runs first.</span>
                </label>
              </div>

              <button type="submit" className="pay-button" disabled={create.busy}>
                Add rule
              </button>
            </form>
          )}

          {/* The most useful thing on this screen: see where a payment WOULD go
              before a customer finds out. */}
          <div style={{ marginTop: 16, paddingTop: 16, borderTop: '1px solid var(--pay-border)' }}>
            <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Where would a payment go?</h3>

            <form
              style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}
              onSubmit={(event) => {
                event.preventDefault()
                const form = new FormData(event.currentTarget)
                setSimulation({ method: String(form.get('sim_method') ?? ''), amount: String(form.get('sim_amount') ?? '1000') })
              }}
            >
              <label className="pay-field" style={{ marginBottom: 0 }}>
                <span className="pay-field__label">Method</span>
                <select className="pay-input" name="sim_method" defaultValue="UPI">
                  {['UPI', 'CARD', 'NETBANKING', 'WALLET'].map((method) => (
                    <option key={method} value={method}>{humanise(method)}</option>
                  ))}
                </select>
              </label>

              <label className="pay-field" style={{ marginBottom: 0 }}>
                <span className="pay-field__label">Amount</span>
                <input className="pay-input" name="sim_amount" type="number" defaultValue={50000} min="1" />
              </label>

              <button type="submit" className="pay-button pay-button--soft">Check</button>
            </form>

            {simulation && simulate.data && (
              <div style={{ marginTop: 12 }}>
                {simulate.data.data.ok ? (
                  <div className="pay-unavailable" style={{ borderColor: 'var(--pay-mint-strong)', background: 'var(--pay-mint)' }}>
                    <strong style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
                      <CheckCircle2 size={15} aria-hidden /> {simulate.data.data.chosen?.name}
                    </strong>
                    Decided by {humanise(simulate.data.data.decided_by ?? 'default')}.
                  </div>
                ) : (
                  <div className="pay-error" role="status">
                    <AlertTriangle size={17} aria-hidden style={{ flexShrink: 0, marginTop: 1 }} />
                    <span>{simulate.data.data.error_message}</span>
                  </div>
                )}

                {/* Including the ones that lost, with the reason. "Stripe was
                    skipped: it does not take UPI" beats "routing failed". */}
                {simulate.data.data.considered.length > 0 && (
                  <div style={{ marginTop: 10 }}>
                    {simulate.data.data.considered.map((candidate) => (
                      <div key={candidate.name} className="pay-action-row" style={{ cursor: 'default', padding: '8px 0' }}>
                        <span style={{ minWidth: 0 }}>
                          <span className="pay-action-row__label">{candidate.name}</span>
                          <span className="pay-action-row__detail">{candidate.detail}</span>
                        </span>
                        <Badge tone={candidate.eligible ? 'success' : 'neutral'}>
                          {candidate.eligible ? 'Eligible' : humanise(candidate.reason)}
                        </Badge>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
        </>
      )}
    </Panel>
  )
}
