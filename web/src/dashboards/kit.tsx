/**
 * The pieces every Pay screen is built from.
 *
 * Three rules run through all of them, and they are the difference between a
 * dashboard a merchant trusts and one they learn to ignore.
 *
 *  1. A FIGURE THAT COULD NOT BE READ RENDERS AS "UNAVAILABLE" WITH THE REASON,
 *     never as ₹0.00. A zero and an outage look identical to the person reading
 *     it, and one of them means "quiet day".
 *
 *  2. COLOUR IS NEVER THE ONLY SIGNAL. Every badge carries a dot and a word as
 *     well as a hue, and every trend carries an arrow. The screen has to work
 *     printed in grey and for somebody who cannot tell red from green.
 *
 *  3. A LOADING STATE KEEPS THE LAYOUT IT WILL HAVE WHEN THE DATA ARRIVES, so
 *     the page does not jump under a finger already moving towards a button.
 */

import type { ReactNode } from 'react'
import {
  AlertCircle,
  AlertTriangle,
  ArrowDownRight,
  ArrowUpRight,
  Info,
  Minus,
  Sparkles,
} from 'lucide-react'
import { count, money, moneyWhole, percent } from '../services/format'
import type { Metric, PulseHeadline } from '../services/types'

// ---------------------------------------------------------------------------
// Metric cards
// ---------------------------------------------------------------------------

/** What kind of number this is, in three words. */
const BASIS_WORDS: Record<string, string> = {
  period: 'Over the period',
  as_of: 'Right now',
  window: 'Falling due',
  count: 'Count',
  stated: 'As stated',
}

function metricValue(metric: Metric): string {
  if (metric.value === null) return '—'

  switch (metric.format) {
    case 'count':
      return count(metric.value)
    case 'percent':
      return percent(metric.value)
    case 'money':
    default:
      // Whole rupees on a headline: the paise are noise at this size, and the
      // full figure is a click away on the list behind it.
      return moneyWhole(metric.value, metric.currency ?? 'INR')
  }
}

export function MetricCard({ metric, icon, tone = 'default' }: { metric: Metric; icon?: ReactNode; tone?: 'default' | 'danger' | 'warning' | 'info' }) {
  const unavailable = metric.status === 'unavailable' || (metric.value === null && metric.format !== 'state')

  return (
    <article className="pay-metric" title={metric.definition}>
      {icon && <span className={`pay-metric__icon pay-metric__icon--${tone}`} aria-hidden="true">{icon}</span>}

      <div className="pay-metric__body">
        <span className="pay-metric__label">{metric.label}</span>

        {metric.status === 'loading' ? (
          <span className="pay-skeleton pay-skeleton--value">
            <span className="pay-sr-only">Loading {metric.label}</span>
          </span>
        ) : metric.format === 'state' ? (
          <strong className="pay-metric__value">{metric.state_label ?? '—'}</strong>
        ) : unavailable ? (
          // Not a zero. The reason is on the card so the merchant knows whether
          // to wait or to go and fix something.
          <strong className="pay-metric__value pay-metric__value--unavailable">Unavailable</strong>
        ) : (
          <strong className="pay-metric__value">{metricValue(metric)}</strong>
        )}

        <span className="pay-metric__detail">
          {unavailable && metric.status === 'unavailable'
            ? (metric.reason ?? 'This figure could not be read.')
            : (metric.detail ?? BASIS_WORDS[metric.basis] ?? metric.basis)}
        </span>

        {metric.status === 'ready' && metric.comparison && <Comparison comparison={metric.comparison} />}
      </div>
    </article>
  )
}

/**
 * A like-for-like change, or an honest refusal to draw one.
 *
 * THE TONE COMES FROM THE SERVER, which knows whether a rise is welcome. Here,
 * +14% on failed payments would otherwise be painted the same green as +14% on
 * collections, and the person glancing at it would read good news.
 */
function Comparison({ comparison }: { comparison: NonNullable<Metric['comparison']> }) {
  if (!comparison.available) {
    return (
      <span className="pay-trend pay-trend--neutral" title={comparison.detail}>
        <Minus size={13} aria-hidden /> {comparison.label}
      </span>
    )
  }

  const Arrow = comparison.direction === 'up' ? ArrowUpRight : ArrowDownRight
  const tone = comparison.tone ?? 'neutral'

  return (
    <span className={`pay-trend pay-trend--${tone}`}>
      <Arrow size={13} aria-hidden /> {comparison.label}
    </span>
  )
}

