import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { Accommodation, Enrolment, EnrolmentImportResult, OfferingEngagement, OfferingSummary, Person, Roster, RoleName, Teacher } from '../../api/types'
import { useAuth, useMe } from '../../auth/AuthContext'
import { PersonPicker } from '../../components/PersonPicker'
import { Alert, Badge, Button, Card, EmptyState, FileField, FormError, Modal, Pager, QueryView, SelectField, Table, TextArea, TextField, pagerFromMeta, useConfirm } from '../../components/ui'
import { formatDateTime, plural } from '../../lib/format'
import { fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

export function OverviewTab() {
  const { id } = useOffering()
  const query = useQuery({ queryKey: ['summary', id], queryFn: () => api.get<OfferingSummary>(`/offerings/${id}/summary`) })
  return (
    <QueryView query={query}>
      {(s) => (
        <>
          <Card title="Assignments" actions={<span className="muted">{plural(s.enrolled, 'active student')}</span>}>
            {s.assignments.length === 0 ? (
              <EmptyState title="No assignments yet" />
            ) : (
              <Table caption="Assignment progress">
                <thead>
                  <tr>
                    <th>Assignment</th>
                    <th>Due</th>
                    <th>Submitted</th>
                    <th>Graded</th>
                    <th>Still to submit</th>
                  </tr>
                </thead>
                <tbody>
                  {s.assignments.map((a) => (
                    <tr key={a.id}>
                      <td>
                        {a.title} {!a.published && <Badge tone="warn">Draft</Badge>}
                      </td>
                      <td>{formatDateTime(a.due_at)}</td>
                      <td>{a.submissions}</td>
                      <td>{a.graded}</td>
                      <td>{a.awaiting_submission}</td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
          <Card title="Quizzes">
            {s.quizzes.length === 0 ? (
              <EmptyState title="No quizzes yet" />
            ) : (
              <Table caption="Quiz progress">
                <thead>
                  <tr>
                    <th>Quiz</th>
                    <th>Due</th>
                    <th>Students who attempted</th>
                  </tr>
                </thead>
                <tbody>
                  {s.quizzes.map((q) => (
                    <tr key={q.id}>
                      <td>
                        {q.title} {!q.published && <Badge tone="warn">Draft</Badge>}
                      </td>
                      <td>{formatDateTime(q.due_at)}</td>
                      <td>
                        {q.students_attempted} of {s.enrolled}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        </>
      )}
    </QueryView>
  )
}

export function PeopleTab() {
  const { id, admin, registrar, manage } = useOffering()
  const { can } = useAuth()
  const me = useMe()
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [enrolling, setEnrolling] = useState(false)
  const [assigning, setAssigning] = useState(false)
  const [importing, setImporting] = useState(false)
  const [settingHoursFor, setSettingHoursFor] = useState<Teacher | null>(null)
  const [settingAccommodationFor, setSettingAccommodationFor] = useState<Enrolment | null>(null)
  const accommodations = useQuery({ queryKey: ['accommodations', id], queryFn: () => api.get<Accommodation[]>(`/offerings/${id}/accommodations`), enabled: manage })
  const engagement = useQuery({ queryKey: ['engagement', 'offering', id], queryFn: () => api.get<OfferingEngagement>(`/offerings/${id}/engagement`), enabled: manage })
  const query = useQuery({ queryKey: ['roster', id, page], queryFn: () => api.get<Roster>(`/offerings/${id}/roster`, { page }), placeholderData: (previous) => previous })

  const setStatus = useApiMutation((v: { userId: number; status: 'active' | 'withdrawn' }) => api.post(`/offerings/${id}/enrolments`, { user_id: v.userId, status: v.status }), { invalidate: [['roster', id]], toastError: true, success: 'Enrolment updated.' })
  const removeTeacher = useApiMutation((userId: number) => api.delete(`/offerings/${id}/teachers/${userId}`), { invalidate: [['roster', id], ['offering', id]], success: 'Teacher removed.', toastError: true })

  return (
    <QueryView query={query}>
      {(data) => (
        <>
          <Card
            title="Teaching staff"
            actions={
              admin && (
                <Button small variant="primary" onClick={() => setAssigning(true)}>
                  Assign a teacher
                </Button>
              )
            }
          >
            {data.teachers.length === 0 ? (
              <EmptyState title="No teachers assigned">{admin ? 'Assign a lecturer or teaching assistant so someone can build and grade this course.' : 'An administrator assigns teachers to this course.'}</EmptyState>
            ) : (
              <ul className="list">
                {data.teachers.map((t) => (
                  <li key={t.id}>
                    <div className="grow">
                      <strong>{t.user?.name}</strong>
                      <span className="muted small block">{t.user?.email}</span>
                      {t.consultation_hours && <span className="small block">🕘 {t.consultation_hours}</span>}
                    </div>
                    {(admin || t.user_id === me.id) && (
                      <Button small onClick={() => setSettingHoursFor(t)}>
                        {t.consultation_hours ? 'Edit hours' : 'Set hours'}
                      </Button>
                    )}
                    {admin && (
                      <Button
                        small
                        variant="danger"
                        onClick={async () => {
                          if (await confirm({ title: `Remove ${t.user?.name}?`, message: 'They will no longer be able to manage or grade this course.', confirmLabel: 'Remove teacher', danger: true })) removeTeacher.mutate(t.user_id)
                        }}
                      >
                        Remove
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          {settingHoursFor && <ConsultationHoursDialog offeringId={id} teacher={settingHoursFor} onClose={() => setSettingHoursFor(null)} />}

          <Card
            title={`Students (${data.meta.total})`}
            actions={
              (registrar || can('manage-enrolments')) && (
                <>
                  <Button small onClick={() => setImporting(true)}>
                    Import a list
                  </Button>
                  <Button small variant="primary" onClick={() => setEnrolling(true)}>
                    Enrol a student
                  </Button>
                </>
              )
            }
          >
            {data.enrolments.length === 0 ? (
              <EmptyState title="No students enrolled yet">{registrar ? 'Enrol students one at a time or import a list of email addresses.' : 'The registrar enrols students in this course.'}</EmptyState>
            ) : (
              <>
                <Table caption="Students">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Email</th>
                      <th>Status</th>
                      {manage && <th>Accommodation</th>}
                      {manage && <th>Participation</th>}
                      {(registrar || manage) && <th />}
                    </tr>
                  </thead>
                  <tbody>
                    {data.enrolments.map((e) => {
                      const accommodation = accommodations.data?.find((a) => a.user_id === e.user_id)
                      const points = engagement.data?.students.find((s) => s.user.id === e.user_id)?.points
                      return (
                        <tr key={e.id}>
                          <td>{e.user?.name}</td>
                          <td>{e.user?.email}</td>
                          <td>{e.status === 'active' ? <Badge tone="good">Active</Badge> : <Badge>Withdrawn</Badge>}</td>
                          {manage && <td>{accommodation ? <Badge tone="info">+{accommodation.extra_time_percent}% time</Badge> : <span className="muted">None</span>}</td>}
                          {manage && <td>{plural(points ?? 0, 'point')}</td>}
                          {(registrar || manage) && (
                            <td className="actions">
                              {registrar && (
                                <Button small onClick={() => setStatus.mutate({ userId: e.user_id, status: e.status === 'active' ? 'withdrawn' : 'active' })}>
                                  {e.status === 'active' ? 'Withdraw' : 'Re-enrol'}
                                </Button>
                              )}
                              {manage && (
                                <Button small onClick={() => setSettingAccommodationFor(e)}>
                                  {accommodation ? 'Edit accommodation' : 'Add accommodation'}
                                </Button>
                              )}
                            </td>
                          )}
                        </tr>
                      )
                    })}
                  </tbody>
                </Table>
                <Pager {...pagerFromMeta(data.meta)} onPage={setPage} />
              </>
            )}
          </Card>
          {enrolling && <EnrolDialog offeringId={id} enrolledIds={data.enrolments.map((e) => e.user_id)} onClose={() => setEnrolling(false)} />}
          {assigning && <AssignDialog offeringId={id} teacherIds={data.teachers.map((t) => t.user_id)} onClose={() => setAssigning(false)} />}
          {importing && <ImportDialog offeringId={id} onClose={() => setImporting(false)} />}
          {settingAccommodationFor && (
            <AccommodationDialog
              offeringId={id}
              student={settingAccommodationFor}
              existing={accommodations.data?.find((a) => a.user_id === settingAccommodationFor.user_id) ?? null}
              onClose={() => setSettingAccommodationFor(null)}
            />
          )}
        </>
      )}
    </QueryView>
  )
}

function EnrolDialog({ offeringId, enrolledIds, onClose }: { offeringId: number; enrolledIds: number[]; onClose: () => void }) {
  const enrol = useApiMutation((person: Person) => api.post(`/offerings/${offeringId}/enrolments`, { user_id: person.id, status: 'active' }), { invalidate: [['roster', offeringId]], success: 'Student enrolled.', toastError: true })
  return (
    <Modal title="Enrol a student" onClose={onClose}>
      <PersonPicker role="student" exclude={enrolledIds} actionLabel="Enrol" onPick={(p) => enrol.mutate(p)} />
    </Modal>
  )
}

function AssignDialog({ offeringId, teacherIds, onClose }: { offeringId: number; teacherIds: number[]; onClose: () => void }) {
  const [role, setRole] = useState<RoleName>('lecturer')
  const assign = useApiMutation((person: Person) => api.post(`/offerings/${offeringId}/teachers`, { user_id: person.id }), { invalidate: [['roster', offeringId], ['offering', offeringId]], success: 'Teacher assigned.', toastError: true })
  return (
    <Modal title="Assign a teacher" onClose={onClose}>
      <SelectField label="Role" value={role} onChange={(e) => setRole(e.target.value as RoleName)}>
        <option value="lecturer">Lecturer</option>
        <option value="teaching-assistant">Teaching assistant</option>
      </SelectField>
      <PersonPicker key={role} role={role} exclude={teacherIds} actionLabel="Assign" onPick={(p) => assign.mutate(p)} />
    </Modal>
  )
}

function ConsultationHoursDialog({ offeringId, teacher, onClose }: { offeringId: number; teacher: Teacher; onClose: () => void }) {
  const [hours, setHours] = useState(teacher.consultation_hours ?? '')
  const save = useApiMutation(() => api.patch(`/offerings/${offeringId}/teachers/${teacher.user_id}`, { consultation_hours: hours.trim() || null }), {
    invalidate: [['roster', offeringId], ['offering', offeringId]],
    success: 'Consultation hours saved.',
    onSuccess: onClose,
  })
  return (
    <Modal title={`Consultation hours for ${teacher.user?.name}`} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextArea
          label="When can students reach you for this course?"
          optional
          rows={3}
          value={hours}
          onChange={(e) => setHours(e.target.value)}
          error={fieldError(save.error, 'consultation_hours')}
          maxLength={500}
          placeholder="e.g. Tuesdays 2-4pm, Room 204, or by appointment"
          autoFocus
        />
        {save.error && !fieldError(save.error, 'consultation_hours') && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function AccommodationDialog({ offeringId, student, existing, onClose }: { offeringId: number; student: Enrolment; existing: Accommodation | null; onClose: () => void }) {
  const [percent, setPercent] = useState(String(existing?.extra_time_percent ?? ''))
  const [notes, setNotes] = useState(existing?.notes ?? '')
  const confirm = useConfirm()
  const save = useApiMutation(() => api.post(`/offerings/${offeringId}/accommodations`, { user_id: student.user_id, extra_time_percent: Number(percent), notes: notes.trim() || null }), {
    invalidate: [['accommodations', offeringId]],
    success: 'Accommodation saved.',
    onSuccess: onClose,
  })
  const remove = useApiMutation(() => api.delete(`/accommodations/${existing!.id}`), {
    invalidate: [['accommodations', offeringId]],
    success: 'Accommodation removed.',
    onSuccess: onClose,
  })
  return (
    <Modal title={`Extended-time accommodation for ${student.user?.name}`} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField
          label="Extra time (%)"
          type="number"
          min={1}
          max={300}
          value={percent}
          onChange={(e) => setPercent(e.target.value)}
          hint="Added to the time limit of every timed quiz in this course, e.g. 25 for time-and-a-quarter."
          error={fieldError(save.error, 'extra_time_percent')}
          required
        />
        <TextArea label="Notes" optional rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} error={fieldError(save.error, 'notes')} maxLength={2000} placeholder="e.g. Documented with disability services, review date" />
        {save.error && !fieldError(save.error, 'extra_time_percent') && !fieldError(save.error, 'notes') && <FormError error={save.error} />}
        <div className="form-actions">
          {existing && (
            <Button
              variant="danger"
              onClick={async () => {
                if (await confirm({ title: 'Remove this accommodation?', message: 'Timed quizzes will go back to their plain time limit for this student.', confirmLabel: 'Remove accommodation', danger: true })) remove.mutate()
              }}
              loading={remove.isPending}
            >
              Remove
            </Button>
          )}
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!percent}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function ImportDialog({ offeringId, onClose }: { offeringId: number; onClose: () => void }) {
  const [text, setText] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const listed = text.split(/[\s,;]+/).map((e) => e.trim()).filter(Boolean)
  // The server refuses a whole list that contains something that is not an address, so send the good ones and say which were skipped.
  const emails = listed.filter((e) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e))
  const skipped = listed.filter((e) => !emails.includes(e))
  const run = useApiMutation(
    () => {
      if (file) {
        const form = new FormData()
        form.append('file', file)
        return api.upload<EnrolmentImportResult>(`/offerings/${offeringId}/enrolments/import`, form)
      }
      return api.post<EnrolmentImportResult>(`/offerings/${offeringId}/enrolments/import`, { emails })
    },
    { invalidate: [['roster', offeringId]] },
  )
  const result = run.data
  return (
    <Modal title="Enrol students from a list" onClose={onClose} wide>
      {result ? (
        <>
          <Alert tone="success">{plural(result.enrolled, 'student')} enrolled.</Alert>
          {result.not_found.length > 0 && (
            <Alert tone="warn">
              No student account for: {result.not_found.join(', ')}. Accounts must exist (and have the student role) before they can be enrolled.
            </Alert>
          )}
          {[...result.invalid, ...(file ? [] : skipped)].length > 0 && <Alert tone="warn">Not valid email addresses (skipped): {[...result.invalid, ...(file ? [] : skipped)].join(', ')}</Alert>}
          <div className="form-actions">
            <Button variant="primary" onClick={onClose}>
              Done
            </Button>
          </div>
        </>
      ) : (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            run.mutate()
          }}
        >
          <TextArea label="Email addresses" optional rows={6} value={text} onChange={(e) => setText(e.target.value)} disabled={!!file} hint="One per line, or separated by commas. Up to 500." />
          <FileField label="Or upload a CSV file" optional accept=".csv,.txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} hint="It needs a column named “email”." />
          {run.error && <FormError error={run.error} />}
          <div className="form-actions">
            <Button onClick={onClose}>Cancel</Button>
            <Button type="submit" variant="primary" loading={run.isPending} disabled={!file && emails.length === 0}>
              Enrol {file ? 'from the file' : plural(emails.length, 'address', 'addresses')}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
