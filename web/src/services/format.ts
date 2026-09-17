/**
 * How money, dates and counts are written on screen.
 *
 * ONE PLACE, because a product that formats ₹ in eleven components is a product
 * where three of them group digits as thousands. In India the grouping is
 * lakhs and crores, and a merchant reading ₹842,320 where they expected
 * ₹8,42,320 has to stop and count the digits.
 */

const INR = new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const INR_WHOLE = new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  minimumFractionDigits: 0,
  maximumFractionDigits: 0,
})

const COUNT = new Intl.NumberFormat('en-IN')

/** ₹8,42,320.00 — the full figure, for a table cell or a total. */
export function money(value: number | null | undefined, currency = 'INR'): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'

  if (currency !== 'INR') {
    return new Intl.NumberFormat('en-IN', {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value)
  }

  return INR.format(value)
}

/** ₹8,42,320 — no paise, for a headline where the decimals are noise. */
export function moneyWhole(value: number | null | undefined, currency = 'INR'): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  if (currency !== 'INR') {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value)
  }
  return INR_WHOLE.format(value)
}

/**
 * ₹8.4L, ₹2.6Cr — for an axis, where the space is the constraint.
 *
 * Lakhs and crores rather than K and M: an axis reading "840K" in a country
 * that thinks in lakhs is an axis people misread.
 */
export function moneyShort(value: number | null | undefined, currency = 'INR'): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'

  const symbol = currency === 'INR' ? '₹' : `${currency} `
  const abs = Math.abs(value)
  const sign = value < 0 ? '-' : ''

  if (abs >= 10000000) return `${sign}${symbol}${(abs / 10000000).toFixed(abs >= 100000000 ? 0 : 1)}Cr`
  if (abs >= 100000) return `${sign}${symbol}${(abs / 100000).toFixed(abs >= 1000000 ? 0 : 1)}L`
  if (abs >= 1000) return `${sign}${symbol}${Math.round(abs / 1000)}K`

  return `${sign}${symbol}${Math.round(abs)}`
}

export function count(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return COUNT.format(value)
}

/**
 * A percentage, or an em dash.
 *
 * Null is NOT zero. "0% success" and "nobody tried" are different facts and
 * only one of them is alarming, so a rate with no denominator is written as a
 * dash rather than a number.
 */
export function percent(value: number | null | undefined, places = 1): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return `${value.toFixed(places)}%`
}

/** 14 Jan 2026 */
export function date(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }).format(parsed)
}

/** 14 Jan, 10:22 AM */
export function dateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', {
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  }).format(parsed)
}

/** 10:22 AM — for a list of today's activity, where the date is a given. */
export function time(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).format(parsed)
}

/** 14 Jan 2026 — short, for an axis. */
export function shortDate(value: string): string {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short' }).format(parsed)
}

/**
 * "in 2 days", "3 days ago" — for a deadline somebody has to act on.
 *
 * Words rather than a date, because "evidence due 19 Jan" needs a mental
 * subtraction and "2 days left" does not.
 */
export function relativeDays(days: number | null | undefined): string {
  if (days === null || days === undefined || Number.isNaN(days)) return '—'
  if (days === 0) return 'today'
  if (days === 1) return 'tomorrow'
  if (days === -1) return 'yesterday'
  if (days > 0) return `in ${days} days`
  return `${Math.abs(days)} days ago`
}

/** Initials for an avatar, from a name — never from a uuid. */
export function initials(name: string | null | undefined): string {
  if (!name) return '?'

  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()

  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

/** A state in the words a person would use. The server sends a label; this is the fallback. */
export function humanise(value: string | null | undefined): string {
  if (!value) return '—'
  const words = value.replace(/_/g, ' ').toLowerCase()
  return words.charAt(0).toUpperCase() + words.slice(1)
}
