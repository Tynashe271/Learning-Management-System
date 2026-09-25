import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api, downloadFile, errorMessage, setUnauthorizedHandler, tokenStore } from '../src/api/client'
import { mockApi } from './helpers'

beforeEach(() => {
  window.__LMS_CONFIG__ = { apiUrl: 'http://api.test/api' }
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
  setUnauthorizedHandler(null)
})

describe('api client', () => {
  it('sends the bearer token and asks for JSON', async () => {
    tokenStore.set('abc', false)
    const { calls } = mockApi({ 'GET /me': () => ({ body: { id: 1 } }) })
    await api.get('/me')
    expect(calls[0].headers.Authorization).toBe('Bearer abc')
    expect(calls[0].headers.Accept).toBe('application/json')
  })

  it('builds the query string and skips empty values', async () => {
    const { calls } = mockApi({ 'GET /users': () => ({ body: [] }) })
    await api.get('/users', { q: 'ada', role: '', page: 2, missing: undefined })
    expect(calls[0].query.get('q')).toBe('ada')
    expect(calls[0].query.get('page')).toBe('2')
    expect(calls[0].query.has('role')).toBe(false)
    expect(calls[0].query.has('missing')).toBe(false)
  })

  it('adds an Idempotency-Key to every change but not to reads', async () => {
    const { calls } = mockApi({ 'GET /a': () => ({ body: {} }), 'POST /a': () => ({ body: {} }), 'DELETE /a': () => ({ body: {} }) })
    await api.get('/a')
    await api.post('/a', { x: 1 })
    await api.delete('/a')
    expect(calls[0].headers['Idempotency-Key']).toBeUndefined()
    expect(calls[1].headers['Idempotency-Key']).toMatch(/.{8,}/)
    expect(calls[2].headers['Idempotency-Key']).toMatch(/.{8,}/)
    expect(calls[1].headers['Idempotency-Key']).not.toBe(calls[2].headers['Idempotency-Key'])
  })

  it('retries a change after a dropped connection with the same key, so it cannot happen twice', async () => {
    const keys: string[] = []
    let attempt = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init: RequestInit) => {
        keys.push((init.headers as Record<string, string>)['Idempotency-Key'])
        if (++attempt === 1) throw new TypeError('Failed to fetch')
        return new Response('{"id":5}', { status: 201, headers: { 'Content-Type': 'application/json' } })
      }),
    )
    const result = await api.post<{ id: number }>('/things', { a: 1 })
    expect(result.id).toBe(5)
    expect(keys).toHaveLength(2)
    expect(keys[0]).toBe(keys[1])
  })

  it('gives up after repeated network failures with a friendly error', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    const error = (await api.get('/x').catch((e) => e)) as ApiError
    expect(error).toBeInstanceOf(ApiError)
    expect(error.kind).toBe('network')
    expect(errorMessage(error)).toMatch(/cannot reach the server/i)
  })

  it('turns validation errors into per-field messages', async () => {
    mockApi({ 'POST /users': () => ({ status: 422, body: { message: 'The email has already been taken.', errors: { email: ['The email has already been taken.'] } } }) })
    const error = (await api.post('/users', {}).catch((e) => e)) as ApiError
    expect(error.status).toBe(422)
    expect(error.fieldError('email')).toBe('The email has already been taken.')
    expect(error.fieldError('name')).toBeUndefined()
  })

  it('explains rate limits with the wait time', async () => {
    mockApi({ 'GET /x': () => ({ status: 429, body: { message: 'Too Many Attempts.' }, headers: { 'Retry-After': '42' } }) })
    const error = (await api.get('/x').catch((e) => e)) as ApiError
    expect(error.retryAfter).toBe(42)
    expect(errorMessage(error)).toContain('42 seconds')
  })

  it('shows the lockout message from the server as it is', () => {
    const error = new ApiError({ message: 'Too many failed sign-in attempts. Try again in 15 minute(s).', status: 429, retryAfter: 900 })
    expect(errorMessage(error)).toContain('Too many failed sign-in attempts')
  })

  it('explains a service outage and an oversized upload', () => {
    expect(errorMessage(new ApiError({ message: 'x', status: 503 }))).toMatch(/temporarily unavailable/i)
    expect(errorMessage(new ApiError({ message: 'x', status: 413 }))).toMatch(/too large/i)
    expect(errorMessage(new ApiError({ message: 'This action is unauthorized.', status: 403 }))).toBe('You are not allowed to do that.')
  })

  it('reports a timeout', async () => {
    vi.useFakeTimers()
    vi.stubGlobal(
      'fetch',
      vi.fn((_url: string, init: RequestInit) => new Promise((_resolve, reject) => init.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))))),
    )
    const pending = api.get('/slow').catch((e) => e)
    await vi.advanceTimersByTimeAsync(31_000)
    const error = (await pending) as ApiError
    expect(error.kind).toBe('timeout')
    expect(errorMessage(error)).toMatch(/too long/i)
  })

  it('signs the person out when the server says the token is no longer valid', async () => {
    tokenStore.set('old', false)
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    mockApi({ 'GET /me': () => ({ status: 401, body: { message: 'Unauthenticated.' } }) })
    await expect(api.get('/me')).rejects.toBeInstanceOf(ApiError)
    expect(handler).toHaveBeenCalledOnce()
  })

  it('does not treat a wrong password as an expired session', async () => {
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    mockApi({ 'POST /login': () => ({ status: 401, body: { message: 'Nope' } }) })
    await expect(api.post('/login', {}, { anonymous: true })).rejects.toBeInstanceOf(ApiError)
    expect(handler).not.toHaveBeenCalled()
  })

  it('waits and asks again when an identical request is still being processed (409)', async () => {
    vi.useFakeTimers()
    let n = 0
    vi.stubGlobal('fetch', vi.fn(async () => (++n === 1 ? new Response('{"message":"busy"}', { status: 409 }) : new Response('{"ok":true}', { status: 200, headers: { 'Content-Type': 'application/json' } }))))
    const pending = api.post('/x', {})
    await vi.advanceTimersByTimeAsync(1000)
    await expect(pending).resolves.toEqual({ ok: true })
    expect(n).toBe(2)
  })

  it('keeps the token in session storage unless the person asks to be remembered', () => {
    tokenStore.set('t1', false)
    expect(sessionStorage.getItem('lms.token')).toBe('t1')
    expect(localStorage.getItem('lms.token')).toBeNull()
    tokenStore.set('t2', true)
    expect(localStorage.getItem('lms.token')).toBe('t2')
    expect(sessionStorage.getItem('lms.token')).toBeNull()
    expect(tokenStore.get()).toBe('t2')
    tokenStore.clear()
    expect(tokenStore.get()).toBeNull()
  })

  it('sends files as multipart without forcing a content type', async () => {
    const { calls } = mockApi({ 'POST /up': () => ({ body: {} }) })
    const form = new FormData()
    form.append('file', new File(['x'], 'a.txt'))
    await api.upload('/up', form)
    expect(calls[0].headers['Content-Type']).toBeUndefined()
    expect(calls[0].body).toBeInstanceOf(FormData)
  })
})

describe('downloadFile', () => {
  it('saves the file under the name the server gives it', async () => {
    tokenStore.set('abc', false)
    vi.stubGlobal('fetch', vi.fn(async () => new Response('hello', { status: 200, headers: { 'Content-Disposition': 'attachment; filename="report final.csv"' } })))
    const created: string[] = []
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      created.push(this.download)
    })
    await downloadFile('/offerings/1/gradebook', 'fallback.csv', { format: 'csv' })
    expect(created).toEqual(['report final.csv'])
    click.mockRestore()
  })

  it('reports a refused download instead of saving an error page', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{"message":"This action is unauthorized."}', { status: 403 })))
    await expect(downloadFile('/items/1/download', 'x')).rejects.toMatchObject({ status: 403 })
  })
})
