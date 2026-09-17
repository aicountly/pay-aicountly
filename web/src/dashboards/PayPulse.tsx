/**
 * Dashboard 5 — Pay Pulse.
 *
 * "What should I do next?", answered from this company's own payment data.
 *
 * THREE THINGS THIS SCREEN DOES THAT AN AI DASHBOARD USUALLY DOES NOT:
 *
 *  1. It shows its working. Every insight carries the counts it was computed
 *     from and a confidence figure, and the forecast states its method in one
 *     sentence on the chart. A merchant who can check a number will act on it;
 *     a black box gets ignored, and an ignored recommendation occupies the
 *     space where a useful one would go.
 *
 *  2. It says plainly when it does not know enough yet, rather than drawing a
 *     confident-looking line through four data points.
 *
 *  3. It says, on the screen, whether it is allowed to change anything. Auto
 *     optimisation is off by default and the card says so — nobody should have
 *     to wonder whether software is moving their money.
 */

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, Bell, Repeat, Sparkles, Target, TrendingUp, Users } from 'lucide-react'
import { api } from '../services/api'
import { money, moneyWhole, percent } from '../services/format'
import type { PulseDashboard as PulseDashboardData, PulseHeadline } from '../services/types'
import { ForecastChart } from './Chart'
import { DashboardLayout } from './DashboardLayout'
import { useDashboard } from './useDashboard'
import { EmptyState, ErrorState, InsightRow, MetricRow, Panel, SkeletonRows, Unavailable } from './kit'

const ICONS = {
  expected_7d: <TrendingUp size={18} />,
  high_confidence: <Target size={18} />,
  at_risk: <AlertTriangle size={18} />,
  conversion_lift: <TrendingUp size={18} />,
  reminder_effectiveness: <Bell size={18} />,
  repeat_payers: <Users size={18} />,
}

const TONES = { at_risk: 'danger' } as const

