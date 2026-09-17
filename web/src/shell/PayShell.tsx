/**
 * The Pay shell — one shell, every screen.
 *
 * THE MENU COMES FROM THE SERVER. It depends on the user's Pay role, and that
 * lives in the backend: a menu computed in the browser from a permission list
 * the browser was handed is a menu the browser can edit. The dashboard tabs
 * come from the same place and are checked by the same list the endpoints
 * check, so a screen that is not offered is also a URL that is refused.
 *
 * Below 900px the sidebar becomes a real drawer rather than disappearing. Pay
 * is used on a phone behind a counter at least as often as on a desk, and a
 * phone user with no navigation has a one-screen application.
 */

import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import {
  ArrowLeftRight,
  Building2,
  Check,
  ChevronDown,
  Code2,
  CreditCard,
  FileText,
  LayoutDashboard,
  Link2,
  LogOut,
  Menu,
  Repeat,
  RotateCcw,
  Scale,
  Search,
  Settings,
  Sparkles,
  Users,
} from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { usePay } from '../context/PayContext'
import { api } from '../services/api'
import { initials } from '../services/format'
import type { CompanyOption } from '../services/types'
import { APP_ENV } from '../config'
import '../styles/pay.css'

/** Menu key → icon. The server decides which entries exist; this decides how they look. */
const ICONS: Record<string, typeof LayoutDashboard> = {
  dashboard: LayoutDashboard,
  payments: CreditCard,
  requests: FileText,
  links: Link2,
  customers: Users,
  mandates: Repeat,
  refunds: RotateCcw,
  settlements: Building2,
  reconciliation: Scale,
  gateways: ArrowLeftRight,
  pay_pulse: Sparkles,
  developers: Code2,
  settings: Settings,
}

export function PayShell() {
  const { session, loading, error, scope } = usePay()
  const { signOut } = useAuth()
  const location = useLocation()
  const [drawerOpen, setDrawerOpen] = useState(false)

  // A route change closes the drawer. Leaving it open over the new screen is
  // the commonest mobile navigation bug there is.
  useEffect(() => {
    setDrawerOpen(false)
  }, [location.pathname])

  if (!scope) {
    return <CompanyPicker />
  }

  return (
    <div className="pay-app">
      {drawerOpen && (
        <button
          type="button"
          className="pay-scrim"
          aria-label="Close the menu"
          onClick={() => setDrawerOpen(false)}
        />
      )}

      <aside className={`pay-sidebar ${drawerOpen ? 'is-open' : ''}`.trim()} aria-label="Pay navigation">
        <NavLink to="/" className="pay-brand">
          <span className="pay-brand__mark" aria-hidden>A</span>
          <span>
            <span className="pay-brand__name">
              Aicountly <span>Pay</span>
            </span>
            <span className="pay-brand__tag">Collect. Reconcile. Grow.</span>
          </span>
        </NavLink>

        <nav className="pay-nav">
          {loading && !session
            ? Array.from({ length: 8 }, (_, i) => <span key={i} className="pay-skeleton pay-skeleton--line" style={{ height: 34 }} />)
            : (session?.menu ?? []).map((entry) => {
                const Icon = ICONS[entry.key] ?? FileText

                return (
                  <NavLink
                    key={entry.key}
                    to={entry.path}
                    end={entry.path === '/'}
                    className={({ isActive }) => `pay-nav__link ${isActive ? 'is-active' : ''}`.trim()}
                  >
                    <span className="pay-nav__icon" aria-hidden><Icon size={17} /></span>
                    {entry.label}
                    {entry.badge && <span className="pay-pill">{entry.badge}</span>}
                  </NavLink>
                )
              })}
        </nav>

        <div className="pay-sidebar__foot">
          {/* Which deployment this is, and whether it can actually take money.
              A tagline here would look the same on a sandbox as on production,
              and "I thought I was on sandbox" is an expensive mistake to make
              with a payment app. */}
          <strong>{APP_ENV === 'production' ? 'Live' : APP_ENV === 'sandbox' ? 'Sandbox' : 'Local'}</strong>
          {!session
            ? 'Loading…'
            : session.capabilities.providers.length > 0
              ? `${session.capabilities.providers.length} provider${session.capabilities.providers.length === 1 ? '' : 's'} available`
              : 'No payment provider connected yet'}
        </div>
      </aside>

      <main className="pay-main">
        <header className="pay-topbar">
          <button
            type="button"
            className="pay-chip"
            onClick={() => setDrawerOpen(true)}
            aria-label="Open the menu"
            style={{ display: 'none' }}
            data-drawer-toggle
          >
            <Menu size={17} aria-hidden />
          </button>

          <GlobalSearch />

          <div className="pay-topbar__actions">
            <CompanySwitcher />

            <div className="pay-chip" style={{ cursor: 'default' }}>
              <span className="pay-avatar" aria-hidden>
                {/* Initials from a NAME. Cut from a uuid they are not initials,
                    they are the first character of an identifier, and showing
                    one makes the product look broken to the person it belongs
                    to. */}
                {session?.user.has_name ? initials(session.user.name) : '—'}
              </span>
              <span className="pay-user">
                <strong style={{ fontSize: 13 }}>{session?.user.has_name ? session.user.name : 'Signed in'}</strong>
                <small>{session?.user.is_owner ? 'Owner' : 'Team'}</small>
              </span>
            </div>

            <button type="button" className="pay-chip" onClick={signOut} aria-label="Log out">
              <LogOut size={16} aria-hidden />
            </button>
          </div>
        </header>

        {error && (
          <div className="pay-error" role="alert" style={{ marginTop: 16 }}>
            <div>
              <strong>Pay could not load your profile.</strong>
              <span>{error}</span>
            </div>
          </div>
        )}

        <Outlet />
      </main>
    </div>
  )
}