/** Skeletons while the first response is in flight, so nothing jumps. */
export function MetricRow({
  metrics,
  loading,
  placeholders = 6,
  icons,
  tones,
}: {
  metrics: Metric[]
  loading: boolean
  placeholders?: number
  icons?: Record<string, ReactNode>
  tones?: Record<string, 'default' | 'danger' | 'warning' | 'info'>
}) {
  const shown: Metric[] =
    loading && metrics.length === 0
      ? Array.from({ length: placeholders }, (_, index) => ({
          id: `placeholder-${index}`,
          label: ' ',
          value: null,
          format: 'money' as const,
          currency: null,
          status: 'loading' as const,
          basis: 'as_of' as const,
          definition: '',
          detail: null,
          comparison: null,
        }))
      : metrics

  return (
    <section className="pay-metrics" aria-label="Key figures" aria-busy={loading}>
      {shown.map((metric) => (
        <MetricCard key={metric.id} metric={metric} icon={icons?.[metric.id]} tone={tones?.[metric.id] ?? 'default'} />
      ))}
    </section>
  )
}

// ---------------------------------------------------------------------------
// Panels
// ---------------------------------------------------------------------------

export function Panel({
  title,
  description,
  action,
  footnote,
  span,
  children,
}: {
  title: string
  description?: string
  action?: ReactNode
  footnote?: ReactNode
  span?: 2 | 3 | 4
  children: ReactNode
}) {
  return (
    <section className={`pay-panel ${span ? `pay-span-${span}` : ''}`.trim()}>
      <div className="pay-panel__head">
        <div>
          <h2>{title}</h2>
          {description && <p>{description}</p>}
        </div>
        {action}
      </div>
      {children}
      {footnote && <p style={{ margin: '12px 0 0', color: 'var(--pay-muted)', fontSize: 11.5 }}>{footnote}</p>}
    </section>
  )
}

// ---------------------------------------------------------------------------
// States
// ---------------------------------------------------------------------------

/**
 * Something that could not be read, and why.
 *
 * Deliberately not styled as an error: an unavailable panel is usually a
 * service having a moment, not the user having done anything wrong.
 */
export function Unavailable({ title = 'Not available', children, action }: { title?: string; children: ReactNode; action?: ReactNode }) {
  return (
    <div className="pay-unavailable" role="status">
      <strong>{title}</strong>
      <span>{children}</span>
      {action && <div style={{ marginTop: 12 }}>{action}</div>}
    </div>
  )
}

