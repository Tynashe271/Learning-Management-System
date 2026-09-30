import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { Competency, CompetencyStatusValue, LogbookEntry } from '../../api/types'
import { Badge, Button, Card, EmptyState, FileField, FormError, Modal, QueryView, Table, TextArea, TextField, useConfirm } from '../../components/ui'
import { formatDateTime } from '../../lib/format'
import { DownloadButton, fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

const STATUS_TONE: Record<CompetencyStatusValue, 'neutral' | 'warn' | 'good'> = { not_started: 'neutral', developing: 'warn', competent: 'good' }
const STATUS_LABEL: Record<CompetencyStatusValue, string> = { not_started: 'Not started', developing: 'Developing', competent: 'Competent' }

export function SkillsTab() {
  const { id, manage } = useOffering()
  const [editing, setEditing] = useState<Competency | 'new' | null>(null)
  const [logging, setLogging] = useState<Competency | null>(null)
  const [reviewing, setReviewing] = useState<Competency | null>(null)
  const query = useQuery({ queryKey: ['competencies', id], queryFn: () => api.get<Competency[]>(`/offerings/${id}/competencies`) })

  return (
    <>
      {manage && (
        <p>
          <Button variant="primary" onClick={() => setEditing('new')}>
            Add a competency
          </Button>
        </p>
      )}
      <Card>
        <QueryView query={query} isEmpty={(c) => c.length === 0} empty={<EmptyState title="No competencies yet">{manage ? 'Add the practical skills this course tracks.' : 'Practical skills tracked for this course will appear here.'}</EmptyState>}>
          {(competencies) => (
            <Table caption="Competencies">
              <thead>
                <tr>
                  <th>Competency</th>
                  {!manage && <th>Your status</th>}
                  {!manage && <th>Hours logged</th>}
                  <th />
                </tr>
              </thead>
              <tbody>
                {competencies.map((c) => (
                  <tr key={c.id}>
                    <td>
                      <strong>{c.title}</strong>
                      {c.description && <span className="muted small block">{c.description}</span>}
                    </td>
                    {!manage && (
                      <td>
                        <Badge tone={STATUS_TONE[c.my_status ?? 'not_started']}>{STATUS_LABEL[c.my_status ?? 'not_started']}</Badge>
                      </td>
                    )}
                    {!manage && <td>{c.my_hours ?? 0}</td>}
                    <td className="actions">
                      {manage ? (
                        <>
                          <Button small onClick={() => setReviewing(c)}>
                            Review logbook
                          </Button>
                          <Button small onClick={() => setEditing(c)}>
                            Edit
                          </Button>
                        </>
                      ) : (
                        <Button small variant="primary" onClick={() => setLogging(c)}>
                          Log activity
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </QueryView>
      </Card>
      {editing && <CompetencyDialog offeringId={id} competency={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      {logging && <MyLogbookDialog competency={logging} onClose={() => setLogging(null)} />}
      {reviewing && <ReviewLogbookDialog competency={reviewing} onClose={() => setReviewing(null)} />}
    </>
  )
}

function CompetencyDialog({ offeringId, competency, onClose }: { offeringId: number; competency: Competency | null; onClose: () => void }) {
  const confirm = useConfirm()
  const editing = competency !== null
  const [title, setTitle] = useState(competency?.title ?? '')
  const [description, setDescription] = useState(competency?.description ?? '')

  const save = useApiMutation(
    () => {
      const body = { title: title.trim(), description: description.trim() || null }
      return editing ? api.patch(`/competencies/${competency.id}`, body) : api.post(`/offerings/${offeringId}/competencies`, body)
    },
    { invalidate: [['competencies', offeringId]], success: editing ? 'Competency saved.' : 'Competency added.', onSuccess: onClose },
  )
  const remove = useApiMutation(() => api.delete(`/competencies/${competency!.id}`), { invalidate: [['competencies', offeringId]], success: 'Competency deleted.', onSuccess: onClose, toastError: true })

  return (
    <Modal title={editing ? 'Edit competency' : 'Add a competency'} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <TextArea label="Description" optional rows={3} value={description} onChange={(e) => setDescription(e.target.value)} error={fieldError(save.error, 'description')} />
        {save.error && !fieldError(save.error, 'title') && !fieldError(save.error, 'description') && <FormError error={save.error} />}
        <div className="form-actions">
          {editing && (
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={async () => {
                if (await confirm({ title: 'Delete this competency?', message: 'Only possible before any student has logged activity toward it.', confirmLabel: 'Delete', danger: true })) remove.mutate()
              }}
            >
              Delete
            </Button>
          )}
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim()}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** A student's own logbook for one competency: past entries, and a form to log a new one. */
function MyLogbookDialog({ competency, onClose }: { competency: Competency; onClose: () => void }) {
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [hours, setHours] = useState('')
  const [description, setDescription] = useState('')
  const [evidence, setEvidence] = useState<File | null>(null)
  const entries = useQuery({ queryKey: ['logbook', competency.id, 'mine'], queryFn: () => api.get<LogbookEntry[]>(`/competencies/${competency.id}/logbook`) })
  const log = useApiMutation(
    () => {
      const form = new FormData()
      form.append('activity_date', date)
      form.append('hours', hours)
      form.append('description', description)
      if (evidence) form.append('evidence', evidence)
      return api.upload(`/competencies/${competency.id}/logbook`, form)
    },
    {
      invalidate: [
        ['logbook', competency.id, 'mine'],
        ['competencies'],
      ],
      success: 'Logged.',
      onSuccess: () => {
        setHours('')
        setDescription('')
        setEvidence(null)
      },
    },
  )

  return (
    <Modal title={`Logbook: ${competency.title}`} onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          log.mutate()
        }}
      >
        <div className="row">
          <TextField label="Date" type="date" value={date} onChange={(e) => setDate(e.target.value)} max={new Date().toISOString().slice(0, 10)} error={fieldError(log.error, 'activity_date')} required />
          <TextField label="Hours" type="number" min={0.25} max={24} step="0.25" value={hours} onChange={(e) => setHours(e.target.value)} error={fieldError(log.error, 'hours')} required />
        </div>
        <TextArea label="What did you do?" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} error={fieldError(log.error, 'description')} maxLength={2000} required />
        <FileField label="Evidence" optional onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} error={fieldError(log.error, 'evidence')} hint="A photo or video of the work, up to 25 MB." />
        {log.error && !['activity_date', 'hours', 'description', 'evidence'].some((f) => fieldError(log.error, f)) && <FormError error={log.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Close</Button>
          <Button type="submit" variant="primary" loading={log.isPending} disabled={!hours || !description.trim()}>
            Log activity
          </Button>
        </div>
      </form>
      <h3>Your entries</h3>
      {entries.isPending && <p className="muted">Loading…</p>}
      {entries.data && entries.data.length === 0 && <p className="muted small">No entries yet.</p>}
      {entries.data && entries.data.length > 0 && (
        <Table caption="Your entries">
          <thead>
            <tr>
              <th>Date</th>
              <th>Hours</th>
              <th>Description</th>
              <th>Reviewed</th>
            </tr>
          </thead>
          <tbody>
            {entries.data.map((e) => (
              <tr key={e.id}>
                <td>{e.activity_date}</td>
                <td>{e.hours}</td>
                <td>
                  {e.description}
                  {e.evidence_path && (
                    <div>
                      <DownloadButton path={`/logbook-entries/${e.id}/evidence`} filename={`evidence-${e.id}`}>
                        Evidence
                      </DownloadButton>
                    </div>
                  )}
                </td>
                <td>
                  {e.reviewed_at ? (
                    <>
                      <Badge tone="good">Reviewed {formatDateTime(e.reviewed_at)}</Badge>
                      {e.reviewer_comment && <span className="muted small block">{e.reviewer_comment}</span>}
                    </>
                  ) : (
                    <span className="muted">Not yet</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Modal>
  )
}

/** The teacher's/supervisor's view of every student's logbook for one competency, with a review action per entry. */
function ReviewLogbookDialog({ competency, onClose }: { competency: Competency; onClose: () => void }) {
  const entries = useQuery({ queryKey: ['logbook', competency.id, 'all'], queryFn: () => api.get<LogbookEntry[]>(`/competencies/${competency.id}/logbook`) })

  return (
    <Modal title={`Review logbook: ${competency.title}`} onClose={onClose} wide>
      {entries.isPending && <p className="muted">Loading…</p>}
      {entries.data && entries.data.length === 0 && <p className="muted small">No entries yet.</p>}
      {entries.data && entries.data.length > 0 && (
        <Table caption="Logbook entries">
          <thead>
            <tr>
              <th>Student</th>
              <th>Date</th>
              <th>Hours</th>
              <th>Description</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {entries.data.map((e) => (
              <tr key={e.id}>
                <td>{e.user?.name ?? `Student #${e.user_id}`}</td>
                <td>{e.activity_date}</td>
                <td>{e.hours}</td>
                <td>
                  {e.description}
                  {e.evidence_path && (
                    <div>
                      <DownloadButton path={`/logbook-entries/${e.id}/evidence`} filename={`evidence-${e.id}`}>
                        Evidence
                      </DownloadButton>
                    </div>
                  )}
                </td>
                <td className="actions">
                  <ReviewCell competencyId={competency.id} entry={e} />
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Modal>
  )
}

function ReviewCell({ competencyId, entry }: { competencyId: number; entry: LogbookEntry }) {
  const [comment, setComment] = useState(entry.reviewer_comment ?? '')
  const review = useApiMutation(
    (status: CompetencyStatusValue) => api.patch(`/logbook-entries/${entry.id}/review`, { status, reviewer_comment: comment.trim() || null }),
    { invalidate: [['logbook', competencyId, 'all'], ['competencies']], success: 'Reviewed.', toastError: true },
  )

  if (entry.reviewed_at) {
    return (
      <>
        <Badge tone="good">Reviewed {formatDateTime(entry.reviewed_at)}</Badge>
        {entry.reviewer_comment && <span className="muted small block">{entry.reviewer_comment}</span>}
      </>
    )
  }
  return (
    <div className="inline-form">
      <TextField label="Comment" optional value={comment} onChange={(e) => setComment(e.target.value)} />
      <Button small onClick={() => review.mutate('developing')} loading={review.isPending && review.variables === 'developing'}>
        Mark developing
      </Button>
      <Button small variant="primary" onClick={() => review.mutate('competent')} loading={review.isPending && review.variables === 'competent'}>
        Mark competent
      </Button>
    </div>
  )
}
