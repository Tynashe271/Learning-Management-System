import { useState } from 'react'
import { api } from '../../api/client'
import type { Assignment, GradeRecord, RubricCriterion, Submission } from '../../api/types'
import { Alert, Badge, Button, CheckField, FileField, FormError, Modal, SelectField, TextArea, TextField } from '../../components/ui'
import { formatDateTime, formatScore } from '../../lib/format'
import { DownloadButton, fieldError, useApiMutation } from '../../lib/hooks'

export const recordsOf = (s?: Submission | null): GradeRecord[] => s?.grade_records ?? s?.gradeRecords ?? []

/** Marks shown to staff: the latest record of any status, and the latest published one. */
export function latestRecord(s?: Submission | null): GradeRecord | undefined {
  return [...recordsOf(s)].sort((a, b) => b.id - a.id)[0]
}

interface Mark {
  levelId: string
  points: string
  comment: string
}

/**
 * The grading form. Without a rubric it takes one mark; with a rubric it takes a mark per criterion (a level, or points),
 * and the total is worked out from them. Grades are never overwritten: each save adds a record, and changing an existing
 * grade needs a reason.
 */
export function GradeDialog({ assignment, submission, rubric, studentName, onClose }: { assignment: Assignment; submission: Submission; rubric: RubricCriterion[]; studentName: string; onClose: () => void }) {
  const history = [...recordsOf(submission)].sort((a, b) => b.id - a.id)
  const previous = history[0]
  const hasHistory = history.length > 0

  const [score, setScore] = useState(previous ? String(Number(previous.score)) : '')
  const [feedback, setFeedback] = useState(previous?.feedback ?? '')
  const [status, setStatus] = useState<'draft' | 'published'>('published')
  const [reason, setReason] = useState('')
  const [recording, setRecording] = useState<File | null>(null)
  const [marks, setMarks] = useState<Record<number, Mark>>(() =>
    Object.fromEntries(
      rubric.map((c) => {
        const prior = previous?.criteria_scores?.find((m) => m.criterion_id === c.id)
        return [c.id, { levelId: prior?.level_id ? String(prior.level_id) : '', points: prior && !prior.level_id ? String(prior.points) : '', comment: prior?.comment ?? '' }]
      }),
    ) as Record<number, Mark>,
  )

  const setMark = (id: number, changes: Partial<Mark>) => setMarks((m) => ({ ...m, [id]: { ...m[id], ...changes } }))
  const pointsFor = (c: RubricCriterion): number | null => {
    const m = marks[c.id!]
    if (m.levelId) return c.levels.find((l) => String(l.id) === m.levelId)?.points ?? null
    return m.points === '' ? null : Number(m.points)
  }
  const total = rubric.reduce((sum, c) => sum + (pointsFor(c) ?? 0), 0)
  const complete = rubric.every((c) => pointsFor(c) !== null)

  const save = useApiMutation(
    () => {
      const body: Record<string, unknown> = { status, feedback: feedback.trim() || null }
      if (hasHistory) body.change_reason = reason.trim()
      if (rubric.length === 0) body.score = Number(score)
      else {
        body.criteria = rubric.map((c) => {
          const m = marks[c.id!]
          return { criterion_id: c.id, ...(m.levelId ? { level_id: Number(m.levelId) } : { points: Number(m.points) }), comment: m.comment.trim() || null }
        })
      }
      if (!recording) return api.post(`/submissions/${submission.id}/grades`, body)
      const form = new FormData()
      form.append('status', status)
      if (feedback.trim()) form.append('feedback', feedback.trim())
      if (hasHistory) form.append('change_reason', reason.trim())
      if (rubric.length === 0) form.append('score', String(Number(score)))
      else
        (body.criteria as Record<string, unknown>[]).forEach((c, i) => {
          Object.entries(c).forEach(([key, value]) => {
            if (value !== null && value !== undefined) form.append(`criteria[${i}][${key}]`, String(value))
          })
        })
      form.append('feedback_recording', recording)
      return api.upload(`/submissions/${submission.id}/grades`, form)
    },
    {
      invalidate: [['submissions', assignment.id], ['appeals'], ['grades', assignment.course_offering_id], ['my-grade', assignment.id]],
      success: status === 'published' ? 'Grade published. The student has been notified.' : 'Draft saved.',
      onSuccess: onClose,
    },
  )

  const valid = (rubric.length === 0 ? score !== '' && Number(score) >= 0 && Number(score) <= assignment.max_score : complete) && (!hasHistory || reason.trim() !== '')
  const criteriaErrors = fieldError(save.error, 'criteria')

  return (
    <Modal title={`Grade: ${studentName}`} onClose={onClose} wide>
      <section className="submission-view">
        <h3>Submission</h3>
        <p className="muted small">Submitted {formatDateTime(submission.submitted_at)}</p>
        {submission.body && <div className="reading pre">{submission.body}</div>}
        {submission.storage_path && (
          <p>
            <DownloadButton path={`/submissions/${submission.id}/download`} filename={`submission-${submission.id}`}>
              Download the attached file
            </DownloadButton>
          </p>
        )}
        {!submission.body && !submission.storage_path && <p className="muted">Nothing was attached.</p>}
      </section>

      {history.length > 0 && (
        <details className="history">
          <summary>Earlier marks ({history.length})</summary>
          <ul className="list">
            {history.map((h) => (
              <li key={h.id}>
                <strong>
                  {formatScore(h.score)} / {assignment.max_score}
                </strong>{' '}
                <Badge tone={h.status === 'published' ? 'good' : 'warn'}>{h.status}</Badge>
                <span className="muted small block">
                  {formatDateTime(h.created_at)}
                  {h.change_reason ? ` · Reason: ${h.change_reason}` : ''}
                </span>
                {h.feedback_recording_path && (
                  <DownloadButton path={`/grades/${h.id}/recording`} filename={`feedback-${h.id}`}>
                    Play/download voice or video feedback
                  </DownloadButton>
                )}
              </li>
            ))}
          </ul>
        </details>
      )}

      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {rubric.length === 0 ? (
          <TextField label={`Score (out of ${assignment.max_score})`} type="number" step="0.01" min={0} max={assignment.max_score} value={score} onChange={(e) => setScore(e.target.value)} error={fieldError(save.error, 'score')} required />
        ) : (
          <>
            {rubric.map((c) => {
              const m = marks[c.id!]
              return (
                <fieldset key={c.id} className="rubric-edit">
                  <legend>
                    {c.title} <span className="muted">(up to {formatScore(c.max_points)})</span>
                  </legend>
                  {c.description && <p className="muted small">{c.description}</p>}
                  {c.levels.length > 0 && (
                    <SelectField label="Level" value={m.levelId} onChange={(e) => setMark(c.id!, { levelId: e.target.value, points: '' })}>
                      <option value="">Enter points instead</option>
                      {c.levels.map((l) => (
                        <option key={l.id} value={l.id}>
                          {l.title} ({formatScore(l.points)} points){l.description ? ` – ${l.description}` : ''}
                        </option>
                      ))}
                    </SelectField>
                  )}
                  {!m.levelId && <TextField label="Points" type="number" step="0.01" min={0} max={c.max_points} value={m.points} onChange={(e) => setMark(c.id!, { points: e.target.value })} required />}
                  <TextField label="Comment" optional value={m.comment} onChange={(e) => setMark(c.id!, { comment: e.target.value })} maxLength={2000} />
                </fieldset>
              )
            })}
            <p>
              <strong>
                Total: {formatScore(total)} / {assignment.max_score}
              </strong>
            </p>
            {criteriaErrors && <Alert>{criteriaErrors}</Alert>}
          </>
        )}
        <TextArea label="Feedback for the student" optional rows={4} value={feedback} onChange={(e) => setFeedback(e.target.value)} error={fieldError(save.error, 'feedback')} />
        <FileField label="Voice or video feedback" optional onChange={(e) => setRecording(e.target.files?.[0] ?? null)} error={fieldError(save.error, 'feedback_recording')} hint="An audio or video file (mp3, mp4, wav, m4a, webm or ogg), up to 25 MB." />
        {hasHistory && <TextArea label="Reason for changing the grade" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldError(save.error, 'change_reason')} hint="Recorded in the audit log and shown with the earlier marks." required />}
        <CheckField label="Publish now" hint="Untick to save a draft that only staff can see. Publishing notifies the student." checked={status === 'published'} onChange={(e) => setStatus(e.target.checked ? 'published' : 'draft')} />
        {save.error && !['score', 'feedback', 'feedback_recording', 'change_reason', 'criteria'].some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!valid}>
            {status === 'published' ? 'Publish grade' : 'Save draft'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
