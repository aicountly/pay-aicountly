/**
 * The frame around all five dashboards: the heading, the switcher, the period.
 *
 * THE SWITCHER IS BUILT FROM THE SERVER'S LIST. A dashboard the user's role
 * does not include is not in the tabs and is also a 403 on its own URL, because
 * both come from the same permission. A tab bar filtered in the browser is a
 * tab bar the browser can unfilter.
 */

import type { ReactNode } from 'react'
import { NavLink } from 'react-router-dom'
import { CalendarDays, Sparkles } from 'lucide-react'
import { usePay } from '../context/PayContext'
import { PERIOD_OPTIONS, type PeriodKey } from './useDashboard'
import type { PeriodDescription } from '../services/types'

const DASHBOARD_PATHS: Record<string, string> = {
  overview: '/',
  collections: '/dashboards/collections',
  gateways: '/dashboards/gateways',
  settlements: '/dashboards/settlements',
  pay_pulse: '/dashboards/pay-pulse',
}

function greeting(): string {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 17) return 'Good afternoon'
  return 'Good evening'
}

export function DashboardLayout({
  title,
  subtitle,
  banner,
  period,
  onPeriodChange,
  description,
  children,
}: {
  title: ReactNode
  subtitle: string
  banner?: { title: string; detail: string }
  period?: PeriodKey
  onPeriodChange?: (key: PeriodKey) => void
  description?: PeriodDescription | null
  children: ReactNode
}) {
  const { session } = usePay()
  const dashboards = session?.dashboards ?? []

  return (
    <>
      <section className="pay-heading">
        <div>
          <h1>{title}</h1>
          <p>{subtitle}</p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
          {banner && (
            <div className="pay-banner">
              <Sparkles size={17} aria-hidden style={{ color: 'var(--pay-action)', flexShrink: 0, marginTop: 1 }} />
              <div>
                <strong>{banner.title}</strong>
                <span>{banner.detail}</span>
              </div>
            </div>
          )}

          {period && onPeriodChange && (
            <label className="pay-chip" style={{ cursor: 'pointer' }}>
              <CalendarDays size={16} aria-hidden />
              <span className="pay-sr-only">Period</span>
              <select
                value={period}
                onChange={(event) => onPeriodChange(event.target.value as PeriodKey)}
                style={{ border: 0, background: 'transparent', outline: 0, fontWeight: 600, cursor: 'pointer' }}
              >
                {PERIOD_OPTIONS.map((option) => (
                  <option key={option.key} value={option.key}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
          )}
        </div>
      </section>

      {dashboards.length > 1 && (
        <nav className="pay-switcher" aria-label="Dashboards">
          {dashboards.map((dashboard) => (
            <NavLink
              key={dashboard.key}
              to={DASHBOARD_PATHS[dashboard.key] ?? '/'}
              end={dashboard.key === 'overview'}
              className={({ isActive }) => (isActive ? 'is-active' : '')}
            >
              {dashboard.label}
            </NavLink>
          ))}
        </nav>
      )}

      {description && (
        <p style={{ margin: '0 0 14px', color: 'var(--pay-muted)', fontSize: 12 }}>
          {description.label} · {description.timezone.replace('_', ' ')}
          {description.in_progress && ' · still running'}
        </p>
      )}

      {children}
    </>
  )
}

/** The greeting used on the overview, where a name is worth using. */
export function greetingFor(name: string | null, hasName: boolean): ReactNode {
  if (!hasName || !name) return 'Your payments'

  const firstName = name.trim().split(/\s+/)[0]

  return (
    <>
      {greeting()}, <span>{firstName}</span>
    </>
  )
}
