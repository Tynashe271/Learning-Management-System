import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { Assignment } from '../../api/types'
import { Badge, Button, Card, CheckField, EmptyState, FormError, Modal, PublishedBadge, Table, TextArea, TextField } from '../../components/ui'
import { formatDateTime, isPast } from '../../lib/format'
import { fromLocalInput, toLocalInput } from '../../lib/format'
import { fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

export function AssignmentsTab() {
  const { offering, id, manage } = useOffering()
  const [creating, setCreating] = useState(false)
  const assignments = [...(offering.assignments ?? [])].sort((a, b) => a.due_at.localeCompare(b.due_at))

  return (
    <>
      {manage && (
        <p>
          <Button variant="primary" onClick={() => setCreating(true)}>
            New assignment
          </Button>
        </p>
      )}
      <Card>
        {assignments.length === 0 ? (
          <EmptyState title="No assignments yet">{manage ? 'Create an assignment, then publish it so students can submit work.' : 'Assignments will appear here when your teacher publishes them.'}</EmptyState>
        ) : (
          <Table caption="Assignments">
            <thead>
              <tr>
                <th>Assignment</th>
                <th>Due</th>
                <th>Out of</th>
                {manage && <th>Status</th>}
              </tr>
            </thead>
            <tbody>
              {assignments.map((a) => (
                <tr key={a.id}>
                  <td>
                    <Link to={`/assignments/${a.id}`}>{a.title}</Link>
                  </td>
                  <td>
                    {formatDateTime(a.due_at)} {isPast(a.due_at) && <Badge tone="neutral">Closed</Badge>}
                  </td>
                  <td>{a.max_score}</td>
                  {manage && (
                    <td>
                      <PublishedBadge published={a.published} />
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      {creating && <AssignmentDialog offeringId={id} assignment={null} onClose={() => setCreating(false)} />}
    </>
  )
}

/** Creates an assignment, or edits one. Changing the deadline needs a reason, which the audit log keeps. */
export function AssignmentDialog({ offeringId, assignment, onClose }: { offeringId: number; assignment: Assignment | null; onClose: () => void }) {
  const editing = assignment !== null
  const [title, setTitle] = useState(assignment?.title ?? '')
  const [instructions, setInstructions] = useState(assignment?.instructions ?? '')
  const [dueAt, setDueAt] = useState(toLocalInput(assignment?.due_at))
  const [maxScore, setMaxScore] = useState(String(assignment?.max_score ?? 100))
  const [published, setPublished] = useState(assignment?.published ?? false)
  const [reason, setReason] = useState('')

  const dueChanged = editing && dueAt !== toLocalInput(assignment.due_at)
  const save = useApiMutation(
    () => {
      if (!editing) {
        return api.post(`/offerings/${offeringId}/assignments`, { title: title.trim(), instructions: instructions || null, due_at: fromLocalInput(dueAt), max_score: Number(maxScore), published })
      }
      const changes: Record<string, unknown> = { title: title.trim(), instructions: instructions || null, published }
      if (dueChanged) {
        changes.due_at = fromLocalInput(dueAt)
        changes.change_reason = reason.trim()
      }
      return api.patch(`/assignments/${assignment.id}`, changes)
    },
    { invalidate: [['offering', offeringId], ['assignment']], success: editing ? 'Assignment saved.' : 'Assignment created.', onSuccess: onClose },
  )

  return (
    <Modal title={editing ? 'Edit assignment' : 'New assignment'} onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <TextArea label="Instructions" optional rows={6} value={instructions} onChange={(e) => setInstructions(e.target.value)} error={fieldError(save.error, 'instructions')} />
        <div className="row">
          <TextField label="Due" type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} error={fieldError(save.error, 'due_at')} hint="In your local time. Must be in the future." required />
          {!editing && <TextField label="Maximum score" type="number" min={1} value={maxScore} onChange={(e) => setMaxScore(e.target.value)} error={fieldError(save.error, 'max_score')} required />}
        </div>
        {dueChanged && <TextArea label="Reason for changing the deadline" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldError(save.error, 'change_reason')} hint="Recorded in the audit log." required />}
        <CheckField label="Published" hint="Students can see and submit to published assignments." checked={published} onChange={(e) => setPublished(e.target.checked)} />
        {save.error && !['title', 'instructions', 'due_at', 'max_score', 'change_reason'].some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
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
