import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import type { DigestSummary, Notification, Offering, Overview, Page } from '../api/types'
import { ROLE_LABELS } from '../api/types'
import { useAuth } from '../auth/AuthContext'
import { Badge, Card, EmptyState, ErrorState, Loading, PageHeader, QueryView, Stat } from '../components/ui'
import { formatDateTime, plural, relativeTime } from '../lib/format'
import { useTitle } from '../lib/hooks'
import { notificationLink, notificationText } from '../lib/notifications'
import { StudentDashboard } from './StudentDashboard'

export function Dashboard() {
  const { isStudentOnly } = useAuth()
  if (isStudentOnly) return <StudentDashboard />
  return <StaffDashboard />
}

function StaffDashboard() {
  useTitle('Dashboard')
  const { user, can } = useAuth()

  const offerings = useQuery({ queryKey: ['offerings', 1], queryFn: () => api.get<Page<Offering>>('/offerings', { page: 1 }) })
  const todo = useQuery({ queryKey: ['digest-preview'], queryFn: () => api.get<DigestSummary>('/me/digest-preview'), staleTime: 60_000, retry: false })
  const notifications = useQuery({ queryKey: ['notifications', 'bell'], queryFn: () => api.get<Page<Notification>>('/notifications') })
  const overview = useQuery({ queryKey: ['overview'], queryFn: () => api.get<Overview>('/reports/overview'), enabled: can('manage-courses'), staleTime: 60_000 })

  return (
    <>
      <PageHeader title={`Welcome, ${user?.name ?? ''}`} subtitle={(user?.roles ?? []).map((r) => ROLE_LABELS[r.name] ?? r.name).join(' · ')} />

      {can('manage-courses') && (
        <Card title="The university at a glance" actions={<Link to="/admin/reports">Full report</Link>}>
          <QueryView query={overview}>
            {(o) => (
              <div className="stats">
                <Stat label="Accounts" value={o.users.total} hint={`${o.users.active} active`} />
                <Stat label="Course offerings" value={o.offerings.total} hint={`${o.offerings.published} published`} />
                <Stat label="Active enrolments" value={o.enrolments_active} />
                <Stat label="Assignments" value={o.assignments} hint={`${o.submissions} submissions`} />
                <Stat label="Quizzes" value={o.quizzes} hint={`${o.quiz_attempts_submitted} attempts`} />
              </div>
            )}
          </QueryView>
        </Card>
      )}

      <div className="grid-2">
        <Card title="Needs your attention">
          {todo.isPending && <Loading />}
          {todo.isError && <p className="muted">We could not load your summary just now. {(todo.error as Error).message}</p>}
          {todo.data && !todo.data.summary && <EmptyState title="You are all caught up">Nothing is due this week and nothing is waiting for you.</EmptyState>}
          {todo.data?.summary && <Attention summary={todo.data.summary} />}
        </Card>

        <Card title="Latest notifications" actions={<Link to="/notifications">See all</Link>}>
          <QueryView query={notifications} isEmpty={(page) => page.data.length === 0} empty={<EmptyState title="No notifications yet">Grades, announcements and reminders will appear here.</EmptyState>}>
            {(page) => (
              <ul className="list">
                {page.data.slice(0, 5).map((n) => {
                  const link = notificationLink(n)
                  const text = notificationText(n)
                  return (
                    <li key={n.id} className={n.read_at ? '' : 'unread'}>
                      {link ? <Link to={link}>{text}</Link> : text}
                      <span className="muted small block">{relativeTime(n.created_at)}</span>
                    </li>
                  )
                })}
              </ul>
            )}
          </QueryView>
        </Card>
      </div>

      <Card title="Your courses" actions={<Link to="/courses">All courses</Link>}>
        {offerings.isPending && <Loading />}
        {offerings.isError && <ErrorState error={offerings.error} onRetry={() => void offerings.refetch()} />}
        {offerings.data && offerings.data.data.length === 0 && <EmptyState title="No courses yet">{can('submit-assignments') ? 'You will see your courses here once you are enrolled in one that has been published.' : 'Courses you teach or manage will appear here.'}</EmptyState>}
        {offerings.data && offerings.data.data.length > 0 && (
          <div className="course-grid">
            {offerings.data.data.slice(0, 6).map((o) => (
              <Link key={o.id} to={`/courses/${o.id}`} className="course-tile">
                <strong>{o.course?.code}</strong>
                <span>{o.course?.title}</span>
                <span className="muted small">
                  {o.term?.name} · Section {o.section}
                </span>
                {!o.published && <Badge tone="warn">Draft</Badge>}
              </Link>
            ))}
          </div>
        )}
      </Card>
    </>
  )
}

function Attention({ summary }: { summary: NonNullable<DigestSummary['summary']> }) {
  return (
    <div className="attention">
      {summary.deadlines && summary.deadlines.length > 0 && (
        <div>
          <h3>Due in the next 7 days</h3>
          <ul className="list">
            {summary.deadlines.map((d, i) => (
              <li key={i}>
                <strong>{d.title}</strong> <Badge tone={d.type === 'quiz' ? 'info' : 'neutral'}>{d.type}</Badge>
                <span className="muted small block">
                  {d.course} · {formatDateTime(d.due_at)}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
      {summary.to_grade && summary.to_grade.length > 0 && (
        <div>
          <h3>Waiting for a published grade</h3>
          <ul className="list">
            {summary.to_grade.map((g, i) => (
              <li key={i}>
                <strong>{g.assignment}</strong>
                <span className="muted small block">
                  {g.course} · {plural(g.awaiting, 'submission')}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
      {!!summary.open_appeals && (
        <p>
          <Link to="/courses">{plural(summary.open_appeals, 'open grade appeal')}</Link> waiting for your decision.
        </p>
      )}
      {summary.notifications && (
        <p className="muted">
          {plural(summary.notifications.total, 'unread update')}: {summary.notifications.groups.map((g) => `${g.count} ${g.label.toLowerCase()}`).join(', ')}.
        </p>
      )}
    </div>
  )
}
