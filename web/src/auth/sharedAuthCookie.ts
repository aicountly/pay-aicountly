/**
 * Shared `auth_token` cookie for cross-product SSO within *.aicountly.com.
 *
 * localStorage is origin-scoped, so a session created on one AICOUNTLY product
 * is invisible to the next. The cookie is what lets a user who signed in to
 * another product land here already authenticated.
 *
 * Ported from `web/src/auth/sharedAuthCookie.js` in books-react-app.
 */

const AUTH_TOKEN_COOKIE = 'auth_token'
const COOKIE_MAX_AGE_SEC = 60 * 60 * 24 * 30

/**
 * The cookie is only ever written on the shared parent domain. Returning null
 * for anything else keeps it off unrelated origins (localhost included).
 */
function getSharedCookieDomain(): string | null {
  const host = window.location.hostname
  if (host === 'localhost' || host.endsWith('.localhost')) return null
  if (host.endsWith('.aicountly.com')) return '.aicountly.com'
  return null
}

export function readSharedAuthToken(): string | null {
  const prefix = `${AUTH_TOKEN_COOKIE}=`
  for (const part of document.cookie.split(';')) {
    const trimmed = part.trim()
    if (trimmed.startsWith(prefix)) {
      return decodeURIComponent(trimmed.slice(prefix.length))
    }
  }
  return null
}

export function writeSharedAuthToken(token: string): void {
  const domain = getSharedCookieDomain()
  if (!domain || !token) return

  const secure = window.location.protocol === 'https:' ? '; Secure' : ''
  document.cookie = [
    `${AUTH_TOKEN_COOKIE}=${encodeURIComponent(token)}`,
    `domain=${domain}`,
    'path=/',
    `max-age=${COOKIE_MAX_AGE_SEC}`,
    'SameSite=Lax',
    secure,
  ]
    .filter(Boolean)
    .join('; ')
}

export function clearSharedAuthToken(): void {
  const domain = getSharedCookieDomain()
  if (!domain) return

  document.cookie = `${AUTH_TOKEN_COOKIE}=; domain=${domain}; path=/; max-age=0`
}
