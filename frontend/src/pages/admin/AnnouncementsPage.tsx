import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { Page, RoleName, SystemAnnouncement } from '../../api/types'
import { ROLES, ROLE_LABELS } from '../../api/types'
import { Badge, Button, Card, CheckField, EmptyState, FormError, Modal, PageHeader, Pager, QueryView, SelectField, Table, TextArea, TextField, pagerFromPage, useConfirm } from '../../components/ui'
import { formatDateTime, fromLocalInput, toLocalInput } from '../../lib/format'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'

const SEVERITY = { info: { tone: 'info', label: 'Information' }, warning: { tone: 'warn', label: 'Warning' }, critical: { tone: 'bad', label: 'Urgent' } } as const

function state(a: SystemAnnouncement): { tone: 'good' | 'neutral' | 'warn'; label: string } {
  const now = Date.now()
  if (a.starts_at && new Date(a.starts_at).getTime() > now) return { tone: 'warn', label: 'Scheduled' }
  if (a.ends_at && new Date(a.ends_at).getTime() < now) return { tone: 'neutral', label: 'Ended' }
  return { tone: 'good', label: 'Showing now' }
}

/** Notices for the whole institution, shown at the top of every screen while they are current. */
export function AnnouncementsPage() {
  useTitle('Notices to everyone')
  const [page, setPage] = useState(1)
  const [dialog, setDialog] = useState<SystemAnnouncement | 'new' | null>(null)
  const confirm = useConfirm()
  const query = useQuery({ queryKey: ['system-announcements', 'all', page], queryFn: () => api.get<Page<SystemAnnouncement>>('/system-announcements', { page }), placeholderData: (previous) => previous })
  const remove = useApiMutation((id: number) => api.delete(`/system-announcements/${id}`), { invalidate: [['system-announcements']], success: 'Notice removed.', toastError: true })

  return (
    <>
      <PageHeader
        title="Notices to everyone"
        subtitle="“Registration closes on Friday”, “The system will be down on Saturday night”. Shown across the top of every screen while current."
        actions={
          <Button variant="primary" onClick={() => setDialog('new')}>
            New notice
          </Button>
        }
      />
      <Card>
        <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No notices yet">Post one to reach every student and staff member at once.</EmptyState>}>
          {(data) => (
            <>
              <Table caption="Notices">
                <thead>
                  <tr>
                    <th>Notice</th>
                    <th>For</th>
                    <th>Shows</th>
                    <th>State</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((a) => {
                    const s = state(a)
                    return (
                      <tr key={a.id}>
                        <td>
                          <Badge tone={SEVERITY[a.severity].tone}>{SEVERITY[a.severity].label}</Badge> <strong>{a.title}</strong>
                          <span className="muted small block">{a.body.length > 140 ? `${a.body.slice(0, 137)}…` : a.body}</span>
                        </td>
                        <td>{a.audience?.length ? a.audience.map((r) => ROLE_LABELS[r] ?? r).join(', ') : 'Everyone'}</td>
                        <td className="small">
                          {a.starts_at ? `from ${formatDateTime(a.starts_at)}` : 'now'}
                          <span className="block">{a.ends_at ? `until ${formatDateTime(a.ends_at)}` : 'until removed'}</span>
                        </td>
                        <td>
                          <Badge tone={s.tone}>{s.label}</Badge>
                        </td>
                        <td className="actions">
                          <Button small onClick={() => setDialog(a)}>
                            Edit
                          </Button>
                          <Button
                            small
                            variant="ghost"
                            onClick={async () => {
                              if (await confirm({ title: 'Remove this notice?', message: 'It stops showing straight away. Notifications already sent stay in people’s inboxes.', confirmLabel: 'Remove', danger: true })) remove.mutate(a.id)
                            }}
                          >
                            Remove
                          </Button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </Table>
              <Pager {...pagerFromPage(data)} onPage={setPage} />
            </>
          )}
        </QueryView>
      </Card>
      {dialog && <NoticeDialog notice={dialog === 'new' ? null : dialog} onClose={() => setDialog(null)} />}
    </>
  )
}

function NoticeDialog({ notice, onClose }: { notice: SystemAnnouncement | null; onClose: () => void }) {
  const [title, setTitle] = useState(notice?.title ?? '')
  const [body, setBody] = useState(notice?.body ?? '')
  const [severity, setSeverity] = useState<SystemAnnouncement['severity']>(notice?.severity ?? 'info')
  const [audience, setAudience] = useState<RoleName[]>(notice?.audience ?? [])
  const [starts, setStarts] = useState(toLocalInput(notice?.starts_at))
  const [ends, setEnds] = useState(toLocalInput(notice?.ends_at))
  const [notify, setNotify] = useState(false)

  const save = useApiMutation(
    () => {
      const data = { title: title.trim(), body: body.trim(), severity, audience: audience.length ? audience : null, starts_at: starts ? fromLocalInput(starts) : null, ends_at: ends ? fromLocalInput(ends) : null }
      return notice ? api.patch(`/system-announcements/${notice.id}`, data) : api.post('/system-announcements', { ...data, notify })
    },
    { invalidate: [['system-announcements']], success: notice ? 'Notice saved.' : notify ? 'Notice posted, and people are being notified.' : 'Notice posted.', onSuccess: onClose },
  )
  const fields = ['title', 'body', 'severity', 'audience', 'starts_at', 'ends_at']
  return (
    <Modal title={notice ? 'Edit notice' : 'New notice'} onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Headline" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <TextArea label="Message" value={body} onChange={(e) => setBody(e.target.value)} error={fieldError(save.error, 'body')} maxLength={5000} required />
        <SelectField label="Importance" value={severity} onChange={(e) => setSeverity(e.target.value as SystemAnnouncement['severity'])} error={fieldError(save.error, 'severity')} hint="Urgent notices cannot be dismissed by the reader.">
          {Object.entries(SEVERITY).map(([key, v]) => (
            <option key={key} value={key}>
              {v.label}
            </option>
          ))}
        </SelectField>
        <fieldset className="checklist">
          <legend>Who should see it</legend>
          <div className="hint">Leave all unticked to show it to everyone.</div>
          <div className="checks">
            {ROLES.map((r) => (
              <label key={r} className="check-chip">
                <input type="checkbox" checked={audience.includes(r)} onChange={(e) => setAudience((a) => (e.target.checked ? [...a, r] : a.filter((x) => x !== r)))} />
                {ROLE_LABELS[r]}
              </label>
            ))}
          </div>
        </fieldset>
        <div className="row">
          <TextField label="Start showing" optional type="datetime-local" value={starts} onChange={(e) => setStarts(e.target.value)} error={fieldError(save.error, 'starts_at')} hint="Blank means straight away." />
          <TextField label="Stop showing" optional type="datetime-local" value={ends} onChange={(e) => setEnds(e.target.value)} error={fieldError(save.error, 'ends_at')} hint="Blank means until you remove it." />
        </div>
        {!notice && <CheckField label="Also put it in everyone's notifications" hint="Sent in the background to each person it is for. Only do this for things people must not miss." checked={notify} onChange={(e) => setNotify(e.target.checked)} />}
        {save.error && !fields.some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim() || !body.trim()}>
            {notice ? 'Save' : 'Post notice'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
