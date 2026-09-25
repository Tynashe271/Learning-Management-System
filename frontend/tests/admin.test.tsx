import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokenStore } from '../src/api/client'
import type { RolesResult, SettingsResult, SystemInfo, UserDetail } from '../src/api/types'
import { App } from '../src/App'
import { describePolicy, policyProblems } from '../src/lib/institution'
import { adminMe, emptyPage, mockApi, renderApp, studentMe } from './helpers'

const config = {
  sso_enabled: false,
  sso_label: 'Sign in',
  password_login: true,
  privacy_url: null,
  terms_url: null,
  institution: { name: 'Contoso University', short_name: 'Contoso', support_email: 'help@contoso.test', support_phone: null, website: null, timezone: 'Africa/Harare', locale: 'en-GB' },
  password_policy: { min_length: 14, mixed_case: true, number: true, symbol: false },
  maintenance: { enabled: false, message: null },
}

const shared = {
  'GET /auth/config': () => ({ body: config }),
  'GET /notifications': () => ({ body: emptyPage }),
  'GET /messages': () => ({ body: { unread_total: 0, conversations: [] } }),
  'GET /system-announcements/active': () => ({ body: [] }),
  'GET /offerings': () => ({ body: emptyPage }),
  'GET /reports/overview': () => ({ body: { users: { total: 3, active: 3, by_role: {} }, offerings: { total: 1, published: 1 }, enrolments_active: 2, assignments: 1, submissions: 1, quizzes: 1, quiz_attempts_submitted: 1 } }),
}

beforeEach(() => {
  window.__LMS_CONFIG__ = { apiUrl: 'http://api.test/api' }
  tokenStore.set('t', false)
})
afterEach(() => vi.unstubAllGlobals())

const signedInAs = (me: unknown) => ({ ...shared, 'GET /me': () => ({ body: me }) })
const registrarMe = { id: 9, name: 'Reg Istrar', email: 'reg@example.test', roles: [{ id: 3, name: 'registrar' }], permissions: ['manage-enrolments'] }

