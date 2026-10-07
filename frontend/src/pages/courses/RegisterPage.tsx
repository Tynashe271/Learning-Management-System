import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../../api/client'
import type { Department, Offering, Page, RegistrationOffering } from '../../api/types'
import { Alert, Badge, Button, Card, EmptyState, FormError, PageHeader, Pager, QueryView, SelectField, TextField, pagerFromPage, useConfirm, useDebounced } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'

/** A course code a lecturer shared, so a student can join directly without browsing the self-registration list. */
function JoinByCodeCard() {
  const navigate = useNavigate()
  const [code, setCode] = useState('')
  const join = useApiMutation(() => api.post<{ offering: Offering }>('/offerings/join', { code: code.trim() }), {
    invalidate: [['registration'], ['offerings']],
    onSuccess: (r) => navigate(`/courses/${r.offering.id}`),
  })
  return (
    <Card title="Have a course code?">
      <p className="muted small">Your lecturer can give you a code to join their course directly.</p>
      <form
        className="inline-form"
        onSubmit={(event) => {
          event.preventDefault()
          join.mutate()
        }}
      >
        <TextField label="Course code" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} error={fieldError(join.error, 'code')} maxLength={12} placeholder="e.g. 7K9PQR" />
        <Button type="submit" variant="primary" loading={join.isPending} disabled={!code.trim()}>
          Join
        </Button>
      </form>
      {join.error && !fieldError(join.error, 'code') && <FormError error={join.error} />}
    </Card>
  )
}

/** Course registration for students: what they may sign up for this term, how many places are left, and dropping before the deadline. */
export function RegisterPage() {
  useTitle('Register for courses')
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [text, setText] = useState('')
  const q = useDebounced(text.trim())
  const [department, setDepartment] = useState('')
  const departments = useQuery({ queryKey: ['departments', 'public'], queryFn: () => api.get<Department[]>('/departments', { archived: 'exclude' }).catch(() => [] as Department[]) })
  const query = useQuery({
    queryKey: ['registration', q, department, page],
    queryFn: () => api.get<Page<RegistrationOffering>>('/registration', { q: q || undefined, department_id: department || undefined, page }),
    placeholderData: (previous) => previous,
  })
  const keys = [['registration'], ['offerings']]
  const register = useApiMutation((o: RegistrationOffering) => api.post(`/offerings/${o.id}/register`), { invalidate: keys, success: 'You are registered.', toastError: true })
  const drop = useApiMutation((o: RegistrationOffering) => api.delete(`/offerings/${o.id}/register`), { invalidate: keys, success: 'You have dropped the course.', toastError: true })
  const reset = () => setPage(1)

  return (
    <>
      <PageHeader title="Register for courses" subtitle="Courses you can sign up for yourself. Some courses are enrolled by the registrar and do not appear here." />
      <JoinByCodeCard />
      <Card>
        <div className="filters">
          <TextField label="Search" type="search" value={text} onChange={(e) => { setText(e.target.value); reset() }} placeholder="Course code or title" />
          {(departments.data ?? []).length > 0 && (
            <SelectField label="Department" value={department} onChange={(e) => { setDepartment(e.target.value); reset() }}>
              <option value="">All departments</option>
              {(departments.data ?? []).map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </SelectField>
          )}
        </div>
        <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="Nothing to register for">No courses are open for self-registration right now. Ask your registrar if you expected to see one.</EmptyState>}>
          {(data) => (
            <>
              {/* Cards, not a table: on a phone a table pushes the Register button out of sight. */}
              <ul className="reg-list" aria-label="Courses open for registration">
                {data.data.map((o) => {
                  const r = o.registration
                  const mine = r.my_status === 'active'
                  return (
                    <li key={o.id} className="reg-item">
                      <div className="reg-main">
                        <strong>{o.course?.code}</strong> {o.course?.title}
                        <span className="muted small block">
                          Section {o.section}
                          {o.course?.credits ? ` · ${o.course.credits} credits` : ''}
                          {o.teachers?.length ? ` · ${o.teachers.map((t) => t.user?.name).filter(Boolean).join(', ')}` : ''}
                        </span>
                        <span className="muted small block">{o.term?.name}</span>
                      </div>
                      <div className="reg-meta small">
                        <span>
                          {r.open ? <Badge tone="good">Open</Badge> : <Badge tone="neutral">Closed</Badge>}{' '}
                          <span className="muted">{r.opens_on ? `${formatDate(r.opens_on)} – ${formatDate(r.closes_on)}` : 'No dates set'}</span>
                        </span>
                        <span>{r.seats_left === null ? 'No limit on places' : r.full ? <Badge tone="bad">Full</Badge> : `${r.seats_left} ${r.seats_left === 1 ? 'place' : 'places'} left`}</span>
                      </div>
                      <div className="reg-action">
                        {mine ? (
                          <>
                            <Badge tone="good">Registered</Badge>
                            <Link className="btn btn-ghost btn-small" to={`/courses/${o.id}`}>
                              Open
                            </Link>
                            {r.can_drop && (
                              <Button
                                small
                                variant="ghost"
                                loading={drop.isPending && drop.variables?.id === o.id}
                                onClick={async () => {
                                  if (await confirm({ title: `Drop ${o.course?.code}?`, message: `You can drop until ${formatDate(r.drop_deadline)}. After that only the registrar can withdraw you.`, confirmLabel: 'Drop the course', danger: true })) drop.mutate(o)
                                }}
                              >
                                Drop
                              </Button>
                            )}
                          </>
                        ) : (
                          <Button small variant="primary" disabled={!r.can_register} loading={register.isPending && register.variables?.id === o.id} onClick={() => register.mutate(o)}>
                            Register
                          </Button>
                        )}
                      </div>
                    </li>
                  )
                })}
              </ul>
              <Pager {...pagerFromPage(data)} onPage={setPage} />
            </>
          )}
        </QueryView>
        <Alert tone="info">Registration is only possible between the opening and closing dates, and while places remain. If you cannot register for a course you need, ask your registrar.</Alert>
      </Card>
    </>
  )
}
