import { useState } from 'react'
import { api } from '../../api/client'
import type { Assignment, Module } from '../../api/types'
import { Button, CheckField, FileField, FormError, Modal, SelectField, TextArea, TextField } from '../../components/ui'
import { fromLocalInput, toLocalInput } from '../../lib/format'
import { DownloadButton, bool, fieldError, useApiMutation } from '../../lib/hooks'
import { TargetPicker } from './TargetPicker'

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
  const [weight, setWeight] = useState(assignment?.weight !== null && assignment?.weight !== undefined ? String(assignment.weight) : '')
  const [published, setPublished] = useState(assignment?.published ?? false)
  const [allowLate, setAllowLate] = useState(assignment?.allow_late_submissions ?? false)
  const [allowResubmission, setAllowResubmission] = useState(assignment?.allow_resubmission ?? false)
  const [isGroup, setIsGroup] = useState(assignment?.is_group_assignment ?? false)
  const [topicId, setTopicId] = useState(String(assignment ? (assignment.course_module_id ?? '') : (defaultModuleId ?? '')))
  const [reason, setReason] = useState('')
  const [targetMode, setTargetMode] = useState<'everyone' | 'specific'>((assignment?.target_user_ids?.length ?? 0) > 0 ? 'specific' : 'everyone')
  const [targetIds, setTargetIds] = useState<number[]>(assignment?.target_user_ids ?? [])
  const [file, setFile] = useState<File | null>(null)

  const dueChanged = editing && dueAt !== toLocalInput(assignment.due_at)
  const save = useApiMutation(
    () => {
      const targetUserIds = targetMode === 'specific' ? targetIds : []
      if (!editing) {
        const form = new FormData()
        form.append('title', title.trim())
        if (instructions) form.append('instructions', instructions)
        form.append('due_at', fromLocalInput(dueAt))
        form.append('max_score', maxScore)
        if (weight) form.append('weight', weight)
        form.append('published', bool(published))
        form.append('allow_late_submissions', bool(allowLate))
        form.append('allow_resubmission', bool(allowResubmission))
        form.append('is_group_assignment', bool(isGroup))
        if (topicId) form.append('course_module_id', topicId)
        targetUserIds.forEach((id) => form.append('target_user_ids[]', String(id)))
        if (file) form.append('file', file)
        return api.upload(`/offerings/${offeringId}/assignments`, form)
      }
      const changes: Record<string, unknown> = { title: title.trim(), instructions: instructions || null, weight: weight ? Number(weight) : null, published, allow_late_submissions: allowLate, allow_resubmission: allowResubmission, course_module_id: topicId ? Number(topicId) : null, target_user_ids: targetUserIds }
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
        {!editing && <FileField label="Question paper" optional hint="A file students can download once this is published, such as the exam or assignment brief." onChange={(e) => setFile(e.target.files?.[0] ?? null)} error={fieldError(save.error, 'file')} />}
        {editing && assignment.storage_path && (
          <div className="setting">
            <span className="muted small">Question paper</span>
            <DownloadButton path={`/assignments/${assignment.id}/file`} filename="question-paper">
              Download
            </DownloadButton>
          </div>
        )}
        <TextField
          label="Weight toward the final grade (%)"
          optional
          type="number"
          min={0}
          max={100}
          step="0.01"
          value={weight}
          onChange={(e) => setWeight(e.target.value)}
          error={fieldError(save.error, 'weight')}
          hint="Leave empty to leave this out of the gradebook's weighted total."
        />
        {dueChanged && <TextArea label="Reason for changing the deadline" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldError(save.error, 'change_reason')} hint="Recorded in the audit log." required />}
        <TargetPicker offeringId={offeringId} mode={targetMode} ids={targetIds} onModeChange={setTargetMode} onIdsChange={setTargetIds} />
        <CheckField label="Published" hint="Students can see and submit to published assignments." checked={published} onChange={(e) => setPublished(e.target.checked)} />
        <CheckField label="Allow late submissions" hint="Students may still submit after the deadline, but must explain why." checked={allowLate} onChange={(e) => setAllowLate(e.target.checked)} />
        <CheckField label="Allow resubmission" hint="Students can submit a new draft to replace their last one, and you can leave feedback before it's graded. Locked once a grade is published." checked={allowResubmission} onChange={(e) => setAllowResubmission(e.target.checked)} />
        {!editing && <CheckField label="Group assignment" hint="Students submit as a team you place into groups after creating this assignment. One member's submission and grade apply to the whole group." checked={isGroup} onChange={(e) => setIsGroup(e.target.checked)} />}
        {save.error && !['title', 'instructions', 'due_at', 'max_score', 'weight', 'change_reason', 'course_module_id', 'file'].some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
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
