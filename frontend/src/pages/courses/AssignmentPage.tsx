import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import type { Appeal, Assignment, MyGrade, Offering, Page, RubricCriterion, SimilarityReport, Submission, SubmissionFeedback, SubmissionVersion } from '../../api/types'
import { Alert, Badge, Button, Card, EmptyState, ErrorState, FileField, FormError, Loading, Modal, PageHeader, Pager, PublishedBadge, QueryView, Table, TextArea, TextField, pagerFromPage, useConfirm } from '../../components/ui'
import { formatDateTime, formatScore, isPast, plural } from '../../lib/format'
import { DownloadButton, fieldError, useApiMutation, useTitle } from '../../lib/hooks'
import { AssignmentDialog } from './AssignmentsTab'
import { GradeDialog, latestRecord, recordsOf } from './GradeDialog'
import { RubricEditor, RubricView } from './Rubric'

type AssignmentDetail = Assignment & { offering: Offering; abilities: { manage: boolean; grade: boolean; submit: boolean } }

export function AssignmentPage() {
  const { id } = useParams()
  const assignmentId = Number(id)
  const navigate = useNavigate()
  const confirm = useConfirm()
  const [editing, setEditing] = useState(false)
  const [editingRubric, setEditingRubric] = useState(false)

  const query = useQuery({ queryKey: ['assignment', assignmentId], queryFn: () => api.get<AssignmentDetail>(`/assignments/${assignmentId}`), retry: false })
  const rubric = useQuery({ queryKey: ['rubric', assignmentId], queryFn: () => api.get<RubricCriterion[]>(`/assignments/${assignmentId}/rubric`), enabled: query.isSuccess })
  const remove = useApiMutation(() => api.delete(`/assignments/${assignmentId}`), {
    invalidate: [['offering']],
    success: 'Assignment deleted.',
    onSuccess: () => navigate(`/courses/${query.data?.course_offering_id}/classwork`),
    toastError: true,
  })
  useTitle(query.data?.title ?? 'Assignment')

  if (query.isPending) return <Loading />
  if (query.isError) {
    if (query.error instanceof ApiError && (query.error.status === 403 || query.error.status === 404)) return <Alert>This assignment is not available to you. It may not be published yet. <Link to="/courses">Back to courses</Link></Alert>
    return <ErrorState error={query.error} onRetry={() => void query.refetch()} />
  }
  const a = query.data
  const course = a.offering.course

  return (
    <>
      <p className="crumbs">
        <Link to={`/courses/${a.course_offering_id}/classwork`}>
          {course?.code} {course?.title}
        </Link>{' '}
        / Classwork
      </p>
      <PageHeader
        title={a.title}
        subtitle={
          <>
            Due {formatDateTime(a.due_at)} · Out of {a.max_score} {isPast(a.due_at) && <Badge>Closed</Badge>}
          </>
        }
        actions={
          a.abilities.manage && (
            <>
              <PublishedBadge published={a.published} />
              <Button onClick={() => setEditing(true)}>Edit</Button>
              <Button
                variant="danger"
                onClick={async () => {
                  if (await confirm({ title: 'Delete this assignment?', message: 'Assignments that already have submissions cannot be deleted; unpublish them instead.', confirmLabel: 'Delete', danger: true })) remove.mutate()
                }}
              >
                Delete
              </Button>
            </>
          )
        }
      />
      {a.instructions && (
        <Card title="Instructions">
          <div className="reading pre">{a.instructions}</div>
        </Card>
      )}
      <Card
        title="Grading rubric"
        actions={
          a.abilities.manage && (
            <Button small onClick={() => setEditingRubric(true)}>
              {rubric.data && rubric.data.length > 0 ? 'Edit rubric' : 'Add a rubric'}
            </Button>
          )
        }
      >
        <QueryView query={rubric} isEmpty={(r) => r.length === 0} empty={<p className="muted">{a.abilities.manage ? 'No rubric. Grades are a single mark out of ' + a.max_score + '.' : 'This assignment is marked with a single score.'}</p>}>
          {(criteria) => <RubricView criteria={criteria} />}
        </QueryView>
      </Card>

      {a.abilities.grade || a.abilities.manage ? <StaffSection assignment={a} rubric={rubric.data ?? []} /> : <StudentSection assignment={a} />}

      {editing && <AssignmentDialog offeringId={a.course_offering_id} modules={a.offering.modules ?? []} assignment={a} onClose={() => setEditing(false)} />}
      {editingRubric && <RubricEditor assignmentId={a.id} maxScore={a.max_score} criteria={rubric.data ?? []} onClose={() => setEditingRubric(false)} />}
    </>
  )
}

