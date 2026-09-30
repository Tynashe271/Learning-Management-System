import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { AttachmentLogbookEntry, AttachmentPlacement, Roster } from '../../api/types'
import { Badge, Button, Card, EmptyState, FileField, FormError, Modal, QueryView, SelectField, Table, TextArea, TextField } from '../../components/ui'
import { DownloadButton, fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

export function AttachmentTab() {
  const { id, manage } = useOffering()
  const [creating, setCreating] = useState(false)
  const [logging, setLogging] = useState<AttachmentPlacement | null>(null)

  if (!manage) {
    return (
      <>
        <MyPlacement offeringId={id} onOpenLogbook={setLogging} />
        {logging && <LogbookDialog placement={logging} manage={false} onClose={() => setLogging(null)} />}
      </>
    )
  }

  return (
    <>
      <p>
        <Button variant="primary" onClick={() => setCreating(true)}>
          Add a placement
        </Button>
      </p>
      <PlacementsTable offeringId={id} onOpenLogbook={setLogging} />
      {creating && <PlacementDialog offeringId={id} onClose={() => setCreating(false)} />}
      {logging && <LogbookDialog placement={logging} manage onClose={() => setLogging(null)} />}
    </>
  )
}

function PlacementsTable({ offeringId, onOpenLogbook }: { offeringId: number; onOpenLogbook: (p: AttachmentPlacement) => void }) {
  const query = useQuery({ queryKey: ['attachments', offeringId], queryFn: () => api.get<AttachmentPlacement[]>(`/offerings/${offeringId}/attachments`) })
  const request = useApiMutation((placementId: number) => api.post(`/attachment-placements/${placementId}/request-supervisor-feedback`), { invalidate: [['attachments', offeringId]], toastError: true })

  return (
    <Card>
      <QueryView query={query} isEmpty={(p) => p.length === 0} empty={<EmptyState title="No placements yet">Add a placement for each student on an attachment.</EmptyState>}>
        {(placements) => (
          <Table caption="Attachment placements">
            <thead>
              <tr>
                <th>Student</th>
                <th>Organisation</th>
                <th>Supervisor</th>
                <th>Dates</th>
                <th>Supervisor feedback</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {placements.map((p) => (
                <tr key={p.id}>
                  <td>{p.student?.name ?? `Student #${p.user_id}`}</td>
                  <td>{p.organisation}</td>
                  <td>
                    {p.supervisor_name}
                    <span className="muted small block">{p.supervisor_email}</span>
                  </td>
                  <td>
                    {p.starts_on} – {p.ends_on}
                  </td>
                  <td>
                    {p.supervisor_submitted_at ? (
                      <>
                        <Badge tone="good">{p.supervisor_rating} / 5</Badge>
                        {p.supervisor_comment && <span className="muted small block">{p.supervisor_comment}</span>}
                      </>
                    ) : (
                      <Badge>Not yet</Badge>
                    )}
                  </td>
                  <td className="actions">
                    <Button small onClick={() => onOpenLogbook(p)}>
                      Logbook
                    </Button>
                    <Button small loading={request.isPending && request.variables === p.id} onClick={() => request.mutate(p.id)}>
                      {p.supervisor_submitted_at ? 'Request again' : 'Request feedback'}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </QueryView>
    </Card>
  )
}

function PlacementDialog({ offeringId, onClose }: { offeringId: number; onClose: () => void }) {
  const roster = useQuery({ queryKey: ['roster', offeringId, 1], queryFn: () => api.get<Roster>(`/offerings/${offeringId}/roster`) })
  const students = (roster.data?.enrolments ?? []).filter((e) => e.status === 'active' && e.user)
  const [userId, setUserId] = useState('')
  const [organisation, setOrganisation] = useState('')
  const [supervisorName, setSupervisorName] = useState('')
  const [supervisorEmail, setSupervisorEmail] = useState('')
  const [objectives, setObjectives] = useState('')
  const [startsOn, setStartsOn] = useState('')
  const [endsOn, setEndsOn] = useState('')

  const save = useApiMutation(
    () =>
      api.post(`/offerings/${offeringId}/attachments`, {
        user_id: Number(userId),
        organisation: organisation.trim(),
        supervisor_name: supervisorName.trim(),
        supervisor_email: supervisorEmail.trim(),
        objectives: objectives.trim() || null,
        starts_on: startsOn,
        ends_on: endsOn,
      }),
    { invalidate: [['attachments', offeringId]], success: 'Placement added.', onSuccess: onClose },
  )
  const known = ['user_id', 'organisation', 'supervisor_name', 'supervisor_email', 'objectives', 'starts_on', 'ends_on']

  return (
    <Modal title="Add a placement" onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <SelectField label="Student" value={userId} onChange={(e) => setUserId(e.target.value)} error={fieldError(save.error, 'user_id')} required>
          <option value="" disabled>
            {roster.isLoading ? 'Loading…' : 'Choose a student'}
          </option>
          {students.map((e) => (
            <option key={e.user_id} value={e.user_id}>
              {e.user?.name}
            </option>
          ))}
        </SelectField>
        <TextField label="Organisation" value={organisation} onChange={(e) => setOrganisation(e.target.value)} error={fieldError(save.error, 'organisation')} maxLength={255} required />
        <div className="row">
          <TextField label="Supervisor name" value={supervisorName} onChange={(e) => setSupervisorName(e.target.value)} error={fieldError(save.error, 'supervisor_name')} maxLength={255} required />
          <TextField label="Supervisor email" type="email" value={supervisorEmail} onChange={(e) => setSupervisorEmail(e.target.value)} error={fieldError(save.error, 'supervisor_email')} required />
        </div>
        <TextArea label="Learning objectives" optional rows={3} value={objectives} onChange={(e) => setObjectives(e.target.value)} error={fieldError(save.error, 'objectives')} />
        <div className="row">
          <TextField label="Starts" type="date" value={startsOn} onChange={(e) => setStartsOn(e.target.value)} error={fieldError(save.error, 'starts_on')} required />
          <TextField label="Ends" type="date" value={endsOn} onChange={(e) => setEndsOn(e.target.value)} error={fieldError(save.error, 'ends_on')} required />
        </div>
        {save.error && !known.some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!userId || !organisation.trim() || !supervisorName.trim() || !supervisorEmail.trim() || !startsOn || !endsOn}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function MyPlacement({ offeringId, onOpenLogbook }: { offeringId: number; onOpenLogbook: (p: AttachmentPlacement) => void }) {
  const query = useQuery({ queryKey: ['attachments', offeringId, 'mine'], queryFn: () => api.get<AttachmentPlacement | null>(`/offerings/${offeringId}/attachments/mine`) })

  if (query.isPending) return null
  if (!query.data) return <Card><EmptyState title="No attachment set up">Your teacher will add your placement details here once it is arranged.</EmptyState></Card>
  const p = query.data

  return (
    <Card title={p.organisation}>
      <dl className="details">
        <dt>Supervisor</dt>
        <dd>
          {p.supervisor_name} ({p.supervisor_email})
        </dd>
        <dt>Dates</dt>
        <dd>
          {p.starts_on} – {p.ends_on}
        </dd>
        {p.objectives && (
          <>
            <dt>Objectives</dt>
            <dd>{p.objectives}</dd>
          </>
        )}
      </dl>
      <Button variant="primary" onClick={() => onOpenLogbook(p)}>
        Open your logbook
      </Button>
    </Card>
  )
}

function LogbookDialog({ placement, manage, onClose }: { placement: AttachmentPlacement; manage: boolean; onClose: () => void }) {
  const [weekEnding, setWeekEnding] = useState('')
  const [hours, setHours] = useState('')
  const [activities, setActivities] = useState('')
  const [evidence, setEvidence] = useState<File | null>(null)
  const entries = useQuery({ queryKey: ['attachment-logbook', placement.id], queryFn: () => api.get<AttachmentLogbookEntry[]>(`/attachment-placements/${placement.id}/logbook`) })
  const log = useApiMutation(
    () => {
      const form = new FormData()
      form.append('week_ending', weekEnding)
      form.append('hours', hours)
      form.append('activities', activities)
      if (evidence) form.append('evidence', evidence)
      return api.upload(`/attachment-placements/${placement.id}/logbook`, form)
    },
    {
      invalidate: [['attachment-logbook', placement.id]],
      success: 'Logged.',
      onSuccess: () => {
        setWeekEnding('')
        setHours('')
        setActivities('')
        setEvidence(null)
      },
    },
  )

  return (
    <Modal title={`Logbook: ${placement.organisation}`} onClose={onClose} wide>
      {!manage && (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            log.mutate()
          }}
        >
          <div className="row">
            <TextField label="Week ending" type="date" value={weekEnding} onChange={(e) => setWeekEnding(e.target.value)} error={fieldError(log.error, 'week_ending')} required />
            <TextField label="Hours worked" type="number" min={0.25} max={168} step="0.25" value={hours} onChange={(e) => setHours(e.target.value)} error={fieldError(log.error, 'hours')} required />
          </div>
          <TextArea label="Activities this week" rows={3} value={activities} onChange={(e) => setActivities(e.target.value)} error={fieldError(log.error, 'activities')} maxLength={4000} required />
          <FileField label="Evidence" optional onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} error={fieldError(log.error, 'evidence')} hint="A photo or video of the work, up to 25 MB." />
          {log.error && !['week_ending', 'hours', 'activities', 'evidence'].some((f) => fieldError(log.error, f)) && <FormError error={log.error} />}
          <div className="form-actions">
            <Button type="submit" variant="primary" loading={log.isPending} disabled={!weekEnding || !hours || !activities.trim()}>
              Log this week
            </Button>
          </div>
        </form>
      )}
      <h3>Entries</h3>
      {entries.isPending && <p className="muted">Loading…</p>}
      {entries.data && entries.data.length === 0 && <p className="muted small">No entries yet.</p>}
      {entries.data && entries.data.length > 0 && (
        <Table caption="Logbook entries">
          <thead>
            <tr>
              <th>Week ending</th>
              <th>Hours</th>
              <th>Activities</th>
            </tr>
          </thead>
          <tbody>
            {entries.data.map((e) => (
              <tr key={e.id}>
                <td>{e.week_ending}</td>
                <td>{e.hours}</td>
                <td>
                  {e.activities}
                  {e.evidence_path && (
                    <div>
                      <DownloadButton path={`/attachment-logbook-entries/${e.id}/evidence`} filename={`evidence-${e.id}`}>
                        Evidence
                      </DownloadButton>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      <div className="form-actions">
        <Button onClick={onClose}>Close</Button>
      </div>
    </Modal>
  )
}
