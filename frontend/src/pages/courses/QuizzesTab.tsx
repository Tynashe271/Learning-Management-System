import { useState } from 'react'
import { api } from '../../api/client'
import type { Module, Quiz } from '../../api/types'
import { Button, CheckField, FormError, Modal, SelectField, TextArea, TextField } from '../../components/ui'
import { fromLocalInput, toLocalInput } from '../../lib/format'
import { fieldError, useApiMutation } from '../../lib/hooks'
import { TargetPicker } from './TargetPicker'

export function QuizDialog({
  offeringId,
  modules = [],
  defaultModuleId = null,
  quiz,
  onClose,
}: {
  offeringId: number
  modules?: Module[]
  defaultModuleId?: number | null
  quiz: Quiz | null
  onClose: () => void
}) {
  const editing = quiz !== null
  const [title, setTitle] = useState(quiz?.title ?? '')
  const [instructions, setInstructions] = useState(quiz?.instructions ?? '')
  const [opensAt, setOpensAt] = useState(toLocalInput(quiz?.opens_at))
  const [dueAt, setDueAt] = useState(toLocalInput(quiz?.due_at))
  const [limit, setLimit] = useState(quiz?.time_limit_minutes ? String(quiz.time_limit_minutes) : '')
  const [attempts, setAttempts] = useState(String(quiz?.max_attempts ?? 1))
  const [published, setPublished] = useState(quiz?.published ?? false)
  const [isPractice, setIsPractice] = useState(quiz?.is_practice ?? false)
  const [questionsPerAttempt, setQuestionsPerAttempt] = useState(quiz?.questions_per_attempt ? String(quiz.questions_per_attempt) : '')
  const [openBook, setOpenBook] = useState(quiz?.is_open_book ?? false)
  const [topicId, setTopicId] = useState(String(quiz ? (quiz.course_module_id ?? '') : (defaultModuleId ?? '')))
  const [reason, setReason] = useState('')
  const [targetMode, setTargetMode] = useState<'everyone' | 'specific'>((quiz?.target_user_ids?.length ?? 0) > 0 ? 'specific' : 'everyone')
  const [targetIds, setTargetIds] = useState<number[]>(quiz?.target_user_ids ?? [])

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
        is_practice: isPractice,
        questions_per_attempt: questionsPerAttempt ? Number(questionsPerAttempt) : null,
        is_open_book: openBook,
        course_module_id: topicId ? Number(topicId) : null,
        target_user_ids: targetMode === 'specific' ? targetIds : [],
      }
      if (!editing || dueChanged) body.due_at = fromLocalInput(dueAt)
      if (dueChanged) body.change_reason = reason.trim()
      return editing ? api.patch(`/quizzes/${quiz.id}`, body) : api.post(`/offerings/${offeringId}/quizzes`, body)
    },
    { invalidate: [['offering', offeringId], ['quiz']], success: editing ? 'Quiz saved.' : 'Quiz created.', onSuccess: onClose },
  )
  const known = ['title', 'instructions', 'opens_at', 'due_at', 'time_limit_minutes', 'max_attempts', 'change_reason', 'course_module_id', 'is_practice', 'questions_per_attempt']

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
        {modules.length > 0 && (
          <SelectField label="Topic" optional value={topicId} onChange={(e) => setTopicId(e.target.value)} error={fieldError(save.error, 'course_module_id')} hint="Groups this quiz with a topic on the Classwork tab.">
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
          <TextField label="Opens" optional type="datetime-local" value={opensAt} onChange={(e) => setOpensAt(e.target.value)} error={fieldError(save.error, 'opens_at')} hint="Leave empty to open as soon as it is published." />
          <TextField label="Due" type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} error={fieldError(save.error, 'due_at')} hint="Must be in the future." required />
        </div>
        <div className="row">
          <TextField label="Time limit (minutes)" optional type="number" min={1} max={600} value={limit} onChange={(e) => setLimit(e.target.value)} error={fieldError(save.error, 'time_limit_minutes')} hint="Leave empty for no limit." />
          <TextField label="Attempts allowed" type="number" min={1} max={isPractice ? 999 : 20} value={attempts} onChange={(e) => setAttempts(e.target.value)} error={fieldError(save.error, 'max_attempts')} />
        </div>
        <TextField
          label="Questions per attempt"
          optional
          type="number"
          min={1}
          value={questionsPerAttempt}
          onChange={(e) => setQuestionsPerAttempt(e.target.value)}
          error={fieldError(save.error, 'questions_per_attempt')}
          hint="Leave empty to give every attempt all the questions. Otherwise each attempt draws this many at random from the full pool below, so students can see different questions."
        />
        {dueChanged && <TextArea label="Reason for changing the deadline" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldError(save.error, 'change_reason')} hint="Recorded in the audit log." required />}
        <TargetPicker offeringId={offeringId} mode={targetMode} ids={targetIds} onModeChange={setTargetMode} onIdsChange={setTargetIds} />
        <CheckField
          label="Practice quiz"
          hint="Ungraded and doesn't count toward marks. Raises the attempts limit up to 999, for retaking freely."
          checked={isPractice}
          onChange={(e) => setIsPractice(e.target.checked)}
        />
        <CheckField label="Open book" hint="Just a label shown to students — this cannot check what they have open elsewhere." checked={openBook} onChange={(e) => setOpenBook(e.target.checked)} />
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
