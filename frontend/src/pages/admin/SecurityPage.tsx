import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { Page, SecurityCounts, SecurityEventRow, SecuritySummary } from '../../api/types'
import { Badge, Button, Card, EmptyState, PageHeader, Pager, QueryView, SelectField, Stat, Table, TextField, pagerFromPage, useDebounced } from '../../components/ui'
import { formatDateTime } from '../../lib/format'
import { useTitle } from '../../lib/hooks'

/** Plain-English names for the event codes the server records. */
export const EVENT_LABELS: Record<string, string> = {
  'login.success': 'Signed in',
  'login.failed': 'Wrong password or unknown email',
  'login.locked': 'Account locked after too many tries',
  'login.blocked_while_locked': 'Tried to sign in while locked',
  'login.inactive': 'Deactivated account tried to sign in',
  logout: 'Signed out',
  'password.changed': 'Changed their password',
  'password.change_failed': 'Password change failed',
  'password.reset': 'Reset their password',
  'password.reset_failed': 'Password reset failed',
  'password.reset_requested': 'Asked for a password reset link',
  'password.reset_sent_by_admin': 'Reset link sent by an administrator',
  'account.role_changed': 'Role changed',
  'account.unlocked': 'Unlocked by an administrator',
  'account.deleted': 'Account deleted',
  'account.exported': 'Data exported',
  'account.anonymised': 'Account anonymised',
  'sessions.revoked_by_admin': 'Signed out everywhere by an administrator',
  'access.denied': 'Refused (not allowed)',
  'sso.failed': 'Single sign-on failed',
  'role.permissions_changed': 'Role permissions changed',
  'settings.security_changed': 'Security settings changed',
  'backup.downloaded': 'Backup downloaded',
}
export const eventLabel = (event: string) => EVENT_LABELS[event] ?? event

const tone = (level: string) => (level === 'error' ? 'bad' : level === 'warning' ? 'warn' : 'neutral')

function Counts({ title, counts }: { title: string; counts: SecurityCounts }) {
  return (
    <Card title={title}>
      <div className="stats">
        <Stat label="Sign-ins" value={counts.sign_ins} />
        <Stat label="Failed sign-ins" value={counts.failed_sign_ins} hint={counts.failed_sign_ins > 0 ? 'wrong password or unknown email' : undefined} />
        <Stat label="Lockouts" value={counts.lockouts} />
        <Stat label="Password changes" value={counts.password_changes} />
        <Stat label="Refused requests" value={counts.access_denied} />
      </div>
    </Card>
  )
}

export function SecurityPage() {
  useTitle('Security')
  const [page, setPage] = useState(1)
  const [event, setEvent] = useState('')
  const [level, setLevel] = useState('')
  const [text, setText] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const q = useDebounced(text.trim())
  const summary = useQuery({ queryKey: ['security', 'summary'], queryFn: () => api.get<SecuritySummary>('/security/summary'), refetchInterval: 60_000 })
  const events = useQuery({
    queryKey: ['security', 'events', event, level, q, from, to, page],
    queryFn: () => api.get<Page<SecurityEventRow>>('/security/events', { event: event || undefined, level: level || undefined, email: q.includes('@') ? q : undefined, q: q && !q.includes('@') ? q : undefined, from: from || undefined, to: to || undefined, page }),
    placeholderData: (previous) => previous,
  })
  const reset = () => setPage(1)

  return (
    <>
      <PageHeader title="Security" subtitle="Sign-ins, lockouts, password changes and refused requests. Email addresses are never stored here, only who the account was." actions={<Button onClick={() => { void summary.refetch(); void events.refetch() }} loading={events.isFetching}>Refresh</Button>} />
      <QueryView query={summary}>
        {(s) => (
          <>
            <Counts title="Last 24 hours" counts={s.last_24_hours} />
            <Counts title="Last 7 days" counts={s.last_7_days} />
            {s.busiest_failing_addresses.length > 0 && (
              <Card title="Busiest sources of failed sign-ins (last 24 hours)">
                <Table caption="Addresses with the most failures">
                  <thead>
                    <tr>
                      <th>Address</th>
                      <th>Failures</th>
                    </tr>
                  </thead>
                  <tbody>
                    {s.busiest_failing_addresses.map((a) => (
                      <tr key={a.ip}>
                        <td>
                          <code>{a.ip}</code>
                        </td>
                        <td>{a.total}</td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
                <p className="muted small">Many failures from one address usually means someone guessing passwords. Accounts lock themselves; block the address at your firewall if it continues.</p>
              </Card>
            )}
            <Card title="Event log">
              <div className="filters">
                <SelectField label="What happened" value={event} onChange={(e) => { setEvent(e.target.value); reset() }}>
                  <option value="">Everything</option>
                  {Object.keys(s.events).map((name) => (
                    <option key={name} value={name}>
                      {eventLabel(name)} ({s.events[name]})
                    </option>
                  ))}
                </SelectField>
                <SelectField label="Importance" value={level} onChange={(e) => { setLevel(e.target.value); reset() }}>
                  <option value="">All</option>
                  <option value="warning">Warnings</option>
                  <option value="error">Errors</option>
                  <option value="info">Ordinary</option>
                </SelectField>
                <TextField label="Search" type="search" value={text} onChange={(e) => { setText(e.target.value); reset() }} placeholder="An email address, or part of an event name" />
                <TextField label="From" type="date" value={from} onChange={(e) => { setFrom(e.target.value); reset() }} />
                <TextField label="To" type="date" value={to} onChange={(e) => { setTo(e.target.value); reset() }} />
              </div>
              <QueryView query={events} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="Nothing matches">No events match these filters.</EmptyState>}>
                {(data) => (
                  <>
                    <Table caption="Security events">
                      <thead>
                        <tr>
                          <th>When</th>
                          <th>What</th>
                          <th>Who</th>
                          <th>From</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.data.map((e) => (
                          <tr key={e.id}>
                            <td>{formatDateTime(e.created_at)}</td>
                            <td>
                              <Badge tone={tone(e.level)}>{eventLabel(e.event)}</Badge>
                            </td>
                            <td>{e.user_id ? <Link to={`/admin/users/${e.user_id}`}>{e.user_name ?? `Account ${e.user_id}`}</Link> : <span className="muted">Unknown</span>}</td>
                            <td>
                              <code className="small">{e.ip ?? '—'}</code>
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
          </>
        )}
      </QueryView>
    </>
  )
}
