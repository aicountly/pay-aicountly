/**
 * Four charts, all drawn from whatever the API actually returned.
 *
 * There is no chart library in this project and adding one for four shapes
 * would cost more bundle than it saves — so these are plain SVG, but plain SVG
 * with the parts a chart library gives you and a hand-rolled one usually
 * forgets:
 *
 *   * a y-axis that starts at zero, so a 3% change cannot be drawn as a cliff;
 *   * a real text equivalent, not `aria-label="chart"` — a screen reader gets
 *     the same figures in a table, and so does anyone who prints the page;
 *   * an explicit "not enough data" state, distinct from "all zeros", because a
 *     flat line at the bottom and no data are different facts;
 *   * axis labels in lakhs and crores, since ₹150000 in a country that groups
 *     digits as 1,50,000 is a number people misread.
 *
 * NOTHING HERE INVENTS A POINT. Every coordinate comes from the props.
 */

import { useId, useMemo, useState } from 'react'
import { money, moneyShort, percent, shortDate } from '../services/format'
import { EmptyState } from './kit'
import type { MethodSlice, TrendPoint } from '../services/types'

/**
 * A rounded axis top, so gridlines land on numbers a person would choose.
 *
 * Also never zero: a day with no collections still needs an axis, or every
 * point is drawn against a height of nothing.
 */
function niceMax(value: number): number {
  if (value <= 0) return 1000

  const magnitude = 10 ** Math.floor(Math.log10(value))
  const normalised = value / magnitude
  const step = normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10

  return step * magnitude
}

const SERIES = [
  { key: 'successful', label: 'Successful', colour: 'var(--pay-series-1)' },
  { key: 'pending', label: 'Pending', colour: 'var(--pay-series-3)' },
  { key: 'failed', label: 'Failed', colour: 'var(--pay-series-4)' },
  { key: 'refunded', label: 'Refunded', colour: 'var(--pay-series-6)' },
] as const

// ---------------------------------------------------------------------------
// Collections trend — stacked bars
// ---------------------------------------------------------------------------