/**
 * The drawer toggle is a CSS-media concern, not a JavaScript one — but the
 * button has to exist in the DOM to be shown. This inline style block is the
 * one place the shell reaches into CSS, and it is here rather than in pay.css
 * because the selector depends on a data attribute this file owns.
 */
const drawerToggleStyles = `
@media (max-width: 900px) {
  [data-drawer-toggle] { display: inline-flex !important; }
}
`

// ---------------------------------------------------------------------------
// Company switcher
// ---------------------------------------------------------------------------

function CompanySwitcher() {
  const { scope, setCompanyScope, session } = usePay()
  const [open, setOpen] = useState(false)
  const [companies, setCompanies] = useState<CompanyOption[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open || companies !== null) return

    let cancelled = false
    api
      .unscoped<CompanyOption[]>('v1/manage/companies', { filter: 'all', per_page: 100 })
      .then((response) => {
        if (!cancelled) setCompanies(response.data)
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof Error ? err.message : String(err))
      })

    return () => {
      cancelled = true
    }
  }, [open, companies])

  // Clicking away closes it. Without this the panel follows the user onto the
  // next screen.
  useEffect(() => {
    if (!open) return

    const onClick = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onClick)
    document.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('mousedown', onClick)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const current = companies?.find((c) => c.cmp_id === scope?.cmp_id)

  // The list is only fetched when the panel is opened, so until then the name
  // comes from the session — which carries what Manage said on this request.
  // Without this the chip reads "Company 55" for the whole visit of anyone who
  // never opens the switcher, which is almost everyone.
  const currentName = current?.name ?? session?.company.name ?? null

  return (
    <div ref={containerRef} style={{ position: 'relative' }}>
      <button type="button" className="pay-chip" onClick={() => setOpen((o) => !o)} aria-expanded={open} aria-haspopup="listbox">
        <span className="pay-avatar" aria-hidden>{initials(currentName ?? 'Company')}</span>
        <span style={{ maxWidth: 160, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {currentName ?? `Company ${scope?.cmp_id ?? ''}`}
        </span>
        <ChevronDown size={15} aria-hidden />
      </button>

      {open && (
        <div
          role="listbox"
          aria-label="Choose a company"
          style={{
            position: 'absolute',
            right: 0,
            top: 'calc(100% + 6px)',
            zIndex: 40,
            width: 290,
            maxHeight: 330,
            overflowY: 'auto',
            padding: 6,
            background: 'var(--pay-surface)',
            border: '1px solid var(--pay-border)',
            borderRadius: 12,
            boxShadow: 'var(--pay-shadow-lg)',
          }}
        >
          {error && <p style={{ margin: 8, color: 'var(--pay-danger)', fontSize: 12.5 }}>{error}</p>}
          {!companies && !error && <SkeletonList />}

          {companies?.map((company) => (
            <button
              key={company.cmp_id}
              type="button"
              role="option"
              aria-selected={company.cmp_id === scope?.cmp_id}
              className="pay-action-row"
              style={{ padding: '10px 8px', borderBottom: 0 }}
              onClick={() => {
                setCompanyScope({ cmp_id: company.cmp_id, bo_id: 0 })
                setOpen(false)
              }}
            >
              <span style={{ display: 'flex', alignItems: 'center', gap: 9, minWidth: 0 }}>
                <span className="pay-avatar" aria-hidden>{initials(company.name)}</span>
                <span style={{ minWidth: 0 }}>
                  <span className="pay-action-row__label" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                    {company.name}
                  </span>
                  <span className="pay-action-row__detail">{company.is_owner ? 'Owner' : 'Shared with you'}</span>
                </span>
              </span>
              {company.cmp_id === scope?.cmp_id && <Check size={16} aria-hidden style={{ color: 'var(--pay-action)' }} />}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

function SkeletonList() {
  return (
    <div style={{ padding: 8 }}>
      {Array.from({ length: 3 }, (_, i) => (
        <span key={i} className="pay-skeleton pay-skeleton--line" style={{ height: 32 }} />
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Company picker — before a company is chosen there is nothing to show
// ---------------------------------------------------------------------------

function CompanyPicker() {
  const { setCompanyScope } = usePay()
  const [companies, setCompanies] = useState<CompanyOption[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    api
      .unscoped<CompanyOption[]>('v1/manage/companies', { filter: 'all', per_page: 100 })
      .then((response) => {
        if (cancelled) return
        setCompanies(response.data)

        // One company means no choice worth asking about.
        if (response.data.length === 1) {
          setCompanyScope({ cmp_id: response.data[0].cmp_id, bo_id: 0 })
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof Error ? err.message : String(err))
      })

    return () => {
      cancelled = true
    }
  }, [setCompanyScope])

  return (
    <main style={{ display: 'grid', placeItems: 'center', minHeight: '100dvh', padding: 20 }}>
      <div className="pay-panel" style={{ width: 'min(440px, 100%)' }}>
        <div className="pay-brand" style={{ padding: '0 0 16px' }}>
          <span className="pay-brand__mark" aria-hidden>A</span>
          <span>
            <span className="pay-brand__name">
              Aicountly <span>Pay</span>
            </span>
            <span className="pay-brand__tag">Collect. Reconcile. Grow.</span>
          </span>
        </div>

        <h1 style={{ fontSize: 19, margin: '0 0 6px' }}>Which company?</h1>
        <p style={{ margin: '0 0 16px', color: 'var(--pay-muted)', fontSize: 13 }}>
          Payments, providers and settlements are all kept separately per company.
        </p>

        {error && <div className="pay-error" role="alert"><span>{error}</span></div>}
        {!companies && !error && <SkeletonList />}

        {companies?.length === 0 && (
          <div className="pay-empty">
            <strong>No companies yet</strong>
            <span>Create one in Aicountly Manage, then come back.</span>
          </div>
        )}

        {companies?.map((company) => (
          <button
            key={company.cmp_id}
            type="button"
            className="pay-action-row"
            onClick={() => setCompanyScope({ cmp_id: company.cmp_id, bo_id: 0 })}
          >
            <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <span className="pay-avatar" aria-hidden>{initials(company.name)}</span>
              <span>
                <span className="pay-action-row__label">{company.name}</span>
                <span className="pay-action-row__detail">{company.is_owner ? 'Owner' : 'Shared with you'}</span>
              </span>
            </span>
            <ChevronDown size={16} aria-hidden style={{ transform: 'rotate(-90deg)', color: 'var(--pay-muted)' }} />
          </button>
        ))}
      </div>
    </main>
  )
}

// ---------------------------------------------------------------------------
// Global search
// ---------------------------------------------------------------------------

/**
 * One box, every kind of reference.
 *
 * A merchant with a customer on the phone has a number in front of them and
 * does not know or care whether it is a payment id, an invoice reference or a
 * UTR. The server works out which it is; this just submits it.
 *
 * It searches PAY'S OWN records. Looking up an invoice in Books is what the
 * source panel on a request does, on that one screen, rather than fanning out
 * to five products from a search box.
 */
function GlobalSearch() {
  const navigate = useNavigate()
  const [term, setTerm] = useState('')
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        inputRef.current?.focus()
      }
    }

    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  return (
    <form
      className="pay-search"
      role="search"
      onSubmit={(event) => {
        event.preventDefault()
        const query = term.trim()
        if (query === '') return
        navigate(`/payments?q=${encodeURIComponent(query)}`)
      }}
    >
      <Search size={16} aria-hidden />
      <input
        ref={inputRef}
        type="search"
        value={term}
        onChange={(event) => setTerm(event.target.value)}
        placeholder="Search payments, requests, references, UTRs…"
        aria-label="Search Pay"
      />
      <kbd aria-hidden>⌘K</kbd>
    </form>
  )
}

/** Injected once so the drawer toggle appears only on narrow screens. */
export function ShellStyles() {
  return <style>{drawerToggleStyles}</style>
}