// ---- student ---------------------------------------------------------------------------------------------------
function StudentSection({ assignment }: { assignment: AssignmentDetail }) {
  const mine = useQuery({
    queryKey: ['my-grade', assignment.id],
    queryFn: () => api.get<MyGrade>(`/assignments/${assignment.id}/my-grade`),
    retry: false,
  })
  const notSubmitted = mine.error instanceof ApiError && mine.error.status === 404

  if (mine.isPending) return <Loading />
  if (mine.isError && !notSubmitted) return <ErrorState error={mine.error} onRetry={() => void mine.refetch()} />
  if (notSubmitted) return assignment.abilities.submit ? <SubmitForm assignment={assignment} /> : <Card title="Your submission"><Alert tone="warn">{isPast(assignment.due_at) ? 'The deadline has passed and no work was submitted.' : 'You cannot submit to this assignment right now.'}</Alert></Card>
  return <SubmittedView assignment={assignment} mine={mine.data!} />
}

function SubmitForm({ assignment, resubmission, onDone }: { assignment: AssignmentDetail; resubmission?: boolean; onDone?: () => void }) {
  const [body, setBody] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [lateExplanation, setLateExplanation] = useState('')
  const confirm = useConfirm()
  const late = isPast(assignment.due_at)
  const submit = useApiMutation(
    () => {
      const form = new FormData()
      if (body.trim()) form.append('body', body)
      if (file) form.append('file', file)
      if (late) form.append('late_explanation', lateExplanation.trim())
      return api.upload(`/assignments/${assignment.id}/submissions`, form)
    },
    { invalidate: [['my-grade', assignment.id]], success: resubmission ? 'Resubmitted.' : 'Submitted. Good luck!', onSuccess: onDone },
  )
  return (
    <Card title={resubmission ? 'Update your submission' : 'Your submission'}>
      <p className="muted">{resubmission ? 'This replaces your last submission; your teacher can still see what you had before.' : 'Write your answer, attach a file, or both. You can submit once, so check it before you send.'}</p>
      {late && <Alert tone="warn">The deadline has passed. Your teacher allows late submissions, but you must explain why.</Alert>}
      <form
        onSubmit={async (event) => {
          event.preventDefault()
          if (await confirm({ title: resubmission ? 'Replace your submission?' : 'Submit your work?', message: resubmission ? 'Your previous answer will be kept in its version history.' : 'You cannot change a submission after sending it.', confirmLabel: resubmission ? 'Resubmit' : 'Submit' })) submit.mutate()
        }}
      >
        <TextArea label="Your answer" optional rows={8} value={body} onChange={(e) => setBody(e.target.value)} error={fieldError(submit.error, 'body')} />
        <FileField label="Attach a file" optional onChange={(e) => setFile(e.target.files?.[0] ?? null)} error={fieldError(submit.error, 'file')} hint="Up to 25 MB. Documents, slides, spreadsheets, images, ZIP files and similar are accepted." />
        {late && <TextArea label="Why is this late?" rows={3} value={lateExplanation} onChange={(e) => setLateExplanation(e.target.value)} error={fieldError(submit.error, 'late_explanation')} required />}
        {submit.error && !fieldError(submit.error, 'body') && !fieldError(submit.error, 'file') && !fieldError(submit.error, 'late_explanation') && <FormError error={submit.error} />}
        <div className="form-actions">
          {resubmission && <Button onClick={onDone}>Cancel</Button>}
          <Button type="submit" variant="primary" loading={submit.isPending} disabled={(!body.trim() && !file) || (late && !lateExplanation.trim())}>
            {resubmission ? 'Resubmit' : 'Submit'}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function SubmittedView({ assignment, mine }: { assignment: AssignmentDetail; mine: MyGrade }) {
  const { submission, grade } = mine
  const [resubmitting, setResubmitting] = useState(false)
  const appeals = useQuery({ queryKey: ['appeals', 'mine'], queryFn: () => api.get<Page<Appeal>>('/my-appeals'), enabled: !!grade })
  const appeal = appeals.data?.data.find((x) => x.submission_id === submission.id)
  const canResubmit = assignment.allow_resubmission && !grade

  return (
    <>
      <Card title="Your submission">
        <p className="muted small">
          Submitted {formatDateTime(submission.submitted_at)} {submission.late && <Badge tone="warn">Late</Badge>} {submission.version > 1 && <Badge tone="info">Version {submission.version}</Badge>}
        </p>
        {submission.late && submission.late_explanation && (
          <p className="muted small">
            Your explanation: <em>{submission.late_explanation}</em>
          </p>
        )}
        {submission.body && <div className="reading pre">{submission.body}</div>}
        {submission.storage_path && (
          <p>
            <DownloadButton path={`/submissions/${submission.id}/download`} filename={`submission-${submission.id}`}>
              Download your file
            </DownloadButton>
          </p>
        )}
        {submission.version > 1 && <VersionsSection submissionId={submission.id} />}
      </Card>
      <FeedbackSection submissionId={submission.id} canWrite={false} />
      {canResubmit &&
        (resubmitting ? <SubmitForm assignment={assignment} resubmission onDone={() => setResubmitting(false)} /> : (
          <p>
            <Button onClick={() => setResubmitting(true)}>Update your submission</Button>
          </p>
        ))}
      <Card title="Your grade">
        {!grade ? (
          <EmptyState title="Not graded yet">You will be notified when your grade is published.</EmptyState>
        ) : (
          <>
            <p className="big-score">
              {formatScore(grade.score)} <span className="muted">/ {assignment.max_score}</span>
            </p>
            <p className="muted small">Published {formatDateTime(grade.created_at)}</p>
            {grade.criteria_scores && grade.criteria_scores.length > 0 && (
              <Table caption="Marks by criterion">
                <thead>
                  <tr>
                    <th>Criterion</th>
                    <th>Level</th>
                    <th>Points</th>
                    <th>Comment</th>
                  </tr>
                </thead>
                <tbody>
                  {grade.criteria_scores.map((m) => (
                    <tr key={m.criterion_id}>
                      <td>{m.title}</td>
                      <td>{m.level_title ?? '—'}</td>
                      <td>
                        {formatScore(m.points)} / {formatScore(m.max_points)}
                      </td>
                      <td>{m.comment}</td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
            {grade.feedback && (
              <>
                <h3>Feedback</h3>
                <div className="reading pre">{grade.feedback}</div>
              </>
            )}
          </>
        )}
      </Card>
      {grade && <AppealSection submission={submission} appeal={appeal} loading={appeals.isPending} />}
    </>
  )
}

/** Feedback a grader leaves on a submission before it's formally scored. Read-only for the student, writable for graders. */
function FeedbackSection({ submissionId, canWrite }: { submissionId: number; canWrite: boolean }) {
  const [body, setBody] = useState('')
  const feedback = useQuery({ queryKey: ['submission-feedback', submissionId], queryFn: () => api.get<SubmissionFeedback[]>(`/submissions/${submissionId}/feedback`) })
  const send = useApiMutation(() => api.post<SubmissionFeedback>(`/submissions/${submissionId}/feedback`, { body: body.trim() }), { invalidate: [['submission-feedback', submissionId]], onSuccess: () => setBody('') })

  if (feedback.isPending || feedback.isError) return null
  if (!canWrite && feedback.data.length === 0) return null

  return (
    <Card title="Feedback">
      {feedback.data.length === 0 && <p className="muted small">No feedback yet.</p>}
      {feedback.data.map((f) => (
        <div key={f.id}>
          <p className="muted small">
            {f.author.name} on version {f.version} · {formatDateTime(f.created_at)}
          </p>
          <div className="reading pre">{f.body}</div>
        </div>
      ))}
      {canWrite && (
        <form
          className="inline-form"
          onSubmit={(event) => {
            event.preventDefault()
            send.mutate()
          }}
        >
          <TextArea label="Leave feedback on the current draft" rows={3} value={body} onChange={(e) => setBody(e.target.value)} error={fieldError(send.error, 'body')} />
          {send.error && !fieldError(send.error, 'body') && <FormError error={send.error} />}
          <Button type="submit" variant="primary" loading={send.isPending} disabled={!body.trim()}>
            Send feedback
          </Button>
        </form>
      )}
    </Card>
  )
}

/** Earlier drafts, kept whenever a resubmission replaces them. */
function VersionsSection({ submissionId }: { submissionId: number }) {
  const [open, setOpen] = useState(false)
  const versions = useQuery({ queryKey: ['submission-versions', submissionId], queryFn: () => api.get<SubmissionVersion[]>(`/submissions/${submissionId}/versions`), enabled: open })

  return (
    <>
      <p>
        <Button small onClick={() => setOpen((o) => !o)}>
          {open ? 'Hide previous versions' : 'View previous versions'}
        </Button>
      </p>
      {open && versions.isPending && <p className="muted small">Loading…</p>}
      {open &&
        versions.data?.map((v) => (
          <div key={v.version} className="reading">
            <p className="muted small">
              Version {v.version} · submitted {formatDateTime(v.submitted_at)}
              {v.storage_path && ' · had a file attached'}
            </p>
            {v.body && <div className="reading pre">{v.body}</div>}
          </div>
        ))}
    </>
  )
}

function AppealSection({ submission, appeal, loading }: { submission: Submission; appeal?: Appeal; loading: boolean }) {
  const [reason, setReason] = useState('')
  const [open, setOpen] = useState(false)
  const confirm = useConfirm()
  const file = useApiMutation(() => api.post(`/submissions/${submission.id}/appeal`, { reason: reason.trim() }), { invalidate: [['appeals']], success: 'Your appeal has been sent to your teacher.', onSuccess: () => setOpen(false) })
  const withdraw = useApiMutation(() => api.delete(`/appeals/${appeal!.id}`), { invalidate: [['appeals']], success: 'Appeal withdrawn.', toastError: true })

  if (loading) return null
  if (appeal) {
    return (
      <Card title="Your grade appeal">
        <p>
          Status:{' '}
          <Badge tone={appeal.status === 'open' ? 'warn' : appeal.status === 'upheld' ? 'good' : 'neutral'}>{appeal.status === 'open' ? 'Waiting for a decision' : appeal.status === 'upheld' ? 'Upheld: grade revised' : 'Reviewed: grade stands'}</Badge>
        </p>
        <p>
          <Link to={`/appeals/${appeal.id}`}>Read the appeal{appeal.status !== 'open' ? ' and the response' : ''}</Link>
        </p>
        {appeal.status === 'open' && (
          <Button
            variant="danger"
            loading={withdraw.isPending}
            onClick={async () => {
              if (await confirm({ title: 'Withdraw your appeal?', message: 'You can file it again while the appeal window is still open.', confirmLabel: 'Withdraw appeal', danger: true })) withdraw.mutate()
            }}
          >
            Withdraw appeal
          </Button>
        )}
      </Card>
    )
  }
  return (
    <Card title="Disagree with your grade?">
      <p className="muted">You can appeal a published grade once, within the appeal window. Explain what you think was missed.</p>
      <Button onClick={() => setOpen(true)}>Appeal this grade</Button>
      {open && (
        <Modal title="Appeal your grade" onClose={() => setOpen(false)}>
          <form
            onSubmit={(event) => {
              event.preventDefault()
              file.mutate()
            }}
          >
            <TextArea label="Why should the grade be reviewed?" rows={6} value={reason} onChange={(e) => setReason(e.target.value)} hint={`At least 20 characters (${reason.trim().length} so far).`} error={fieldError(file.error, 'reason')} required />
            {file.error && !fieldError(file.error, 'reason') && <FormError error={file.error} />}
            <div className="form-actions">
              <Button onClick={() => setOpen(false)}>Cancel</Button>
              <Button type="submit" variant="primary" loading={file.isPending} disabled={reason.trim().length < 20}>
                Send appeal
              </Button>
            </div>
          </form>
        </Modal>
      )}
    </Card>
  )
}

// ---- staff -----------------------------------------------------------------------------------------------------
function StaffSection({ assignment, rubric }: { assignment: AssignmentDetail; rubric: RubricCriterion[] }) {
  const [page, setPage] = useState(1)
  const [grading, setGrading] = useState<Submission | null>(null)
  const [givingFeedback, setGivingFeedback] = useState<Submission | null>(null)
  const [similarity, setSimilarity] = useState(false)
  const canGrade = assignment.abilities.grade
  const query = useQuery({ queryKey: ['submissions', assignment.id, page], queryFn: () => api.get<Page<Submission & { user?: { id: number; name: string; email: string } }>>(`/assignments/${assignment.id}/submissions`, { page }), enabled: canGrade, placeholderData: (previous) => previous })

  return (
    <>
      <Card
        title="Submissions"
        actions={
          canGrade && (
            <>
              <Link className="btn btn-secondary btn-small" to={`/courses/${assignment.course_offering_id}/appeals`}>
                Grade appeals
              </Link>
              <Button small onClick={() => setSimilarity(true)}>
                Check similarity
              </Button>
            </>
          )
        }
      >
        {!canGrade && <Alert tone="info">You can edit this assignment, but grading is done by the course’s lecturers and teaching assistants.</Alert>}
        {canGrade && (
          <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No submissions yet">Submissions appear here as students send them in.</EmptyState>}>
            {(data) => (
              <>
                <Table caption="Submissions">
                  <thead>
                    <tr>
                      <th>Student</th>
                      <th>Submitted</th>
                      <th>Grade</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {data.data.map((s) => {
                      const latest = latestRecord(s)
                      const published = recordsOf(s).some((r) => r.status === 'published')
                      return (
                        <tr key={s.id}>
                          <td>
                            {s.user?.name ?? `Student #${s.user_id}`}
                            <span className="muted small block">{s.user?.email}</span>
                          </td>
                          <td>
                            {formatDateTime(s.submitted_at)} {s.late && <Badge tone="bad">Late</Badge>} {s.version > 1 && <Badge tone="info">Version {s.version}</Badge>}
                            {s.late && s.late_explanation && <span className="muted small block">{s.late_explanation}</span>}
                          </td>
                          <td>
                            {latest ? (
                              <>
                                {formatScore(latest.score)} / {assignment.max_score} <Badge tone={latest.status === 'published' ? 'good' : 'warn'}>{latest.status}</Badge>
                                {!published && <span className="muted small block">Not visible to the student</span>}
                              </>
                            ) : (
                              <Badge>Not graded</Badge>
                            )}
                          </td>
                          <td className="actions">
                            {assignment.allow_resubmission && !published && (
                              <Button small onClick={() => setGivingFeedback(s)}>
                                Feedback
                              </Button>
                            )}
                            <Button small variant="primary" onClick={() => setGrading(s)}>
                              {latest ? 'Regrade' : 'Grade'}
                            </Button>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </Table>
                <Pager {...pagerFromPage(data)} onPage={setPage} />
              </>
            )}
          </QueryView>
        )}
      </Card>
      {grading && <GradeDialog assignment={assignment} submission={grading} rubric={rubric} studentName={(grading as Submission & { user?: { name: string } }).user?.name ?? `Student #${grading.user_id}`} onClose={() => setGrading(null)} />}
      {givingFeedback && (
        <Modal title={`Feedback: ${(givingFeedback as Submission & { user?: { name: string } }).user?.name ?? `Student #${givingFeedback.user_id}`}`} onClose={() => setGivingFeedback(null)}>
          <FeedbackSection submissionId={givingFeedback.id} canWrite />
        </Modal>
      )}
      {similarity && <SimilarityDialog assignmentId={assignment.id} onClose={() => setSimilarity(false)} />}
    </>
  )
}

function SimilarityDialog({ assignmentId, onClose }: { assignmentId: number; onClose: () => void }) {
  const [threshold, setThreshold] = useState('0.5')
  const run = useApiMutation(() => api.get<SimilarityReport>(`/assignments/${assignmentId}/similarity`, { threshold }))
  const report = run.data
  return (
    <Modal title="Similarity check" onClose={onClose} wide>
      <Alert tone="info">This compares the class’s written answers and plain-text files with each other, using overlapping five-word phrases. It is a reason to read two submissions side by side, not proof of copying.</Alert>
      <form
        className="inline-form"
        onSubmit={(event) => {
          event.preventDefault()
          run.mutate()
        }}
      >
        <TextField label="Flag pairs at or above" type="number" step="0.05" min={0.1} max={1} value={threshold} onChange={(e) => setThreshold(e.target.value)} error={fieldError(run.error, 'threshold')} hint="1 means identical, 0.5 means half of the phrases are shared." />
        <Button type="submit" variant="primary" loading={run.isPending}>
          Run the check
        </Button>
      </form>
      {run.error && !fieldError(run.error, 'threshold') && <FormError error={run.error} />}
      {report && (
        <>
          <p>
            Compared {plural(report.compared, 'submission')}. {report.skipped_too_short_or_unreadable > 0 && `${plural(report.skipped_too_short_or_unreadable, 'submission')} skipped (under 20 words, or a file type that cannot be read).`}
          </p>
          {report.pairs.length === 0 ? (
            <EmptyState title="No similar pairs found">Nothing reached the threshold you chose.</EmptyState>
          ) : (
            <Table caption="Similar pairs">
              <thead>
                <tr>
                  <th>Similarity</th>
                  <th>Student A</th>
                  <th>Student B</th>
                </tr>
              </thead>
              <tbody>
                {report.pairs.map((p) => (
                  <tr key={`${p.a.submission_id}-${p.b.submission_id}`}>
                    <td>
                      <strong>{Math.round(p.similarity * 100)}%</strong>
                    </td>
                    <td>{p.a.user.name}</td>
                    <td>{p.b.user.name}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
          <p className="muted small">{report.note}</p>
        </>
      )}
    </Modal>
  )
}
