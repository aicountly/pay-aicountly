/**
 * Google Analytics 4 (gtag) for pay.aicountly.com.
 *
 * Set at build time: VITE_GA4_SAAS_PAY_MEASUREMENT_ID=G-…
 * Flow backend: GA4_PROPERTY_ID_SAAS_PAY (numeric property ID).
 */

declare global {
  interface Window {
    dataLayer: unknown[]
    gtag: (...args: unknown[]) => void
  }
}

const GA4_ID: string =
  import.meta.env.VITE_GA4_SAAS_PAY_MEASUREMENT_ID ||
  import.meta.env.VITE_GA4_MEASUREMENT_ID ||
  ''

let initialized = false

export function getGa4MeasurementId(): string {
  return GA4_ID
}

export function initAnalytics(): void {
  if (initialized || !GA4_ID || typeof window === 'undefined') return
  initialized = true

  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GA4_ID}`
  document.head.appendChild(script)

  window.dataLayer = window.dataLayer || []
  window.gtag = function gtag(...args: unknown[]) {
    window.dataLayer.push(args)
  }
  window.gtag('js', new Date())
  window.gtag('config', GA4_ID, { send_page_view: false })
}

export function trackPageView(path: string, title?: string): void {
  if (!GA4_ID || typeof window.gtag !== 'function') return
  // GA4 requires a `page_view` *event*, not a repeated `config` call: once
  // `send_page_view: false` is set (above), gtag.js suppresses page_view on
  // every subsequent config call for this measurement ID too, so re-calling
  // config here silently sends nothing. See Google's SPA tracking guide.
  window.gtag('event', 'page_view', {
    page_location: window.location.origin + path,
    page_path: path,
    ...(title ? { page_title: title } : {}),
  })
}

export function trackEvent(eventName: string, params: Record<string, unknown> = {}): void {
  if (!GA4_ID || typeof window.gtag !== 'function') return
  window.gtag('event', eventName, params)
}
