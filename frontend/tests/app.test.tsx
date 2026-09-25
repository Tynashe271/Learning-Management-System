import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokenStore } from '../src/api/client'
import { App } from '../src/App'
import { adminMe, emptyPage, mockApi, renderApp, studentMe } from './helpers'

beforeEach(() => {
  window.__LMS_CONFIG__ = { apiUrl: 'http://api.test/api' }
})
afterEach(() => vi.unstubAllGlobals())

const shared = {
  'GET /auth/config': () => ({ body: { sso_enabled: false, sso_label: 'University sign-in', password_login: true, privacy_url: 'https://uni.test/privacy', terms_url: null } }),
  'GET /notifications': () => ({ body: emptyPage }),
  'GET /offerings': () => ({ body: emptyPage }),
  'GET /me/digest-preview': () => ({ body: { since: '2026-01-01T00:00:00Z', summary: null } }),
}

describe('signing in', () => {
  it('sends someone who is not signed in to the sign-in page', async () => {
    mockApi(shared)
    renderApp(<App />, '/courses')
    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('signs in, remembers the person only for this tab, and shows their dashboard', async () => {
    const { calls } = mockApi({
      ...shared,
      'POST /login': () => ({ body: { token: 'tok-1', user: { id: 4, name: 'Ada Student', email: 'ada@example.test' }, roles: ['student'] } }),
      'GET /me': () => ({ body: studentMe }),
    })
    renderApp(<App />, '/login')
    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Email'), ' ada@example.test ')
    await user.type(screen.getByLabelText('Password'), 'a-long-enough-password')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Welcome back, Ada')).toBeInTheDocument()
    expect(calls.find((c) => c.path === '/login')?.body).toEqual({ email: 'ada@example.test', password: 'a-long-enough-password' })
    expect(sessionStorage.getItem('lms.token')).toBe('tok-1')
    expect(localStorage.getItem('lms.token')).toBeNull()
  })

  it('shows the server’s reason when the sign-in is refused', async () => {
    mockApi({ ...shared, 'POST /login': () => ({ status: 422, body: { message: 'Invalid credentials.', errors: { email: ['Invalid credentials.'] } } }) })
    renderApp(<App />, '/login')
    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Email'), 'ada@example.test')
    await user.type(screen.getByLabelText('Password'), 'wrong-password-here')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))
    expect(await screen.findByText('Invalid credentials.')).toBeInTheDocument()
    expect(sessionStorage.getItem('lms.token')).toBeNull()
  })

  it('offers single sign-on when the university has turned it on, and links the policies', async () => {
    mockApi({ ...shared, 'GET /auth/config': () => ({ body: { sso_enabled: true, sso_label: 'Sign in with Contoso', password_login: false, privacy_url: 'https://uni.test/privacy', terms_url: 'https://uni.test/terms' } }) })
    renderApp(<App />, '/login')
    expect(await screen.findByRole('button', { name: 'Sign in with Contoso' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Password')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Privacy policy' })).toHaveAttribute('href', 'https://uni.test/privacy')
    expect(screen.getByRole('link', { name: 'Terms of use' })).toHaveAttribute('href', 'https://uni.test/terms')
  })

  it('completes a single sign-on round trip from the provider’s redirect', async () => {
    const { calls } = mockApi({
      ...shared,
      'POST /auth/sso/callback': () => ({ body: { token: 'sso-tok', user: { id: 4, name: 'Ada Student', email: 'a@x.test' }, roles: ['student'] } }),
      'GET /me': () => ({ body: studentMe }),
    })
    renderApp(<App />, '/sso/callback?code=abc&state=xyz')
    expect(await screen.findByText('Welcome back, Ada')).toBeInTheDocument()
    expect(calls.find((c) => c.path === '/auth/sso/callback')?.body).toEqual({ code: 'abc', state: 'xyz' })
  })

  it('says so when the provider sends the person back with an error', async () => {
    mockApi(shared)
    renderApp(<App />, '/sso/callback?error=access_denied&error_description=You%20declined')
    expect(await screen.findByText('You declined')).toBeInTheDocument()
  })
})

describe('password reset', () => {
  it('asks for a reset link and does not reveal whether the account exists', async () => {
    const { calls } = mockApi({ ...shared, 'POST /forgot-password': () => ({ body: { message: 'If that account exists, a reset link has been sent.' } }) })
    renderApp(<App />, '/forgot-password')
    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Email'), 'someone@example.test')
    await user.click(screen.getByRole('button', { name: 'Send reset link' }))
    expect(await screen.findByText(/If that account exists/)).toBeInTheDocument()
    expect(calls.find((c) => c.path === '/forgot-password')?.body).toEqual({ email: 'someone@example.test' })
  })

  it('sets a new password from the emailed link, checking the two entries match', async () => {
    const { calls } = mockApi({ ...shared, 'POST /reset-password': () => ({ body: { message: 'Password reset. You can now sign in.' } }) })
    renderApp(<App />, '/reset-password?token=tkn&email=ada%40example.test')
    const user = userEvent.setup()
    expect(await screen.findByLabelText('Email')).toHaveValue('ada@example.test')
    await user.type(screen.getByLabelText('New password'), 'a-brand-new-passphrase')
    await user.type(screen.getByLabelText('Repeat the new password'), 'a-different-passphrase')
    expect(screen.getByText('The two passwords do not match.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save new password' })).toBeDisabled()
    await user.clear(screen.getByLabelText('Repeat the new password'))
    await user.type(screen.getByLabelText('Repeat the new password'), 'a-brand-new-passphrase')
    await user.click(screen.getByRole('button', { name: 'Save new password' }))
    expect(await screen.findByText('Password reset. You can now sign in.')).toBeInTheDocument()
    expect(calls.find((c) => c.path === '/reset-password')?.body).toEqual({ token: 'tkn', email: 'ada@example.test', password: 'a-brand-new-passphrase', password_confirmation: 'a-brand-new-passphrase' })
  })

  it('explains a reset link with no token', async () => {
    mockApi(shared)
    renderApp(<App />, '/reset-password')
    expect(await screen.findByText(/link is incomplete/i)).toBeInTheDocument()
  })
})

