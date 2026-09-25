import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import type { DigestFrequency, DigestSummary, Me, Notification, Page, Preferences } from '../api/types'
import { ROLE_LABELS } from '../api/types'
import { useAuth } from '../auth/AuthContext'
import { Alert, Badge, Button, Card, EmptyState, FormError, Pager, PageHeader, QueryView, SelectField, TextField, pagerFromPage, useToast } from '../components/ui'
import { formatDateTime, relativeTime } from '../lib/format'
import { fieldError, useApiMutation, useTitle } from '../lib/hooks'
import { describePolicy, policyProblems, useAuthConfig } from '../lib/institution'
import { notificationLink, notificationText } from '../lib/notifications'

export function ProfilePage() {
  useTitle('My account')
  const { user, setUser } = useAuth()
  const toast = useToast()
  const [name, setName] = useState(user?.name ?? '')

  const save = useApiMutation(() => api.patch<Me>('/me', { name: name.trim() }), {
    onSuccess: (me) => {
      setUser(me)
      toast.success('Your name has been updated.')
    },
  })

  return (
    <>
      <PageHeader title="My account" subtitle={user?.email} />
      <div className="grid-2">
        <Card title="Profile">
          <dl className="details">
            <dt>Email</dt>
            <dd>{user?.email}</dd>
            <dt>Role</dt>
            <dd>{(user?.roles ?? []).map((r) => ROLE_LABELS[r.name] ?? r.name).join(', ')}</dd>
          </dl>
          <form
            onSubmit={(event) => {
              event.preventDefault()
              save.mutate()
            }}
          >
            <TextField label="Display name" value={name} onChange={(e) => setName(e.target.value)} error={fieldError(save.error, 'name')} maxLength={255} required />
            {save.error && !fieldError(save.error, 'name') && <FormError error={save.error} />}
            <Button type="submit" variant="primary" loading={save.isPending} disabled={!name.trim() || name.trim() === user?.name}>
              Save
            </Button>
          </form>
        </Card>
        <PasswordCard />
      </div>
      <DigestCard />
    </>
  )
}

function PasswordCard() {
  const toast = useToast()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [again, setAgain] = useState('')
  const config = useAuthConfig()
  const change = useApiMutation(() => api.post('/me/password', { current_password: current, password: next, password_confirmation: again }), {
    onSuccess: () => {
      setCurrent('')
      setNext('')
      setAgain('')
      toast.success('Password changed. You have been signed out on your other devices.')
    },
  })
  const mismatch = again !== '' && next !== again
  return (
    <Card title="Change password">
      <form
        onSubmit={(event) => {
          event.preventDefault()
          change.mutate()
        }}
      >
        <TextField label="Current password" type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} error={fieldError(change.error, 'current_password')} required />
        <TextField label="New password" type="password" autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} hint={`${describePolicy(config.data?.password_policy)} It must differ from the current one.`} error={fieldError(change.error, 'password')} required />
        <TextField label="Repeat the new password" type="password" autoComplete="new-password" value={again} onChange={(e) => setAgain(e.target.value)} error={mismatch ? 'The two passwords do not match.' : undefined} required />
        {change.error && !fieldError(change.error, 'current_password') && !fieldError(change.error, 'password') && <FormError error={change.error} />}
        <Button type="submit" variant="primary" loading={change.isPending} disabled={!current || policyProblems(next, config.data?.password_policy).length > 0 || next !== again}>
          Change password
        </Button>
      </form>
    </Card>
  )
}

const DIGEST_LABELS: Record<DigestFrequency, string> = { off: 'Do not send me summary emails', daily: 'Every morning', weekly: 'Every Monday morning' }

