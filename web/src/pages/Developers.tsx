/**
 * The developer surface: API clients, keys, webhook endpoints and the logs.
 *
 * THE SECRET IS SHOWN EXACTLY ONCE, here, and never again. That is the contract
 * a developer expects and the only thing that makes hashing it worth anything —
 * so the screen is explicit about it rather than leaving somebody to discover
 * it after they close the dialog.
 */

import { useState } from 'react'
import { Code2, Copy, KeyRound, RefreshCw, Trash2, Webhook } from 'lucide-react'
import { useAction, useApi } from '../hooks/useApi'
import { api } from '../services/api'
import { date, dateTime, humanise } from '../services/format'
import { Badge, EmptyState, ErrorState, Panel, SkeletonRows } from '../dashboards/kit'

interface DeveloperResponse {
  clients: Array<{ client_id: string; name: string; description: string | null; source_app: string; status: string; created_at: string }>
  keys: Array<{ key_id: string; prefix: string; environment: string; scopes: string[]; status: string; last_used_at: string | null; use_count: number; created_at: string; client_id: string; client_name: string }>
  endpoints: Array<{ endpoint_id: string; target_url: string; events: string[]; status: string; secret_hint: string | null; consecutive_failures: number; last_success_at: string | null; last_failure_reason: string | null }>
  scopes: Record<string, string>
  events: string[]
  can_manage: boolean
  api: {
    base_url: string
    auth: { scheme: string; note: string }
    idempotency: { header: string; note: string }
    endpoints: Array<{ method: string; path: string; scope: string; summary: string }>
    webhooks: { signature_header: string; format: string; note: string; events: string[] }
    minimum_request: Record<string, unknown>
  }
}

