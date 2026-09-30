import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { Project, ProjectMeeting, ProjectMilestone, Roster } from '../../api/types'
import { Badge, Button, Card, EmptyState, FormError, Modal, QueryView, SelectField, Table, TextArea, TextField } from '../../components/ui'
import { fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

const STATUS_TONE: Record<Project['status'], 'neutral' | 'warn' | 'good' | 'bad'> = { proposed: 'warn', approved: 'good', rejected: 'bad' }

export function ProjectsTab() {
  const { id, manage } = useOffering()
  const [proposing, setProposing] = useState(false)
  const [opened, setOpened] = useState<Project | null>(null)

  if (!manage) {
    return (
      <>
        <MyProject offeringId={id} onPropose={() => setProposing(true)} onOpen={setOpened} />
        {proposing && <ProposeDialog offeringId={id} onClose={() => setProposing(false)} />}
        {opened && <ProjectDialog project={opened} onClose={() => setOpened(null)} />}
      </>
    )
  }

  return (
    <>
      <ProjectsTable offeringId={id} onOpen={setOpened} />
      {opened && <ProjectDialog project={opened} onClose={() => setOpened(null)} />}
    </>
  )
}

function ProjectsTable({ offeringId, onOpen }: { offeringId: number; onOpen: (p: Project) => void }) {
  const query = useQuery({ queryKey: ['projects', offeringId], queryFn: () => api.get<Project[]>(`/offerings/${offeringId}/projects`) })

  return (
    <Card>
      <QueryView query={query} isEmpty={(p) => p.length === 0} empty={<EmptyState title="No project topics yet">Students propose their own topics from this tab.</EmptyState>}>
        {(projects) => (
          <Table caption="Projects">
            <thead>
              <tr>
                <th>Student</th>
                <th>Title</th>
                <th>Status</th>
                <th>Supervisor</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {projects.map((p) => (
                <tr key={p.id}>
                  <td>{p.student?.name ?? `Student #${p.user_id}`}</td>
                  <td>{p.title}</td>
                  <td>
                    <Badge tone={STATUS_TONE[p.status]}>{p.status}</Badge>
                  </td>
                  <td>{p.supervisor?.name ?? <span className="muted">Unassigned</span>}</td>
                  <td className="actions">
                    <Button small variant="primary" onClick={() => onOpen(p)}>
                      Open
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

function MyProject({ offeringId, onPropose, onOpen }: { offeringId: number; onPropose: () => void; onOpen: (p: Project) => void }) {
  const query = useQuery({ queryKey: ['projects', offeringId, 'mine'], queryFn: () => api.get<Project | null>(`/offerings/${offeringId}/projects/mine`) })

  if (query.isPending) return null
  if (!query.data) {
    return (
      <Card>
        <EmptyState title="No project topic yet">
          Propose a topic to get started.
          <p>
            <Button variant="primary" onClick={onPropose}>
              Propose a topic
            </Button>
          </p>
        </EmptyState>
      </Card>
    )
  }
  const p = query.data
  return (
    <Card title={p.title}>
      <p>
        <Badge tone={STATUS_TONE[p.status]}>{p.status}</Badge> {p.supervisor && <>· Supervisor: {p.supervisor.name}</>}
      </p>
      {p.description && <div className="reading pre">{p.description}</div>}
      <Button variant="primary" onClick={() => onOpen(p)}>
        Milestones and meetings
      </Button>
    </Card>
  )
}

function ProposeDialog({ offeringId, onClose }: { offeringId: number; onClose: () => void }) {
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const save = useApiMutation(() => api.post(`/offerings/${offeringId}/projects`, { title: title.trim(), description: description.trim() || null }), {
    invalidate: [['projects', offeringId, 'mine']],
    success: 'Topic proposed.',
    onSuccess: onClose,
  })

  return (
    <Modal title="Propose a project topic" onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <TextArea label="Description" optional rows={4} value={description} onChange={(e) => setDescription(e.target.value)} error={fieldError(save.error, 'description')} />
        {save.error && !fieldError(save.error, 'title') && !fieldError(save.error, 'description') && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim()}>
            Submit
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** Shared by student and manager: milestones and meeting records for one project. Managers also get approval/supervisor controls. */
function ProjectDialog({ project, onClose }: { project: Project; onClose: () => void }) {
  const { manage } = useOffering()
  const milestones = useQuery({ queryKey: ['project-milestones', project.id], queryFn: () => api.get<ProjectMilestone[]>(`/projects/${project.id}/milestones`) })
  const meetings = useQuery({ queryKey: ['project-meetings', project.id], queryFn: () => api.get<ProjectMeeting[]>(`/projects/${project.id}/meetings`) })

  return (
    <Modal title={project.title} onClose={onClose} wide>
      {manage && <ManageProject project={project} />}
      <MilestonesSection project={project} milestones={milestones.data} manage={manage} />
      <MeetingsSection project={project} meetings={meetings.data} />
      <div className="form-actions">
        <Button onClick={onClose}>Close</Button>
      </div>
    </Modal>
  )
}

function ManageProject({ project }: { project: Project }) {
  const roster = useQuery({ queryKey: ['roster', project.course_offering_id, 1], queryFn: () => api.get<Roster>(`/offerings/${project.course_offering_id}/roster`) })
  const teachers = roster.data?.teachers ?? []
  const [supervisorId, setSupervisorId] = useState(project.supervisor_id ? String(project.supervisor_id) : '')
  const save = useApiMutation((status: Project['status']) => api.patch(`/projects/${project.id}`, { status, supervisor_id: supervisorId ? Number(supervisorId) : null }), {
    invalidate: [['projects']],
    success: 'Saved.',
    toastError: true,
  })

  return (
    <Card title="Review">
      {project.description && <div className="reading pre">{project.description}</div>}
      <SelectField label="Supervisor" optional value={supervisorId} onChange={(e) => setSupervisorId(e.target.value)}>
        <option value="">Unassigned</option>
        {teachers.map((t) => (
          <option key={t.user_id} value={t.user_id}>
            {t.user?.name}
          </option>
        ))}
      </SelectField>
      <div className="form-actions">
        <Button loading={save.isPending && save.variables === 'rejected'} onClick={() => save.mutate('rejected')}>
          Reject
        </Button>
        <Button variant="primary" loading={save.isPending && save.variables === 'approved'} onClick={() => save.mutate('approved')}>
          Approve
        </Button>
      </div>
    </Card>
  )
}

function MilestonesSection({ project, milestones, manage }: { project: Project; milestones?: ProjectMilestone[]; manage: boolean }) {
  const [title, setTitle] = useState('')
  const [dueOn, setDueOn] = useState('')
  const add = useApiMutation(() => api.post(`/projects/${project.id}/milestones`, { title: title.trim(), due_on: dueOn }), {
    invalidate: [['project-milestones', project.id]],
    onSuccess: () => {
      setTitle('')
      setDueOn('')
    },
  })
  const toggle = useApiMutation((m: ProjectMilestone) => api.patch(`/project-milestones/${m.id}`, { completed: !m.completed_at }), { invalidate: [['project-milestones', project.id]], toastError: true })
  const isSupervisor = manage

  return (
    <Card title="Milestones">
      {!milestones?.length && <p className="muted small">No milestones yet.</p>}
      {!!milestones?.length && (
        <ul className="list">
          {milestones.map((m) => (
            <li key={m.id}>
              <span className="grow">
                {m.title} <span className="muted small">due {m.due_on}</span>
              </span>
              {m.completed_at ? <Badge tone="good">Done</Badge> : <Badge tone="warn">Pending</Badge>}
              {isSupervisor && (
                <Button small loading={toggle.isPending && toggle.variables?.id === m.id} onClick={() => toggle.mutate(m)}>
                  {m.completed_at ? 'Reopen' : 'Mark done'}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
      {isSupervisor && (
        <form
          className="inline-form"
          onSubmit={(event) => {
            event.preventDefault()
            add.mutate()
          }}
        >
          <TextField label="New milestone" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(add.error, 'title')} />
          <TextField label="Due" type="date" value={dueOn} onChange={(e) => setDueOn(e.target.value)} error={fieldError(add.error, 'due_on')} />
          <Button type="submit" loading={add.isPending} disabled={!title.trim() || !dueOn}>
            Add
          </Button>
        </form>
      )}
    </Card>
  )
}

function MeetingsSection({ project, meetings }: { project: Project; meetings?: ProjectMeeting[] }) {
  const [occurredOn, setOccurredOn] = useState(new Date().toISOString().slice(0, 10))
  const [notes, setNotes] = useState('')
  const log = useApiMutation(() => api.post(`/projects/${project.id}/meetings`, { occurred_on: occurredOn, notes: notes.trim() }), {
    invalidate: [['project-meetings', project.id]],
    onSuccess: () => setNotes(''),
  })

  return (
    <Card title="Meeting records">
      {!meetings?.length && <p className="muted small">No meetings logged yet.</p>}
      {meetings?.map((m) => (
        <div key={m.id}>
          <p className="muted small">
            {m.occurred_on} · {m.user?.name}
          </p>
          <p>{m.notes}</p>
        </div>
      ))}
      <form
        className="inline-form"
        onSubmit={(event) => {
          event.preventDefault()
          log.mutate()
        }}
      >
        <TextField label="Date" type="date" value={occurredOn} onChange={(e) => setOccurredOn(e.target.value)} error={fieldError(log.error, 'occurred_on')} max={new Date().toISOString().slice(0, 10)} />
        <TextArea label="Notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} error={fieldError(log.error, 'notes')} />
        <Button type="submit" loading={log.isPending} disabled={!notes.trim()}>
          Log meeting
        </Button>
      </form>
    </Card>
  )
}
