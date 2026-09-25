import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../../api/client'
import type { RoleName, UserDetail } from '../../api/types'
import { ROLES, ROLE_LABELS } from '../../api/types'
import { useAuth } from '../../auth/AuthContext'
import { Alert, Badge, Button, Card, CheckField, EmptyState, FormError, Modal, PageHeader, QueryView, SelectField, Table, TextField, useConfirm } from '../../components/ui'
import { formatDateTime, plural, relativeTime } from '../../lib/format'
import { DownloadButton, fieldError, useApiMutation, useTitle } from '../../lib/hooks'
import { eventLabel } from './SecurityPage'

/** Everything an administrator needs to help one person: why they cannot sign in, what they are in, and the privacy tools. */
export function UserPage() {
  const { id } = useParams()
  const query = useQuery({ queryKey: ['users', 'detail', id], queryFn: () => api.get<UserDetail>(`/users/${id}`), enabled: !!id })
  useTitle(query.data?.user.name ?? 'Account')
  return <QueryView query={query}>{(detail) => <Account detail={detail} />}</QueryView>
}

function Account({ detail }: { detail: UserDetail }) {
  const { user: me, can, hasRole } = useAuth()
  const { user, signin, sessions, enrolments, teaching, events, records } = detail
  const isSelf = user.id === me?.id
  const target = user.roles[0]?.name as RoleName | undefined
  const locked = !!user.roles.find((r) => r.name === 'super-admin') && !hasRole('super-admin')
  const keys = [['users']]
  const confirm = useConfirm()
  const [anonymising, setAnonymising] = useState(false)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const unlock = useApiMutation(() => api.post('/users/' + user.id + '/unlock'), { invalidate: keys, success: 'The account is unlocked.', toastError: true })
  const resetLink = useApiMutation(() => api.post<{ message: string }>(`/users/${user.id}/reset-link`), { success: (r) => r.message, toastError: true })
  const revoke = useApiMutation(() => api.post<{ revoked: number }>(`/users/${user.id}/sessions/revoke`), { invalidate: keys, success: (r) => `Signed out of ${plural(r.revoked, 'device')}.`, toastError: true })
  const remove = useApiMutation(() => api.delete(`/users/${user.id}`), {
    success: 'Account deleted.',
    onSuccess: () => {
      // Forget this account first: asking for it again would only find that it is gone.
      queryClient.removeQueries({ queryKey: ['users', 'detail'] })
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      navigate('/admin/users')
    },
    toastError: true,
  })
  const held = Object.entries(records).filter(([, n]) => n > 0)

  return (
    <>
      <PageHeader
        title={user.name}
        subtitle={
          <>
            {user.email} · <Link to="/admin/users">All people</Link>
          </>
        }
        actions={
          !isSelf && !user.anonymised_at && (
            <Link className="btn btn-secondary" to={`/messages/${user.id}`} state={{ name: user.name }}>
              Message
            </Link>
          )
        }
      />
      {user.anonymised_at && <Alert tone="warn">This account was anonymised on {formatDateTime(user.anonymised_at)}. Its name and email were replaced and nobody can sign in to it. Its academic records remain.</Alert>}
      {locked && <Alert tone="info">This is a super administrator account. Only another super administrator can change it.</Alert>}

      <Card title="Sign-in">
        <div className="badges">
          {user.anonymised_at ? <Badge tone="neutral">Anonymised</Badge> : user.is_active ? <Badge tone="good">Active</Badge> : <Badge tone="bad">Deactivated</Badge>}
          {signin.locked && <Badge tone="warn">Locked for {Math.ceil(signin.locked_for_seconds / 60)} more minutes</Badge>}
          {user.has_sso_link && <Badge tone="info">Single sign-on linked</Badge>}
          <Badge tone={signin.can_sign_in ? 'good' : 'bad'}>{signin.can_sign_in ? 'Can sign in now' : 'Cannot sign in now'}</Badge>
        </div>
        <dl className="facts">
          <dt>Role</dt>
          <dd>{user.roles.map((r) => ROLE_LABELS[r.name] ?? r.name).join(', ')}</dd>
          <dt>Last signed in</dt>
          <dd>{user.last_login_at ? `${formatDateTime(user.last_login_at)} (${relativeTime(user.last_login_at)})` : 'Never'}</dd>
          <dt>Account made</dt>
          <dd>{formatDateTime(user.created_at)}</dd>
        </dl>
        {!user.anonymised_at && !locked && can('manage-users') && (
          <div className="form-actions left">
            {signin.locked && (
              <Button variant="primary" onClick={() => unlock.mutate()} loading={unlock.isPending}>
                Unlock the account
              </Button>
            )}
            {user.is_active && (
              <Button onClick={() => resetLink.mutate()} loading={resetLink.isPending}>
                Email a password reset link
              </Button>
            )}
            <Button
              onClick={async () => {
                if (await confirm({ title: 'Sign out of every device?', message: `${user.name} will have to sign in again everywhere. Use this for a lost phone or a shared computer.`, confirmLabel: 'Sign out everywhere' })) revoke.mutate()
              }}
              loading={revoke.isPending}
              disabled={sessions.length === 0}
            >
              Sign out everywhere
            </Button>
          </div>
        )}
        <h3>Signed-in devices</h3>
        {sessions.length === 0 ? (
          <p className="muted">Not signed in anywhere.</p>
        ) : (
          <Table caption="Sessions">
            <thead>
              <tr>
                <th>Kind</th>
                <th>Started</th>
                <th>Last used</th>
                <th>Ends</th>
              </tr>
            </thead>
            <tbody>
              {sessions.map((s) => (
                <tr key={s.id}>
                  <td>{s.kind}</td>
                  <td>{formatDateTime(s.created_at)}</td>
                  <td>{s.last_used_at ? relativeTime(s.last_used_at) : 'not used'}</td>
                  <td>{s.expires_at ? formatDateTime(s.expires_at) : 'when signed out'}</td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {can('manage-users') && !user.anonymised_at && !locked && <EditCard detail={detail} isSelf={isSelf} />}

      <Card title="Courses">
        {enrolments.length === 0 && teaching.length === 0 ? (
          <EmptyState title="Not in any course" />
        ) : (
          <Table caption="Courses">
            <thead>
              <tr>
                <th>Course</th>
                <th>Term</th>
                <th>As</th>
              </tr>
            </thead>
            <tbody>
              {teaching.map((t) => (
                <tr key={`t${t.offering_id}`}>
                  <td>
                    <Link to={`/courses/${t.offering_id}`}>
                      {t.code} {t.title}
                    </Link>{' '}
                    <span className="muted">section {t.section}</span>
                  </td>
                  <td>{t.term}</td>
                  <td>Teaching</td>
                </tr>
              ))}
              {enrolments.map((e) => (
                <tr key={`e${e.offering_id}`}>
                  <td>
                    <Link to={`/courses/${e.offering_id}`}>
                      {e.code} {e.title}
                    </Link>{' '}
                    <span className="muted">section {e.section}</span>
                  </td>
                  <td>{e.term}</td>
                  <td>
                    <Badge tone={e.status === 'active' ? 'good' : 'neutral'}>{e.status === 'active' ? 'Enrolled' : 'Withdrawn'}</Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      <Card title="Recent security events">
        {events.length === 0 ? (
          <EmptyState title="Nothing recorded" />
        ) : (
          <Table caption="Recent security events">
            <thead>
              <tr>
                <th>When</th>
                <th>What</th>
                <th>From</th>
              </tr>
            </thead>
            <tbody>
              {events.map((e) => (
                <tr key={e.id}>
                  <td>{formatDateTime(e.created_at)}</td>
                  <td>
                    <Badge tone={e.level === 'error' ? 'bad' : e.level === 'warning' ? 'warn' : 'neutral'}>{eventLabel(e.event)}</Badge>
                  </td>
                  <td>
                    <code className="small">{e.ip ?? '—'}</code>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
        <p className="muted small">
          <Link to="/admin/audit">Audit log</Link> shows what this person changed.
        </p>
      </Card>

      {can('manage-users') && !locked && (
        <Card title="Privacy and removal">
          <p className="muted">
            {held.length === 0
              ? 'This account has never produced anything, so it can be deleted outright.'
              : `This account holds records the institution must be able to stand behind (${held.map(([k, n]) => `${n} ${k.replace(/_/g, ' ')}`).join(', ')}), so it cannot be deleted. Deactivate it, or anonymise it if the person has asked for their data to be erased.`}
          </p>
          <div className="form-actions left">
            <DownloadButton path={`/users/${user.id}/export`} filename={`account-${user.id}-data.json`} small={false}>
              Download all their data
            </DownloadButton>
            {held.length === 0 && !isSelf && (
              <Button
                variant="danger"
                loading={remove.isPending}
                onClick={async () => {
                  if (await confirm({ title: `Delete ${user.name}?`, message: 'The account is removed for good.', confirmLabel: 'Delete the account', danger: true })) remove.mutate()
                }}
              >
                Delete the account
              </Button>
            )}
            {can('manage-system') && !isSelf && !user.anonymised_at && target !== 'super-admin' && (
              <Button variant="danger" onClick={() => setAnonymising(true)}>
                Anonymise…
              </Button>
            )}
          </div>
          {remove.error && <FormError error={remove.error} />}
          <p className="muted small">The data download and every change here are recorded in the audit log.</p>
        </Card>
      )}
      {anonymising && <AnonymiseDialog detail={detail} onClose={() => setAnonymising(false)} />}
    </>
  )
}

function EditCard({ detail, isSelf }: { detail: UserDetail; isSelf: boolean }) {
  const { hasRole } = useAuth()
  const confirm = useConfirm()
  const { user } = detail
  const currentRole = (user.roles[0]?.name ?? 'student') as RoleName
  const [name, setName] = useState(user.name)
  const [email, setEmail] = useState(user.email)
  const [role, setRole] = useState<RoleName>(currentRole)
  const [active, setActive] = useState(user.is_active)
  const roles = ROLES.filter((r) => r !== 'super-admin' || hasRole('super-admin'))
  const save = useApiMutation(() => api.patch(`/users/${user.id}`, { name: name.trim(), email: email.trim(), role, is_active: active }), { invalidate: [['users'], ['overview']], success: 'Account updated.' })
  const dirty = name.trim() !== user.name || email.trim() !== user.email || role !== currentRole || active !== user.is_active

  return (
    <Card title="Account details">
      <form
        onSubmit={async (event) => {
          event.preventDefault()
          if (!active && user.is_active && !(await confirm({ title: `Deactivate ${user.name}?`, message: 'They will be signed out everywhere and cannot sign in until the account is reactivated.', confirmLabel: 'Deactivate', danger: true }))) return
          if (role !== currentRole && !(await confirm({ title: 'Change their role?', message: `${user.name} will go from ${ROLE_LABELS[currentRole]} to ${ROLE_LABELS[role]}. Their access changes straight away and the change is recorded.`, confirmLabel: 'Change role' }))) return
          save.mutate()
        }}
      >
        <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} error={fieldError(save.error, 'name')} maxLength={255} required />
        <TextField label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={fieldError(save.error, 'email')} hint="Changing it also changes what they sign in with." required />
        <SelectField label="Role" value={role} onChange={(e) => setRole(e.target.value as RoleName)} disabled={isSelf} error={fieldError(save.error, 'role')} hint={isSelf ? 'You cannot change your own role.' : undefined}>
          {roles.map((r) => (
            <option key={r} value={r}>
              {ROLE_LABELS[r]}
            </option>
          ))}
        </SelectField>
        <CheckField label="Account is active" hint={isSelf ? 'You cannot deactivate your own account.' : 'Untick to stop this person signing in. Nothing they made is removed.'} checked={active} disabled={isSelf} onChange={(e) => setActive(e.target.checked)} />
        {save.error && !['name', 'email', 'role'].some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!dirty || !name.trim() || !email.trim()}>
            Save changes
          </Button>
        </div>
      </form>
    </Card>
  )
}

function AnonymiseDialog({ detail, onClose }: { detail: UserDetail; onClose: () => void }) {
  const { user } = detail
  const [typed, setTyped] = useState('')
  const run = useApiMutation(() => api.post<{ message: string }>(`/users/${user.id}/anonymise`, { confirm_email: typed.trim() }), { invalidate: [['users']], success: (r) => r.message, onSuccess: onClose })
  return (
    <Modal title={`Anonymise ${user.name}`} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          run.mutate()
        }}
      >
        <Alert tone="warn">
          This cannot be undone. The name and email are replaced, sign-in becomes impossible, their messages are emptied and their notifications and attachments are deleted. Their submissions and grades stay, under “Former user”.
        </Alert>
        <TextField label={`Type ${user.email} to confirm`} value={typed} onChange={(e) => setTyped(e.target.value)} error={fieldError(run.error, 'confirm_email')} autoComplete="off" autoFocus />
        {run.error && !fieldError(run.error, 'confirm_email') && <FormError error={run.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="danger" loading={run.isPending} disabled={typed.trim().toLowerCase() !== user.email.toLowerCase()}>
            Anonymise for good
          </Button>
        </div>
      </form>
    </Modal>
  )
}
