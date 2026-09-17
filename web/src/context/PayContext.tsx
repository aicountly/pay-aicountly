/**
 * Company scope, session and permissions for the whole app.
 *
 * The scope is two ids. The names behind them — company, branch — belong to
 * Manage and are read from Manage through the API; this provider holds only
 * what it needs to make calls and to remember the user's last choice between
 * visits.
 *
 * `can()` is a CONVENIENCE FOR THE UI AND NOTHING MORE. Every endpoint checks
 * the same permission on the server, and the menu itself is computed there. A
 * permission list in the browser tells a user what they may do; it does not
 * stop them, and the person most interested in the refund screen they were not
 * shown is exactly the one who will try the URL.
 */

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, setScope, type CompanyScope } from '../services/api'
import type { PaySession } from '../services/types'

interface PayContextValue {
  scope: CompanyScope | null
  setCompanyScope: (scope: CompanyScope) => void
  session: PaySession | null
  /** Has this user got that permission? Owners hold everything. */
  can: (permission: string) => boolean
  loading: boolean
  error: string | null
  reload: () => void
}

const PayContextObject = createContext<PayContextValue | null>(null)

const SCOPE_KEY = 'pay:scope'

function readStoredScope(): CompanyScope | null {
  try {
    const raw = window.localStorage.getItem(SCOPE_KEY)
    if (!raw) return null

    const parsed = JSON.parse(raw) as Partial<CompanyScope>
    if (!parsed.cmp_id) return null

    return { cmp_id: Number(parsed.cmp_id), bo_id: Number(parsed.bo_id ?? 0) }
  } catch {
    // A private window, or storage the browser refuses. The app still works;
    // the user just picks their company again.
    return null
  }
}

export function PayProvider({ children }: { children: ReactNode }) {
  const [scope, setScopeState] = useState<CompanyScope | null>(() => readStoredScope())
  const [session, setSession] = useState<PaySession | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)

  // The API module reads the scope on every call, so it is registered as soon
  // as it changes rather than being threaded through each request.
  useEffect(() => {
    setScope(scope)
  }, [scope])

  const setCompanyScope = useCallback((next: CompanyScope) => {
    setScopeState(next)
    try {
      window.localStorage.setItem(SCOPE_KEY, JSON.stringify(next))
    } catch {
      /* storage refused; the choice still applies for this visit */
    }
  }, [])

  useEffect(() => {
    if (!scope) {
      setSession(null)
      return
    }

    let cancelled = false
    const controller = new AbortController()

    setLoading(true)
    setError(null)

    api
      .one<PaySession>('v1/session', undefined, controller.signal)
      .then((response) => {
        if (!cancelled) setSession(response.data)
      })
      .catch((err: unknown) => {
        if (cancelled || controller.signal.aborted) return
        // The previous company's menu must not stay on screen under the new
        // company's name.
        setSession(null)
        setError(err instanceof Error ? err.message : String(err))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [scope?.cmp_id, scope?.bo_id, reloadToken])

  const can = useCallback(
    (permission: string): boolean => {
      if (!session) return false
      if (session.user.is_owner) return true
      return session.permissions.includes(permission)
    },
    [session],
  )

  const value = useMemo<PayContextValue>(
    () => ({
      scope,
      setCompanyScope,
      session,
      can,
      loading,
      error,
      reload: () => setReloadToken((n) => n + 1),
    }),
    [scope, setCompanyScope, session, can, loading, error],
  )

  return <PayContextObject.Provider value={value}>{children}</PayContextObject.Provider>
}

export function usePay(): PayContextValue {
  const value = useContext(PayContextObject)
  if (value === null) {
    throw new Error('usePay must be used inside a PayProvider')
  }
  return value
}
