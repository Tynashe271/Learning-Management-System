/**
 * The one place that talks to the backend. It adds the bearer token, an Idempotency-Key on every change (so a retried
 * request never repeats its action), a timeout, and turns every failure into an ApiError the screens can show.
 */

declare global {
  interface Window {
    __LMS_CONFIG__?: { apiUrl?: string }
  }
}

export function apiBase(): string {
  const configured = window.__LMS_CONFIG__?.apiUrl || import.meta.env.VITE_API_URL || 'http://localhost:8080/api'
  return String(configured).replace(/\/+$/, '')
}

export type ErrorKind = 'network' | 'timeout' | 'http'

export class ApiError extends Error {
  readonly status: number
  readonly kind: ErrorKind
  readonly errors: Record<string, string[]>
  readonly retryAfter: number | null
  readonly requestId: string | null
  /** true when the server is in maintenance mode (its message says when it will be back) */
  readonly maintenance: boolean

  constructor(init: { message: string; status?: number; kind?: ErrorKind; errors?: Record<string, string[]>; retryAfter?: number | null; requestId?: string | null; maintenance?: boolean }) {
    super(init.message)
    this.name = 'ApiError'
    this.status = init.status ?? 0
    this.kind = init.kind ?? 'http'
    this.errors = init.errors ?? {}
    this.retryAfter = init.retryAfter ?? null
    this.requestId = init.requestId ?? null
    this.maintenance = init.maintenance ?? false
  }

  /** The first message for one field, e.g. fieldError('email'). */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

/** A message for anything thrown by a request, safe to show to a person. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.kind === 'network') return 'Cannot reach the server. Check your connection and try again.'
    if (error.kind === 'timeout') return 'The server took too long to answer. Please try again.'
    if (error.status === 429) {
      const wait = error.retryAfter ? ` Try again in ${error.retryAfter > 90 ? `${Math.ceil(error.retryAfter / 60)} minutes` : `${error.retryAfter} seconds`}.` : ' Please wait a moment.'
      return error.message && !/^too many attempts\.?$/i.test(error.message) ? error.message : `You are doing that too often.${wait}`
    }
    if (error.status === 503 && error.maintenance && error.message) return error.message
    if (error.status === 503) return 'The service is temporarily unavailable. Please try again shortly.'
    if (error.status === 413) return 'That is too large to send.'
    if (error.status === 403) return error.message && error.message !== 'This action is unauthorized.' ? error.message : 'You are not allowed to do that.'
    return error.message || 'Something went wrong.'
  }
  return error instanceof Error ? error.message : 'Something went wrong.'
}

// ---- token storage -----------------------------------------------------------------------------------------------
const TOKEN_KEY = 'lms.token'

export const tokenStore = {
  get(): string | null {
    try {
      return sessionStorage.getItem(TOKEN_KEY) ?? localStorage.getItem(TOKEN_KEY)
    } catch {
      return null
    }
  },
  set(token: string, remember: boolean): void {
    try {
      sessionStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(TOKEN_KEY)
      const store = remember ? localStorage : sessionStorage
      store.setItem(TOKEN_KEY, token)
    } catch {
      /* storage unavailable: the person stays signed in until the page is closed */
    }
  },
  clear(): void {
    try {
      sessionStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(TOKEN_KEY)
    } catch {
      /* nothing to clear */
    }
  },
}

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(handler: (() => void) | null): void {
  onUnauthorized = handler
}

// ---- requests ----------------------------------------------------------------------------------------------------
export type Query = Record<string, string | number | boolean | null | undefined>

export interface RequestOptions {
  query?: Query
  json?: unknown
  form?: FormData
  signal?: AbortSignal
  idempotencyKey?: string
  timeoutMs?: number
  /** Do not treat a 401 as "signed out" (used by the sign-in call itself). */
  anonymous?: boolean
}

const READ_TIMEOUT = 30_000
const UPLOAD_TIMEOUT = 120_000
const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