function DigestCard() {
  const preferences = useQuery({ queryKey: ['preferences'], queryFn: () => api.get<Preferences>('/me/preferences') })
  const [frequency, setFrequency] = useState<DigestFrequency>('off')
  const [showPreview, setShowPreview] = useState(false)
  useEffect(() => {
    if (preferences.data) setFrequency(preferences.data.digest_frequency)
  }, [preferences.data])

  const save = useApiMutation(() => api.patch<Preferences>('/me/preferences', { digest_frequency: frequency }), { invalidate: [['preferences']], success: 'Email preference saved.' })
  const preview = useQuery({ queryKey: ['digest-preview', 'profile'], queryFn: () => api.get<DigestSummary>('/me/digest-preview'), enabled: showPreview, staleTime: 0, gcTime: 0, retry: false })

  return (
    <Card title="Summary emails">
      <QueryView query={preferences}>
        {(prefs) => (
          <>
            <p className="muted">A short email with what you missed and what is due soon. It contains counts and titles only, never marks.</p>
            <form
              onSubmit={(event) => {
                event.preventDefault()
                save.mutate()
              }}
            >
              <SelectField label="Send me a summary" value={frequency} onChange={(e) => setFrequency(e.target.value as DigestFrequency)}>
                {(Object.keys(DIGEST_LABELS) as DigestFrequency[]).map((f) => (
                  <option key={f} value={f}>
                    {DIGEST_LABELS[f]}
                  </option>
                ))}
              </SelectField>
              {save.error && <FormError error={save.error} />}
              <div className="form-actions">
                <Button type="submit" variant="primary" loading={save.isPending} disabled={frequency === prefs.digest_frequency}>
                  Save preference
                </Button>
                <Button onClick={() => setShowPreview(true)} loading={preview.isFetching}>
                  Preview the next summary
                </Button>
              </div>
            </form>
            <p className="muted small">{prefs.digest_sent_at ? `Last summary sent ${formatDateTime(prefs.digest_sent_at)}.` : 'No summary has been sent to you yet.'}</p>
          </>
        )}
      </QueryView>
      {showPreview && preview.isError && <Alert>Could not load the preview. {(preview.error as Error).message}</Alert>}
      {showPreview && preview.data && (
        <div className="preview">
          <h3>What it would say right now</h3>
          <p className="muted small">Covers updates since {formatDateTime(preview.data.since)}. Nothing has been sent.</p>
          {!preview.data.summary && <p>Nothing to report: no email would be sent.</p>}
          {preview.data.summary && (
            <ul className="list">
              {preview.data.summary.notifications?.groups.map((g) => (
                <li key={g.label}>
                  <strong>{g.label}</strong> ({g.count}) {g.titles.length > 0 && <span className="muted">– {g.titles.join(', ')}</span>}
                </li>
              ))}
              {preview.data.summary.deadlines?.map((d, i) => (
                <li key={i}>
                  Due: {d.title} ({d.course}) – {formatDateTime(d.due_at)}
                </li>
              ))}
              {preview.data.summary.to_grade?.map((g, i) => (
                <li key={i}>
                  To grade: {g.assignment} ({g.course}) – {g.awaiting} waiting
                </li>
              ))}
              {!!preview.data.summary.open_appeals && <li>{preview.data.summary.open_appeals} open grade appeal(s)</li>}
            </ul>
          )}
        </div>
      )}
    </Card>
  )
}

export function NotificationsPage() {
  useTitle('Notifications')
  const [page, setPage] = useState(1)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['notifications', 'list', page], queryFn: () => api.get<Page<Notification>>('/notifications', { page }), placeholderData: (previous) => previous })

  const markRead = useApiMutation((id: string) => api.post(`/notifications/${id}/read`), {
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
    toastError: true,
  })

  return (
    <>
      <PageHeader title="Notifications" subtitle="Updates from your courses" />
      <Card>
        <QueryView query={query} isEmpty={(data) => data.data.length === 0} empty={<EmptyState title="Nothing here yet">Grades, announcements, appeal decisions and reminders will show up as they happen.</EmptyState>}>
          {(data) => (
            <>
              <ul className="list notification-list">
                {data.data.map((n) => {
                  const link = notificationLink(n)
                  return (
                    <li key={n.id} className={n.read_at ? '' : 'unread'}>
                      <div className="grow">
                        {link ? (
                          <Link
                            to={link}
                            onClick={() => {
                              if (!n.read_at) markRead.mutate(n.id)
                            }}
                          >
                            {notificationText(n)}
                          </Link>
                        ) : (
                          notificationText(n)
                        )}
                        <span className="muted small block">{relativeTime(n.created_at)}</span>
                      </div>
                      {n.read_at ? (
                        <Badge>Read</Badge>
                      ) : (
                        <Button small onClick={() => markRead.mutate(n.id)} loading={markRead.isPending && markRead.variables === n.id}>
                          Mark as read
                        </Button>
                      )}
                    </li>
                  )
                })}
              </ul>
              <Pager {...pagerFromPage(data)} onPage={setPage} />
            </>
          )}
        </QueryView>
      </Card>
      <p>
        <Button variant="ghost" onClick={() => navigate('/profile')}>
          Email summary settings
        </Button>
      </p>
    </>
  )
}
