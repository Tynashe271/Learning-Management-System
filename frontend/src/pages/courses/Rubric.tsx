import { useState } from 'react'
import { api, ApiError } from '../../api/client'
import type { RubricCriterion } from '../../api/types'
import { Alert, Button, FormError, Modal, Table, TextArea, TextField, useConfirm } from '../../components/ui'
import { formatScore } from '../../lib/format'
import { useApiMutation } from '../../lib/hooks'

/** Read-only view of a rubric, as students and graders see it. */
export function RubricView({ criteria }: { criteria: RubricCriterion[] }) {
  return (
    <div className="rubric">
      {criteria.map((c) => (
        <div key={c.id} className="rubric-criterion">
          <div className="rubric-head">
            <strong>{c.title}</strong>
            <span className="muted">{formatScore(c.max_points)} points</span>
          </div>
          {c.description && <p className="muted">{c.description}</p>}
          {c.levels.length > 0 && (
            <Table caption={`Levels for ${c.title}`}>
              <thead>
                <tr>
                  <th>Level</th>
                  <th>What it looks like</th>
                  <th>Points</th>
                </tr>
              </thead>
              <tbody>
                {c.levels.map((l) => (
                  <tr key={l.id}>
                    <td>{l.title}</td>
                    <td>{l.description}</td>
                    <td>{formatScore(l.points)}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </div>
      ))}
    </div>
  )
}

interface DraftLevel {
  title: string
  description: string
  points: string
}
interface DraftCriterion {
  title: string
  description: string
  max_points: string
  levels: DraftLevel[]
}

const blankCriterion = (): DraftCriterion => ({ title: '', description: '', max_points: '10', levels: [] })

function toDraft(criteria: RubricCriterion[]): DraftCriterion[] {
  if (criteria.length === 0) return [blankCriterion()]
  return criteria.map((c) => ({
    title: c.title,
    description: c.description ?? '',
    max_points: String(c.max_points),
    levels: c.levels.map((l) => ({ title: l.title, description: l.description ?? '', points: String(l.points) })),
  }))
}

export function RubricEditor({ assignmentId, maxScore, criteria, onClose }: { assignmentId: number; maxScore: number; criteria: RubricCriterion[]; onClose: () => void }) {
  const confirm = useConfirm()
  const [draft, setDraft] = useState<DraftCriterion[]>(() => toDraft(criteria))
  const total = draft.reduce((sum, c) => sum + (Number(c.max_points) || 0), 0)
  const balanced = total === maxScore

  const update = (index: number, changes: Partial<DraftCriterion>) => setDraft((rows) => rows.map((row, i) => (i === index ? { ...row, ...changes } : row)))
  const updateLevel = (ci: number, li: number, changes: Partial<DraftLevel>) =>
    setDraft((rows) => rows.map((row, i) => (i === ci ? { ...row, levels: row.levels.map((level, j) => (j === li ? { ...level, ...changes } : level)) } : row)))

  const save = useApiMutation(
    () =>
      api.put(`/assignments/${assignmentId}/rubric`, {
        criteria: draft.map((c) => ({
          title: c.title.trim(),
          description: c.description.trim() || null,
          max_points: Number(c.max_points),
          ...(c.levels.length ? { levels: c.levels.map((l) => ({ title: l.title.trim(), description: l.description.trim() || null, points: Number(l.points) })) } : {}),
        })),
      }),
    { invalidate: [['rubric', assignmentId]], success: 'Rubric saved.', onSuccess: onClose },
  )
  const remove = useApiMutation(() => api.delete(`/assignments/${assignmentId}/rubric`), { invalidate: [['rubric', assignmentId]], success: 'Rubric removed.', onSuccess: onClose })

  const problems = save.error instanceof ApiError ? Object.entries(save.error.errors).flatMap(([field, messages]) => messages.map((m) => `${field.startsWith('criteria.') ? `Criterion ${Number(field.split('.')[1]) + 1}: ` : ''}${m}`)) : []
  const valid = draft.every((c) => c.title.trim() && Number(c.max_points) >= 1 && c.levels.every((l) => l.title.trim() && l.points !== '' && Number(l.points) >= 0 && Number(l.points) <= Number(c.max_points))) && balanced

  return (
    <Modal title="Grading rubric" onClose={onClose} wide>
      <p className="muted">
        Break the mark into criteria. The points of all criteria must add up to the assignment’s {maxScore} points. Levels are optional: graders can pick one to award its points.
      </p>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {draft.map((c, ci) => (
          <fieldset key={ci} className="rubric-edit">
            <legend>Criterion {ci + 1}</legend>
            <div className="row">
              <TextField label="Title" value={c.title} onChange={(e) => update(ci, { title: e.target.value })} maxLength={255} required />
              <TextField label="Points" type="number" min={1} value={c.max_points} onChange={(e) => update(ci, { max_points: e.target.value })} required />
            </div>
            <TextArea label="Description" optional rows={2} value={c.description} onChange={(e) => update(ci, { description: e.target.value })} />
            {c.levels.map((l, li) => (
              <div key={li} className="level-edit">
                <TextField label="Level name" value={l.title} onChange={(e) => updateLevel(ci, li, { title: e.target.value })} placeholder="Excellent" required />
                <TextField label="Points" type="number" min={0} max={Number(c.max_points) || undefined} value={l.points} onChange={(e) => updateLevel(ci, li, { points: e.target.value })} required />
                <TextField label="Description" optional value={l.description} onChange={(e) => updateLevel(ci, li, { description: e.target.value })} />
                <Button small variant="ghost" onClick={() => update(ci, { levels: c.levels.filter((_, j) => j !== li) })}>
                  Remove level
                </Button>
              </div>
            ))}
            <div className="form-actions left">
              <Button small disabled={c.levels.length >= 10} onClick={() => update(ci, { levels: [...c.levels, { title: '', description: '', points: '' }] })}>
                Add level
              </Button>
              {draft.length > 1 && (
                <Button small variant="ghost" onClick={() => setDraft((rows) => rows.filter((_, i) => i !== ci))}>
                  Remove criterion
                </Button>
              )}
            </div>
          </fieldset>
        ))}
        <p>
          <Button disabled={draft.length >= 20} onClick={() => setDraft((rows) => [...rows, blankCriterion()])}>
            Add criterion
          </Button>
        </p>
        <Alert tone={balanced ? 'success' : 'warn'}>
          Criteria add up to {total} of {maxScore} points{balanced ? '.' : ' — they must match.'}
        </Alert>
        {problems.length > 0 && <Alert>{problems.join(' ')}</Alert>}
        {save.error && problems.length === 0 && <FormError error={save.error} />}
        {remove.error && <FormError error={remove.error} />}
        <div className="form-actions">
          {criteria.length > 0 && (
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={async () => {
                if (await confirm({ title: 'Remove the rubric?', message: 'Grading will go back to a single mark.', confirmLabel: 'Remove rubric', danger: true })) remove.mutate()
              }}
            >
              Remove rubric
            </Button>
          )}
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!valid}>
            Save rubric
          </Button>
        </div>
      </form>
    </Modal>
  )
}