export function ErrorState({ message, onRetry, retryable = true }: { message: string; onRetry?: () => void; retryable?: boolean }) {
  return (
    <div className="pay-error" role="alert">
      <AlertCircle size={18} aria-hidden style={{ flexShrink: 0, marginTop: 1 }} />
      <div style={{ flex: 1 }}>
        <span>{message}</span>
        {onRetry && retryable && (
          <div style={{ marginTop: 10 }}>
            <button type="button" className="pay-button pay-button--small pay-button--ghost" onClick={onRetry}>
              Try again
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

export function EmptyState({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="pay-empty">
      <strong>{title}</strong>
      {children && <span>{children}</span>}
      {action && <div style={{ marginTop: 14 }}>{action}</div>}
    </div>
  )
}

export function SkeletonRows({ rows = 4 }: { rows?: number }) {
  return (
    <div aria-busy="true">
      <span className="pay-sr-only">Loading</span>
      {Array.from({ length: rows }, (_, index) => (
        <span key={index} className="pay-skeleton pay-skeleton--line" style={{ width: `${100 - index * 7}%` }} />
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Small pieces
// ---------------------------------------------------------------------------

export type BadgeTone = 'success' | 'warning' | 'danger' | 'neutral' | 'info'

export function Badge({ tone, children }: { tone: BadgeTone; children: ReactNode }) {
  return (
    <span className={`pay-badge pay-badge--${tone}`}>
      {/* A dot as well as a hue, so the badge survives greyscale and colour blindness. */}
      <span className="pay-badge__dot" aria-hidden="true" />
      {children}
    </span>
  )
}

/**
 * Payment and request state, in words.
 *
 * PARTIALLY_PAID is deliberately its own badge rather than being rounded up to
 * Paid: the difference between a customer who owes nothing and one who owes
 * half is the whole of the collections screen.
 */
const STATUS_TONES: Record<string, BadgeTone> = {
  // Requests
  DRAFT: 'neutral',
  ACTIVE: 'info',
  PARTIALLY_PAID: 'warning',
  PAID: 'success',
  EXPIRED: 'neutral',
  CANCELLED: 'neutral',
  // Attempts
  CREATED: 'neutral',
  INITIATED: 'info',
  PENDING: 'warning',
  AUTHORIZED: 'warning',
  CAPTURED: 'success',
  SUCCESS: 'success',
  FAILED: 'danger',
  // Refunds
  REQUESTED: 'warning',
  APPROVED: 'info',
  PROCESSING: 'info',
  REFUNDED: 'success',
  REJECTED: 'neutral',
  // Settlements
  EXPECTED: 'info',
  SETTLED: 'success',
  PARTIALLY_SETTLED: 'warning',
  DELAYED: 'warning',
  RECONCILIATION_REQUIRED: 'danger',
  // Connections
  CREDENTIALS_INVALID: 'danger',
  DISABLED: 'neutral',
  SUSPENDED: 'danger',
}

export function StatusBadge({ status, label }: { status: string | null; label?: string | null }) {
  if (!status) return <span style={{ color: 'var(--pay-muted)' }}>—</span>

  const tone = STATUS_TONES[status] ?? 'neutral'
  const words = label ?? status.replace(/_/g, ' ').toLowerCase()

  return <Badge tone={tone}>{words.charAt(0).toUpperCase() + words.slice(1)}</Badge>
}

/**
 * A share, as a bar and a figure.
 *
 * The figure is always present. A bar alone cannot be read precisely, and a
 * merchant comparing two providers needs the number.
 */
export function ShareBar({ value, tone = 'default', label }: { value: number | null; tone?: 'default' | 'warning' | 'danger'; label?: string }) {
  if (value === null) {
    return <span style={{ color: 'var(--pay-muted)' }}>—</span>
  }

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
      <span className="num" style={{ minWidth: 48, fontSize: 12.5 }}>
        {label ?? percent(value)}
      </span>
      <div className="pay-bar" style={{ flex: 1 }} role="img" aria-label={`${percent(value)}`}>
        <div
          className={`pay-bar__fill ${tone === 'default' ? '' : `pay-bar__fill--${tone}`}`.trim()}
          style={{ width: `${Math.max(0, Math.min(100, value))}%` }}
        />
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Pay Pulse
// ---------------------------------------------------------------------------

const SEVERITY_TONE: Record<string, { tone: BadgeTone; className: string }> = {
  RISK: { tone: 'danger', className: 'pay-action-row__mark--danger' },
  WARNING: { tone: 'warning', className: 'pay-action-row__mark--warning' },
  OPPORTUNITY: { tone: 'success', className: 'pay-action-row__mark--info' },
  INFO: { tone: 'info', className: 'pay-action-row__mark--info' },
}

/**
 * One Pay Pulse finding, WITH THE EVIDENCE IT WAS COMPUTED FROM.
 *
 * The evidence chips are not decoration. An insight a merchant cannot check is
 * an insight they will not act on, and a confidence figure with nothing behind
 * it is worse than no figure at all.
 */
export function InsightRow({ insight, onAct }: { insight: PulseHeadline; onAct?: (insight: PulseHeadline) => void }) {
  const severity = SEVERITY_TONE[insight.severity] ?? SEVERITY_TONE.INFO
  const Mark = insight.severity === 'RISK' ? AlertCircle : insight.severity === 'WARNING' ? AlertTriangle : insight.severity === 'OPPORTUNITY' ? Sparkles : Info

  const facts = Object.entries(insight.evidence ?? {})
    .filter(([, value]) => typeof value === 'string' || typeof value === 'number')
    .slice(0, 4)

  return (
    <div className="pay-insight">
      <span className={`pay-insight__mark ${severity.className}`} aria-hidden="true">
        <Mark size={16} />
      </span>

      <div className="pay-insight__body">
        <strong>{insight.headline}</strong>
        {insight.detail && <p>{insight.detail}</p>}

        <div className="pay-insight__evidence">
          {facts.map(([key, value]) => (
            <span key={key} className="pay-insight__fact">
              {key.replace(/_/g, ' ')}: {String(value)}
            </span>
          ))}
          <span className="pay-insight__fact" title="How much data this was computed from">
            confidence {insight.confidence}%
          </span>
        </div>
      </div>

      {insight.action && onAct && (
        <button type="button" className="pay-button pay-button--soft pay-button--small" onClick={() => onAct(insight)}>
          {insight.action.label}
        </button>
      )}
    </div>
  )
}

/** The compact strip at the foot of a dashboard. Read-only, three or four lines. */
export function PulseStrip({ insights }: { insights: PulseHeadline[] }) {
  if (insights.length === 0) return null

  return (
    <section className="pay-pulse-strip" aria-label="Pay Pulse insights">
      <div className="pay-pulse-strip__title">
        <strong>
          <Sparkles size={16} aria-hidden /> Pay Pulse
          <span className="pay-pill">AI</span>
        </strong>
        <small>From your own payment data</small>
      </div>

      {insights.slice(0, 4).map((insight) => (
        <div key={insight.insight_id} className="pay-pulse-strip__item">
          <div>
            <strong>{insight.headline}</strong>
            {insight.value !== null && <span>{money(insight.value)} involved</span>}
          </div>
        </div>
      ))}
    </section>
  )
}