describe('the institution', () => {
  it('shows its name in the header and names what is missing from a new password', () => {
    expect(describePolicy(config.password_policy)).toBe('At least 14 characters, with upper and lower case letters and a number.')
    expect(policyProblems('short', config.password_policy)).toEqual(['at least 14 characters', 'both upper and lower case letters', 'a number'])
    expect(policyProblems('Longer-Passphrase-1', config.password_policy)).toEqual([])
  })

  it('uses the institution’s name in the header and the support address in the footer', async () => {
    mockApi(signedInAs(studentMe))
    renderApp(<App />, '/')
    expect(await screen.findByRole('link', { name: /Contoso University/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Help: help@contoso.test' })).toHaveAttribute('href', 'mailto:help@contoso.test')
  })

  it('tells people on the sign-in page when the system is in maintenance', async () => {
    tokenStore.clear()
    mockApi({ ...shared, 'GET /auth/config': () => ({ body: { ...config, maintenance: { enabled: true, message: 'Back at 06:00.' } } }) })
    renderApp(<App />, '/login')
    expect(await screen.findByText('Back at 06:00.')).toBeInTheDocument()
  })
})

describe('the menu', () => {
  it('groups the administration links and shows each person only what they may use', async () => {
    mockApi(signedInAs(adminMe))
    renderApp(<App />, '/')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    for (const heading of ['Academics', 'Institution', 'Security and records', 'System']) expect(within(nav).getByText(heading)).toBeInTheDocument()
    for (const name of ['Settings', 'Notices to everyone', 'Integrations', 'Roles and permissions', 'Security', 'Audit log', 'System and jobs', 'Backups']) {
      expect(within(nav).getByRole('link', { name })).toBeInTheDocument()
    }
  })

  it('shows a registrar the enrolment work and nothing technical', async () => {
    mockApi(signedInAs(registrarMe))
    renderApp(<App />, '/')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    for (const name of ['Terms and courses', 'People', 'Reports']) expect(within(nav).getByRole('link', { name })).toBeInTheDocument()
    for (const name of ['Settings', 'Backups', 'System and jobs', 'Audit log', 'Security', 'Roles and permissions', 'Import accounts']) expect(within(nav).queryByRole('link', { name })).not.toBeInTheDocument()
  })

  it('offers students course registration', async () => {
    mockApi(signedInAs(studentMe))
    renderApp(<App />, '/')
    expect(await within(await screen.findByRole('navigation', { name: 'Main' })).findByRole('link', { name: 'Register for courses' })).toBeInTheDocument()
  })

  it('shows the institution’s notices at the top and lets a reader dismiss them, but not an urgent one', async () => {
    mockApi({
      ...signedInAs(studentMe),
      'GET /system-announcements/active': () => ({
        body: [
          { id: 1, title: 'Registration closes Friday', body: 'Sign up soon.', severity: 'info', ends_at: null },
          { id: 2, title: 'Down tonight', body: 'From 22:00.', severity: 'critical', ends_at: null },
        ],
      }),
    })
    renderApp(<App />, '/')
    expect(await screen.findByText('Registration closes Friday')).toBeInTheDocument()
    expect(screen.getByText('Down tonight')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Dismiss: Down tonight' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Dismiss: Registration closes Friday' }))
    expect(screen.queryByText('Registration closes Friday')).not.toBeInTheDocument()
  })
})

describe('settings', () => {
  const settings = (over: Partial<SettingsResult['groups'][number]['settings'][number]> = {}): SettingsResult => ({
    groups: [
      {
        group: 'Institution',
        settings: [{ key: 'institution.name', label: 'Institution name', help: 'Shown everywhere.', type: 'string', options: null, min: null, max: null, value: 'Contoso University', default: 'University LMS', overridden: true, ...over }],
      },
      {
        group: 'Maintenance',
        settings: [{ key: 'maintenance.enabled', label: 'Maintenance mode', help: '', type: 'bool', options: null, min: null, max: null, value: false, default: false, overridden: false }],
      },
    ],
  })

  it('saves only what was changed, and can put one setting back to its default', async () => {
    const { calls } = mockApi({ ...signedInAs(adminMe), 'GET /settings': () => ({ body: settings() }), 'PUT /settings': () => ({ body: { changed: ['institution.name'], ...settings() } }) })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/settings')
    const name = await screen.findByLabelText('Institution name')
    const save = screen.getByRole('button', { name: 'Save settings' })
    expect(save).toBeDisabled()
    await user.clear(name)
    await user.type(name, 'Fabrikam College')
    expect(screen.getByText('1 unsaved change.')).toBeInTheDocument()
    await user.click(save)
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT')).toBe(true))
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ settings: { 'institution.name': 'Fabrikam College' } })
  })

  it('offers to go back to the default only for a setting that was changed', async () => {
    const { calls } = mockApi({ ...signedInAs(adminMe), 'GET /settings': () => ({ body: settings() }), 'PUT /settings': () => ({ body: { changed: ['institution.name'], ...settings() } }) })
    renderApp(<App />, '/admin/settings')
    await userEvent.click(await screen.findByRole('button', { name: 'Use the default' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ reset: ['institution.name'] }))
  })

  it('asks before switching maintenance mode on, and does nothing if the answer is no', async () => {
    const { calls } = mockApi({ ...signedInAs(adminMe), 'GET /settings': () => ({ body: settings() }), 'PUT /settings': () => ({ body: { changed: [], ...settings() } }) })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/settings')
    await user.click(await screen.findByLabelText('Maintenance mode'))
    await user.click(screen.getByRole('button', { name: 'Save settings' }))
    const dialog = await screen.findByRole('dialog', { name: 'Turn on maintenance mode?' })
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(calls.some((c) => c.method === 'PUT')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Save settings' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Turn it on' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ settings: { 'maintenance.enabled': true } }))
  })

  it('shows the server’s complaint next to the setting it is about', async () => {
    mockApi({ ...signedInAs(adminMe), 'GET /settings': () => ({ body: settings() }), 'PUT /settings': () => ({ status: 422, body: { message: 'x', errors: { 'institution.name': ['The name is too long.'] } } }) })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/settings')
    await user.type(await screen.findByLabelText('Institution name'), '!')
    await user.click(screen.getByRole('button', { name: 'Save settings' }))
    expect(await screen.findByText('The name is too long.')).toBeInTheDocument()
  })
})

