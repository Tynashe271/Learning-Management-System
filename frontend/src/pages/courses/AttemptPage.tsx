import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import type { AttemptInProgress, AttemptResult, Quiz } from '../../api/types'
import { Alert, Badge, Button, Card, ErrorState, FormError, Loading, PageHeader, Table, TextArea, TextField, useConfirm } from '../../components/ui'
import { formatDateTime, formatScore } from '../../lib/format'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'

type Attempt = AttemptInProgress | AttemptResult

export function AttemptPage() {
  const { id } = useParams()
  const attemptId = Number(id)
  const location = useLocation()
  const staffQuiz = (location.state as { quiz?: Quiz } | null)?.quiz
  const query = useQuery({ queryKey: ['attempt', attemptId], queryFn: () => api.get<Attempt>(`/attempts/${attemptId}`), retry: false, staleTime: 0 })
  useTitle('Quiz attempt')

  if (query.isPending) return <Loading />
  if (query.isError) {
    if (query.error instanceof ApiError && (query.error.status === 403 || query.error.status === 404)) return <Alert>This attempt is not available to you. <Link to="/courses">Back to courses</Link></Alert>
    return <ErrorState error={query.error} onRetry={() => void query.refetch()} />
  }
  return 'questions' in query.data ? <Taking attempt={query.data} /> : <Result attempt={query.data} quiz={staffQuiz} />
}

type Answers = Record<number, { option_ids: number[]; text: string }>

const SNAPSHOT_PREFIX = 'lms.attempt.options.'

function optionWording(attemptId: number): Record<string, string> {
  try {
    return JSON.parse(sessionStorage.getItem(SNAPSHOT_PREFIX + attemptId) ?? '{}') as Record<string, string>
  } catch {
    return {}
  }
}

function useCountdown(deadline: string | null): number | null {
  const [left, setLeft] = useState<number | null>(deadline ? Math.max(0, Math.round((new Date(deadline).getTime() - Date.now()) / 1000)) : null)
  useEffect(() => {
    if (!deadline) return
    const tick = () => setLeft(Math.max(0, Math.round((new Date(deadline).getTime() - Date.now()) / 1000)))
    tick()
    const timer = setInterval(tick, 1000)
    return () => clearInterval(timer)
  }, [deadline])
  return left
}

