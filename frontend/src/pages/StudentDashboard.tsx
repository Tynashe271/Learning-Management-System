import { useQueries, useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import type { Agenda, AgendaDeadline, AgendaSession, Engagement, Insights, LearningGoal, Offering, Page, Progress } from '../api/types'
import { useAuth } from '../auth/AuthContext'
import { CourseCard } from '../components/CourseCard'
import { Badge, Button, Card, CheckField, EmptyState, ErrorState, FormError, Loading, ProgressRing, TextField } from '../components/ui'
import { dayLabel, formatDate, formatTime, plural } from '../lib/format'
import { DownloadButton, fieldError, useApiMutation, useTitle } from '../lib/hooks'

export function StudentDashboard() {
  useTitle('My learning')
  const { user } = useAuth()

  const lowData = !!user?.low_data_mode
  const [wantInsights, setWantInsights] = useState(!lowData)

  const offerings = useQuery({ queryKey: ['offerings', 1], queryFn: () => api.get<Page<Offering>>('/offerings', { page: 1 }) })
  const agenda = useQuery({ queryKey: ['me', 'agenda'], queryFn: () => api.get<Agenda>('/me/agenda', { days: 7 }) })
  const insights = useQuery({ queryKey: ['me', 'insights'], queryFn: () => api.get<Insights>('/me/insights'), enabled: wantInsights })

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

      <div className="grid-2">
        <Card title="Today & this week">
          {agenda.isPending && <Loading />}
          {agenda.isError && <p className="muted">We could not load your agenda just now. {(agenda.error as Error).message}</p>}
          {agenda.data && agenda.data.sessions.length === 0 && agenda.data.deadlines.length === 0 && (
            <EmptyState title="Nothing on your plate this week" icon="🎉">
              No classes and nothing due for the next 7 days.
            </EmptyState>
          )}
          {agenda.data && (agenda.data.sessions.length > 0 || agenda.data.deadlines.length > 0) && <WeeklyPlan agenda={agenda.data} />}
        </Card>

        {wantInsights ? (
          <Card title="Missing work">
            {insights.isPending && <Loading />}
            {insights.isError && <p className="muted">We could not load this just now.</p>}
            {insights.data && insights.data.missing.length === 0 && (
              <EmptyState title="Nothing overdue" icon="✅">
                Everything that has come due so far is submitted.
              </EmptyState>
            )}
            {insights.data && insights.data.missing.length > 0 && (
              <ul className="agenda">
                {insights.data.missing.map((m) => (
                  <MissingRow key={`${m.type}${m.id}`} item={m} />
                ))}
              </ul>
            )}
          </Card>
        ) : (
          <Card title="Missing work, feedback & recommendations">
            <p className="muted small">Low-data mode is on, so this is not loaded automatically.</p>
            <Button onClick={() => setWantInsights(true)}>Load anyway</Button>
          </Card>
        )}
      </div>

      {wantInsights && (
        <div className="grid-2">
          <Card title="Recent feedback">
            {insights.isPending && <Loading />}
            {insights.isError && <p className="muted">We could not load this just now.</p>}
            {insights.data && insights.data.recent_feedback.length === 0 && <p className="muted">No published feedback yet.</p>}
            {insights.data && insights.data.recent_feedback.length > 0 && (
              <ul className="agenda">
                {insights.data.recent_feedback.map((f, i) => (
                  <li key={i}>
                    <span className="agenda-icon" aria-hidden="true">
                      💬
                    </span>
                    <span>
                      <strong>{f.assignment}</strong>
                      <span className="muted small block">
                        {f.course} · {f.score}/{f.max_score}
                      </span>
                      <span className="small block">{f.feedback}</span>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title="Topics to review">
            {insights.isPending && <Loading />}
            {insights.isError && <p className="muted">We could not load this just now.</p>}
            {insights.data && insights.data.weak_topics.length === 0 && <p className="muted">Nothing flagged — your marks look solid across every topic.</p>}
            {insights.data && insights.data.weak_topics.length > 0 && (
              <ul className="agenda">
                {insights.data.weak_topics.map((t) => (
                  <li key={t.module_id}>
                    <span className="agenda-icon" aria-hidden="true">
                      📉
                    </span>
                    <span>
                      <Link to={`/courses/${t.offering_id}/classwork#module-${t.module_id}`}>
                        <strong>{t.title}</strong>
                      </Link>
                      <span className="muted small block">Averaging {t.percent}% — worth a second look</span>
                      {t.practice_quiz_ids.length > 0 && (
                        <span className="small block">
                          {t.practice_quiz_ids.map((qid, i) => (
                            <span key={qid}>
                              {i > 0 && ', '}
                              <Link to={`/quizzes/${qid}`}>Practice quiz</Link>
                            </span>
                          ))}
                        </span>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      <div className="grid-2">
        <EngagementCard />
        <LearningGoalsCard />
      </div>

      <CalendarSync />
    </>
  )
}

function EngagementCard() {
  const engagement = useQuery({ queryKey: ['engagement', 'me'], queryFn: () => api.get<Engagement>('/me/engagement') })
  return (
    <Card title="Streak and badges">
      {engagement.isPending && <Loading />}
      {engagement.isError && <p className="muted">We could not load this just now.</p>}
      {engagement.data && (
        <>
          <p>
            🔥 {plural(engagement.data.streak.current_days, 'day')} streak
            {engagement.data.streak.longest_days > engagement.data.streak.current_days && <span className="muted small"> · best was {plural(engagement.data.streak.longest_days, 'day')}</span>}
          </p>
          {engagement.data.badges.length === 0 ? (
            <p className="muted small">Badges celebrate what you have already done. Sign in regularly, work through your course content and ace a quiz to start earning them.</p>
          ) : (
            <div className="badges">
              {engagement.data.badges.map((b) => (
                <Badge key={b.key} tone="info">
                  {b.label}
                </Badge>
              ))}
            </div>
          )}
          <p className="muted small">Badges are encouragement, not an academic result — they never affect a grade.</p>
        </>
      )}
    </Card>
  )
}

function LearningGoalsCard() {
  const [title, setTitle] = useState('')
  const [targetDate, setTargetDate] = useState('')
  const goals = useQuery({ queryKey: ['learning-goals', 'me'], queryFn: () => api.get<LearningGoal[]>('/me/learning-goals') })
  const add = useApiMutation(() => api.post<LearningGoal>('/me/learning-goals', { title: title.trim(), target_date: targetDate || null }), {
    invalidate: [['learning-goals', 'me']],
    onSuccess: () => {
      setTitle('')
      setTargetDate('')
    },
  })
  const toggle = useApiMutation((goal: LearningGoal) => api.patch(`/learning-goals/${goal.id}`, { completed: !goal.completed_at }), { invalidate: [['learning-goals', 'me']], toastError: true })
  const remove = useApiMutation((id: number) => api.delete(`/learning-goals/${id}`), { invalidate: [['learning-goals', 'me']], toastError: true })

  return (
    <Card title="My learning goals">
      {goals.isPending && <Loading />}
      {goals.isError && <p className="muted">We could not load this just now.</p>}
      {goals.data && goals.data.length === 0 && <p className="muted small">Nothing set yet — a goal can be as small as &quot;finish Module 3 this week&quot;.</p>}
      {goals.data && goals.data.length > 0 && (
        <ul className="list">
          {goals.data.map((g) => (
            <li key={g.id}>
              <CheckField label={g.title} checked={!!g.completed_at} onChange={() => toggle.mutate(g)} />
              <span className="grow">{g.target_date && <span className="muted small block">Target: {formatDate(g.target_date)}</span>}</span>
              <Button small variant="danger" onClick={() => remove.mutate(g.id)}>
                Remove
              </Button>
            </li>
          ))}
        </ul>
      )}
      <form
        className="inline-form"
        onSubmit={(event) => {
          event.preventDefault()
          add.mutate()
        }}
      >
        <TextField label="New goal" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(add.error, 'title')} maxLength={255} placeholder="e.g. Finish the React course" required />
        <TextField label="Target date" optional type="date" value={targetDate} onChange={(e) => setTargetDate(e.target.value)} error={fieldError(add.error, 'target_date')} />
        {add.error && !fieldError(add.error, 'title') && !fieldError(add.error, 'target_date') && <FormError error={add.error} />}
        <Button type="submit" variant="primary" loading={add.isPending} disabled={!title.trim()}>
          Add goal
        </Button>
      </form>
    </Card>
  )
}

function WeeklyPlan({ agenda }: { agenda: Agenda }) {
  type Item = { at: string; node: ReactNode }
  const items: Item[] = [
    ...agenda.sessions.map((s: AgendaSession) => ({
      at: s.at,
      node: (
        <>
          <span className="agenda-icon" aria-hidden="true">
            🎥
          </span>
          <span>
            <strong>{s.title}</strong>
            <span className="muted small block">
              {s.course} · {formatTime(s.at)}
              {s.location ? ` · ${s.location}` : ''}
            </span>
          </span>
        </>
      ),
    })),
    ...agenda.deadlines.map((d: AgendaDeadline) => ({
      at: d.at,
      node: (
        <>
          <span className="agenda-icon" aria-hidden="true">
            {d.type === 'quiz' ? '❓' : '📝'}
          </span>
          <span>
            <strong>{d.title}</strong>
            <span className="muted small block">
              {d.course} · due {formatTime(d.at)}
            </span>
          </span>
        </>
      ),
    })),
  ].sort((a, b) => a.at.localeCompare(b.at))

  const days = new Map<string, Item[]>()
  for (const item of items) {
    const label = dayLabel(item.at)
    days.set(label, [...(days.get(label) ?? []), item])
  }

  const itemCount = agenda.deadlines.length
  const estimateMinutes = itemCount * 45

  return (
    <>
      {[...days.entries()].map(([label, dayItems]) => (
        <div key={label} className="agenda-day">
          <h3>{label}</h3>
          <ul className="agenda">
            {dayItems.map((item, i) => (
              <li key={i}>{item.node}</li>
            ))}
          </ul>
        </div>
      ))}
      {itemCount > 0 && (
        <p className="muted small">
          Roughly {plural(estimateMinutes, 'minute')} of work due this week ({plural(itemCount, 'item')}) — a rough guide, not a tracked total.
        </p>
      )}
    </>
  )
}

function MissingRow({ item }: { item: AgendaDeadline }) {
  const link = item.type === 'quiz' ? `/quizzes/${item.id}` : `/assignments/${item.id}`
  return (
    <li>
      <span className="agenda-icon" aria-hidden="true">
        ⚠️
      </span>
      <span>
        <Link to={link}>
          <strong>{item.title}</strong>
        </Link>
        <span className="muted small block">
          {item.course} · was due <Badge tone="bad">overdue</Badge>
        </span>
      </span>
    </li>
  )
}

function CalendarSync() {
  const [url, setUrl] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const get = useApiMutation(() => api.post<{ url: string }>('/me/calendar-token'), { onSuccess: (r) => setUrl(r.url), toastError: true })

  return (
    <Card title="Sync to your calendar">
      <p className="muted small">Add your classes and deadlines to Google Calendar, Outlook or Apple Calendar. It updates on its own — no need to redo this.</p>
      <div className="inline-form">
        {!url && (
          <Button onClick={() => get.mutate()} loading={get.isPending}>
            Get my calendar link
          </Button>
        )}
        <DownloadButton path="/me/calendar.pdf" filename="agenda.pdf">
          Download as PDF
        </DownloadButton>
      </div>
      {url && (
        <div className="inline-form">
          <div className="field grow">
            <label htmlFor="ics-url">Subscribe URL</label>
            <input id="ics-url" type="text" readOnly value={url} onFocus={(e) => e.currentTarget.select()} />
          </div>
          <Button
            onClick={async () => {
              await navigator.clipboard.writeText(url)
              setCopied(true)
              setTimeout(() => setCopied(false), 2000)
            }}
          >
            {copied ? 'Copied' : 'Copy'}
          </Button>
        </div>
      )}
      {url && <p className="hint">In Google Calendar: Other calendars → + → From URL, then paste this in. Outlook and Apple Calendar have a similar &quot;subscribe by URL&quot; option.</p>}
    </Card>
  )
}
