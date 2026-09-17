/**
 * Fetch-on-mount with loading, error and reload, cancelled cleanly on unmount.
 *
 * The abort matters: without it, a user clicking through a list faster than the
 * network answers gets the FIRST response painted last, and the screen shows a
 * record they have already navigated away from.
 */

import { useCallback, useEffect, useState } from 'react'
import { ApiError } from '../services/api'

export interface AsyncState<T> {
  data: T | null
  loading: boolean
  error: string | null
  /** True when pressing Retry could reasonably work, rather than needing a fix. */
  retryable: boolean
  reload: () => void
}

export function useApi<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  enabled = true,
): AsyncState<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<string | null>(null)
  const [retryable, setRetryable] = useState(false)
  const [token, setToken] = useState(0)

  const reload = useCallback(() => setToken((n) => n + 1), [])

  useEffect(() => {
    if (!enabled) {
      setLoading(false)
      return
    }

    const controller = new AbortController()
    let cancelled = false

    setLoading(true)
    setError(null)

    fetcher(controller.signal)
      .then((result) => {
        if (!cancelled) setData(result)
      })
      .catch((err: unknown) => {
        // An abort is this component going away, not a failure to report.
        if (cancelled || controller.signal.aborted) return

        // The previous scope's figures must not stay on screen under the new
        // scope's heading.
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
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, token, enabled])

  return { data, loading, error, retryable, reload }
}

/**
 * Running one action — creating a request, sending a refund — with its own
 * in-flight and error state.
 *
 * `busy` is what disables the button. On a payment screen a double-clicked
 * Submit is not a nuisance, it is a second payment, and the server's
 * idempotency key is the belt to this pair of braces.
 */
export interface ActionState<TArgs extends unknown[], TResult> {
  run: (...args: TArgs) => Promise<TResult | null>
  busy: boolean
  error: string | null
  /** The input a validation error belongs to, so the message lands beside it. */
  field: string | null
  reset: () => void
}

export function useAction<TArgs extends unknown[], TResult>(
  action: (...args: TArgs) => Promise<TResult>,
): ActionState<TArgs, TResult> {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [field, setField] = useState<string | null>(null)

  const reset = useCallback(() => {
    setError(null)
    setField(null)
  }, [])

  const run = useCallback(
    async (...args: TArgs): Promise<TResult | null> => {
      // Two clicks, one action. The server would refuse the second anyway, but
      // the button should not invite it.
      if (busy) return null

      setBusy(true)
      setError(null)
      setField(null)

      try {
        return await action(...args)
      } catch (err: unknown) {
        if (err instanceof ApiError) {
          setError(err.message)
          setField(err.field)
        } else {
          setError(err instanceof Error ? err.message : String(err))
        }
        return null
      } finally {
        setBusy(false)
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [action, busy],
  )

  return { run, busy, error, field, reset }
}