const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`

function Taking({ attempt }: { attempt: AttemptInProgress }) {
  const confirm = useConfirm()
  const storageKey = `lms.attempt.${attempt.id}`
  const [answers, setAnswers] = useState<Answers>(() => {
    try {
      const stored = sessionStorage.getItem(storageKey)
      if (stored) return JSON.parse(stored) as Answers
    } catch {
      /* fall through to whatever the server auto-saved */
    }
    return Object.fromEntries(attempt.draft_answers.map((a) => [a.question_id, { option_ids: a.option_ids ?? [], text: a.text ?? '' }]))
  })
  const left = useCountdown(attempt.deadline)
  const submitted = useRef(false)

  // The result screen only receives option numbers, so keep the wording seen while answering to show it afterwards.
  useEffect(() => {
    try {
      sessionStorage.setItem(SNAPSHOT_PREFIX + attempt.id, JSON.stringify(Object.fromEntries(attempt.questions.flatMap((q) => q.options.map((o) => [o.id, o.text])))))
    } catch {
      /* the result then shows how many options were chosen */
    }
  }, [attempt])

  useEffect(() => {
    try {
      sessionStorage.setItem(storageKey, JSON.stringify(answers))
    } catch {
      /* answers are simply not kept across a reload */
    }
  }, [answers, storageKey])

  const payload = useMemo(
    () =>
      attempt.questions.map((q) => {
        const a = answers[q.id]
        return q.type === 'short_answer' || q.type === 'essay' ? { question_id: q.id, text: a?.text ?? '' } : { question_id: q.id, option_ids: a?.option_ids ?? [] }
      }),
    [attempt.questions, answers],
  )
  const submit = useApiMutation(() => api.post<AttemptResult>(`/attempts/${attempt.id}/submit`, { answers: payload }), {
    invalidate: [['attempt', attempt.id], ['quiz'], ['grades']],
    onSuccess: () => {
      try {
        sessionStorage.removeItem(storageKey)
      } catch {
        /* nothing stored */
      }
    },
  })

  // Auto-saves periodically so an exam survives a crashed browser, a cleared cache, or switching devices - not just this tab's local storage.
  const saveDraft = useApiMutation(() => api.put<{ saved_at: string }>(`/attempts/${attempt.id}/draft`, { answers: payload }))
  const savedAt = saveDraft.data?.saved_at
  useEffect(() => {
    if (submit.isPending || submit.isSuccess) return
    const timer = setTimeout(() => saveDraft.mutate(), 2000)
    return () => clearTimeout(timer)
  }, [payload, submit, saveDraft])

  // When the time is up, send whatever has been answered; the server decides whether it still counts.
  useEffect(() => {
    if (left === 0 && !submitted.current && !submit.isPending && !submit.isSuccess) {
      submitted.current = true
      submit.mutate()
    }
  }, [left, submit])

  const setChoice = (qid: number, optionId: number, single: boolean, checked: boolean) =>
    setAnswers((current) => {
      const previous = current[qid]?.option_ids ?? []
      const next = single ? [optionId] : checked ? [...previous, optionId] : previous.filter((id) => id !== optionId)
      return { ...current, [qid]: { option_ids: next, text: current[qid]?.text ?? '' } }
    })
  const unanswered = attempt.questions.filter((q) => (q.type === 'short_answer' || q.type === 'essay' ? !(answers[q.id]?.text ?? '').trim() : !(answers[q.id]?.option_ids ?? []).length)).length

  return (
    <>
      <PageHeader
        title="Quiz attempt"
        subtitle={`Started ${formatDateTime(attempt.started_at)}`}
        actions={left !== null && <span className={`timer${left <= 60 ? ' timer-low' : ''}`} role="timer" aria-live="off">⏱ {clock(left)}</span>}
      />
      {left !== null && left <= 60 && left > 0 && <Alert tone="warn">Less than a minute left. Your answers will be sent automatically when the time is up.</Alert>}
      <p className="muted small">{saveDraft.isPending ? 'Saving…' : savedAt ? `Auto-saved ${formatDateTime(savedAt)}` : 'Your answers are auto-saved as you go.'}</p>
      <form
        onSubmit={async (event) => {
          event.preventDefault()
          const message = unanswered > 0 ? `${unanswered} question${unanswered === 1 ? ' is' : 's are'} unanswered.` : 'You cannot change your answers after submitting.'
          if (await confirm({ title: 'Submit your answers?', message, confirmLabel: 'Submit' })) submit.mutate()
        }}
      >
        {attempt.questions.map((q, index) => (
          <Card key={q.id}>
            <fieldset className="question-take">
              <legend>
                <span className="muted">Question {index + 1} · {q.points} point{q.points === 1 ? '' : 's'}</span>
                <strong className="block pre">{q.prompt}</strong>
              </legend>
              {q.type === 'short_answer' ? (
                <input type="text" aria-label={`Answer to question ${index + 1}`} maxLength={500} value={answers[q.id]?.text ?? ''} onChange={(e) => setAnswers((c) => ({ ...c, [q.id]: { option_ids: [], text: e.target.value } }))} />
              ) : q.type === 'essay' ? (
                <TextArea label={`Answer to question ${index + 1}`} rows={8} maxLength={10000} value={answers[q.id]?.text ?? ''} onChange={(e) => setAnswers((c) => ({ ...c, [q.id]: { option_ids: [], text: e.target.value } }))} />
              ) : (
                q.options.map((o) => {
                  const single = q.type !== 'multiple_choice'
                  const checked = (answers[q.id]?.option_ids ?? []).includes(o.id)
                  return (
                    <label key={o.id} className="choice">
                      <input type={single ? 'radio' : 'checkbox'} name={`q-${q.id}`} checked={checked} onChange={(e) => setChoice(q.id, o.id, single, e.target.checked)} />
                      {o.text}
                    </label>
                  )
                })
              )}
              {q.type === 'multiple_choice' && <p className="hint">Select every correct answer.</p>}
            </fieldset>
          </Card>
        ))}
        {submit.error && <FormError error={submit.error} />}
        <div className="form-actions">
          <Button type="submit" variant="primary" loading={submit.isPending}>
            Submit answers
          </Button>
        </div>
      </form>
    </>
  )
}

function Result({ attempt, quiz }: { attempt: AttemptResult; quiz?: Quiz }) {
  const questions = new Map((quiz?.questions ?? []).map((q) => [q.id, q]))
  const remembered = optionWording(attempt.id)
  const percent = attempt.max_score > 0 ? Math.round((Number(attempt.score) / Number(attempt.max_score)) * 100) : 0
  return (
    <>
      <PageHeader title="Quiz result" subtitle={`Submitted ${formatDateTime(attempt.submitted_at)}`} actions={<Link className="btn btn-secondary" to={`/quizzes/${attempt.quiz_id}`}>Back to the quiz</Link>} />
      <Card>
        <p className="big-score">
          {formatScore(attempt.score)} <span className="muted">/ {formatScore(attempt.max_score)}</span> <span className="muted">({percent}%)</span>
        </p>
        {attempt.awaiting_manual_grading && <Alert tone="info">One or more essay answers are still waiting to be marked by hand; the score above will rise once they are.</Alert>}
      </Card>
      <Card title="Your answers">
        <Table caption="Answers">
          <thead>
            <tr>
              <th>Question</th>
              <th>Answer</th>
              <th>Result</th>
            </tr>
          </thead>
          <tbody>
            {attempt.answers.map((a) => {
              const q = questions.get(a.question_id)
              const chosen = a.response.option_ids?.map((id) => q?.options.find((o) => o.id === id)?.text ?? remembered[id])
              const shown = chosen?.every(Boolean) ? chosen.join(', ') : `${chosen?.length ?? 0} option(s) selected`
              return (
                <tr key={a.question_id}>
                  <td className="pre">{a.prompt}</td>
                  <td>{a.response.text !== undefined ? a.response.text || <span className="muted">No answer</span> : chosen && chosen.length ? shown : <span className="muted">No answer</span>}</td>
                  <td>
                    {a.needs_manual_grading ? <Badge tone="warn">Awaiting grading</Badge> : a.is_correct ? <Badge tone="good">Correct</Badge> : <Badge tone="bad">Incorrect</Badge>}{' '}
                    <span className="muted small">
                      {formatScore(a.points)} / {formatScore(a.max_points)} pt
                    </span>
                    {a.needs_manual_grading && quiz && <EssayGradeForm attemptId={attempt.id} answerId={a.id} maxPoints={a.max_points} />}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </Table>
      </Card>
    </>
  )
}

/** A grader's inline form to mark one essay answer; the attempt's overall score updates once saved. */
function EssayGradeForm({ attemptId, answerId, maxPoints }: { attemptId: number; answerId: number; maxPoints: number }) {
  const [points, setPoints] = useState('')
  const grade = useApiMutation(() => api.patch(`/quiz-answers/${answerId}/grade`, { points: Number(points) }), {
    invalidate: [['attempt', attemptId]],
    success: 'Marked.',
  })
  return (
    <form
      className="inline-form"
      onSubmit={(event) => {
        event.preventDefault()
        grade.mutate()
      }}
    >
      <TextField label={`Points (out of ${maxPoints})`} type="number" min={0} max={maxPoints} step="0.01" value={points} onChange={(e) => setPoints(e.target.value)} error={fieldError(grade.error, 'points')} />
      <Button small type="submit" variant="primary" loading={grade.isPending} disabled={points === ''}>
        Mark
      </Button>
    </form>
  )
}
