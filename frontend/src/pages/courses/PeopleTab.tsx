import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { EnrolmentImportResult, OfferingSummary, Person, Roster, RoleName } from '../../api/types'
import { useAuth } from '../../auth/AuthContext'
import { PersonPicker } from '../../components/PersonPicker'
import { Alert, Badge, Button, Card, EmptyState, FileField, FormError, Modal, Pager, QueryView, SelectField, Table, TextArea, pagerFromMeta, useConfirm } from '../../components/ui'
import { formatDateTime, plural } from '../../lib/format'
import { useApiMutation } from '../../lib/hooks'
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
  const { id, admin, registrar } = useOffering()
  const { can } = useAuth()
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [enrolling, setEnrolling] = useState(false)
  const [assigning, setAssigning] = useState(false)
  const [importing, setImporting] = useState(false)
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
                    </div>
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
                      {registrar && <th />}
                    </tr>
                  </thead>
                  <tbody>
                    {data.enrolments.map((e) => (
                      <tr key={e.id}>
                        <td>{e.user?.name}</td>
                        <td>{e.user?.email}</td>
                        <td>{e.status === 'active' ? <Badge tone="good">Active</Badge> : <Badge>Withdrawn</Badge>}</td>
                        {registrar && (
                          <td className="actions">
                            <Button small onClick={() => setStatus.mutate({ userId: e.user_id, status: e.status === 'active' ? 'withdrawn' : 'active' })}>
                              {e.status === 'active' ? 'Withdraw' : 'Re-enrol'}
                            </Button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </Table>
                <Pager {...pagerFromMeta(data.meta)} onPage={setPage} />
              </>
            )}
          </Card>
          {enrolling && <EnrolDialog offeringId={id} enrolledIds={data.enrolments.map((e) => e.user_id)} onClose={() => setEnrolling(false)} />}
          {assigning && <AssignDialog offeringId={id} teacherIds={data.teachers.map((t) => t.user_id)} onClose={() => setAssigning(false)} />}
          {importing && <ImportDialog offeringId={id} onClose={() => setImporting(false)} />}
        </>
      )}
    </QueryView>
  )
}

function EnrolDialog({ offeringId, enrolledIds, onClose }: { offeringId: number; enrolledIds: number[]; onClose: () => void }) {
  const enrol = useApiMutation((person: Person) => api.post(`/offerings/${offeringId}/enrolments`, { user_id: person.id, status: 'active' }), { invalidate: [['roster', offeringId]], success: 'Student enrolled.', toastError: true })
  return (
    <Modal title="Enrol a student" onClose={onClose}>
      <PersonPicker source="users" role="student" exclude={enrolledIds} actionLabel="Enrol" onPick={(p) => enrol.mutate(p)} />
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
      <PersonPicker key={role} source="users" role={role} exclude={teacherIds} actionLabel="Assign" onPick={(p) => assign.mutate(p)} />
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
