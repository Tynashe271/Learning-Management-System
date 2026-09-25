import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import type { Appeal, AppealStatus, Page } from '../../api/types'
import { useMe } from '../../auth/AuthContext'
import { Alert, Badge, Button, Card, EmptyState, ErrorState, FormError, Loading, PageHeader, Pager, QueryView, SelectField, Table, TextArea, pagerFromPage, useConfirm } from '../../components/ui'
import { formatDateTime, formatScore } from '../../lib/format'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'
import { useOffering } from './context'
import { recordsOf } from './GradeDialog'

const STATUS_LABEL: Record<AppealStatus, string> = { open: 'Open', upheld: 'Upheld', rejected: 'Rejected' }
const STATUS_TONE: Record<AppealStatus, 'warn' | 'good' | 'neutral'> = { open: 'warn', upheld: 'good', rejected: 'neutral' }

export function AppealBadge({ status }: { status: AppealStatus }) {
  return <Badge tone={STATUS_TONE[status]}>{STATUS_LABEL[status]}</Badge>
}

/** A student's own appeals, across all their courses. */
export function MyAppealsPage() {
  useTitle('My grade appeals')
  const [page, setPage] = useState(1)
  const confirm = useConfirm()
  const query = useQuery({ queryKey: ['appeals', 'mine', page], queryFn: () => api.get<Page<Appeal>>('/my-appeals', { page }), placeholderData: (previous) => previous })
  const withdraw = useApiMutation((id: number) => api.delete(`/appeals/${id}`), { invalidate: [['appeals']], success: 'Appeal withdrawn.', toastError: true })

  return (
    <>
      <PageHeader title="My grade appeals" subtitle="Appeals you have filed against a published grade" />
      <Card>
        <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No appeals">If you think a published grade is wrong, open the assignment and choose “Appeal this grade”.</EmptyState>}>
          {(data) => (
            <>
              <Table caption="My appeals">
                <thead>
                  <tr>
                    <th>Assignment</th>
                    <th>Filed</th>
                    <th>Status</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((a) => (
                    <tr key={a.id}>
                      <td>
                        {a.submission?.assignment ? <Link to={`/assignments/${a.submission.assignment.id}`}>{a.submission.assignment.title}</Link> : `Appeal #${a.id}`}
                      </td>
                      <td>{formatDateTime(a.created_at)}</td>
                      <td>
                        <AppealBadge status={a.status} />
                      </td>
                      <td className="actions">
                        <Link className="btn btn-secondary btn-small" to={`/appeals/${a.id}`}>
                          Open
                        </Link>
                        {a.status === 'open' && (
                          <Button
                            small
                            variant="danger"
                            onClick={async () => {
                              if (await confirm({ title: 'Withdraw this appeal?', message: 'You can file it again while the appeal window is still open.', confirmLabel: 'Withdraw', danger: true })) withdraw.mutate(a.id)
                            }}
                          >
                            Withdraw
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
              <Pager {...pagerFromPage(data)} onPage={setPage} />
            </>
          )}
        </QueryView>
      </Card>
    </>
  )
}

/** Appeals in one course, open ones first. */
export function AppealsTab() {
  const { id } = useOffering()
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState<'' | AppealStatus>('')
  const query = useQuery({ queryKey: ['appeals', 'offering', id, status, page], queryFn: () => api.get<Page<Appeal>>(`/offerings/${id}/appeals`, { status: status || undefined, page }), placeholderData: (previous) => previous })

  return (
    <Card title="Grade appeals">
      <SelectField label="Show" value={status} onChange={(e) => { setStatus(e.target.value as '' | AppealStatus); setPage(1) }}>
        <option value="">All appeals</option>
        <option value="open">Open</option>
        <option value="upheld">Upheld</option>
        <option value="rejected">Rejected</option>
      </SelectField>
      <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No appeals">Students can appeal a published grade for a limited time after it is published.</EmptyState>}>
        {(data) => (
          <>
            <Table caption="Appeals">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Assignment</th>
                  <th>Filed</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {data.data.map((a) => (
                  <tr key={a.id}>
                    <td>{a.student?.name}</td>
                    <td>{a.submission?.assignment?.title}</td>
                    <td>{formatDateTime(a.created_at)}</td>
                    <td>
                      <AppealBadge status={a.status} />
                    </td>
                    <td className="actions">
                      <Link className="btn btn-secondary btn-small" to={`/appeals/${a.id}`}>
                        {a.status === 'open' ? 'Review' : 'View'}
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </Table>
            <Pager {...pagerFromPage(data)} onPage={setPage} />
          </>
        )}
      </QueryView>
    </Card>
  )
}

export function AppealPage() {
  const { id } = useParams()
  const appealId = Number(id)
  const me = useMe()
  const confirm = useConfirm()
  const query = useQuery({ queryKey: ['appeal', appealId], queryFn: () => api.get<Appeal>(`/appeals/${appealId}`), retry: false })
  const offeringId = query.data?.submission?.assignment?.course_offering_id
  const canResolve = me.permissions.includes('resolve-appeals') && me.id !== query.data?.user_id
  const [outcome, setOutcome] = useState<'upheld' | 'rejected'>('rejected')
  const [response, setResponse] = useState('')
  useTitle('Grade appeal')

  const resolve = useApiMutation(() => api.post(`/appeals/${appealId}/resolve`, { outcome, response: response.trim() }), { invalidate: [['appeal', appealId], ['appeals']], success: 'Decision recorded. The student has been notified.' })
  const withdraw = useApiMutation(() => api.delete(`/appeals/${appealId}`), { invalidate: [['appeals']], success: 'Appeal withdrawn.', onSuccess: () => history.back(), toastError: true })

  if (query.isPending) return <Loading />
  if (query.isError) {
    if (query.error instanceof ApiError && (query.error.status === 403 || query.error.status === 404)) return <Alert>This appeal is not available to you. <Link to="/">Back to the dashboard</Link></Alert>
    return <ErrorState error={query.error} onRetry={() => void query.refetch()} />
  }
  const a = query.data
  const assignment = a.submission?.assignment
  const grades = [...recordsOf(a.submission as never)].sort((x, y) => x.id - y.id)
  const mine = a.user_id === me.id

  return (
    <>
      <p className="crumbs">
        {assignment && <Link to={`/assignments/${assignment.id}`}>{assignment.title}</Link>} / Grade appeal
      </p>
      <PageHeader title="Grade appeal" subtitle={`${a.student?.name ?? 'Student'} · filed ${formatDateTime(a.created_at)}`} actions={<AppealBadge status={a.status} />} />
      <Card title="The appeal">
        <div className="reading pre">{a.reason}</div>
      </Card>
      <Card title="Published grades">
        {grades.length === 0 ? (
          <p className="muted">No published grade.</p>
        ) : (
          <ul className="list">
            {grades.map((g) => (
              <li key={g.id}>
                <strong>
                  {formatScore(g.score)} / {assignment?.max_score}
                </strong>{' '}
                {g.id === a.grade_record_id && <Badge tone="warn">Grade being appealed</Badge>} {g.id > a.grade_record_id && <Badge tone="good">Revised after the appeal</Badge>}
                <span className="muted small block">
                  {formatDateTime(g.created_at)}
                  {g.change_reason ? ` · Reason: ${g.change_reason}` : ''}
                </span>
                {g.feedback && <span className="block pre small">{g.feedback}</span>}
              </li>
            ))}
          </ul>
        )}
      </Card>

      {a.status !== 'open' && (
        <Card title="Decision">
          <p>
            <AppealBadge status={a.status} /> {a.resolver && <span className="muted">by {a.resolver.name} on {formatDateTime(a.resolved_at)}</span>}
          </p>
          <div className="reading pre">{a.response}</div>
        </Card>
      )}

      {a.status === 'open' && mine && (
        <Card>
          <p className="muted">Your appeal is waiting for a decision. You will be notified.</p>
          <Button
            variant="danger"
            loading={withdraw.isPending}
            onClick={async () => {
              if (await confirm({ title: 'Withdraw your appeal?', message: 'You can file it again while the appeal window is still open.', confirmLabel: 'Withdraw', danger: true })) withdraw.mutate()
            }}
          >
            Withdraw appeal
          </Button>
        </Card>
      )}

      {a.status === 'open' && !mine && canResolve && (
        <Card title="Decide the appeal">
          <Alert tone="info">To uphold an appeal, first record and publish the revised grade (with a change reason) in the assignment’s submissions, then come back and uphold it. To reject, the grade must be unchanged since the appeal was filed.{offeringId && <> <Link to={`/assignments/${assignment?.id}`}>Open the assignment to regrade</Link>.</>}</Alert>
          <form
            onSubmit={(event) => {
              event.preventDefault()
              resolve.mutate()
            }}
          >
            <SelectField label="Outcome" value={outcome} onChange={(e) => setOutcome(e.target.value as 'upheld' | 'rejected')} error={fieldError(resolve.error, 'outcome')}>
              <option value="rejected">Reject: the grade stands</option>
              <option value="upheld">Uphold: the grade was revised</option>
            </SelectField>
            <TextArea label="Your response to the student" rows={5} value={response} onChange={(e) => setResponse(e.target.value)} hint={`At least 10 characters (${response.trim().length} so far).`} error={fieldError(resolve.error, 'response')} required />
            {resolve.error && !fieldError(resolve.error, 'outcome') && !fieldError(resolve.error, 'response') && <FormError error={resolve.error} />}
            <Button type="submit" variant="primary" loading={resolve.isPending} disabled={response.trim().length < 10}>
              Record decision
            </Button>
          </form>
        </Card>
      )}
      {a.status === 'open' && !mine && !canResolve && <Alert tone="info">Only staff with permission to decide appeals can resolve this one.</Alert>}
    </>
  )
}
