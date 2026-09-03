import { useEffect, useId, useRef, useState } from 'react'
import type { CSSProperties } from 'react'
import {
  listLauncherApps,
  launchApp,
  fetchProductIconManifest,
  fetchBundledIconVersions,
  preloadLauncherIcons,
  readIconChoices,
} from '../services/appLauncher'
import type { LauncherTile } from '../services/appLauncher'
import { useLauncherTileIcon } from '../services/useLauncherTileIcon'

const S: Record<string, CSSProperties> = {
  wrap: { position: 'fixed', top: 16, left: 16, zIndex: 1000, display: 'inline-block' },
  trigger: {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 8,
    border: '1px solid var(--border, #e4e4e7)',
    borderRadius: 8,
    background: 'var(--bg, #fff)',
    color: 'var(--fg, #18181b)',
    cursor: 'pointer',
    boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
  },
  panel: {
    position: 'absolute',
    left: 0,
    top: '100%',
    marginTop: 8,
    zIndex: 1000,
    width: 'min(28rem, calc(100vw - 2rem))',
    maxWidth: 448,
    padding: 16,
    borderRadius: 12,
    border: '1px solid #e2e8f0',
    background: '#fff',
    boxShadow: '0 10px 40px rgba(15, 23, 42, 0.12)',
  },
  title: { margin: 0, fontSize: 14, fontWeight: 700, color: '#0f172a' },
  subtitle: { margin: '4px 0 0', fontSize: 12, color: '#64748b', lineHeight: 1.4 },
  search: {
    width: '100%',
    marginTop: 12,
    marginBottom: 4,
    padding: '8px 10px',
    borderRadius: 8,
    border: '1px solid #e2e8f0',
    background: '#fff',
    color: '#0f172a',
    fontSize: 13,
    outline: 'none',
  },
  empty: {
    gridColumn: '1 / -1',
    margin: '8px 0',
    fontSize: 12,
    color: '#64748b',
    textAlign: 'center',
  },
  grid: {
    display: 'grid',
    gridTemplateColumns: 'repeat(4, minmax(0, 1fr))',
    gap: 8,
    maxHeight: 'min(24rem, 70vh)',
    overflowY: 'auto',
    marginTop: 12,
  },
  tile: {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: 8,
    padding: 12,
    borderRadius: 12,
    border: '1px solid #e2e8f0',
    background: '#fff',
    cursor: 'pointer',
    textAlign: 'center',
  },
  tileCurrent: {
    border: '1px solid #93c5fd',
    background: '#eff6ff',
    cursor: 'default',
  },
  icon: {
    display: 'flex',
    width: 44,
    height: 44,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 12,
    fontSize: 13,
    fontWeight: 700,
    color: '#fff',
    boxShadow: '0 1px 3px rgba(0,0,0,0.12)',
  },
  name: { fontSize: 12, fontWeight: 600, color: '#0f172a', lineHeight: 1.25 },
  footer: {
    marginTop: 12,
    paddingTop: 12,
    borderTop: '1px solid #f1f5f9',
    fontSize: 10,
    color: '#94a3b8',
    lineHeight: 1.4,
  },
}

const ACCENT: Record<string, string> = {
  'bg-yellow-600': '#ca8a04',
  'bg-purple-700': '#7e22ce',
  'bg-blue-700': '#1d4ed8',
  'bg-lime-700': '#4d7c0f',
  'bg-rose-500': '#f43f5e',
  'bg-pink-700': '#be185d',
  'bg-violet-600': '#7c3aed',
  'bg-slate-700': '#334155',
  'bg-slate-800': '#1e293b',
  'bg-emerald-600': '#059669',
  'bg-emerald-700': '#047857',
  'bg-sky-600': '#0284c7',
  'bg-indigo-600': '#4f46e5',
  'bg-fuchsia-600': '#c026d3',
  'bg-rose-600': '#e11d48',
  'bg-cyan-600': '#0891b2',
  'bg-teal-600': '#0d9488',
  'bg-teal-700': '#0f766e',
  'bg-stone-600': '#57534e',
  'bg-orange-600': '#ea580c',
  'bg-amber-600': '#d97706',
  'bg-green-700': '#15803d',
  'bg-blue-500': '#3b82f6',
  'bg-slate-600': '#475569',
}

function GridIcon() {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <rect x="3" y="3" width="7" height="7" rx="1.5" />
      <rect x="14" y="3" width="7" height="7" rx="1.5" />
      <rect x="3" y="14" width="7" height="7" rx="1.5" />
      <rect x="14" y="14" width="7" height="7" rx="1.5" />
    </svg>
  )
}

