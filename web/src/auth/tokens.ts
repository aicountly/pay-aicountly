/**
 * Token storage for the two-token AICOUNTLY model.
 *
 * | Token        | Lifetime    | Storage       | Use                          |
 * |--------------|-------------|---------------|------------------------------|
 * | `auth_token` | Long-lived  | localStorage  | Mint / refresh a `ses_key`   |
 * | `ses_key`    | ~15 minutes | Memory ONLY   | `Bearer` on product API calls|
 *
 * `ses_key` must never reach localStorage or sessionStorage — that rule is the
 * whole point of the split, and it is why the key below lives in a module
 * variable that dies with the page.
 */

import {
  readSharedAuthToken,
  writeSharedAuthToken,
  clearSharedAuthToken,
} from './sharedAuthCookie'

const AUTH_TOKEN_KEY = 'auth_token'

/** Web Storage throws in private-mode Safari and when quota is exhausted. */
function safeLocalStorage(): Storage | null {
  try {
    return window.localStorage
  } catch {
    return null
  }
}

// ---------- auth_token ----------

let authToken: string | null = null

export function setAuthToken(token: string | null): void {
  authToken = token || null

  const store = safeLocalStorage()
  if (store) {
    try {
      if (token) store.setItem(AUTH_TOKEN_KEY, token)
      else store.removeItem(AUTH_TOKEN_KEY)
    } catch {
      /* ignore quota / private mode */
    }
  }

  if (token) writeSharedAuthToken(token)
  else clearSharedAuthToken()
}

/**
 * Memory → localStorage → shared cookie. The cookie is the cross-product hop:
 * finding a token there means the user signed in on another AICOUNTLY product,
 * so it is promoted into localStorage for this origin.
 */
export function getAuthToken(): string | null {
  if (authToken) return authToken

  const store = safeLocalStorage()
  if (store) {
    try {
      const stored = store.getItem(AUTH_TOKEN_KEY)
      if (stored) {
        authToken = stored
        return stored
      }
    } catch {
      /* ignore */
    }
  }

  authToken = readSharedAuthToken()
  if (authToken && store) {
    try {
      store.setItem(AUTH_TOKEN_KEY, authToken)
    } catch {
      /* ignore */
    }
  }
  return authToken
}

// ---------- ses_key (memory only, never persisted) ----------

let sesKey: string | null = null
let sesExpiry = 0

export function saveSession(key: string, expiresInSeconds: number): void {
  sesKey = key
  sesExpiry = Date.now() + expiresInSeconds * 1000
}

export function getSesKey(): string | null {
  if (sesKey && Date.now() < sesExpiry) return sesKey
  sesKey = null
  sesExpiry = 0
  return null
}

export function clearSession(): void {
  sesKey = null
  sesExpiry = 0
}

/** Full sign-out: drops the session key, the auth token and the shared cookie. */
export function clearAllTokens(): void {
  clearSession()
  authToken = null

  const store = safeLocalStorage()
  if (store) {
    try {
      store.removeItem(AUTH_TOKEN_KEY)
    } catch {
      /* ignore */
    }
  }

  clearSharedAuthToken()
}
