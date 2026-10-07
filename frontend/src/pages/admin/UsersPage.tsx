import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { ImportUsersResult, Page, RoleName, User } from '../../api/types'
import { ROLES, ROLE_LABELS } from '../../api/types'
import { useAuth } from '../../auth/AuthContext'
import { Alert, Badge, Button, Card, CheckField, EmptyState, FileField, FormError, Modal, PageHeader, Pager, QueryView, SelectField, Table, TextField, pagerFromPage, useDebounced } from '../../components/ui'
import { plural, relativeTime } from '../../lib/format'
import { describePolicy, policyProblems, useAuthConfig } from '../../lib/institution'
import { bool, fieldError, useApiMutation, useTitle } from '../../lib/hooks'

/** Groups the seven roles into the three kinds of person this screen lists, for a quick visual scan. */
const roleTone = (role: string): 'neutral' | 'info' | 'warn' =>
  role === 'student' ? 'neutral' : role === 'lecturer' || role === 'teaching-assistant' ? 'info' : 'warn'

const STAFF_ROLES = ROLES.filter((r) => r !== 'student')

export function UsersPage() {
  const { can } = useAuth()
  const admin = can('manage-users')
  const [classification, setClassification] = useState<'student' | 'staff'>('student')
  const [page, setPage] = useState(1)
  const [role, setRole] = useState<'' | RoleName>('')
  const [text, setText] = useState('')
  const q = useDebounced(text.trim())
  const [creating, setCreating] = useState(false)
  const title = classification === 'student' ? 'Students' : 'Staff'
  useTitle(title)
  const query = useQuery({
    queryKey: ['users', classification, role, q, page],
    queryFn: () => api.get<Page<User>>('/users', { classification, role: role || undefined, q: q || undefined, page }),
    placeholderData: (previous) => previous,
  })

  const chooseClassification = (next: 'student' | 'staff') => {
    setClassification(next)
    setRole('')
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title={title}
        subtitle={admin ? (classification === 'student' ? 'Every student account' : 'Lecturers, administrators and other staff') : 'Look up students and staff'}
        actions={
          admin && (
            <>
              <Link className="btn btn-secondary" to="/admin/import">
                Import from a file
              </Link>
              <Button variant="primary" onClick={() => setCreating(true)}>
                New account
              </Button>
            </>
          )
        }
      />
      <nav className="tabs" aria-label="Classify people">
        <button type="button" className={`tab${classification === 'student' ? ' tab-active' : ''}`} onClick={() => chooseClassification('student')}>
          Students
        </button>
        <button type="button" className={`tab${classification === 'staff' ? ' tab-active' : ''}`} onClick={() => chooseClassification('staff')}>
          Staff
        </button>
      </nav>
      <Card>
        <div className="filters">
          <TextField label="Search" type="search" value={text} onChange={(e) => { setText(e.target.value); setPage(1) }} placeholder="Name or email" />
          {classification === 'staff' && (
            <SelectField label="Role" value={role} onChange={(e) => { setRole(e.target.value as '' | RoleName); setPage(1) }}>
              <option value="">All staff</option>
              {STAFF_ROLES.map((r) => (
                <option key={r} value={r}>
                  {ROLE_LABELS[r]}
                </option>
              ))}
            </SelectField>
          )}
        </div>
        <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No one matches">Try a different name or role.</EmptyState>}>
          {(data) => (
            <>
              <Table caption={title}>
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last signed in</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((u) => (
                    <tr key={u.id}>
                      <td>{u.name}</td>
                      <td>{u.email}</td>
                      <td>
                        <div className="badges">
                          {(u.roles ?? []).map((r) => (
                            <Badge key={r.name} tone={roleTone(r.name)}>
                              {ROLE_LABELS[r.name] ?? r.name}
                            </Badge>
                          ))}
                        </div>
                      </td>
                      <td>{u.is_active === false ? <Badge tone="bad">Deactivated</Badge> : <Badge tone="good">Active</Badge>}</td>
                      <td className="muted small">{u.last_login_at ? relativeTime(u.last_login_at) : 'never'}</td>
                      <td className="actions">
                        {admin && (
                          <Link className="btn btn-secondary btn-small" to={`/admin/users/${u.id}`}>
                            Manage
                          </Link>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
              <Pager {...pagerFromPage(data)} onPage={setPage} />
            </>
          )}
        </QueryView>
      </Card>
      {creating && <CreateUserDialog onClose={() => setCreating(false)} />}
    </>
  )
}

function CreateUserDialog({ onClose }: { onClose: () => void }) {
  const { hasRole } = useAuth()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState<RoleName>('student')
  const roles = ROLES.filter((r) => r !== 'super-admin' || hasRole('super-admin'))
  const policy = useAuthConfig().data?.password_policy
  const save = useApiMutation(() => api.post('/users', { name: name.trim(), email: email.trim(), password, role }), { invalidate: [['users'], ['overview']], success: 'Account created.', onSuccess: onClose })
  return (
    <Modal title="New account" onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Full name" value={name} onChange={(e) => setName(e.target.value)} error={fieldError(save.error, 'name')} maxLength={255} autoFocus required />
        <TextField label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={fieldError(save.error, 'email')} required />
        <TextField label="Starting password" type="text" autoComplete="off" value={password} onChange={(e) => setPassword(e.target.value)} error={fieldError(save.error, 'password')} hint={`${describePolicy(policy)} Give it to the person securely; they should change it after signing in. To let people choose their own, import them from a file instead.`} required />
        <SelectField label="Role" value={role} onChange={(e) => setRole(e.target.value as RoleName)} error={fieldError(save.error, 'role')}>
          {roles.map((r) => (
            <option key={r} value={r}>
              {ROLE_LABELS[r]}
            </option>
          ))}
        </SelectField>
        {save.error && !['name', 'email', 'password', 'role'].some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!name.trim() || !email.trim() || policyProblems(password, policy).length > 0}>
            Create account
          </Button>
        </div>
      </form>
    </Modal>
  )
}

export function ImportUsersPage() {
  useTitle('Import accounts')
  const [file, setFile] = useState<File | null>(null)
  const [invite, setInvite] = useState(true)
  const run = useApiMutation((dry: boolean) => {
    const form = new FormData()
    form.append('file', file as File)
    form.append('dry_run', bool(dry))
    form.append('send_invitations', bool(invite))
    return api.upload<ImportUsersResult>('/users/import', form)
  }, { invalidate: [['users'], ['overview']] })
  const result = run.data

  return (
    <>
      <PageHeader title="Import accounts" subtitle="Create many accounts at once from a CSV file" />
      <Card title="1. Prepare the file">
        <p>
          A CSV file whose first row names the columns <code>name</code>, <code>email</code> and <code>role</code>. Up to 1,000 rows, 2 MB. Roles: {ROLES.join(', ')}.
        </p>
        <pre className="code">{'name,email,role\nAda Lovelace,ada@uni.edu,student\nGrace Hopper,grace@uni.edu,lecturer'}</pre>
        <Button
          small
          onClick={() => {
            const blob = new Blob(['name,email,role\nAda Lovelace,ada@uni.edu,student\n'], { type: 'text/csv' })
            const url = URL.createObjectURL(blob)
            const a = document.createElement('a')
            a.href = url
            a.download = 'accounts-template.csv'
            a.click()
            URL.revokeObjectURL(url)
          }}
        >
          Download a template
        </Button>
      </Card>
      <Card title="2. Check, then import">
        <FileField label="CSV file" accept=".csv,.txt" onChange={(e) => { setFile(e.target.files?.[0] ?? null); run.reset() }} error={fieldError(run.error, 'file')} />
        <CheckField label="Email each person a link to choose their own password" hint="The link lasts 7 days. Without it, people can use “Forgot your password?” to get started." checked={invite} onChange={(e) => setInvite(e.target.checked)} />
        {run.error && !fieldError(run.error, 'file') && <FormError error={run.error} />}
        <div className="form-actions left">
          <Button loading={run.isPending && run.variables === true} disabled={!file} onClick={() => run.mutate(true)}>
            Check the file (nothing is created)
          </Button>
          <Button variant="primary" loading={run.isPending && run.variables === false} disabled={!file} onClick={() => run.mutate(false)}>
            Create the accounts
          </Button>
        </div>
      </Card>
      {result && (
        <Card title={result.dry_run ? 'Check result' : 'Import result'}>
          {result.dry_run ? (
            <Alert tone={result.errors.length ? 'warn' : 'success'}>
              {plural(result.would_create, 'account')} would be created{result.errors.length ? `, ${plural(result.errors.length, 'row')} would be skipped` : ''}. Nothing has been created yet.
            </Alert>
          ) : (
            <Alert tone="success">
              {plural(result.created, 'account')} created{result.invited ? ' and invited by email' : ''}
              {result.errors.length ? `; ${plural(result.errors.length, 'row')} skipped` : ''}.
            </Alert>
          )}
          {result.errors.length > 0 && (
            <Table caption="Rows with problems">
              <thead>
                <tr>
                  <th>Line</th>
                  <th>Email</th>
                  <th>Problem</th>
                </tr>
              </thead>
              <tbody>
                {result.errors.map((e) => (
                  <tr key={e.line}>
                    <td>{e.line}</td>
                    <td>{e.email}</td>
                    <td>{e.errors.join(' ')}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
      )}
    </>
  )
}