export function Developers() {
  const [tab, setTab] = useState<'keys' | 'webhooks' | 'logs' | 'reference'>('keys')
  const [issued, setIssued] = useState<{ secret: string; notice: string } | null>(null)

  const { data, loading, error, retryable, reload } = useApi(
    (signal) => api.one<DeveloperResponse>('v1/developer', undefined, signal),
    [],
  )

  const createClient = useAction(async (form: FormData) => {
    await api.post('v1/developer/clients', {
      name: form.get('name'),
      source_app: (form.get('source_app') as string)?.trim().toUpperCase() || 'EXTERNAL',
      description: form.get('description') || undefined,
    })
    reload()
  })

  const createKey = useAction(async (form: FormData) => {
    const scopes = form.getAll('scopes').map(String)
    const response = await api.post<{ secret: string; secret_notice: string }>('v1/developer/keys', {
      client_id: form.get('client_id'),
      environment: form.get('environment'),
      scopes,
    })
    setIssued({ secret: response.data.secret, notice: response.data.secret_notice })
    reload()
  })

  const revoke = useAction(async (id: string) => {
    await api.delete(`v1/developer/keys/${id}`)
    reload()
  })

  const createEndpoint = useAction(async (form: FormData) => {
    const response = await api.post<{ signing_secret: string; secret_notice: string }>('v1/developer/endpoints', {
      target_url: form.get('target_url'),
      events: form.getAll('events').map(String),
    })
    setIssued({ secret: response.data.signing_secret, notice: response.data.secret_notice })
    reload()
  })

  const developer = data?.data

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>Developers</h1>
          <p>
            How software that is not an Aicountly product uses Pay — your own website, a custom ERP, or an Aicountly app
            that has not been built yet.
          </p>
        </div>
      </section>

      {/* Once. The screen says so, because a developer who closes this dialog
          without copying has to mint a new key. */}
      {issued && (
        <Panel title="Copy this now" description="It is hashed on our side and cannot be shown again">
          <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
            <code
              style={{
                flex: 1,
                minWidth: 260,
                padding: '12px 14px',
                background: 'var(--pay-surface-2)',
                border: '1px solid var(--pay-border)',
                borderRadius: 10,
                fontSize: 12.5,
                wordBreak: 'break-all',
              }}
            >
              {issued.secret}
            </code>
            <button
              type="button"
              className="pay-button"
              onClick={async () => {
                try {
                  await navigator.clipboard.writeText(issued.secret)
                } catch {
                  /* clipboard refused; the value is selectable */
                }
              }}
            >
              <Copy size={16} aria-hidden /> Copy
            </button>
            <button type="button" className="pay-button pay-button--ghost" onClick={() => setIssued(null)}>
              Done
            </button>
          </div>
          <p style={{ margin: '10px 0 0', color: 'var(--pay-warning)', fontSize: 12.5, fontWeight: 600 }}>{issued.notice}</p>
        </Panel>
      )}

      <nav className="pay-switcher" aria-label="Developer section" style={{ marginTop: issued ? 14 : 0 }}>
        {[
          { key: 'keys', label: 'API keys' },
          { key: 'webhooks', label: 'Webhooks' },
          { key: 'logs', label: 'Logs' },
          { key: 'reference', label: 'API reference' },
        ].map((entry) => (
          <a
            key={entry.key}
            href="#"
            className={tab === entry.key ? 'is-active' : ''}
            onClick={(event) => {
              event.preventDefault()
              setTab(entry.key as typeof tab)
            }}
          >
            {entry.label}
          </a>
        ))}
      </nav>

      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

      <div className="pay-grid">
        {tab === 'keys' && (
          <>
            <Panel title="API keys" span={2}>
              {loading && !developer ? (
                <SkeletonRows rows={4} />
              ) : (developer?.keys ?? []).length === 0 ? (
                <EmptyState title="No API keys">Register an integration, then mint a key for it.</EmptyState>
              ) : (
                <div className="pay-table-wrap">
                  <table className="pay-table">
                    <thead>
                      <tr><th>Key</th><th>Integration</th><th>Scopes</th><th>Last used</th><th>Status</th><th /></tr>
                    </thead>
                    <tbody>
                      {developer?.keys.map((key) => (
                        <tr key={key.key_id}>
                          <td>
                            <code style={{ fontSize: 12 }}>{key.prefix}</code>
                            {key.environment === 'TEST' && <span className="pay-tag pay-tag--warning" style={{ marginLeft: 6 }}>Test</span>}
                          </td>
                          <td>{key.client_name}</td>
                          <td style={{ maxWidth: 200, fontSize: 11.5, color: 'var(--pay-muted)' }}>{key.scopes.join(', ')}</td>
                          <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)', fontSize: 12 }}>
                            {key.last_used_at ? date(key.last_used_at) : 'Never'}
                          </td>
                          <td><Badge tone={key.status === 'ACTIVE' ? 'success' : 'neutral'}>{humanise(key.status)}</Badge></td>
                          <td>
                            {developer?.can_manage && key.status === 'ACTIVE' && (
                              <button
                                type="button"
                                className="pay-button pay-button--ghost pay-button--small"
                                disabled={revoke.busy}
                                onClick={() => {
                                  if (window.confirm('Revoke this key? Anything using it stops working immediately.')) {
                                    revoke.run(key.key_id)
                                  }
                                }}
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
              {revoke.error && <ErrorState message={revoke.error} />}
            </Panel>

            {developer?.can_manage && (
              <Panel title="Mint a key">
                {createKey.error && <ErrorState message={createKey.error} />}

                {(developer.clients ?? []).length === 0 ? (
                  <p style={{ margin: 0, color: 'var(--pay-muted)', fontSize: 13 }}>Register an integration first.</p>
                ) : (
                  <form
                    onSubmit={(event) => {
                      event.preventDefault()
                      createKey.run(new FormData(event.currentTarget))
                    }}
                  >
                    <label className="pay-field">
                      <span className="pay-field__label">Integration<span aria-hidden> *</span></span>
                      <select className="pay-input" name="client_id" required>
                        {developer.clients.map((client) => (
                          <option key={client.client_id} value={client.client_id}>{client.name}</option>
                        ))}
                      </select>
                    </label>

                    <label className="pay-field">
                      <span className="pay-field__label">Environment</span>
                      <select className="pay-input" name="environment" defaultValue="TEST">
                        <option value="TEST">Test</option>
                        <option value="LIVE">Live</option>
                      </select>
                    </label>

                    <fieldset style={{ border: 0, padding: 0, margin: '0 0 12px' }}>
                      <legend className="pay-field__label">What it may do</legend>
                      {Object.entries(developer.scopes).map(([scope, label]) => (
                        <label key={scope} className="pay-check" style={{ marginBottom: 6 }}>
                          <input type="checkbox" name="scopes" value={scope} />
                          <span style={{ fontSize: 12.5 }}>{label}</span>
                        </label>
                      ))}
                    </fieldset>

                    <button type="submit" className="pay-button pay-button--block" disabled={createKey.busy}>
                      <KeyRound size={16} aria-hidden /> Mint key
                    </button>
                  </form>
                )}
              </Panel>
            )}

            <Panel title="Integrations" span={2} description="What payment requests from each are attributed to">
              {(developer?.clients ?? []).length === 0 ? (
                <EmptyState title="No integrations yet">
                  Register one and its requests appear in &ldquo;Open requests by source&rdquo; under its own name.
                </EmptyState>
              ) : (
                developer?.clients.map((client) => (
                  <div key={client.client_id} className="pay-action-row" style={{ cursor: 'default' }}>
                    <span style={{ minWidth: 0 }}>
                      <span className="pay-action-row__label">{client.name}</span>
                      <span className="pay-action-row__detail">
                        Attributed to {client.source_app}
                        {client.description && ` · ${client.description}`}
                      </span>
                    </span>
                    <Badge tone={client.status === 'ACTIVE' ? 'success' : 'neutral'}>{humanise(client.status)}</Badge>
                  </div>
                ))
              )}
            </Panel>

            {developer?.can_manage && (
              <Panel title="Register an integration">
                {createClient.error && <ErrorState message={createClient.error} />}

                <form
                  onSubmit={(event) => {
                    event.preventDefault()
                    createClient.run(new FormData(event.currentTarget))
                    event.currentTarget.reset()
                  }}
                >
                  <label className="pay-field">
                    <span className="pay-field__label">Name<span aria-hidden> *</span></span>
                    <input className="pay-input" name="name" required placeholder="Our website" />
                  </label>

                  <label className="pay-field">
                    <span className="pay-field__label">Source name</span>
                    <input className="pay-input" name="source_app" placeholder="WEBSITE" pattern="[A-Za-z][A-Za-z0-9_]*" />
                    <span className="pay-field__hint">
                      Capital letters and underscores. Its payment requests are grouped under this on every dashboard.
                    </span>
                  </label>

                  <button type="submit" className="pay-button pay-button--block" disabled={createClient.busy}>
                    <Code2 size={16} aria-hidden /> Register
                  </button>
                </form>
              </Panel>
            )}
          </>
        )}

        {tab === 'webhooks' && (
          <>
            <Panel title="Your webhook endpoints" span={2}>
              {(developer?.endpoints ?? []).length === 0 ? (
                <EmptyState title="No endpoints">Register a URL and Pay will POST its events to it, signed.</EmptyState>
              ) : (
                developer?.endpoints.map((endpoint) => (
                  <div key={endpoint.endpoint_id} className="pay-action-row" style={{ cursor: 'default' }}>
                    <span style={{ minWidth: 0 }}>
                      <span className="pay-action-row__label" style={{ wordBreak: 'break-all' }}>{endpoint.target_url}</span>
                      <span className="pay-action-row__detail">
                        {endpoint.events.length === 0 ? 'Every event' : `${endpoint.events.length} events`}
                        {endpoint.secret_hint && ` · secret ${endpoint.secret_hint}`}
                        {endpoint.consecutive_failures > 0 && ` · ${endpoint.consecutive_failures} failures in a row`}
                      </span>
                      {endpoint.last_failure_reason && (
                        <span style={{ display: 'block', color: 'var(--pay-danger)', fontSize: 11.5 }}>
                          {endpoint.last_failure_reason}
                        </span>
                      )}
                    </span>
                    <Badge tone={endpoint.status === 'ACTIVE' ? 'success' : 'warning'}>{humanise(endpoint.status)}</Badge>
                  </div>
                ))
              )}
            </Panel>

            {developer?.can_manage && (
              <Panel title="Add an endpoint">
                {createEndpoint.error && <ErrorState message={createEndpoint.error} />}

                <form
                  onSubmit={(event) => {
                    event.preventDefault()
                    createEndpoint.run(new FormData(event.currentTarget))
                    event.currentTarget.reset()
                  }}
                >
                  <label className="pay-field">
                    <span className="pay-field__label">URL<span aria-hidden> *</span></span>
                    <input className="pay-input" name="target_url" type="url" required placeholder="https://your-site.example/pay/webhook" />
                    <span className="pay-field__hint">Must be https. Payment events name amounts and references.</span>
                  </label>

                  <fieldset style={{ border: 0, padding: 0, margin: '0 0 12px', maxHeight: 200, overflowY: 'auto' }}>
                    <legend className="pay-field__label">Events (none selected means all)</legend>
                    {(developer.events ?? []).map((event) => (
                      <label key={event} className="pay-check" style={{ marginBottom: 4 }}>
                        <input type="checkbox" name="events" value={event} />
                        <span style={{ fontSize: 12 }}>{event}</span>
                      </label>
                    ))}
                  </fieldset>

                  <button type="submit" className="pay-button pay-button--block" disabled={createEndpoint.busy}>
                    <Webhook size={16} aria-hidden /> Add endpoint
                  </button>
                </form>
              </Panel>
            )}
          </>
        )}

        {tab === 'logs' && <Logs />}

        {tab === 'reference' && developer && (
          <Panel title="API reference" span={4}>
            <h3 style={{ margin: '0 0 8px', fontSize: 14 }}>Authentication</h3>
            <pre style={preStyle}>{developer.api.auth.scheme}</pre>
            <p style={{ margin: '0 0 16px', color: 'var(--pay-muted)', fontSize: 12.5 }}>{developer.api.auth.note}</p>

            <h3 style={{ margin: '0 0 8px', fontSize: 14 }}>Idempotency</h3>
            <pre style={preStyle}>{developer.api.idempotency.header}: &lt;a key you choose&gt;</pre>
            <p style={{ margin: '0 0 16px', color: 'var(--pay-muted)', fontSize: 12.5 }}>{developer.api.idempotency.note}</p>

            <h3 style={{ margin: '0 0 8px', fontSize: 14 }}>Endpoints</h3>
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr><th>Method</th><th>Path</th><th>Scope</th><th>What it does</th></tr>
                </thead>
                <tbody>
                  {developer.api.endpoints.map((endpoint) => (
                    <tr key={`${endpoint.method}-${endpoint.path}`}>
                      <td><code style={{ fontSize: 12 }}>{endpoint.method}</code></td>
                      <td><code style={{ fontSize: 12 }}>{developer.api.base_url}{endpoint.path}</code></td>
                      <td style={{ fontSize: 11.5, color: 'var(--pay-muted)' }}>{endpoint.scope}</td>
                      <td style={{ fontSize: 12.5 }}>{endpoint.summary}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <h3 style={{ margin: '18px 0 8px', fontSize: 14 }}>The smallest request that works</h3>
            <p style={{ margin: '0 0 8px', color: 'var(--pay-muted)', fontSize: 12.5 }}>
              Everything beyond this is optional. A future Aicountly app needs no changes to Pay — it registers a source
              name above and posts this.
            </p>
            <pre style={preStyle}>{JSON.stringify(developer.api.minimum_request, null, 2)}</pre>

            <h3 style={{ margin: '18px 0 8px', fontSize: 14 }}>Verifying our signature</h3>
            <pre style={preStyle}>{developer.api.webhooks.signature_header}: {developer.api.webhooks.format}</pre>
            <p style={{ margin: 0, color: 'var(--pay-muted)', fontSize: 12.5 }}>{developer.api.webhooks.note}</p>
          </Panel>
        )}
      </div>
    </>
  )
}

const preStyle: React.CSSProperties = {
  margin: '0 0 8px',
  padding: '12px 14px',
  background: 'var(--pay-surface-2)',
  border: '1px solid var(--pay-border)',
  borderRadius: 10,
  fontSize: 12,
  overflowX: 'auto',
}

function Logs() {
  const [which, setWhich] = useState<'inbound' | 'outbound'>('inbound')

  const inbound = useApi(
    (signal) => api.list<{ event_id: string; provider: string; provider_event: string | null; event: string | null; status: string; signature_valid: boolean; signature_reason: string | null; error: string | null; processing_ms: number | null; received_at: string }>(
      'v1/developer/webhook-log', { limit: 50 }, signal),
    [which],
    which === 'inbound',
  )

  const outbound = useApi(
    (signal) => api.list<{ outbound_id: number; event_id: string; event_label: string; target: string; status: string; attempts: number; max_attempts: number; last_error: string | null; delivered_at: string | null; created_at: string; can_retry: boolean }>(
      'v1/developer/outbound-log', { limit: 50 }, signal),
    [which],
    which === 'outbound',
  )

  const retry = useAction(async (id: number) => {
    await api.post(`v1/developer/outbound/${id}/retry`)
    outbound.reload()
  })

  return (
    <Panel
      title="Logs"
      span={4}
      action={
        <div style={{ display: 'flex', gap: 6 }}>
          <button
            type="button"
            className={`pay-button pay-button--small ${which === 'inbound' ? '' : 'pay-button--ghost'}`}
            onClick={() => setWhich('inbound')}
          >
            From providers
          </button>
          <button
            type="button"
            className={`pay-button pay-button--small ${which === 'outbound' ? '' : 'pay-button--ghost'}`}
            onClick={() => setWhich('outbound')}
          >
            To your apps
          </button>
        </div>
      }
      footnote="The raw payload is not shown: a provider callback can carry a payer's contact details, and this screen is open to anybody with developer access."
    >
      {which === 'inbound' ? (
        inbound.loading && !inbound.data ? (
          <SkeletonRows rows={6} />
        ) : (inbound.data?.data ?? []).length === 0 ? (
          <EmptyState title="No callbacks received">Provider callbacks appear here as they arrive.</EmptyState>
        ) : (
          <div className="pay-table-wrap">
            <table className="pay-table">
              <thead>
                <tr><th>Received</th><th>Provider</th><th>Their event</th><th>Read as</th><th>Signature</th><th>Result</th><th className="num">Time</th></tr>
              </thead>
              <tbody>
                {inbound.data?.data.map((row) => (
                  <tr key={row.event_id}>
                    <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>{dateTime(row.received_at)}</td>
                    <td>{row.provider}</td>
                    <td style={{ fontSize: 12 }}>{row.provider_event ?? '—'}</td>
                    <td style={{ fontSize: 12 }}>{row.event ?? '—'}</td>
                    <td>
                      <Badge tone={row.signature_valid ? 'success' : 'danger'}>
                        {row.signature_valid ? 'Verified' : 'Failed'}
                      </Badge>
                    </td>
                    <td>
                      <Badge tone={row.status === 'PROCESSED' ? 'success' : row.status === 'DUPLICATE' ? 'neutral' : row.status === 'IGNORED' ? 'info' : 'danger'}>
                        {humanise(row.status)}
                      </Badge>
                      {row.error && <span style={{ display: 'block', color: 'var(--pay-danger)', fontSize: 11 }}>{row.error}</span>}
                    </td>
                    <td className="num">{row.processing_ms === null ? '—' : `${row.processing_ms} ms`}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      ) : outbound.loading && !outbound.data ? (
        <SkeletonRows rows={6} />
      ) : (outbound.data?.data ?? []).length === 0 ? (
        <EmptyState title="Nothing sent yet">Events Pay owes your apps appear here.</EmptyState>
      ) : (
        <>
          {retry.error && <ErrorState message={retry.error} />}
          <div className="pay-table-wrap">
            <table className="pay-table">
              <thead>
                <tr><th>Created</th><th>Event</th><th>To</th><th className="num">Attempts</th><th>Status</th><th /></tr>
              </thead>
              <tbody>
                {outbound.data?.data.map((row) => (
                  <tr key={row.outbound_id}>
                    <td style={{ whiteSpace: 'nowrap', color: 'var(--pay-muted)' }}>{dateTime(row.created_at)}</td>
                    <td style={{ fontSize: 12.5 }}>{row.event_label}</td>
                    <td>{row.target}</td>
                    <td className="num">{row.attempts} / {row.max_attempts}</td>
                    <td>
                      <Badge tone={row.status === 'DELIVERED' ? 'success' : row.status === 'EXHAUSTED' ? 'danger' : 'warning'}>
                        {humanise(row.status)}
                      </Badge>
                      {row.last_error && <span style={{ display: 'block', color: 'var(--pay-danger)', fontSize: 11 }}>{row.last_error}</span>}
                    </td>
                    <td>
                      {row.can_retry && (
                        <button
                          type="button"
                          className="pay-button pay-button--ghost pay-button--small"
                          disabled={retry.busy}
                          onClick={() => retry.run(row.outbound_id)}
                        >
                          <RefreshCw size={13} aria-hidden /> Retry
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </Panel>
  )
}
