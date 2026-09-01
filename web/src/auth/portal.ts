/**
 * AICOUNTLY portal SSO — the login half of the app.
 *
 * Flow (docs/auth/AICOUNTLY_AUTH_WORKFLOW.md):
 *
 *   1. App opens with no auth_token
 *   2. → {portal}/login/authentication_jump/pay?returnUrl={origin}/auth/callback
 *      The portal reuses an existing *.aicountly.com session when the user came
 *      from another AICOUNTLY product; otherwise it shows its login form.
 *   3. ← {origin}/auth/callback?auth_token=…
 *   4. POST /global/seskey (own API, same-origin relay) → ses_key
 *   5. Dashboard
 *
 * The portal is the only issuer of tokens. This app never sees a password.
 */

import {
  PORTAL_AUTH_API,
  resolveLoginPortalOrigin,
  resolveProductKeyFromHost,
} from './hostnames'
import { clearAllTokens, getAuthToken, getSesKey, saveSession } from './tokens'
import { getApiBaseUrl } from '../config'

/** Portal convention for "come back here afterwards". */
const RETURN_PARAM = 'returnUrl'

/** Path the portal redirects back to. Served by the SPA history fallback. */
export const CALLBACK_PATH = '/auth/callback'

const LOGOUT_FLAG = 'aic_logout'
const REDIRECT_GUARD_KEY = 'pay:loginRedirectGuard'
const REDIRECT_GUARD_MS = 12_000
const REDIRECT_MAX = 3

const SESKEY_TIMEOUT_MS = 15_000

// ---------------------------------------------------------------------------
// Logout flag
// ---------------------------------------------------------------------------

/**
 * Set for the duration of a sign-out. Without it the "no token → jump to the
 * portal" rule fires while the logout redirect is still in flight and signs the
 * user straight back in, so logout appears to do nothing.
 */
export function isLogoutInProgress(): boolean {
  try {
    return sessionStorage.getItem(LOGOUT_FLAG) === '1'
  } catch {
    return false
  }
}

export function clearLogoutFlag(): void {
  try {
    sessionStorage.removeItem(LOGOUT_FLAG)
  } catch {
    /* ignore */
  }
}

function markLogoutInProgress(): void {
  try {
    sessionStorage.setItem(LOGOUT_FLAG, '1')
  } catch {
    /* ignore */
  }
}

// ---------------------------------------------------------------------------
// Callback
// ---------------------------------------------------------------------------

export interface AuthCallback {
  authToken: string | null
  /** Error code the portal reported instead of a token, if any. */
  authError: string | null
}

/**
 * Read the portal's answer out of the current URL.
 *
 * The query string is the documented shape. The hash is also checked because
 * portals that were configured for a HashRouter product can land the token
 * there, and dropping it would look like a silent login failure.
 */
export function readAuthCallback(): AuthCallback {
  const fromSearch = new URLSearchParams(window.location.search)
  const searchToken = fromSearch.get('auth_token')
  if (searchToken) {
    return { authToken: searchToken, authError: null }
  }

  const hash = window.location.hash || ''
  const queryIndex = hash.indexOf('?')
  if (queryIndex !== -1) {
    const fromHash = new URLSearchParams(hash.slice(queryIndex + 1))
    const hashToken = fromHash.get('auth_token')
    if (hashToken) {
      return { authToken: hashToken, authError: fromHash.get('auth_error') }
    }
  }

  return { authToken: null, authError: fromSearch.get('auth_error') }
}

/**
 * Drop the token from the address bar once it has been stored.
 *
 * replaceState, not assign: the token must not survive in history, and a real
 * navigation here would restart the app mid-login.
 */
export function clearCallbackFromUrl(): void {
  window.history.replaceState(null, '', `${window.location.origin}/`)
}

function buildCallbackUrl(): string {
  return `${window.location.origin}${CALLBACK_PATH}`
}

// ---------------------------------------------------------------------------
// Redirects to the portal
// ---------------------------------------------------------------------------

interface RedirectGuard {
  count: number
  firstAt: number
}

/**
 * Stop a redirect ping-pong with the portal.
 *
 * When the portal keeps returning a token this app cannot use, both sides are
 * happy to bounce forever and the browser just flickers. Three jumps inside
 * twelve seconds is not a login, so the loop is broken and the error surfaces.
 *
 * @returns true when the redirect may proceed.
 */
function allowRedirect(): boolean {
  const now = Date.now()
  let guard: RedirectGuard = { count: 0, firstAt: now }

  try {
    const raw = sessionStorage.getItem(REDIRECT_GUARD_KEY)
    if (raw) guard = JSON.parse(raw) as RedirectGuard
  } catch {
    guard = { count: 0, firstAt: now }
  }

  if (!Number.isFinite(guard.firstAt) || now - guard.firstAt > REDIRECT_GUARD_MS) {
    guard = { count: 0, firstAt: now }
  }
  guard.count += 1

  try {
    sessionStorage.setItem(REDIRECT_GUARD_KEY, JSON.stringify(guard))
  } catch {
    /* ignore */
  }

  return guard.count <= REDIRECT_MAX
}

export function clearRedirectGuard(): void {
  try {
    sessionStorage.removeItem(REDIRECT_GUARD_KEY)
  } catch {
    /* ignore */
  }
}

/**
 * Silent SSO. Reuses the portal web session when the user arrived from another
 * AICOUNTLY product; falls through to the portal's login form when there is
 * none.
 *
 * @returns false when the loop guard refused the jump.
 */
