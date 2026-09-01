/**
 * Build-time configuration.
 *
 * Vite inlines every VITE_* value into the bundle when the app is compiled, so
 * these are public values and the deployed app never reads a .env from disk.
 * See docs/DEPLOYMENT.md.
 */

export const APP_NAME = (import.meta.env.VITE_APP_NAME ?? 'Pay').trim() || 'Pay'

/** `local` | `sandbox` | `production` — set by the deploy workflows. */
export const APP_ENV = (import.meta.env.VITE_APP_ENV ?? 'local').trim() || 'local'

const CONFIGURED_API_BASE_URL = (import.meta.env.VITE_API_BASE_URL ?? '').trim()

/**
 * Base URL of this product's own PHP API.
 *
 * server-php is deployed into `<document root>/api`, so the API is same-origin
 * with the app on both pay.aicountly.com and pay.gh.aicountly.com. That
 * is what the fallback below assumes, and being same-origin is exactly what
 * keeps the auth relay free of CORS.
 */
export function getApiBaseUrl(): string {
  if (CONFIGURED_API_BASE_URL) return CONFIGURED_API_BASE_URL.replace(/\/$/, '')
  return `${window.location.origin}/api`
}
