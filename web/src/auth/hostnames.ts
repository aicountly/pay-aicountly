/**
 * AICOUNTLY hostname helpers — sandbox vs production detection.
 *
 * Ported from `web/src/utils/hostnameUtils.js` in books-react-app so every
 * AICOUNTLY SaaS resolves the login portal and its product key identically.
 */

const SANDBOX_GH_ZONE_RE = /^[a-z0-9-]+\.gh\.aicountly\.com$/i
const LEGACY_SANDBOX_RE = /^gh-[a-z0-9-]+\.aicountly\.com$/i

/**
 * Product key used in the portal `authentication_jump/{key}` URL.
 *
 * Normally derived from the hostname, so one build serves both
 * pay.aicountly.com and pay.gh.aicountly.com. This is the fallback for
 * hosts the pattern does not cover — localhost above all.
 */
export const PRODUCT_KEY = (import.meta.env.VITE_PRODUCT_KEY ?? 'pay').trim() || 'pay'

/** Login portal — renders the sign-in form and performs the SSO jump. */
export const PORTAL_LOGIN_PRODUCTION = 'https://my.aicountly.com'
export const PORTAL_LOGIN_SANDBOX = 'https://sandbox.aicountly.com'

/**
 * Portal auth API — production in BOTH environments.
 *
 * sandbox.aicountly.com is a *login redirect only*: seskey, seskey/refresh and
 * validatesession always answer on my.aicountly.com. Pointing the sandbox build
 * at sandbox.aicountly.com for those calls is the classic way to break sandbox
 * sign-in. See docs/auth/AICOUNTLY_AUTH_WORKFLOW.md, "Host mapping".
 */
export const PORTAL_AUTH_API = PORTAL_LOGIN_PRODUCTION

function currentHost(): string {
  return typeof window === 'undefined' ? '' : window.location.hostname
}

export function normalizeHost(hostname = ''): string {
  return String(hostname || '')
    .trim()
    .replace(/^www\./i, '')
    .split(':')[0]
    .toLowerCase()
}

/** True for the sandbox zone and for local development. */
export function isSandboxHost(hostname: string = currentHost()): boolean {
  const host = normalizeHost(hostname)
  if (!host) return false
  if (host === 'localhost' || host.endsWith('.localhost') || host.startsWith('127.')) return true
  return SANDBOX_GH_ZONE_RE.test(host) || LEGACY_SANDBOX_RE.test(host)
}

/**
 * `pay.aicountly.com` and `pay.gh.aicountly.com` both resolve to
 * `pay`, which is what the portal expects in authentication_jump.
 */
export function resolveProductKeyFromHost(hostname: string = currentHost()): string {
  const host = normalizeHost(hostname)

  const ghZone = host.match(/^([a-z0-9-]+)\.gh\.aicountly\.com$/i)
  if (ghZone) return ghZone[1].toLowerCase()

  const legacy = host.match(/^gh-([a-z0-9-]+)\.aicountly\.com$/i)
  if (legacy) return legacy[1].toLowerCase()

  const prod = host.match(/^([a-z0-9-]+)\.aicountly\.com$/i)
  if (prod) return prod[1].toLowerCase()

  return PRODUCT_KEY
}

/** Where the user signs in: sandbox portal for sandbox hosts, my. for production. */
export function resolveLoginPortalOrigin(hostname: string = currentHost()): string {
  const override = (import.meta.env.VITE_PORTAL_LOGIN_URL ?? '').trim()
  if (override) return override.replace(/\/$/, '')
  return isSandboxHost(hostname) ? PORTAL_LOGIN_SANDBOX : PORTAL_LOGIN_PRODUCTION
}
