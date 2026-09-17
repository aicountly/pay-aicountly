/**
 * Loading one dashboard, with the period it is showing.
 *
 * The query key carries EVERY dimension the answer depends on — company,
 * branch and the date window — so changing any of them starts a new request and
 * aborts the old one. Without that last part a user who switches company while
 * a slow read is in flight gets the previous company's figures painted over the
 * new company's screen, which is the worst failure this kind of screen has: it
 * is wrong, it looks right, and nothing on it says which company it belongs to.
 */

import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../services/api'
import { usePay } from '../context/PayContext'

export type PeriodKey = 'today' | 'yesterday' | 'week' | 'month' | 'quarter' | 'year' | 'last_7' | 'last_30'

export const PERIOD_OPTIONS: Array<{ key: PeriodKey; label: string }> = [
  { key: 'today', label: 'Today' },
  { key: 'yesterday', label: 'Yesterday' },
  { key: 'week', label: 'This week' },
  { key: 'month', label: 'This month' },
  { key: 'last_7', label: 'Last 7 days' },
  { key: 'last_30', label: 'Last 30 days' },
  { key: 'quarter', label: 'This quarter' },
  { key: 'year', label: 'This year' },
]

export interface DashboardState<T> {
  data: T | null
  loading: boolean
  error: string | null
  /** True when the failure is worth offering a Retry for, rather than a fix. */
  retryable: boolean
  period: PeriodKey
  setPeriod: (key: PeriodKey) => void
  reload: () => void
}

export function useDashboard<T>(path: string, initialPeriod: PeriodKey = 'today'): DashboardState<T> {
  const { scope } = usePay()
  const [period, setPeriod] = useState<PeriodKey>(initialPeriod)
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [retryable, setRetryable] = useState(false)
  const [token, setToken] = useState(0)

  const reload = useCallback(() => setToken((value) => value + 1), [])

  useEffect(() => {
    if (!scope) {
      setLoading(false)
      return undefined
    }

    const controller = new AbortController()
    setLoading(true)
    setError(null)

    api
      .one<T>(path, { period }, controller.signal)
      .then((response) => {
        if (controller.signal.aborted) return
        setData(response.data)
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted) return
        setData(null)

        if (err instanceof ApiError) {
          setError(err.message)
          setRetryable(err.retryable)
        } else {
          setError(err instanceof Error ? err.message : String(err))
          setRetryable(true)
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })

    return () => controller.abort()
  }, [path, period, scope?.cmp_id, scope?.bo_id, token])

  return { data, loading, error, retryable, period, setPeriod, reload }
}