describe('roles and permissions', () => {
  const roles: RolesResult = {
    permissions: [
      { name: 'manage-users', description: 'Create and change accounts.' },
      { name: 'manage-system', description: 'Backups and roles.' },
    ],
    roles: [
      { name: 'super-admin', users: 1, permissions: ['manage-users', 'manage-system'], defaults: ['manage-users', 'manage-system'], locked: true },
      { name: 'registrar', users: 2, permissions: [], defaults: [], locked: false },
    ],
  }

  it('lets a super administrator change what a role may do', async () => {
    const { calls } = mockApi({ ...signedInAs(adminMe), 'GET /roles': () => ({ body: roles }), 'PUT /roles/registrar/permissions': () => ({ body: { name: 'registrar', permissions: ['manage-users'] } }) })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/roles')
    const box = await screen.findByRole('checkbox', { name: /manage-users/ })
    await user.click(box)
    await user.click(screen.getByRole('button', { name: 'Save Registrar' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ permissions: ['manage-users'] }))
  })

  it('shows the roles to someone who may look but not change them', async () => {
    const readOnly = { ...adminMe, permissions: ['manage-users'] }
    mockApi({ ...signedInAs(readOnly), 'GET /roles': () => ({ body: roles }) })
    renderApp(<App />, '/admin/roles')
    expect(await screen.findByText(/Only a super administrator can change it/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save Registrar' })).not.toBeInTheDocument()
    expect(await screen.findByRole('checkbox', { name: /manage-users/ })).toBeDisabled()
  })
})

describe('one person’s account', () => {
  const detail = (over: Partial<UserDetail> = {}): UserDetail => ({
    user: { id: 21, name: 'Ben Locked', email: 'ben@example.test', is_active: true, last_login_at: null, created_at: '2026-01-01T00:00:00Z', anonymised_at: null, digest_frequency: 'off', roles: [{ id: 7, name: 'student' }], has_sso_link: false },
    signin: { locked: true, locked_for_seconds: 600, can_sign_in: false },
    sessions: [{ id: 1, kind: 'Password', last_used_at: null, created_at: '2026-01-01T00:00:00Z', expires_at: null }],
    enrolments: [],
    teaching: [],
    events: [],
    records: { submissions: 0 },
    ...over,
  })

  it('explains why they cannot sign in and unlocks them', async () => {
    const { calls } = mockApi({ ...signedInAs(adminMe), 'GET /users/21': () => ({ body: detail() }), 'POST /users/21/unlock': () => ({ body: { message: 'ok' } }) })
    renderApp(<App />, '/admin/users/21')
    expect(await screen.findByText('Locked for 10 more minutes')).toBeInTheDocument()
    expect(screen.getByText('Cannot sign in now')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Unlock the account' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/users/21/unlock')).toBe(true))
  })

  it('changes a role only after confirmation', async () => {
    const { calls } = mockApi({ ...signedInAs(adminMe), 'GET /users/21': () => ({ body: detail({ signin: { locked: false, locked_for_seconds: 0, can_sign_in: true } }) }), 'PATCH /users/21': () => ({ body: {} }) })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/users/21')
    await user.selectOptions(await screen.findByLabelText('Role'), 'lecturer')
    await user.click(screen.getByRole('button', { name: 'Save changes' }))
    const dialog = await screen.findByRole('dialog', { name: 'Change their role?' })
    await user.click(within(dialog).getByRole('button', { name: 'Change role' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')?.body).toMatchObject({ role: 'lecturer', email: 'ben@example.test', is_active: true }))
  })

  it('will not offer deletion for someone who has produced records, and makes anonymising need the exact email', async () => {
    const { calls } = mockApi({ ...signedInAs(adminMe), 'GET /users/21': () => ({ body: detail({ records: { submissions: 3 } }) }), 'POST /users/21/anonymise': () => ({ body: { message: 'Anonymised.' } }) })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/users/21')
    await screen.findByText('Privacy and removal')
    expect(screen.queryByRole('button', { name: 'Delete the account' })).not.toBeInTheDocument()
    expect(screen.getByText(/3 submissions/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Anonymise…' }))
    const confirm = screen.getByRole('button', { name: 'Anonymise for good' })
    expect(confirm).toBeDisabled()
    await user.type(screen.getByLabelText(/Type ben@example.test to confirm/), 'ben@example.test')
    expect(confirm).toBeEnabled()
    await user.click(confirm)
    await waitFor(() => expect(calls.find((c) => c.path === '/users/21/anonymise')?.body).toEqual({ confirm_email: 'ben@example.test' }))
  })
})

describe('system and backups', () => {
  const info: SystemInfo = {
    warnings: ['No backup has been made yet.'],
    application: { name: 'Contoso', version: '1.1.0', environment: 'production', debug: false, timezone: 'Africa/Harare', php: '8.4', laravel: '13', server_time: '2026-09-19T10:00:00Z' },
    database: { driver: 'pgsql', ok: true, latency_ms: 1.2, size_bytes: 12_000_000, tables: { users: 5 }, pending_migrations: [] },
    storage: { ok: true, total_bytes: 0, total_files: 0, truncated: false, by_kind: {} },
    disk: { free: 50, total: 100 },
    queue: { driver: 'redis', waiting: 0, waiting_backups: 0, failed: 1 },
    scheduler: { last_beat_seconds_ago: 20, ok: true },
    cache: { store: 'redis' },
    mail: { mailer: 'smtp', from: 'lms@contoso.test' },
    backups: { count: 0, last: null, age_hours: null, status: { running: false } },
  }

  it('lists what needs attention and lets a failed job be retried', async () => {
    const { calls } = mockApi({
      ...signedInAs(adminMe),
      'GET /system': () => ({ body: info }),
      'GET /system/failed-jobs': () => ({ body: { ...emptyPage, total: 1, data: [{ id: 1, uuid: 'abc', queue: 'default', job: 'App\\Jobs\\SendThing', error: 'RuntimeException: boom', failed_at: '2026-09-19T09:00:00Z' }] } }),
      'POST /system/failed-jobs/abc/retry': () => ({ body: { message: 'ok' } }),
    })
    renderApp(<App />, '/admin/system')
    expect(await screen.findByText('No backup has been made yet.')).toBeInTheDocument()
    expect(await screen.findByText('SendThing')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/system/failed-jobs/abc/retry')).toBe(true))
  })

  it('starts a backup, and shows how to restore one at the server instead of offering a restore button', async () => {
    const { calls } = mockApi({
      ...signedInAs(adminMe),
      'GET /backups': () => ({ body: { backups: [], status: { running: false }, automatic: { enabled: true, keep: 14, include_files: true, at: '02:30 Africa/Harare' }, location: 'backups', restore_command: 'php artisan lms:restore <backup file name>' } }),
      'POST /backups': () => ({ status: 202, body: { message: 'started' } }),
    })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/backups')
    expect(await screen.findByText('php artisan lms:restore <backup file name>')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /restore/i })).not.toBeInTheDocument()
    await user.click(screen.getByLabelText(/Include uploaded files/))
    await user.click(screen.getByRole('button', { name: 'Back up now' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ include_files: false }))
  })

  it('keeps the technical pages from someone without the system permission', async () => {
    mockApi(signedInAs({ ...adminMe, permissions: ['manage-users', 'manage-settings'] }))
    renderApp(<App />, '/admin/backups')
    expect(await screen.findByText(/do not have access to this page/i)).toBeInTheDocument()
  })
})

describe('notices to everyone', () => {
  it('posts a notice for chosen roles and can ask for a notification too', async () => {
    const { calls } = mockApi({ ...signedInAs(adminMe), 'GET /system-announcements': () => ({ body: emptyPage }), 'POST /system-announcements': () => ({ status: 201, body: { id: 1 } }) })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/announcements')
    await user.click(await screen.findByRole('button', { name: 'New notice' }))
    await user.type(screen.getByLabelText('Headline'), 'Exams start Monday')
    await user.type(screen.getByLabelText('Message'), 'Bring your card.')
    await user.click(screen.getByLabelText('Student'))
    await user.click(screen.getByLabelText(/Also put it in everyone/))
    await user.click(screen.getByRole('button', { name: 'Post notice' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ title: 'Exams start Monday', body: 'Bring your card.', severity: 'info', audience: ['student'], starts_at: null, ends_at: null, notify: true }))
  })
})

describe('integrations', () => {
  it('shows each connection’s state and runs a real test on request', async () => {
    const { calls } = mockApi({
      ...signedInAs(adminMe),
      'GET /integrations': () => ({ body: { integrations: [{ key: 'storage', label: 'File storage', can_test: true, status: 'ok', summary: 'Files are kept in private storage.', details: { Bucket: 'lms' }, change: 'AWS_* in backend/.env' }] } }),
      'POST /integrations/storage/test': () => ({ body: { ok: true, message: 'A test file was written, read back and deleted.' } }),
    })
    renderApp(<App />, '/admin/integrations')
    expect(await screen.findByText('Files are kept in private storage.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Test the connection' }))
    expect(await screen.findByText('A test file was written, read back and deleted.')).toBeInTheDocument()
    expect(calls.some((c) => c.path === '/integrations/storage/test')).toBe(true)
  })
})

describe('course registration by students', () => {
  const offering = (id: number, registration: Record<string, unknown>) => ({
    id,
    course_id: id,
    academic_term_id: 1,
    section: 'A',
    published: true,
    course: { id, code: `CSC10${id}`, title: `Course ${id}`, description: null, credits: 12 },
    term: { id: 1, name: 'Semester 1', starts_on: '2026-02-01', ends_on: '2026-06-01' },
    teachers: [],
    registration: { open: true, opens_on: '2026-01-01', closes_on: '2026-03-01', seats_left: 5, full: false, my_status: null, can_register: true, can_drop: false, drop_deadline: '2026-03-01', ...registration },
  })

  it('registers for an open course, and cannot register for a full one', async () => {
    const { calls } = mockApi({
      ...signedInAs(studentMe),
      'GET /departments': () => ({ status: 403, body: { message: 'no' } }),
      'GET /registration': () => ({ body: { ...emptyPage, total: 2, data: [offering(1, {}), offering(2, { seats_left: 0, full: true, can_register: false })] } }),
      'POST /offerings/1/register': () => ({ status: 201, body: {} }),
    })
    renderApp(<App />, '/register')
    const rows = await screen.findAllByRole('listitem')
    const first = rows.find((r) => within(r).queryByText('Course 1'))!
    const second = rows.find((r) => within(r).queryByText('Course 2'))!
    expect(within(second).getByRole('button', { name: 'Register' })).toBeDisabled()
    expect(within(second).getByText('Full')).toBeInTheDocument()
    await userEvent.click(within(first).getByRole('button', { name: 'Register' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/offerings/1/register')).toBe(true))
  })

  it('drops a course only after confirming, and only while the deadline allows', async () => {
    const { calls } = mockApi({
      ...signedInAs(studentMe),
      'GET /departments': () => ({ status: 403, body: { message: 'no' } }),
      'GET /registration': () => ({ body: { ...emptyPage, total: 2, data: [offering(1, { my_status: 'active', can_register: false, can_drop: true }), offering(2, { my_status: 'active', can_register: false, can_drop: false })] } }),
      'DELETE /offerings/1/register': () => ({ body: { message: 'ok' } }),
    })
    const user = userEvent.setup()
    renderApp(<App />, '/register')
    await screen.findByText('Course 1')
    expect(screen.getAllByRole('button', { name: 'Drop' })).toHaveLength(1)
    await user.click(screen.getByRole('button', { name: 'Drop' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Drop the course' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'DELETE' && c.path === '/offerings/1/register')).toBe(true))
  })
})

describe('the catalogue', () => {
  it('creates a term with its registration window and deadline', async () => {
    const { calls } = mockApi({
      ...signedInAs(adminMe),
      'GET /terms': () => ({ body: emptyPage }),
      'GET /departments': () => ({ body: [] }),
      'GET /courses': () => ({ body: emptyPage }),
      'POST /terms': () => ({ status: 201, body: { id: 1 } }),
    })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/catalogue')
    await user.click(await screen.findByRole('button', { name: 'New term' }))
    await user.type(screen.getByLabelText('Name'), 'Semester 1')
    await user.type(screen.getByLabelText(/Academic year/), '2026/2027')
    await user.type(screen.getByLabelText('Starts'), '2026-02-01')
    await user.type(screen.getByLabelText('Ends'), '2026-06-01')
    await user.type(screen.getByLabelText(/Opens/), '2026-01-05')
    await user.type(screen.getByLabelText(/Closes/), '2026-02-20')
    await user.type(screen.getByLabelText(/Last day to add or drop/), '2026-02-27')
    await user.click(screen.getByLabelText(/This is the current term/))
    await user.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ name: 'Semester 1', academic_year: '2026/2027', starts_on: '2026-02-01', ends_on: '2026-06-01', registration_opens_on: '2026-01-05', registration_closes_on: '2026-02-20', add_drop_deadline: '2026-02-27', is_current: true }))
  })

  it('creates a course in a department, with its level and credits', async () => {
    const { calls } = mockApi({
      ...signedInAs(adminMe),
      'GET /terms': () => ({ body: emptyPage }),
      'GET /departments': () => ({ body: [{ id: 3, code: 'SCI', name: 'Faculty of Science', courses_count: 0 }] }),
      'GET /courses': () => ({ body: emptyPage }),
      'POST /courses': () => ({ status: 201, body: { id: 1 } }),
    })
    const user = userEvent.setup()
    renderApp(<App />, '/admin/catalogue')
    await user.click(await screen.findByRole('button', { name: 'New course' }))
    await user.type(screen.getByLabelText('Course code'), 'CSC301')
    await user.type(screen.getByLabelText('Title'), 'Operating Systems')
    const dialog = screen.getByRole('dialog')
    await waitFor(() => expect(within(dialog).getByRole('option', { name: /Faculty of Science/ })).toBeInTheDocument())
    await user.selectOptions(within(dialog).getByLabelText(/^Department/), '3')
    await user.selectOptions(within(dialog).getByLabelText(/^Level/), 'undergraduate')
    await user.type(within(dialog).getByLabelText(/Credits/), '16')
    await user.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.path === '/courses')?.body).toEqual({ code: 'CSC301', title: 'Operating Systems', description: null, department_id: 3, level: 'undergraduate', credits: 16 }))
  })
})

describe('security', () => {
  it('summarises recent activity and describes events in plain words', async () => {
    mockApi({
      ...signedInAs(adminMe),
      'GET /security/summary': () => ({
        body: {
          last_24_hours: { sign_ins: 4, failed_sign_ins: 2, lockouts: 1, password_changes: 0, access_denied: 0 },
          last_7_days: { sign_ins: 9, failed_sign_ins: 3, lockouts: 1, password_changes: 1, access_denied: 0 },
          busiest_failing_addresses: [{ ip: '203.0.113.9', total: 8 }],
          events: { 'login.failed': 3 },
        },
      }),
      'GET /security/events': () => ({ body: { ...emptyPage, total: 1, data: [{ id: 1, event: 'login.failed', level: 'warning', ip: '203.0.113.9', created_at: '2026-09-19T09:00:00Z', user_id: null, user_name: null }] } }),
    })
    renderApp(<App />, '/admin/security')
    expect((await screen.findAllByText('203.0.113.9', { selector: 'code' })).length).toBeGreaterThan(0)
    expect(screen.getAllByText('Wrong password or unknown email').length).toBeGreaterThan(0)
  })
})
