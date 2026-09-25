import { useQueries, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import type { DigestSummary, Offering, Page, Progress } from '../api/types'
import { useAuth } from '../auth/AuthContext'
import { CourseCard } from '../components/CourseCard'
import { Card, EmptyState, ErrorState, Loading, ProgressRing } from '../components/ui'
import { formatDateTime, plural } from '../lib/format'
import { useTitle } from '../lib/hooks'

export function StudentDashboard() {
  useTitle('My learning')
  const { user } = useAuth()

  const offerings = useQuery({ queryKey: ['offerings', 1], queryFn: () => api.get<Page<Offering>>('/offerings', { page: 1 }) })
  const todo = useQuery({ queryKey: ['digest-preview'], queryFn: () => api.get<DigestSummary>('/me/digest-preview'), staleTime: 60_000, retry: false })

  const enrolled = offerings.data?.data ?? []
  const progressQueries = useQueries({
    queries: enrolled.map((o) => ({ queryKey: ['progress', o.id, 'me'], queryFn: () => api.get<Progress>(`/offerings/${o.id}/progress`), enabled: offerings.isSuccess })),
  })
  const overall = progressQueries.reduce(
    (acc, q) => (q.data ? { completed: acc.completed + (q.data.completed ?? 0), total: acc.total + q.data.items_total } : acc),
    { completed: 0, total: 0 },
  )

  return (
    <>
      <div className="hero">
        <div className="hero-text">
          <h1>Welcome back, {user?.name?.split(' ')[0] ?? ''}</h1>
          <p className="muted">Here&apos;s what&apos;s next in your courses.</p>
          {overall.total > 0 && (
            <div className="hero-progress">
              <span className="muted small">
                {overall.completed} of {overall.total} items complete across your courses
              </span>
            </div>
          )}
        </div>
        {overall.total > 0 && (
          <div className="hero-ring">
            <ProgressRing percent={(overall.completed / overall.total) * 100} label="Overall progress across your courses" />
            <span className="muted small">Overall progress</span>
          </div>
        )}
      </div>

      <h2 className="section-title">Continue learning</h2>
      {offerings.isPending && <Loading />}
      {offerings.isError && <ErrorState error={offerings.error} onRetry={() => void offerings.refetch()} />}
      {offerings.data && enrolled.length === 0 && (
        <EmptyState title="No courses yet" icon="🎓">
          You will see your courses here once you are enrolled in one that has been published.
        </EmptyState>
      )}
      {enrolled.length > 0 && (
        <div className="course-grid course-grid-cards">
          {enrolled.map((o) => (
            <CourseCard key={o.id} offering={o} />
          ))}
        </div>
      )}

      <Card title="Coming up">
        {todo.isPending && <Loading />}
        {todo.isError && <p className="muted">We could not load your summary just now. {(todo.error as Error).message}</p>}
        {todo.data && !todo.data.summary && (
          <EmptyState title="You are all caught up" icon="🎉">
            Nothing is due this week and nothing is waiting for you.
          </EmptyState>
        )}
        {todo.data?.summary && <Agenda summary={todo.data.summary} />}
      </Card>
    </>
  )
}

function Agenda({ summary }: { summary: NonNullable<DigestSummary['summary']> }) {
  return (
    <ul className="agenda">
      {summary.deadlines?.map((d, i) => (
        <li key={`d${i}`}>
          <span className="agenda-icon" aria-hidden="true">
            {d.type === 'quiz' ? '📝' : '📄'}
          </span>
          <span>
            <strong>{d.title}</strong>
            <span className="muted small block">
              {d.course} · due {formatDateTime(d.due_at)}
            </span>
          </span>
        </li>
      ))}
      {summary.to_grade?.map((g, i) => (
        <li key={`g${i}`}>
          <span className="agenda-icon" aria-hidden="true">
            ⏳
          </span>
          <span>
            <strong>{g.assignment}</strong>
            <span className="muted small block">
              {g.course} · {plural(g.awaiting, 'submission')} waiting for a published grade
            </span>
          </span>
        </li>
      ))}
      {!!summary.open_appeals && (
        <li>
          <span className="agenda-icon" aria-hidden="true">
            ⚖️
          </span>
          <Link to="/courses">{plural(summary.open_appeals, 'open grade appeal')}</Link>
        </li>
      )}
    </ul>
  )
}
