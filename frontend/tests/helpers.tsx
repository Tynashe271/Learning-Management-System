import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import type { ReactElement } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import { AuthProvider, useAuth } from '../src/auth/AuthContext'
import { ConfirmProvider, ToastProvider } from '../src/components/ui'

export interface Call {
  method: string
  path: string
  query: URLSearchParams
  headers: Record<string, string>
  body: unknown
}
export type Handler = (call: Call) => { status?: number; body?: unknown; headers?: Record<string, string> } | Promise<{ status?: number; body?: unknown; headers?: Record<string, string> }>

/** Replaces fetch with a fake API. Keys look like "GET /me". Unknown routes answer 404 so a missing mock is obvious. */
export function mockApi(routes: Record<string, Handler>) {
  const calls: Call[] = []
  const fake = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input))
    const method = (init?.method ?? 'GET').toUpperCase()
    const path = url.pathname.replace(/^\/api/, '')
    const headers = Object.fromEntries(Object.entries((init?.headers ?? {}) as Record<string, string>))
    let body: unknown = init?.body
    if (typeof body === 'string') {
      try {
        body = JSON.parse(body)
      } catch {
        /* keep text */
      }
    }
    const call: Call = { method, path, query: url.searchParams, headers, body }
    calls.push(call)
    const handler = routes[`${method} ${path}`]
    if (!handler) return new Response(JSON.stringify({ message: `No mock for ${method} ${path}` }), { status: 404, headers: { 'Content-Type': 'application/json' } })
    const result = await handler(call)
    return new Response(result.body === undefined ? null : JSON.stringify(result.body), {
      status: result.status ?? 200,
      headers: { 'Content-Type': 'application/json', ...(result.headers ?? {}) },
    })
  })
  vi.stubGlobal('fetch', fake)
  return { calls, fake }
}

function SignedInOnly({ children }: { children: ReactElement }) {
  const { user } = useAuth()
  return user ? children : null
}

/** Like renderApp, but shows the screen only once the signed-in user has loaded, as the real app does. */
export function renderSignedIn(ui: ReactElement, route = '/') {
  return renderApp(<SignedInOnly>{ui}</SignedInOnly>, route)
}

export function renderApp(ui: ReactElement, route = '/') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: 0 } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[route]}>
        <ToastProvider>
          <ConfirmProvider>
            <AuthProvider>{ui}</AuthProvider>
          </ConfirmProvider>
        </ToastProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

export const studentMe = { id: 4, name: 'Ada Student', email: 'ada@example.test', roles: [{ id: 7, name: 'student' }], permissions: ['submit-assignments'] }
export const adminMe = {
  id: 1,
  name: 'Root Admin',
  email: 'root@example.test',
  roles: [{ id: 1, name: 'super-admin' }],
  permissions: ['grade-submissions', 'manage-courses', 'manage-enrolments', 'manage-settings', 'manage-system', 'manage-users', 'resolve-appeals', 'submit-assignments', 'teach-courses'],
}
export const emptyPage = { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null }