export function redirectToPortalSso(): boolean {
  if (isLogoutInProgress()) return false
  if (!allowRedirect()) return false

  const portal = resolveLoginPortalOrigin()
  const productKey = resolveProductKeyFromHost()
  const returnUrl = encodeURIComponent(buildCallbackUrl())

  window.location.replace(
    `${portal}/login/authentication_jump/${productKey}?${RETURN_PARAM}=${returnUrl}`,
  )
  return true
}

/**
 * The portal's login form, explicitly.
 *
 * `prompt=login` is what makes this an escape hatch rather than a second lap of
 * the same loop: without it the portal sees its own live session and jumps
 * straight back with the same unusable token.
 */
export function redirectToPortalLoginForm(): void {
  if (isLogoutInProgress()) return

  const portal = resolveLoginPortalOrigin()
  const returnUrl = encodeURIComponent(buildCallbackUrl())
  window.location.replace(`${portal}/?${RETURN_PARAM}=${returnUrl}&prompt=login`)
}

// ---------------------------------------------------------------------------
// ses_key lifecycle
// ---------------------------------------------------------------------------

export class AuthError extends Error {
  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.name = 'AuthError'
    this.status = status
  }
}

async function fetchWithTimeout(url: string, options: RequestInit): Promise<Response> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), SESKEY_TIMEOUT_MS)
  try {
    return await fetch(url, { ...options, signal: controller.signal })
  } catch (err) {
    if ((err as Error)?.name === 'AbortError') {
      throw new AuthError('Session request timed out — please retry.', 0)
    }
    throw err
  } finally {
    clearTimeout(timer)
  }
}

/**
 * Call a portal auth endpoint, preferring this product's same-origin relay.
 *
 * The relay (server-php `/api/global/*`) exists so the browser never makes a
 * cross-origin call to the portal: a new product domain is not in the portal's
 * CORS allowlist on day one, and without the relay sign-in would fail for
 * everyone with only a CORS message to show for it. The direct call is kept as
 * the fallback for when the PHP API is not deployed yet.
 */
async function fetchPortalAuth(path: string, options: RequestInit): Promise<Response> {
  const relayUrl = `${getApiBaseUrl()}/global${path}`
  const directUrl = `${PORTAL_AUTH_API}/api${path}`

  try {
    const relayed = await fetchWithTimeout(relayUrl, options)
    // 404/5xx mean the relay itself is missing or broken, not that the portal
    // rejected the token — fall through and ask the portal directly.
    if (relayed.ok || (relayed.status < 500 && relayed.status !== 404)) {
      return relayed
    }
  } catch {
    /* relay unreachable — try the portal directly */
  }

  return fetchWithTimeout(directUrl, options)
}

interface SesKeyResponse {
  ses_key?: string
  sesKey?: string
  token?: string
  access_token?: string
  expires_in?: number
  expiresIn?: number
}

async function requestSesKey(path: string): Promise<string> {
  const authToken = getAuthToken()
  if (!authToken) {
    throw new AuthError('No auth token — sign in again.', 401)
  }

  const res = await fetchPortalAuth(path, {
    method: 'POST',
    headers: { Authorization: `Bearer ${authToken}` },
  })

  if (!res.ok) {
    const body = await res.text().catch(() => '')
    throw new AuthError(body || `HTTP ${res.status}`, res.status)
  }

  const data = (await res.json()) as SesKeyResponse
  const key = data.ses_key ?? data.sesKey ?? data.token ?? data.access_token
  if (!key) {
    throw new AuthError('The auth service returned no session key.', 200)
  }

  saveSession(key, data.expires_in ?? data.expiresIn ?? 900)
  return key
}

let mintInFlight: Promise<string> | null = null

/**
 * A valid ses_key, minting one from the auth_token when needed.
 *
 * Concurrent callers share one request: a burst of API calls on a cold session
 * would otherwise mint a handful of keys and keep only the last.
 *
 * There is no refresh path here on purpose. The key lives in memory and
 * `getSesKey()` returns null once it expires, so the next call simply mints a
 * fresh one from the long-lived auth_token — which is what a refresh would
 * achieve. `/seskey/refresh` becomes worth wiring up when the app starts making
 * enough API calls for the extra round trip to matter.
 */
export async function ensureSesKey(): Promise<string> {
  const existing = getSesKey()
  if (existing) return existing

  if (!mintInFlight) {
    mintInFlight = requestSesKey('/seskey').finally(() => {
      mintInFlight = null
    })
  }
  return mintInFlight
}

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------

/**
 * Sign out completely.
 *
 * Local tokens go first so nothing can be replayed if the network calls fail,
 * then the portal's own session cookie is cleared by navigating to its logout
 * page. Skipping that last step leaves the portal session alive, and the next
 * visit silently signs the user back in — which reads as "logout is broken".
 */
export function performLogout(): void {
  markLogoutInProgress()
  clearRedirectGuard()

  const authToken = getAuthToken()
  const portalLogoutUrl = `${resolveLoginPortalOrigin()}/login/logout`

  clearAllTokens()

  if (authToken) {
    // Fire-and-forget: the portal invalidates the token server-side, but the
    // redirect must not wait on it.
    fetch(`${PORTAL_AUTH_API}/api/logout`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${authToken}`,
      },
      keepalive: true,
    }).catch(() => {
      /* the local session is already gone; the redirect proceeds regardless */
    })
  }

  window.location.replace(portalLogoutUrl)
}