describe('what each person sees', () => {
  it('shows a student their own menu and hides administration', async () => {
    tokenStore.set('t', false)
    mockApi({ ...shared, 'GET /me': () => ({ body: studentMe }) })
    renderApp(<App />, '/')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(within(nav).getByRole('link', { name: 'My courses' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'My grade appeals' })).toBeInTheDocument()
    expect(within(nav).queryByText('Administration')).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Audit log' })).not.toBeInTheDocument()
  })

  it('shows an administrator the administration pages', async () => {
    tokenStore.set('t', false)
    mockApi({
      ...shared,
      'GET /me': () => ({ body: adminMe }),
      'GET /reports/overview': () => ({ body: { users: { total: 3, active: 3, by_role: {} }, offerings: { total: 1, published: 1 }, enrolments_active: 2, assignments: 1, submissions: 1, quizzes: 1, quiz_attempts_submitted: 1 } }),
    })
    renderApp(<App />, '/')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    for (const name of ['Terms and courses', 'People', 'Import accounts', 'Reports', 'Audit log', 'System status']) {
      expect(within(nav).getByRole('link', { name })).toBeInTheDocument()
    }
    expect(within(nav).queryByRole('link', { name: 'My grade appeals' })).not.toBeInTheDocument()
    expect(await screen.findByText('The university at a glance')).toBeInTheDocument()
  })

  it('keeps a student out of an administration page even if they type the address', async () => {
    tokenStore.set('t', false)
    mockApi({ ...shared, 'GET /me': () => ({ body: studentMe }) })
    renderApp(<App />, '/admin/audit')
    expect(await screen.findByText(/do not have access to this page/i)).toBeInTheDocument()
  })

  it('shows a friendly page for an address that does not exist', async () => {
    tokenStore.set('t', false)
    mockApi({ ...shared, 'GET /me': () => ({ body: studentMe }) })
    renderApp(<App />, '/no/such/page')
    expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
  })

  it('returns to the sign-in page, with an explanation, when the session has ended', async () => {
    tokenStore.set('expired', false)
    mockApi({ ...shared, 'GET /me': () => ({ status: 401, body: { message: 'Unauthenticated.' } }) })
    renderApp(<App />, '/courses')
    expect(await screen.findByText('Your session has ended. Please sign in again.')).toBeInTheDocument()
    expect(tokenStore.get()).toBeNull()
  })

  it('keeps a person signed in, and offers to try again, when the server could not be asked who they are', async () => {
    tokenStore.set('still-good', false)
    let fail = true
    mockApi({ ...shared, 'GET /me': () => (fail ? { status: 500, body: { message: 'Server Error' } } : { body: studentMe }) })
    renderApp(<App />, '/courses')
    expect(await screen.findByText('Something went wrong')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Sign in' })).not.toBeInTheDocument()
    expect(tokenStore.get()).toBe('still-good')
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByText('No courses to show')).toBeInTheDocument()
  })

  it('still goes to the sign-in page when the server says the session has ended', async () => {
    tokenStore.set('old', false)
    mockApi({ ...shared, 'GET /me': () => ({ status: 401, body: { message: 'Unauthenticated.' } }) })
    renderApp(<App />, '/courses')
    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
    expect(tokenStore.get()).toBeNull()
  })

  it('lets someone sign out from the retry screen', async () => {
    tokenStore.set('t', false)
    mockApi({ ...shared, 'GET /me': () => ({ status: 503, body: { message: 'Back at noon.', maintenance: true } }), 'POST /logout': () => ({ body: {} }) })
    renderApp(<App />, '/courses')
    expect(await screen.findByText('Back at noon.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Sign out instead' }))
    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
    expect(tokenStore.get()).toBeNull()
  })

  it('shows empty states and loads, instead of a blank page, for a student with no courses', async () => {
    tokenStore.set('t', false)
    mockApi({ ...shared, 'GET /me': () => ({ body: studentMe }) })
    renderApp(<App />, '/courses')
    expect(await screen.findByText('No courses to show')).toBeInTheDocument()
    expect(screen.getByText(/not enrolled in any published course/i)).toBeInTheDocument()
  })

  it('shows an error with a retry button when a list cannot be loaded', async () => {
    tokenStore.set('t', false)
    let fail = true
    mockApi({
      ...shared,
      'GET /me': () => ({ body: studentMe }),
      'GET /offerings': () => (fail ? { status: 500, body: { message: 'Server Error', request_id: 'req-123' } } : { body: emptyPage }),
    })
    renderApp(<App />, '/courses')
    expect(await screen.findByText('Something went wrong')).toBeInTheDocument()
    expect(screen.getByText('Reference: req-123')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    await waitFor(() => expect(screen.getByText('No courses to show')).toBeInTheDocument())
  })
})
