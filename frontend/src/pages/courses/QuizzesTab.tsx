import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { Quiz } from '../../api/types'
import { Badge, Button, Card, CheckField, EmptyState, FormError, Modal, PublishedBadge, Table, TextArea, TextField } from '../../components/ui'
import { formatDateTime, fromLocalInput, isPast, toLocalInput } from '../../lib/format'
import { fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

export function QuizzesTab() {
  const { offering, id, manage } = useOffering()
  const [creating, setCreating] = useState(false)
  const quizzes = [...(offering.quizzes ?? [])].sort((a, b) => a.due_at.localeCompare(b.due_at))

  return (
    <>
      {manage && (
        <p>
          <Button variant="primary" onClick={() => setCreating(true)}>
            New quiz
          </Button>
        </p>
      )}
      <Card>
        {quizzes.length === 0 ? (
          <EmptyState title="No quizzes yet">{manage ? 'Create a quiz, add questions, then publish it.' : 'Quizzes will appear here when your teacher publishes them.'}</EmptyState>
        ) : (
          <Table caption="Quizzes">
            <thead>
              <tr>
                <th>Quiz</th>
                <th>Opens</th>
                <th>Due</th>
                <th>Time limit</th>
                <th>Attempts</th>
                <th>Questions</th>
                {manage && <th>Status</th>}
              </tr>
            </thead>
            <tbody>
              {quizzes.map((q) => (
                <tr key={q.id}>
                  <td>
                    <Link to={`/quizzes/${q.id}`}>{q.title}</Link>
                  </td>
                  <td>{q.opens_at ? formatDateTime(q.opens_at) : 'Open now'}</td>
                  <td>
                    {formatDateTime(q.due_at)} {isPast(q.due_at) && <Badge>Closed</Badge>}
                  </td>
                  <td>{q.time_limit_minutes ? `${q.time_limit_minutes} min` : 'None'}</td>
                  <td>{q.max_attempts}</td>
                  <td>{q.questions_count ?? 0}</td>
                  {manage && (
                    <td>
                      <PublishedBadge published={!!q.published} />
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      {creating && <QuizDialog offeringId={id} quiz={null} onClose={() => setCreating(false)} />}
    </>
  )
}

export function QuizDialog({ offeringId, quiz, onClose }: { offeringId: number; quiz: Quiz | null; onClose: () => void }) {
  const editing = quiz !== null
  const [title, setTitle] = useState(quiz?.title ?? '')
  const [instructions, setInstructions] = useState(quiz?.instructions ?? '')
  const [opensAt, setOpensAt] = useState(toLocalInput(quiz?.opens_at))
  const [dueAt, setDueAt] = useState(toLocalInput(quiz?.due_at))
  const [limit, setLimit] = useState(quiz?.time_limit_minutes ? String(quiz.time_limit_minutes) : '')
  const [attempts, setAttempts] = useState(String(quiz?.max_attempts ?? 1))
  const [published, setPublished] = useState(quiz?.published ?? false)
  const [reason, setReason] = useState('')

  const dueChanged = editing && dueAt !== toLocalInput(quiz.due_at)
  const save = useApiMutation(
    () => {
      const body: Record<string, unknown> = {
        title: title.trim(),
        instructions: instructions || null,
        opens_at: opensAt ? fromLocalInput(opensAt) : null,
        time_limit_minutes: limit ? Number(limit) : null,
        max_attempts: Number(attempts) || 1,
        published,
      }
      if (!editing || dueChanged) body.due_at = fromLocalInput(dueAt)
      if (dueChanged) body.change_reason = reason.trim()
      return editing ? api.patch(`/quizzes/${quiz.id}`, body) : api.post(`/offerings/${offeringId}/quizzes`, body)
    },
    { invalidate: [['offering', offeringId], ['quiz']], success: editing ? 'Quiz saved.' : 'Quiz created.', onSuccess: onClose },
  )
  const known = ['title', 'instructions', 'opens_at', 'due_at', 'time_limit_minutes', 'max_attempts', 'change_reason']

  return (
    <Modal title={editing ? 'Edit quiz' : 'New quiz'} onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <TextArea label="Instructions" optional rows={4} value={instructions} onChange={(e) => setInstructions(e.target.value)} error={fieldError(save.error, 'instructions')} />
        <div className="row">
          <TextField label="Opens" optional type="datetime-local" value={opensAt} onChange={(e) => setOpensAt(e.target.value)} error={fieldError(save.error, 'opens_at')} hint="Leave empty to open as soon as it is published." />
          <TextField label="Due" type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} error={fieldError(save.error, 'due_at')} hint="Must be in the future." required />
        </div>
        <div className="row">
          <TextField label="Time limit (minutes)" optional type="number" min={1} max={600} value={limit} onChange={(e) => setLimit(e.target.value)} error={fieldError(save.error, 'time_limit_minutes')} hint="Leave empty for no limit." />
          <TextField label="Attempts allowed" type="number" min={1} max={20} value={attempts} onChange={(e) => setAttempts(e.target.value)} error={fieldError(save.error, 'max_attempts')} />
        </div>
        {dueChanged && <TextArea label="Reason for changing the deadline" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldError(save.error, 'change_reason')} hint="Recorded in the audit log." required />}
        <CheckField label="Published" hint="Students can take published quizzes while they are open." checked={published} onChange={(e) => setPublished(e.target.checked)} />
        {save.error && !known.some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim() || !dueAt || (dueChanged && !reason.trim())}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}
