import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { AtRiskRow, InterventionPlan } from '../../api/types'
import { Badge, Button, Card, EmptyState, FormError, Modal, QueryView, Table, TextArea } from '../../components/ui'
import { formatDateTime } from '../../lib/format'
import { fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

/** The learning intervention centre: who shows signs of struggling, and a lecturer's record of reaching out. */
export function SupportTab() {
  const { id } = useOffering()
  const [opening, setOpening] = useState<AtRiskRow | null>(null)
  const query = useQuery({ queryKey: ['at-risk', id], queryFn: () => api.get<AtRiskRow[]>(`/offerings/${id}/at-risk`) })

  return (
    <>
      <Card>
        <QueryView query={query} isEmpty={(r) => r.length === 0} empty={<EmptyState title="No students enrolled" />}>
          {(rows) => {
            const sorted = [...rows].sort((a, b) => Number(b.flagged) - Number(a.flagged))
            return (
              <Table caption="At-risk signals">
                <thead>
                  <tr>
                    <th>Student</th>
                    <th>Missing work</th>
                    <th>Trend</th>
                    <th>Last seen</th>
                    <th>Support</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {sorted.map((r) => (
                    <tr key={r.user.id}>
                      <td>
                        {r.user.name} {r.flagged && <Badge tone="warn">Flagged</Badge>}
                      </td>
                      <td>{r.missing_assignments > 0 ? <Badge tone="bad">{r.missing_assignments} missed</Badge> : <span className="muted">None</span>}</td>
                      <td>{r.declining ? <Badge tone="bad">Declining</Badge> : <span className="muted">Stable</span>}</td>
                      <td>{r.inactive_days === null ? <span className="muted">Never signed in</span> : r.inactive_days === 0 ? 'Today' : `${r.inactive_days} day${r.inactive_days === 1 ? '' : 's'} ago`}</td>
                      <td>{r.has_open_plan ? <Badge tone="info">Plan open</Badge> : <span className="muted">—</span>}</td>
                      <td className="actions">
                        <Button small variant="primary" onClick={() => setOpening(r)}>
                          {r.has_open_plan ? 'View plans' : 'Reach out'}
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )
          }}
        </QueryView>
      </Card>
      {opening && <PlansDialog row={opening} onClose={() => setOpening(null)} />}
    </>
  )
}

function PlansDialog({ row, onClose }: { row: AtRiskRow; onClose: () => void }) {
  const { id } = useOffering()
  const [reason, setReason] = useState('')
  const [actionPlan, setActionPlan] = useState('')
  const [messageToStudent, setMessageToStudent] = useState('')
  const plans = useQuery({ queryKey: ['intervention-plans', id, row.user.id], queryFn: () => api.get<InterventionPlan[]>(`/offerings/${id}/intervention-plans`, { user_id: row.user.id }) })
  const create = useApiMutation(
    () => api.post(`/offerings/${id}/intervention-plans`, { user_id: row.user.id, reason: reason.trim(), action_plan: actionPlan.trim() || null, message_to_student: messageToStudent.trim() || null }),
    {
      invalidate: [
        ['intervention-plans', id, row.user.id],
        ['at-risk', id],
      ],
      success: 'Plan created.',
      onSuccess: () => {
        setReason('')
        setActionPlan('')
        setMessageToStudent('')
      },
    },
  )
  const resolve = useApiMutation((planId: number) => api.patch(`/intervention-plans/${planId}`, { status: 'resolved' }), {
    invalidate: [
      ['intervention-plans', id, row.user.id],
      ['at-risk', id],
    ],
    toastError: true,
  })

  return (
    <Modal title={`Support: ${row.user.name}`} onClose={onClose} wide>
      {plans.data?.map((p) => (
        <Card key={p.id} title={p.status === 'open' ? 'Open plan' : 'Resolved plan'}>
          <p className="muted small">
            By {p.author?.name} on {formatDateTime(p.created_at)}
          </p>
          <p>
            <strong>Reason:</strong> {p.reason}
          </p>
          {p.action_plan && (
            <p>
              <strong>Plan:</strong> {p.action_plan}
            </p>
          )}
          {p.status === 'open' && (
            <Button small loading={resolve.isPending && resolve.variables === p.id} onClick={() => resolve.mutate(p.id)}>
              Mark resolved
            </Button>
          )}
        </Card>
      ))}
      <Card title="New intervention plan">
        <form
          onSubmit={(event) => {
            event.preventDefault()
            create.mutate()
          }}
        >
          <TextArea label="Why are you reaching out?" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldError(create.error, 'reason')} required />
          <TextArea label="Action plan" optional rows={3} value={actionPlan} onChange={(e) => setActionPlan(e.target.value)} error={fieldError(create.error, 'action_plan')} />
          <TextArea
            label="Private message to the student"
            optional
            rows={3}
            value={messageToStudent}
            onChange={(e) => setMessageToStudent(e.target.value)}
            error={fieldError(create.error, 'message_to_student')}
            hint="Sent to the student directly. They never see your reason or action plan above."
          />
          {create.error && !['reason', 'action_plan', 'message_to_student'].some((f) => fieldError(create.error, f)) && <FormError error={create.error} />}
          <div className="form-actions">
            <Button onClick={onClose}>Close</Button>
            <Button type="submit" variant="primary" loading={create.isPending} disabled={!reason.trim()}>
              Create plan
            </Button>
          </div>
        </form>
      </Card>
    </Modal>
  )
}