export function TrendChart({ points }: { points: TrendPoint[] }) {
  const titleId = useId()
  const [hover, setHover] = useState<number | null>(null)

  const width = 760
  const height = 260
  const padding = { top: 14, right: 12, bottom: 34, left: 62 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom

  const max = useMemo(() => {
    let highest = 0
    for (const point of points) {
      highest = Math.max(highest, point.successful + point.pending + point.failed)
    }
    return niceMax(highest)
  }, [points])

  const total = useMemo(() => points.reduce((sum, p) => sum + p.successful, 0), [points])

  if (points.length === 0) {
    return <EmptyState title="Nothing to plot">Nothing was collected in this period.</EmptyState>
  }

  // One bar is not a trend. Say the number rather than drawing a lone rectangle
  // and calling it a chart.
  if (points.length === 1) {
    return (
      <div>
        <p style={{ margin: 0, fontSize: 15 }}>
          <strong>{money(points[0].successful)}</strong> collected on {shortDate(points[0].date)}.
        </p>
        <p style={{ margin: '6px 0 0', color: 'var(--pay-muted)', fontSize: 12 }}>
          A single day cannot be drawn as a trend. Choose a longer period to see one.
        </p>
      </div>
    )
  }

  const bandWidth = plotWidth / points.length
  const barWidth = Math.min(38, bandWidth * 0.62)

  const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight

  // At most eight date labels, distributed across the range so both ends are
  // included by construction and two never land on top of each other.
  const lastIndex = points.length - 1
  const tickCount = Math.min(points.length, 8)
  const ticks = new Set(
    tickCount <= 1 ? [0] : Array.from({ length: tickCount }, (_, i) => Math.round((i * lastIndex) / (tickCount - 1))),
  )

  const gridLines = [0, 0.25, 0.5, 0.75, 1]

  return (
    <figure style={{ margin: 0 }}>
      <div className="pay-legend">
        {SERIES.map((series) => (
          <span key={series.key} className="pay-legend__item">
            <span className="pay-legend__swatch" style={{ background: series.colour }} aria-hidden />
            {series.label}
          </span>
        ))}
      </div>

      <svg viewBox={`0 0 ${width} ${height}`} className="pay-chart" role="img" aria-labelledby={titleId}>
        <title id={titleId}>
          Collections over {points.length} periods, totalling {money(total)} successful.
        </title>

        {gridLines.map((fraction) => {
          const value = max * fraction
          return (
            <g key={fraction}>
              <line
                x1={padding.left}
                x2={width - padding.right}
                y1={y(value)}
                y2={y(value)}
                stroke="var(--pay-border)"
                strokeDasharray={fraction === 0 ? undefined : '3 4'}
              />
              <text x={padding.left - 8} y={y(value) + 4} textAnchor="end">
                {moneyShort(value)}
              </text>
            </g>
          )
        })}

        {points.map((point, index) => {
          const x = padding.left + index * bandWidth + (bandWidth - barWidth) / 2
          let cursor = 0

          return (
            <g
              key={point.date}
              onMouseEnter={() => setHover(index)}
              onMouseLeave={() => setHover(null)}
              onFocus={() => setHover(index)}
              onBlur={() => setHover(null)}
              tabIndex={0}
              role="listitem"
              aria-label={`${shortDate(point.date)}: ${money(point.successful)} successful, ${money(point.failed)} failed`}
              style={{ cursor: 'default' }}
            >
              <rect
                x={padding.left + index * bandWidth}
                y={padding.top}
                width={bandWidth}
                height={plotHeight}
                fill={hover === index ? 'rgb(37 176 3 / 5%)' : 'transparent'}
              />

              {SERIES.filter((s) => s.key !== 'refunded').map((series) => {
                const value = point[series.key as 'successful' | 'pending' | 'failed']
                if (value <= 0) return null

                const barHeight = (value / max) * plotHeight
                cursor += value

                return (
                  <rect
                    key={series.key}
                    x={x}
                    y={y(cursor)}
                    width={barWidth}
                    height={Math.max(1, barHeight)}
                    fill={series.colour}
                    rx={2}
                  />
                )
              })}

              {ticks.has(index) && (
                <text x={padding.left + index * bandWidth + bandWidth / 2} y={height - 12} textAnchor="middle">
                  {shortDate(point.date)}
                </text>
              )}
            </g>
          )
        })}
      </svg>

      {hover !== null && (
        <p style={{ margin: '8px 0 0', fontSize: 12.5 }} aria-live="polite">
          <strong>{shortDate(points[hover].date)}</strong> — {money(points[hover].successful)} successful
          {points[hover].failed > 0 && `, ${money(points[hover].failed)} failed`}
          {points[hover].refunded > 0 && `, ${money(points[hover].refunded)} refunded`}
        </p>
      )}

      {/* The same figures as a table, for a screen reader and for print. */}
      <figcaption className="pay-sr-only">
        <table>
          <caption>Collections by period</caption>
          <thead>
            <tr><th>Period</th><th>Successful</th><th>Pending</th><th>Failed</th></tr>
          </thead>
          <tbody>
            {points.map((point) => (
              <tr key={point.date}>
                <td>{shortDate(point.date)}</td>
                <td>{money(point.successful)}</td>
                <td>{money(point.pending)}</td>
                <td>{money(point.failed)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </figcaption>
    </figure>
  )
}

// ---------------------------------------------------------------------------
// Payment method mix — donut
// ---------------------------------------------------------------------------

const SLICE_COLOURS = [
  'var(--pay-series-1)',
  'var(--pay-series-2)',
  'var(--pay-series-3)',
  'var(--pay-series-5)',
  'var(--pay-series-6)',
  'var(--pay-series-4)',
]

export function MethodDonut({ slices, total }: { slices: MethodSlice[]; total: number }) {
  const titleId = useId()

  if (slices.length === 0) {
    return <EmptyState title="No payments yet">The method mix appears once money starts coming in.</EmptyState>
  }

  const size = 168
  const radius = 68
  const stroke = 26
  const circumference = 2 * Math.PI * radius

  let offset = 0

  return (
    <div>
      <svg viewBox={`0 0 ${size} ${size}`} width={size} height={size} role="img" aria-labelledby={titleId} style={{ display: 'block', margin: '4px auto 0' }}>
        <title id={titleId}>
          {slices.map((slice) => `${slice.label} ${percent(slice.share)}`).join(', ')}
        </title>

        <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="var(--pay-mint)" strokeWidth={stroke} />

        {slices.map((slice, index) => {
          const length = (slice.share / 100) * circumference
          const element = (
            <circle
              key={slice.method}
              cx={size / 2}
              cy={size / 2}
              r={radius}
              fill="none"
              stroke={SLICE_COLOURS[index % SLICE_COLOURS.length]}
              strokeWidth={stroke}
              strokeDasharray={`${length} ${circumference - length}`}
              strokeDashoffset={-offset}
              // Start at twelve o'clock, which is where a reader expects it.
              transform={`rotate(-90 ${size / 2} ${size / 2})`}
            />
          )
          offset += length
          return element
        })}

        <text x={size / 2} y={size / 2 - 4} textAnchor="middle" style={{ fontSize: 15, fontWeight: 700, fill: 'var(--pay-text)' }}>
          {moneyShort(total)}
        </text>
        <text x={size / 2} y={size / 2 + 13} textAnchor="middle" style={{ fontSize: 10 }}>
          collected
        </text>
      </svg>

      <ul className="pay-donut-legend">
        {slices.map((slice, index) => (
          <li key={slice.method}>
            <span className="pay-legend__swatch" style={{ background: SLICE_COLOURS[index % SLICE_COLOURS.length] }} aria-hidden />
            {slice.label}
            <strong>{percent(slice.share)}</strong>
          </li>
        ))}
      </ul>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Pay Pulse forecast — actuals behind, a band ahead
// ---------------------------------------------------------------------------

export interface ForecastPoint {
  date: string
  actual: number | null
  forecast: number | null
  low: number | null
  high: number | null
}

export function ForecastChart({ points, confidence }: { points: ForecastPoint[]; confidence: number }) {
  const titleId = useId()

  const width = 760
  const height = 250
  const padding = { top: 14, right: 14, bottom: 32, left: 62 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom

  const max = useMemo(() => {
    let highest = 0
    for (const point of points) {
      highest = Math.max(highest, point.actual ?? 0, point.high ?? point.forecast ?? 0)
    }
    return niceMax(highest)
  }, [points])

  if (points.length < 2) {
    return <EmptyState title="Not enough history">A forecast needs a few days of payments behind it.</EmptyState>
  }

  const x = (index: number) => padding.left + (index / (points.length - 1)) * plotWidth
  const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight

  const actualPath = points
    .map((p, i) => (p.actual === null ? null : `${i === 0 ? 'M' : 'L'} ${x(i)} ${y(p.actual)}`))
    .filter(Boolean)
    .join(' ')
    .replace(/^L/, 'M')

  const forecastIndices = points.map((p, i) => (p.forecast === null ? null : i)).filter((i): i is number => i !== null)

  // Where the actuals stop is where "today" is drawn, so the eye can see which
  // side of the chart is measured and which is predicted.
  const lastActualIndex = points.reduce((last, p, i) => (p.actual !== null ? i : last), -1)

  const forecastPath = forecastIndices
    .map((i, position) => `${position === 0 ? 'M' : 'L'} ${x(i)} ${y(points[i].forecast ?? 0)}`)
    .join(' ')

  const bandPath =
    forecastIndices.length > 1
      ? `${forecastIndices.map((i, p) => `${p === 0 ? 'M' : 'L'} ${x(i)} ${y(points[i].high ?? 0)}`).join(' ')} ` +
        `${[...forecastIndices].reverse().map((i) => `L ${x(i)} ${y(points[i].low ?? 0)}`).join(' ')} Z`
      : ''

  const todayX = lastActualIndex >= 0 ? x(lastActualIndex) : null

  return (
    <figure style={{ margin: 0 }}>
      <div className="pay-legend">
        <span className="pay-legend__item">
          <span className="pay-legend__swatch" style={{ background: 'var(--pay-series-6)' }} aria-hidden /> Actual
        </span>
        <span className="pay-legend__item">
          <span className="pay-legend__swatch" style={{ background: 'var(--pay-series-1)' }} aria-hidden /> Forecast
        </span>
        <span className="pay-legend__item">
          <span className="pay-legend__swatch" style={{ background: 'var(--pay-mint-strong)' }} aria-hidden /> Confidence range
        </span>
      </div>

      <svg viewBox={`0 0 ${width} ${height}`} className="pay-chart" role="img" aria-labelledby={titleId}>
        <title id={titleId}>
          Collections forecast for the next {forecastIndices.length} days, at {confidence}% confidence.
        </title>

        {[0, 0.5, 1].map((fraction) => (
          <g key={fraction}>
            <line
              x1={padding.left}
              x2={width - padding.right}
              y1={y(max * fraction)}
              y2={y(max * fraction)}
              stroke="var(--pay-border)"
              strokeDasharray={fraction === 0 ? undefined : '3 4'}
            />
            <text x={padding.left - 8} y={y(max * fraction) + 4} textAnchor="end">
              {moneyShort(max * fraction)}
            </text>
          </g>
        ))}

        {bandPath && <path d={bandPath} fill="var(--pay-mint-strong)" opacity={0.55} />}
        {actualPath && <path d={actualPath} fill="none" stroke="var(--pay-series-6)" strokeWidth={2} />}
        {forecastPath && <path d={forecastPath} fill="none" stroke="var(--pay-series-1)" strokeWidth={2.5} strokeDasharray="5 4" />}

        {todayX !== null && (
          <g>
            <line x1={todayX} x2={todayX} y1={padding.top} y2={padding.top + plotHeight} stroke="var(--pay-action)" strokeDasharray="2 3" />
            <text x={todayX} y={padding.top - 2} textAnchor="middle" style={{ fill: 'var(--pay-action)', fontWeight: 700 }}>
              Today
            </text>
          </g>
        )}

        {points.map((point, index) =>
          index % Math.ceil(points.length / 7) === 0 ? (
            <text key={point.date} x={x(index)} y={height - 10} textAnchor="middle">
              {shortDate(point.date)}
            </text>
          ) : null,
        )}
      </svg>

      <figcaption className="pay-sr-only">
        <table>
          <caption>Collections forecast</caption>
          <thead>
            <tr><th>Date</th><th>Actual</th><th>Forecast</th></tr>
          </thead>
          <tbody>
            {points.map((point) => (
              <tr key={point.date}>
                <td>{shortDate(point.date)}</td>
                <td>{point.actual === null ? '—' : money(point.actual)}</td>
                <td>{point.forecast === null ? '—' : money(point.forecast)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </figcaption>
    </figure>
  )
}

// ---------------------------------------------------------------------------
// Conversion funnel
// ---------------------------------------------------------------------------

export function Funnel({ stages }: { stages: Array<{ key: string; label: string; count: number; share: number; note?: string }> }) {
  if (stages.length === 0 || stages[0].count === 0) {
    return <EmptyState title="No requests yet">The funnel appears once payment requests are raised.</EmptyState>
  }

  return (
    <div className="pay-funnel" role="list">
      {stages.map((stage) => (
        <div key={stage.key} className="pay-funnel__row" role="listitem">
          <span className="pay-funnel__label" title={stage.note}>
            {stage.label}
          </span>
          <div className="pay-funnel__track">
            <div
              className="pay-funnel__fill"
              style={{ width: `${Math.max(0.5, stage.share)}%` }}
              role="img"
              aria-label={`${stage.label}: ${stage.count}, ${percent(stage.share)} of requests created`}
            />
          </div>
          <strong className="num" style={{ fontSize: 13 }}>{stage.count.toLocaleString('en-IN')}</strong>
          <span className="num" style={{ color: 'var(--pay-muted)', fontSize: 12, minWidth: 48 }}>{percent(stage.share)}</span>
        </div>
      ))}
    </div>
  )
}
