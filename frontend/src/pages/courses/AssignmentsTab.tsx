import { useState } from 'react'
import { api } from '../../api/client'
import type { Assignment, Module } from '../../api/types'
import { Button, CheckField, FormError, Modal, SelectField, TextArea, TextField } from '../../components/ui'
import { fromLocalInput, toLocalInput } from '../../lib/format'
import { fieldError, useApiMutation } from '../../lib/hooks'

/** Creates an assignment, or edits one. Changing the deadline needs a reason, which the audit log keeps. */
export function AssignmentDialog({
  offeringId,
  modules = [],
  defaultModuleId = null,
  assignment,
  onClose,
}: {
  offeringId: number
  modules?: Module[]
  defaultModuleId?: number | null
  assignment: Assignment | null
  onClose: () => void
}) {
  const editing = assignment !== null
  const [title, setTitle] = useState(assignment?.title ?? '')
  const [instructions, setInstructions] = useState(assignment?.instructions ?? '')
  const [dueAt, setDueAt] = useState(toLocalInput(assignment?.due_at))
  const [maxScore, setMaxScore] = useState(String(assignment?.max_score ?? 100))
  const [published, setPublished] = useState(assignment?.published ?? false)
  const [topicId, setTopicId] = useState(String(assignment ? (assignment.course_module_id ?? '') : (defaultModuleId ?? '')))
  const [reason, setReason] = useState('')

  const dueChanged = editing && dueAt !== toLocalInput(assignment.due_at)
  const save = useApiMutation(
    () => {
      if (!editing) {
        return api.post(`/offerings/${offeringId}/assignments`, {
          title: title.trim(),
          instructions: instructions || null,
          due_at: fromLocalInput(dueAt),
          max_score: Number(maxScore),
          published,
          course_module_id: topicId ? Number(topicId) : null,
        })
      }
      const changes: Record<string, unknown> = { title: title.trim(), instructions: instructions || null, published, course_module_id: topicId ? Number(topicId) : null }
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
        {modules.length > 0 && (
          <SelectField label="Topic" optional value={topicId} onChange={(e) => setTopicId(e.target.value)} error={fieldError(save.error, 'course_module_id')} hint="Groups this assignment with a topic on the Classwork tab.">
            <option value="">No topic</option>
            {[...modules]
              .sort((a, b) => a.position - b.position)
              .map((m) => (
                <option key={m.id} value={m.id}>
                  {m.title}
                </option>
              ))}
          </SelectField>
        )}
        <div className="row">
          <TextField label="Due" type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} error={fieldError(save.error, 'due_at')} hint="In your local time. Must be in the future." required />
          {!editing && <TextField label="Maximum score" type="number" min={1} value={maxScore} onChange={(e) => setMaxScore(e.target.value)} error={fieldError(save.error, 'max_score')} required />}
        </div>
        {dueChanged && <TextArea label="Reason for changing the deadline" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldError(save.error, 'change_reason')} hint="Recorded in the audit log." required />}
        <CheckField label="Published" hint="Students can see and submit to published assignments." checked={published} onChange={(e) => setPublished(e.target.checked)} />
        {save.error && !['title', 'instructions', 'due_at', 'max_score', 'change_reason', 'course_module_id'].some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
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
