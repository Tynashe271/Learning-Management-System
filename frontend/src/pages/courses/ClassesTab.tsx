import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import type { AttendanceStatus, AttendanceSummary, CheckinStatus, ClassSession, RollEntry } from '../../api/types'
import { ATTENDANCE_STATUSES } from '../../api/types'
import { Alert, Badge, Button, Card, EmptyState, FormError, Modal, Pager, QueryView, SelectField, Stat, Table, TextField, pagerFromMeta, useConfirm, useToast } from '../../components/ui'
import { formatDateTime, formatPercent, formatTime, fromLocalInput, isPast, toLocalInput } from '../../lib/format'
import { fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

const STATUS_TONE: Record<AttendanceStatus, 'good' | 'warn' | 'bad' | 'info'> = { present: 'good', late: 'warn', absent: 'bad', excused: 'info' }
export const statusLabel = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)

export function ClassesTab() {
  const { id, manage, student } = useOffering()
  const [editing, setEditing] = useState<ClassSession | 'new' | null>(null)
  const [running, setRunning] = useState<ClassSession | null>(null)
  const [checkingIn, setCheckingIn] = useState<ClassSession | null>(null)
  const query = useQuery({ queryKey: ['sessions', id], queryFn: () => api.get<ClassSession[]>(`/offerings/${id}/sessions`), refetchInterval: student ? 30_000 : false })

  return (
    <>
      {manage && (
        <p>
          <Button variant="primary" onClick={() => setEditing('new')}>
            Schedule a class
          </Button>
        </p>
      )}
      <Card>
        <QueryView query={query} isEmpty={(s) => s.length === 0} empty={<EmptyState title="No classes scheduled">{manage ? 'Schedule classes to take attendance and share meeting links.' : 'Scheduled classes will appear here.'}</EmptyState>}>
          {(sessions) => (
            <Table caption="Classes">
              <thead>
                <tr>
                  <th>Class</th>
                  <th>When</th>
                  <th>Where</th>
                  {student && <th>Your attendance</th>}
                  <th />
                </tr>
              </thead>
              <tbody>
                {sessions.map((s) => (
                  <tr key={s.id}>
                    <td>
                      <strong>{s.title}</strong>
                    </td>
                    <td>
                      {formatDateTime(s.starts_at)}
                      <span className="muted small block">until {formatTime(s.ends_at)}</span>
                    </td>
                    <td>
                      {s.location && <div>{s.location}</div>}
                      {s.join_url && (
                        <a href={s.join_url} target="_blank" rel="noreferrer noopener">
                          Join online
                        </a>
                      )}
                      {!s.location && !s.join_url && <span className="muted">—</span>}
                    </td>
                    {student && <td>{s.my_status ? <Badge tone={STATUS_TONE[s.my_status]}>{statusLabel(s.my_status)}</Badge> : isPast(s.ends_at) ? <Badge>Not recorded</Badge> : '—'}</td>}
                    <td className="actions">
                      {manage && (
                        <>
                          <Button small variant="primary" onClick={() => setRunning(s)}>
                            Attendance
                          </Button>
                          <Button small onClick={() => setEditing(s)}>
                            Edit
                          </Button>
                        </>
                      )}
                      {student && s.checkin_open && !s.my_status && (
                        <Button small variant="primary" onClick={() => setCheckingIn(s)}>
                          Check in
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </QueryView>
      </Card>
      {editing && <SessionDialog offeringId={id} session={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      {running && <AttendanceDialog session={running} offeringId={id} onClose={() => setRunning(null)} />}
      {checkingIn && <CheckinDialog session={checkingIn} offeringId={id} onClose={() => setCheckingIn(null)} />}
    </>
  )
}

function SessionDialog({ offeringId, session, onClose }: { offeringId: number; session: ClassSession | null; onClose: () => void }) {
  const confirm = useConfirm()
  const editing = session !== null
  const [title, setTitle] = useState(session?.title ?? '')
  const [startsAt, setStartsAt] = useState(toLocalInput(session?.starts_at))
  const [endsAt, setEndsAt] = useState(toLocalInput(session?.ends_at))
  const [location, setLocation] = useState(session?.location ?? '')
  const [joinUrl, setJoinUrl] = useState(session?.join_url ?? '')

  const save = useApiMutation(
    () => {
      const body = { title: title.trim(), starts_at: fromLocalInput(startsAt), ends_at: fromLocalInput(endsAt), location: location.trim() || null, join_url: joinUrl.trim() || null }
      return editing ? api.patch(`/sessions/${session.id}`, body) : api.post(`/offerings/${offeringId}/sessions`, body)
    },
    { invalidate: [['sessions', offeringId]], success: editing ? 'Class saved.' : 'Class scheduled.', onSuccess: onClose },
  )
  const remove = useApiMutation(() => api.delete(`/sessions/${session!.id}`), { invalidate: [['sessions', offeringId], ['attendance', offeringId]], success: 'Class deleted.', onSuccess: onClose, toastError: true })
  const known = ['title', 'starts_at', 'ends_at', 'location', 'join_url']

  return (
    <Modal title={editing ? 'Edit class' : 'Schedule a class'} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <div className="row">
          <TextField label="Starts" type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} error={fieldError(save.error, 'starts_at')} required />
          <TextField label="Ends" type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} error={fieldError(save.error, 'ends_at')} required />
        </div>
        <TextField label="Location" optional value={location} onChange={(e) => setLocation(e.target.value)} error={fieldError(save.error, 'location')} maxLength={255} placeholder="Room 204" />
        <TextField label="Online meeting link" optional type="url" value={joinUrl} onChange={(e) => setJoinUrl(e.target.value)} error={fieldError(save.error, 'join_url')} placeholder="https://" hint="A Zoom, Teams or Meet address. Students see it on this page." />
        {save.error && !known.some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          {editing && (
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={async () => {
                if (await confirm({ title: 'Delete this class?', message: 'Attendance recorded for it will be deleted too.', confirmLabel: 'Delete class', danger: true })) remove.mutate()
              }}
            >
              Delete
            </Button>
          )}
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim() || !startsAt || !endsAt}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** A student types the code the teacher shows in class. */
function CheckinDialog({ session, offeringId, onClose }: { session: ClassSession; offeringId: number; onClose: () => void }) {
  const toast = useToast()
  const [code, setCode] = useState('')
  const check = useApiMutation(() => api.post<{ status: AttendanceStatus }>(`/sessions/${session.id}/checkin`, { code: code.trim() }), {
    invalidate: [['sessions', offeringId], ['attendance', offeringId]],
    onSuccess: (result) => {
      toast.success(result.status === 'late' ? 'You are checked in, marked as late.' : 'You are checked in. Welcome!')
      onClose()
    },
  })
  return (
    <Modal title={`Check in: ${session.title}`} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          check.mutate()
        }}
      >
        <TextField label="Check-in code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} error={fieldError(check.error, 'code')} hint="Your teacher shows a 6-digit code in class." autoFocus required />
        {check.error && !fieldError(check.error, 'code') && <FormError error={check.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={check.isPending} disabled={code.length < 4}>
            Check in
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** The teacher's tools for one class: the check-in code, and the roll. */
function AttendanceDialog({ session, offeringId, onClose }: { session: ClassSession; offeringId: number; onClose: () => void }) {
  return (
    <Modal title={`Attendance: ${session.title}`} onClose={onClose} wide>
      <p className="muted">{formatDateTime(session.starts_at)}</p>
      <CheckinPanel session={session} />
      <Roll session={session} offeringId={offeringId} onDone={onClose} />
    </Modal>
  )
}

function CheckinPanel({ session }: { session: ClassSession }) {
  const [minutes, setMinutes] = useState('15')
  const status = useQuery({ queryKey: ['checkin', session.id], queryFn: () => api.get<CheckinStatus>(`/sessions/${session.id}/checkin`), refetchInterval: (q) => (q.state.data?.open ? 5_000 : false) })
  const open = useApiMutation(() => api.post<CheckinStatus>(`/sessions/${session.id}/checkin/open`, { minutes: Number(minutes) || 15 }), { invalidate: [['checkin', session.id], ['roll', session.id]], toastError: true })
  const close = useApiMutation(() => api.post<CheckinStatus>(`/sessions/${session.id}/checkin/close`), { invalidate: [['checkin', session.id], ['roll', session.id]], toastError: true })
  const [, tick] = useState(0)
  useEffect(() => {
    const timer = setInterval(() => tick((n) => n + 1), 1000)
    return () => clearInterval(timer)
  }, [])

  const s = status.data
  return (
    <Card title="Self check-in">
      {status.isPending && <p className="muted">Loading…</p>}
      {status.isError && <Alert>Could not load the check-in status.</Alert>}
      {s?.open ? (
        <div className="checkin-live">
          <div className="checkin-code" aria-label={`Check-in code ${s.code}`}>
            {s.code}
          </div>
          <p>
            Open until <strong>{formatTime(s.closes_at)}</strong> ({Math.max(0, Math.round((new Date(s.closes_at ?? 0).getTime() - Date.now()) / 60000))} min left). <strong>{s.self_checked_in}</strong> checked in so far.
          </p>
          <p className="muted small">Show or read this code to the class. Students enter it on their Classes page.</p>
          <Button variant="danger" loading={close.isPending} onClick={() => close.mutate()}>
            Close check-in
          </Button>
        </div>
      ) : (
        <form
          className="inline-form"
          onSubmit={(event) => {
            event.preventDefault()
            open.mutate()
          }}
        >
          <TextField label="Keep it open for (minutes)" type="number" min={1} max={240} value={minutes} onChange={(e) => setMinutes(e.target.value)} hint={s && s.self_checked_in > 0 ? `${s.self_checked_in} student(s) checked in earlier.` : 'Students who check in after the class has been running a while are marked late.'} />
          <Button type="submit" variant="primary" loading={open.isPending}>
            Open check-in
          </Button>
        </form>
      )}
    </Card>
  )
}

function Roll({ session, offeringId, onDone }: { session: ClassSession; offeringId: number; onDone: () => void }) {
  const roll = useQuery({ queryKey: ['roll', session.id], queryFn: () => api.get<RollEntry[]>(`/sessions/${session.id}/attendance`), refetchOnWindowFocus: false })
  const [changes, setChanges] = useState<Record<number, { status: AttendanceStatus; note: string }>>({})

  const save = useApiMutation(
    () =>
      api.put<{ marked: number }>(`/sessions/${session.id}/attendance`, {
        records: Object.entries(changes).map(([userId, c]) => ({ user_id: Number(userId), status: c.status, note: c.note.trim() || null })),
      }),
    { invalidate: [['roll', session.id], ['attendance', offeringId], ['checkin', session.id]], success: (r) => `Attendance saved for ${r.marked} student${r.marked === 1 ? '' : 's'}.`, onSuccess: () => { setChanges({}); onDone() } },
  )

  const current = (e: RollEntry) => changes[e.user.id] ?? { status: e.status as AttendanceStatus, note: e.note ?? '' }
  const set = (e: RollEntry, patch: Partial<{ status: AttendanceStatus; note: string }>) => setChanges((c) => ({ ...c, [e.user.id]: { ...current(e), ...patch, status: (patch.status ?? current(e).status ?? 'present') as AttendanceStatus } }))

  return (
    <Card
      title="Roll"
      actions={
        roll.data && roll.data.length > 0 && (
          <Button
            small
            onClick={() => {
              const all: typeof changes = {}
              roll.data!.filter((e) => !e.status).forEach((e) => (all[e.user.id] = { status: 'present', note: '' }))
              setChanges((c) => ({ ...c, ...all }))
            }}
          >
            Mark everyone unmarked as present
          </Button>
        )
      }
    >
      <QueryView query={roll} isEmpty={(r) => r.length === 0} empty={<EmptyState title="No students enrolled">Enrol students in this course first.</EmptyState>}>
        {(entries) => (
          <>
            <Table caption="Roll">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Status</th>
                  <th>Note</th>
                </tr>
              </thead>
              <tbody>
                {entries.map((e) => {
                  const c = current(e)
                  return (
                    <tr key={e.user.id}>
                      <td>
                        {e.user.name}
                        {e.source === 'self' && !changes[e.user.id] && <Badge tone="info">Self check-in</Badge>}
                      </td>
                      <td>
                        <SelectField label={`Status for ${e.user.name}`} value={c.status ?? ''} onChange={(ev) => set(e, { status: ev.target.value as AttendanceStatus })}>
                          <option value="" disabled>
                            Not marked
                          </option>
                          {ATTENDANCE_STATUSES.map((s) => (
                            <option key={s} value={s}>
                              {statusLabel(s)}
                            </option>
                          ))}
                        </SelectField>
                      </td>
                      <td>
                        <TextField label={`Note for ${e.user.name}`} value={c.note} maxLength={500} onChange={(ev) => c.status && set(e, { note: ev.target.value })} disabled={!c.status} />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </Table>
            {save.error && <FormError error={save.error} />}
            <div className="form-actions">
              <span className="muted small">{Object.keys(changes).length} change(s) to save</span>
              <Button variant="primary" loading={save.isPending} disabled={Object.keys(changes).length === 0} onClick={() => save.mutate()}>
                Save attendance
              </Button>
            </div>
          </>
        )}
      </QueryView>
    </Card>
  )
}

export function AttendanceTab() {
  const { id, manage } = useOffering()
  const [page, setPage] = useState(1)
  const query = useQuery({ queryKey: ['attendance', id, page], queryFn: () => api.get<AttendanceSummary>(`/offerings/${id}/attendance`, { page }), placeholderData: (previous) => previous })

  return (
    <QueryView query={query} isEmpty={(d) => d.sessions_total === 0} empty={<Card><EmptyState title="No classes yet">Attendance appears once classes have been scheduled.</EmptyState></Card>}>
      {(data) =>
        manage && data.students ? (
          <Card title="Attendance by student">
            <p className="muted small">Late counts as attended. Excused classes are left out of the percentage. {data.sessions_total} classes in total.</p>
            {data.students.length === 0 ? (
              <EmptyState title="No students enrolled" />
            ) : (
              <>
                <Table caption="Attendance by student">
                  <thead>
                    <tr>
                      <th>Student</th>
                      <th>Present</th>
                      <th>Late</th>
                      <th>Absent</th>
                      <th>Excused</th>
                      <th>Attendance</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.students.map((s) => (
                      <tr key={s.user.id}>
                        <td>{s.user.name}</td>
                        <td>{s.present}</td>
                        <td>{s.late}</td>
                        <td>{s.absent}</td>
                        <td>{s.excused}</td>
                        <td>
                          <strong>{formatPercent(s.percent)}</strong>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
                {data.meta && <Pager {...pagerFromMeta(data.meta)} onPage={setPage} />}
              </>
            )}
          </Card>
        ) : (
          <Card title="Your attendance">
            <div className="stats">
              <Stat label="Present" value={data.present ?? 0} />
              <Stat label="Late" value={data.late ?? 0} />
              <Stat label="Absent" value={data.absent ?? 0} />
              <Stat label="Excused" value={data.excused ?? 0} />
              <Stat label="Attendance" value={formatPercent(data.percent)} hint={`${data.sessions_total} classes`} />
            </div>
          </Card>
        )
      }
    </QueryView>
  )
}