interface AppTileProps {
  app: LauncherTile
  onNavigate: (app: LauncherTile) => void
}

function AppTile({ app, onNavigate }: AppTileProps) {
  const { src: iconSrc, onError: handleIconError } = useLauncherTileIcon(app)
  const initials = app.name
    .split(/\s+/)
    .map((w) => w[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
  const bg = ACCENT[app.accent] || '#2563eb'

  return (
    <button
      type="button"
      onClick={() => onNavigate(app)}
      disabled={app.isCurrent}
      style={{ ...S.tile, ...(app.isCurrent ? S.tileCurrent : {}) }}
      title={app.isCurrent ? `${app.name} (current app)` : `Open ${app.name}`}
    >
      <span
        style={{ ...S.icon, background: iconSrc ? 'transparent' : bg, overflow: 'hidden', padding: 0 }}
        aria-hidden
      >
        {iconSrc ? (
          <img
            src={iconSrc}
            alt=""
            style={{ width: '100%', height: '100%', objectFit: 'cover' }}
            decoding="async"
            loading="eager"
            onError={handleIconError}
          />
        ) : (
          initials
        )}
      </span>
      <span style={S.name}>{app.name}</span>
      {app.isCurrent ? <span style={{ fontSize: 10, fontWeight: 600, color: '#2563eb' }}>Current</span> : null}
    </button>
  )
}

export function AppLauncher() {
  const [open, setOpen] = useState(false)
  const [iconVersions, setIconVersions] = useState<Record<string, string | number>>({})
  const [bundledVersions, setBundledVersions] = useState<Record<string, number>>({})
  const [iconChoices] = useState(readIconChoices)
  const [query, setQuery] = useState('')
  const rootRef = useRef<HTMLDivElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)
  const panelId = useId()
  const apps = listLauncherApps(undefined, iconVersions, bundledVersions, iconChoices)
  const filteredApps = query.trim()
    ? apps.filter((app) => app.name.toLowerCase().includes(query.trim().toLowerCase()))
    : apps

  useEffect(() => {
    preloadLauncherIcons()
    let active = true

    Promise.all([fetchBundledIconVersions(), fetchProductIconManifest()]).then(([bundled, manifest]) => {
      if (!active) return
      setBundledVersions(bundled)
      setIconVersions(manifest)
    })

    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    if (!open) return undefined
    let active = true
    fetchProductIconManifest().then((manifest) => {
      if (active) setIconVersions(manifest)
    })
    return () => {
      active = false
    }
  }, [open])

  useEffect(() => {
    if (!open) {
      setQuery('')
      return undefined
    }
    const id = requestAnimationFrame(() => searchRef.current?.focus())
    return () => cancelAnimationFrame(id)
  }, [open])

  useEffect(() => {
    if (!open) return undefined
    const onDoc = (e: MouseEvent) => {
      if (rootRef.current && e.target instanceof Node && !rootRef.current.contains(e.target)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const handleNavigate = (app: LauncherTile) => {
    if (app.isCurrent) return
    setOpen(false)
    launchApp(app, { newTab: true })
  }

  return (
    <div ref={rootRef} style={S.wrap}>
      <button
        type="button"
        style={S.trigger}
        aria-label="AICOUNTLY apps"
        aria-expanded={open}
        aria-controls={panelId}
        title="AICOUNTLY apps"
        onClick={() => setOpen((v) => !v)}
      >
        <GridIcon />
      </button>

      {open ? (
        <div id={panelId} role="dialog" aria-label="AICOUNTLY applications" style={S.panel}>
          <p style={S.title}>AICOUNTLY apps</p>
          <p style={S.subtitle}>Switch apps with single sign-on — opens in a new tab so this app keeps running.</p>
          <input
            ref={searchRef}
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search apps…"
            aria-label="Search AICOUNTLY apps"
            style={S.search}
          />
          <div style={S.grid}>
            {filteredApps.length > 0 ? (
              filteredApps.map((app) => <AppTile key={app.id} app={app} onNavigate={handleNavigate} />)
            ) : (
              <p style={S.empty}>No apps match &ldquo;{query}&rdquo;.</p>
            )}
          </div>
          <p style={S.footer}>Opens the selected product in a new tab using your AICOUNTLY portal session.</p>
        </div>
      ) : null}
    </div>
  )
}

export default AppLauncher