export function newKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`
}

function buildUrl(path: string, query?: Query): string {
  const url = new URL(apiBase() + (path.startsWith('/') ? path : `/${path}`))
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
  }
  return url.toString()
}

async function send(method: string, path: string, options: RequestOptions): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = tokenStore.get()
  if (token && !options.anonymous) headers.Authorization = `Bearer ${token}`
  let body: BodyInit | undefined
  if (options.form) body = options.form
  else if (options.json !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.json)
  }
  if (method !== 'GET') headers['Idempotency-Key'] = options.idempotencyKey ?? newKey()

  const controller = new AbortController()
  const timeout = options.timeoutMs ?? (options.form ? UPLOAD_TIMEOUT : READ_TIMEOUT)
  const timer = setTimeout(() => controller.abort('timeout'), timeout)
  options.signal?.addEventListener('abort', () => controller.abort('caller'), { once: true })
  try {
    return await fetch(buildUrl(path, options.query), { method, headers, body, signal: controller.signal })
  } catch (cause) {
    if (controller.signal.aborted && controller.signal.reason === 'timeout') throw new ApiError({ message: 'Timed out', kind: 'timeout' })
    if (controller.signal.aborted) throw cause
    throw new ApiError({ message: 'Network error', kind: 'network' })
  } finally {
    clearTimeout(timer)
  }
}

async function toError(response: Response): Promise<ApiError> {
  let payload: { message?: string; errors?: Record<string, string[]>; request_id?: string; maintenance?: boolean } = {}
  try {
    payload = await response.json()
  } catch {
    /* not JSON */
  }
  const retry = Number(response.headers.get('Retry-After'))
  return new ApiError({
    message: payload.message ?? response.statusText ?? 'Request failed',
    status: response.status,
    errors: payload.errors,
    retryAfter: Number.isFinite(retry) && retry > 0 ? retry : null,
    requestId: payload.request_id ?? response.headers.get('X-Request-Id'),
    maintenance: payload.maintenance === true,
  })
}

async function request<T>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
  // A repeated GET is harmless, and a repeated change carries the same Idempotency-Key, so both may be retried after a
  // dropped connection without doing anything twice.
  const key = options.idempotencyKey ?? (method === 'GET' ? undefined : newKey())
  const attempts = 3
  let last: unknown
  for (let attempt = 1; attempt <= attempts; attempt++) {
    try {
      const response = await send(method, path, { ...options, idempotencyKey: key })
      if (response.status === 409 && method !== 'GET' && attempt < attempts) {
        await sleep(800 * attempt) // the same request is still being processed; ask again for its result
        continue
      }
      if (!response.ok) {
        const error = await toError(response)
        if (response.status === 401 && !options.anonymous) onUnauthorized?.()
        throw error
      }
      if (response.status === 204) return undefined as T
      const text = await response.text()
      return (text ? JSON.parse(text) : undefined) as T
    } catch (error) {
      last = error
      const retryable = error instanceof ApiError && error.kind === 'network' && attempt < attempts
      if (!retryable) throw error
      await sleep(400 * attempt)
    }
  }
  throw last
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) => request<T>('GET', path, { query, signal }),
  post: <T>(path: string, json?: unknown, options: RequestOptions = {}) => request<T>('POST', path, { ...options, json }),
  put: <T>(path: string, json?: unknown, options: RequestOptions = {}) => request<T>('PUT', path, { ...options, json }),
  patch: <T>(path: string, json?: unknown, options: RequestOptions = {}) => request<T>('PATCH', path, { ...options, json }),
  delete: <T>(path: string, options: RequestOptions = {}) => request<T>('DELETE', path, options),
  upload: <T>(path: string, form: FormData, options: RequestOptions = {}) => request<T>('POST', path, { ...options, form }),
}

/** Fetches a protected file (the API needs the token, so a plain link will not work) and offers it for saving. */
export async function downloadFile(path: string, fallbackName: string, query?: Query): Promise<void> {
  const response = await send('GET', path, { query })
  if (!response.ok) {
    const error = await toError(response)
    if (response.status === 401) onUnauthorized?.()
    throw error
  }
  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = /filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i.exec(disposition)
  let name = fallbackName
  try {
    name = decodeURIComponent(match?.[1] ?? match?.[2] ?? fallbackName)
  } catch {
    name = match?.[2] ?? fallbackName
  }
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10_000)
}
