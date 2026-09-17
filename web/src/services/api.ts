/**
 * Typed fetch wrapper for the Pay API.
 *
 * What it encodes so pages do not have to:
 *
 *  - `Authorization: Bearer <ses_key>` from the portal session, minted on
 *    demand, with one silent retry on 401 with a fresh key (a key can be
 *    revoked before its local expiry).
 *  - Company context (cmp_id, bo_id) on every scoped call, as query parameters
 *    and — for JSON bodies — in the body too, which is what the backend's
 *    Http::param() reads.
 *  - The fleet's envelopes: `{data}`, `{data, meta}`, and errors as ApiError.
 *  - An Idempotency-Key on every mutating call, because this is a payment
 *    product and a double-clicked button must not take the money twice.
 */

import { ensureSesKey } from '../auth/portal'
import { getApiBaseUrl } from '../config'

export interface CompanyScope {
  cmp_id: number
  /** 0 = all branches. */
  bo_id: number
  /** The source app's financial year, passed through when one is known. */
  fy_id?: number
}

export interface ListMeta {
  total: number
  limit: number
  offset: number
  [key: string]: unknown
}

export interface ListResponse<T> {
  data: T[]
  meta: ListMeta
}

export interface ItemResponse<T> {
  data: T
}

export type QueryValue = string | number | boolean | null | undefined
export type QueryParams = Record<string, QueryValue>

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: Record<string, unknown> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /**
   * True when pressing the same button again could reasonably work.
   *
   * The difference between "the gateway timed out" and "your card was declined"
   * decides whether the UI offers Retry or asks the user to change something,
   * and only the server knows which it was.
   */
  get retryable(): boolean {
    if (typeof this.details.retryable === 'boolean') return this.details.retryable
    return this.status === 0 || this.status === 502 || this.status === 503 || this.status === 504
  }

  /** The field a validation error belongs to, for putting the message beside the input. */
  get field(): string | null {
    return typeof this.details.field === 'string' ? this.details.field : null
  }
}

/** The company scope, registered once by PayProvider and read by every call. */
let scope: CompanyScope | null = null

export function setScope(next: CompanyScope | null): void {
  scope = next
}

export function getScope(): CompanyScope | null {
  return scope
}

function buildUrl(path: string, params: QueryParams | undefined, scoped: boolean): string {
  const url = new URL(`${getApiBaseUrl()}/${path.replace(/^\//, '')}`, window.location.origin)

  if (scoped && scope) {
    url.searchParams.set('cmp_id', String(scope.cmp_id))
    url.searchParams.set('bo_id', String(scope.bo_id))
    // Only when there is one. Sending fy_id=0 would invite the other end to
    // read it as a year, and a receipt filed in financial year nought is a
    // support ticket nobody can explain.
    if (scope.fy_id) url.searchParams.set('fy_id', String(scope.fy_id))
  }

  for (const [key, value] of Object.entries(params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    url.searchParams.set(key, String(value))
  }

  return url.toString()
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  params?: QueryParams
  body?: unknown
  /** Pass false for calls that take no company context. */
  scoped?: boolean
  signal?: AbortSignal
  /**
   * Overrides the generated key. Pass the SAME key when retrying one logical
   * action, so the server replays its first answer rather than acting twice.
   */
  idempotencyKey?: string
}

/**
 * A key for one logical action.
 *
 * Generated per call rather than per attempt, which is the right default: a
 * caller that means to retry passes the original key explicitly.
 */
function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID()
  }
  return `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
}

async function send<T>(path: string, options: RequestOptions, sesKey: string): Promise<T> {
  const scoped = options.scoped !== false
  const method = options.method ?? 'GET'

  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${sesKey}`,
  }

  let body: string | undefined
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    // Context travels in the body as well: a POST that carries it only in the
    // query string works until somebody reads the body first, and then fails in
    // a way that looks like the company was never chosen.
    const payload =
      scoped && scope && typeof options.body === 'object' && options.body !== null && !Array.isArray(options.body)
        ? { ...(options.body as Record<string, unknown>), cmp_id: scope.cmp_id, bo_id: scope.bo_id, ...(scope.fy_id ? { fy_id: scope.fy_id } : {}) }
        : options.body

    body = JSON.stringify(payload)
  }

  if (method !== 'GET') {
    headers['Idempotency-Key'] = options.idempotencyKey ?? newIdempotencyKey()
  }

  const response = await fetch(buildUrl(path, options.params, scoped), {
    method,
    headers,
    body,
    signal: options.signal,
    credentials: 'omit',
  })

  if (response.status === 204) {
    return undefined as T
  }

  const text = await response.text()
  let payload: unknown = null
  try {
    payload = text === '' ? null : JSON.parse(text)
  } catch {
    // A non-JSON body from an API that always returns JSON means something
    // upstream answered instead — a proxy error page, usually.
    throw new ApiError(response.status, 'bad_response', 'The server sent something this app could not read.')
  }

  if (!response.ok) {
    const error = (payload as { error?: { code?: string; message?: string; details?: Record<string, unknown> } })?.error
    throw new ApiError(
      response.status,
      error?.code ?? 'error',
      error?.message ?? 'Something went wrong handling that request.',
      error?.details ?? {},
    )
  }

  return payload as T
}

/**
 * Send, and retry once on 401 with a freshly minted session key.
 *
 * A ses_key can be revoked before its local expiry, and the user should not be
 * bounced to the portal for something a second call fixes.
 */
async function request<T>(path: string, options: RequestOptions): Promise<T> {
  const sesKey = await ensureSesKey()

  try {
    return await send<T>(path, options, sesKey)
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      const fresh = await ensureSesKey(true)
      // The same idempotency key on the retry, so a mutating call that actually
      // succeeded before the 401 is replayed rather than repeated.
      return send<T>(path, { ...options, idempotencyKey: options.idempotencyKey ?? newIdempotencyKey() }, fresh)
    }
    throw error
  }
}

export const api = {
  one<T>(path: string, params?: QueryParams, signal?: AbortSignal): Promise<ItemResponse<T>> {
    return request<ItemResponse<T>>(path, { params, signal })
  },

  list<T>(path: string, params?: QueryParams, signal?: AbortSignal): Promise<ListResponse<T>> {
    return request<ListResponse<T>>(path, { params, signal })
  },

  post<T>(path: string, body?: unknown, options?: { params?: QueryParams; idempotencyKey?: string; signal?: AbortSignal }): Promise<ItemResponse<T>> {
    return request<ItemResponse<T>>(path, { method: 'POST', body: body ?? {}, ...options })
  },

  put<T>(path: string, body?: unknown, options?: { params?: QueryParams; signal?: AbortSignal }): Promise<ItemResponse<T>> {
    return request<ItemResponse<T>>(path, { method: 'PUT', body: body ?? {}, ...options })
  },

  delete<T>(path: string, options?: { params?: QueryParams; signal?: AbortSignal }): Promise<ItemResponse<T>> {
    return request<ItemResponse<T>>(path, { method: 'DELETE', ...options })
  },

  /** Calls that take no company context: the session, the company switcher. */
  unscoped<T>(path: string, params?: QueryParams, signal?: AbortSignal): Promise<ItemResponse<T>> {
    return request<ItemResponse<T>>(path, { params, signal, scoped: false })
  },
}