export default function PayPulse() {
  const navigate = useNavigate()
  const { data, loading, error, retryable, period, setPeriod, reload } =
    useDashboard<PulseDashboardData>('v1/dashboards/pay-pulse', 'last_30')
  const [acting, setActing] = useState<string | null>(null)

  const forecast = data?.panels.forecast
  const behaviour = data?.panels.behaviour ?? []
  const actions = data?.panels.actions ?? []
  const autoRouting = data?.panels.auto_routing

  /**
   * Marking an insight acted on is NOT applying it.
   *
   * A routing change goes through the routing screen, with its own permission
   * and its own audit entry. A one-click "do what the AI said" is how an
   * automated decision loses its paper trail.
   */
  async function act(insight: PulseHeadline) {
    if (!insight.action) return

    setActing(insight.insight_id)
    try {
      await api.post(`v1/pay-pulse/insights/${insight.insight_id}`, { status: 'ACTIONED' })

      const target = insight.action.target
      if (target === 'routing') navigate('/gateways#routing')
      else if (target === 'reconciliation') navigate('/reconciliation')
      else if (target === 'customers') navigate('/customers?filter=inactive')
      else navigate('/requests')
    } finally {
      setActing(null)
    }
  }

  return (
    <DashboardLayout
      title={<>Pay Pulse / <span>Payment Intelligence</span></>}
      subtitle="Smarter insights. Higher collections. Healthier cash flow."
      period={period}
      onPeriodChange={setPeriod}
      description={data?.period ?? null}
    >
      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

      {data && !data.enabled && (
        <Unavailable title="Pay Pulse is switched off">
          {data.reason ?? 'Turn it on in Settings to see payment intelligence for this company.'}
        </Unavailable>
      )}

      {data?.warming_up && (
        <Unavailable title="Pay Pulse is still warming up">
          {data.reason}
          <span style={{ display: 'block', marginTop: 8 }}>
            Its recommendations are computed from this company&rsquo;s own payments, so it needs a few of them first.
          </span>
        </Unavailable>
      )}

      {/* Warming up is about the BEHAVIOUR MODEL, which needs a run of payments
          to say anything true. An individual finding — two failures still worth
          chasing, a settlement that does not match — is computed from the rows
          that exist and is worth acting on today. Hiding those behind the same
          notice would withhold real money while the model catches up. */}
      {data?.enabled && data.warming_up && actions.length > 0 && (
        <div className="pay-grid" style={{ marginTop: 14 }}>
          <Panel
            title="Worth acting on already"
            description="Computed from the payments recorded so far, not from a model"
            span={4}
            action={<span className="pay-count pay-count--neutral">{actions.length}</span>}
          >
            {actions.map((insight) => (
              <div key={insight.insight_id} style={{ opacity: acting === insight.insight_id ? 0.55 : 1 }}>
                <InsightRow insight={insight} onAct={data.panels.can_act ? act : undefined} />
              </div>
            ))}
          </Panel>
        </div>
      )}

      {data?.enabled && !data.warming_up && (
        <>
          <section className="pay-hero" style={{ marginBottom: 14 }}>
            <div>
              <span className="pay-hero__mark">
                <Sparkles size={14} aria-hidden /> Pay Pulse
              </span>
              <h2>Higher collections, smarter every day.</h2>
              <p>
                Pay Pulse reads this company&rsquo;s own payment behaviour — nothing from other merchants, and nothing
                from your ledgers. It predicts, recommends and explains; it does not act on its own.
              </p>

              {autoRouting && (
                <p style={{ margin: 0, fontSize: 12.5, color: autoRouting.enabled ? 'var(--pay-warning)' : 'var(--pay-text-soft)' }}>
                  <strong>{autoRouting.enabled ? 'Auto-optimisation is on.' : 'Auto-optimisation is off.'}</strong>{' '}
                  {autoRouting.detail}
                </p>
              )}
            </div>

            {forecast && (
              <div className="pay-hero__stat">
                <small>Expected next 7 days</small>
                <strong>{moneyWhole(forecast.expected_minor / 100)}</strong>
                <small>{forecast.confidence}% confidence</small>
              </div>
            )}
          </section>

          <MetricRow metrics={data.metrics} loading={loading} icons={ICONS} tones={TONES} />

          <div className="pay-grid">
            <Panel
              title="Collection forecast"
              description="Actuals behind, expectation ahead"
              span={3}
              footnote={forecast?.basis}
            >
              {loading && !data ? (
                <SkeletonRows rows={6} />
              ) : forecast ? (
                <ForecastChart points={forecast.points} confidence={forecast.confidence} />
              ) : null}
            </Panel>

            <Panel title="What it is built on" description="So you can check it">
              {forecast && (
                <div>
                  <FactRow label="Expected next 7 days" value={money(forecast.expected_minor / 100)} />
                  <FactRow label="High confidence" value={money(forecast.high_confidence_minor / 100)} />
                  <FactRow label="At risk" value={money(forecast.at_risk_minor / 100)} tone="danger" />
                  <FactRow label="Your payers typically take" value={`${forecast.median_days_to_pay} days`} />
                  {data.panels.sample && (
                    <FactRow
                      label="Computed from"
                      value={`${data.panels.sample.payments} payments, ${data.panels.sample.payers} payers`}
                    />
                  )}
                </div>
              )}
            </Panel>

            <Panel
              title="Payment behaviour"
              description="How this company's payers actually behave, over the last 90 days"
              span={2}
            >
              {behaviour.length === 0 ? (
                <EmptyState title="Not enough payments yet">
                  Behaviour appears once there are enough payments to describe a pattern.
                </EmptyState>
              ) : (
                <div className="pay-behaviour">
                  {behaviour.map((segment) => (
                    <div key={segment.key} className="pay-behaviour__card">
                      <strong>{percent(segment.share)}</strong>
                      <span>{segment.label}</span>
                      <small>{segment.detail}</small>
                    </div>
                  ))}
                </div>
              )}
            </Panel>

            <Panel
              title="Action queue"
              description="Ranked by what it is worth"
              span={2}
              action={actions.length > 0 ? <span className="pay-count pay-count--neutral">{actions.length}</span> : undefined}
            >
              {loading && !data ? (
                <SkeletonRows rows={5} />
              ) : actions.length === 0 ? (
                <EmptyState title="Nothing to act on">
                  No failing method, no request at risk, no settlement difference. A quiet day is a good one.
                </EmptyState>
              ) : (
                actions.map((insight) => (
                  <div key={insight.insight_id} style={{ opacity: acting === insight.insight_id ? 0.55 : 1 }}>
                    <InsightRow insight={insight} onAct={data.panels.can_act ? act : undefined} />
                  </div>
                ))
              )}
            </Panel>
          </div>
        </>
      )}

      {data && !data.enabled && (
        <div className="pay-grid" style={{ marginTop: 14 }}>
          <Panel title="What Pay Pulse does" span={4}>
            <div className="pay-behaviour">
              {[
                { icon: <TrendingUp size={18} />, title: 'Predict', detail: 'What is likely to be collected, and when' },
                { icon: <Repeat size={18} />, title: 'Recover', detail: 'Which failed payments are still worth chasing, and how' },
                { icon: <Target size={18} />, title: 'Route', detail: 'Which of your providers is performing better, per method' },
                { icon: <AlertTriangle size={18} />, title: 'Detect', detail: 'Failure spikes and settlement differences' },
                { icon: <Sparkles size={18} />, title: 'Optimise', detail: 'Which channel and timing actually gets you paid' },
              ].map((capability) => (
                <div key={capability.title} className="pay-behaviour__card">
                  <span style={{ color: 'var(--pay-action)' }} aria-hidden>{capability.icon}</span>
                  <span>{capability.title}</span>
                  <small>{capability.detail}</small>
                </div>
              ))}
            </div>
          </Panel>
        </div>
      )}
    </DashboardLayout>
  )
}

function FactRow({ label, value, tone }: { label: string; value: string; tone?: 'danger' }) {
  return (
    <div className="pay-action-row" style={{ cursor: 'default' }}>
      <span className="pay-action-row__label">{label}</span>
      <strong className="num" style={{ fontSize: 13.5, color: tone === 'danger' ? 'var(--pay-danger)' : undefined }}>
        {value}
      </strong>
    </div>
  )
}
