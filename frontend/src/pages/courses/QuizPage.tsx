import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import type { AttemptInProgress, AttemptSummary, Module, Offering, Page, QuestionType, Quiz, QuizQuestion, StaffAttempt } from '../../api/types'
import { QUESTION_TYPE_LABELS } from '../../api/types'
import { Alert, Badge, Button, Card, EmptyState, ErrorState, FormError, Loading, Modal, PageHeader, Pager, PublishedBadge, QueryView, SelectField, Table, TextArea, TextField, pagerFromPage, useConfirm } from '../../components/ui'
import { formatDateTime, formatScore, isPast, plural } from '../../lib/format'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'
import { QuizDialog } from './QuizzesTab'

export function QuizPage() {
  const { id } = useParams()
  const quizId = Number(id)
  const quiz = useQuery({ queryKey: ['quiz', quizId], queryFn: () => api.get<Quiz>(`/quizzes/${quizId}`), retry: false })
  const offeringId = quiz.data?.course_offering_id
  const offering = useQuery({ queryKey: ['offering', offeringId], queryFn: () => api.get<Offering>(`/offerings/${offeringId}`), enabled: !!offeringId })
  useTitle(quiz.data?.title ?? 'Quiz')

  if (quiz.isPending) return <Loading />
  if (quiz.isError) {
    if (quiz.error instanceof ApiError && (quiz.error.status === 403 || quiz.error.status === 404)) return <Alert>This quiz is not available to you. It may not be published yet. <Link to="/courses">Back to courses</Link></Alert>
    return <ErrorState error={quiz.error} onRetry={() => void quiz.refetch()} />
  }
  const manage = quiz.data.questions !== undefined
  const course = offering.data?.course

  return (
    <>
      <p className="crumbs">
        <Link to={`/courses/${quiz.data.course_offering_id}/classwork`}>
          {course ? `${course.code} ${course.title}` : 'Course'}
        </Link>{' '}
        / Classwork
      </p>
      {manage ? <ManagerView quiz={quiz.data} modules={offering.data?.modules ?? []} /> : <StudentView quiz={quiz.data} />}
    </>
  )
}

function windowText(quiz: Quiz): { open: boolean; text: string } {
  if (quiz.opens_at && !isPast(quiz.opens_at)) return { open: false, text: `Opens ${formatDateTime(quiz.opens_at)}` }
  if (isPast(quiz.due_at)) return { open: false, text: `Closed ${formatDateTime(quiz.due_at)}` }
  return { open: true, text: `Open until ${formatDateTime(quiz.due_at)}` }
}

// ---- student ---------------------------------------------------------------------------------------------------
function StudentView({ quiz }: { quiz: Quiz }) {
  const navigate = useNavigate()
  const mine = useQuery({ queryKey: ['quiz', quiz.id, 'mine'], queryFn: () => api.get<AttemptSummary[]>(`/quizzes/${quiz.id}/my-attempts`) })
  const start = useApiMutation(() => api.post<AttemptInProgress>(`/quizzes/${quiz.id}/attempts`), {
    invalidate: [['quiz', quiz.id]],
    onSuccess: (attempt) => navigate(`/attempts/${attempt.id}`),
    toastError: true,
  })
  const w = windowText(quiz)
  const inProgress = mine.data?.find((a) => !a.submitted_at)
  const used = quiz.attempts_used ?? 0
  const left = Math.max(0, quiz.max_attempts - used)

  return (
    <>
      <PageHeader
        title={
          <>
            {quiz.title} {quiz.is_practice && <Badge tone="info">Practice</Badge>} {quiz.is_open_book && <Badge tone="good">Open book</Badge>}
          </>
        }
        subtitle={quiz.is_practice ? 'Ungraded — take it as many times as you like.' : w.text}
      />
      <Card>
        {quiz.instructions && <div className="reading pre">{quiz.instructions}</div>}
        <dl className="details">
          <dt>Questions</dt>
          <dd>{quiz.questions_per_attempt ? `${quiz.questions_per_attempt} of ${quiz.questions_count}, chosen at random` : quiz.questions_count}</dd>
          <dt>Total points</dt>
          <dd>{quiz.total_points}{quiz.questions_per_attempt && ' for the full pool — your attempt is worth less'}</dd>
          <dt>Time limit</dt>
          <dd>{quiz.time_limit_minutes ? `${quiz.time_limit_minutes} minutes once you start` : 'None'}</dd>
          <dt>Attempts</dt>
          <dd>
            {used} of {quiz.max_attempts} used
          </dd>
          {quiz.weight !== null && (
            <>
              <dt>Weight toward your final grade</dt>
              <dd>{quiz.weight}%</dd>
            </>
          )}
        </dl>
        {inProgress ? (
          <Button variant="primary" loading={start.isPending} onClick={() => start.mutate()}>
            Continue your attempt
          </Button>
        ) : left > 0 && w.open ? (
          <Button variant="primary" loading={start.isPending} onClick={() => start.mutate()}>
            {used === 0 ? 'Start the quiz' : 'Start another attempt'}
          </Button>
        ) : (
          <Alert tone="info">{left === 0 ? 'You have used all your attempts.' : w.text + '.'}</Alert>
        )}
        {start.error instanceof ApiError && <FormError error={start.error} />}
      </Card>
      <Card title="Your attempts">
        <QueryView query={mine} isEmpty={(a) => a.length === 0} empty={<EmptyState title="No attempts yet">Your scores will be listed here.</EmptyState>}>
          {(attempts) => (
            <Table caption="Your attempts">
              <thead>
                <tr>
                  <th>Started</th>
                  <th>Submitted</th>
                  <th>Score</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {attempts.map((a) => (
                  <tr key={a.id}>
                    <td>{formatDateTime(a.started_at)}</td>
                    <td>{a.submitted_at ? formatDateTime(a.submitted_at) : <Badge tone="warn">In progress</Badge>}</td>
                    <td>{a.submitted_at ? `${formatScore(a.score)} / ${formatScore(a.max_score)}` : '—'}</td>
                    <td>
                      <Link to={`/attempts/${a.id}`}>{a.submitted_at ? 'View' : 'Continue'}</Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </QueryView>
      </Card>
    </>
  )
}

// ---- manager ---------------------------------------------------------------------------------------------------
function ManagerView({ quiz, modules }: { quiz: Quiz; modules: Module[] }) {
  const navigate = useNavigate()
  const confirm = useConfirm()
  const [editing, setEditing] = useState(false)
  const [question, setQuestion] = useState<QuizQuestion | 'new' | null>(null)
  const [page, setPage] = useState(1)
  const questions = quiz.questions ?? []
  const totalPoints = questions.reduce((sum, q) => sum + q.points, 0)

  const attempts = useQuery({ queryKey: ['quiz', quiz.id, 'attempts', page], queryFn: () => api.get<Page<StaffAttempt>>(`/quizzes/${quiz.id}/attempts`, { page }), placeholderData: (previous) => previous })
  const remove = useApiMutation(() => api.delete(`/quizzes/${quiz.id}`), { invalidate: [['offering', quiz.course_offering_id]], success: 'Quiz deleted.', onSuccess: () => navigate(`/courses/${quiz.course_offering_id}/classwork`), toastError: true })
  const removeQuestion = useApiMutation((qid: number) => api.delete(`/questions/${qid}`), { invalidate: [['quiz', quiz.id]], success: 'Question deleted.', toastError: true })
  const hasAttempts = (attempts.data?.total ?? 0) > 0

  return (
    <>
      <PageHeader
        title={quiz.title}
        subtitle={windowText(quiz).text}
        actions={
          <>
            <PublishedBadge published={!!quiz.published} />
            <Button onClick={() => setEditing(true)}>Edit</Button>
            <Button
              variant="danger"
              onClick={async () => {
                if (await confirm({ title: 'Delete this quiz?', message: 'A quiz that has attempts cannot be deleted; unpublish it instead.', confirmLabel: 'Delete quiz', danger: true })) remove.mutate()
              }}
            >
              Delete
            </Button>
          </>
        }
      />
      <Card>
        {quiz.instructions && <div className="reading pre">{quiz.instructions}</div>}
        <dl className="details">
          <dt>Time limit</dt>
          <dd>{quiz.time_limit_minutes ? `${quiz.time_limit_minutes} minutes` : 'None'}</dd>
          <dt>Attempts allowed</dt>
          <dd>{quiz.max_attempts}</dd>
          <dt>Open book</dt>
          <dd>{quiz.is_open_book ? 'Yes' : 'No'}</dd>
          <dt>Weight toward the final grade</dt>
          <dd>{quiz.weight !== null ? `${quiz.weight}%` : <span className="muted">Not weighted</span>}</dd>
          <dt>Questions</dt>
          <dd>
            {questions.length} · {totalPoints} points
            {quiz.questions_per_attempt && ` · each attempt draws ${quiz.questions_per_attempt} at random`}
          </dd>
        </dl>
      </Card>

      <Card
        title="Questions"
        actions={
          <Button small variant="primary" onClick={() => setQuestion('new')} disabled={hasAttempts} title={hasAttempts ? 'Questions are locked once someone has attempted the quiz' : undefined}>
            Add a question
          </Button>
        }
      >
        {hasAttempts && <Alert tone="info">Questions are locked because students have attempted this quiz. Unpublish it instead of changing questions, so past scores stay meaningful.</Alert>}
        {questions.length === 0 ? (
          <EmptyState title="No questions yet">Add at least one question before publishing.</EmptyState>
        ) : (
          <ol className="questions">
            {questions.map((q) => (
              <li key={q.id}>
                <div className="question-head">
                  <strong>{q.prompt}</strong>
                  <span className="muted small">
                    {QUESTION_TYPE_LABELS[q.type]} · {plural(q.points, 'point')}
                  </span>
                </div>
                <ul className="options">
                  {q.options.map((o) => (
                    <li key={o.id} className={o.is_correct ? 'correct' : ''}>
                      {o.is_correct ? '✓ ' : '○ '}
                      {o.text}
                      {q.type === 'short_answer' && <span className="muted small"> (accepted answer)</span>}
                    </li>
                  ))}
                </ul>
                {!hasAttempts && (
                  <div className="form-actions left">
                    <Button small onClick={() => setQuestion(q)}>
                      Edit
                    </Button>
                    <Button small variant="danger" onClick={() => removeQuestion.mutate(q.id)}>
                      Delete
                    </Button>
                  </div>
                )}
              </li>
            ))}
          </ol>
        )}
      </Card>

      <Card title="Attempts">
        <QueryView query={attempts} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No attempts yet">Attempts appear here as students take the quiz.</EmptyState>}>
          {(data) => (
            <>
              <Table caption="Attempts">
                <thead>
                  <tr>
                    <th>Student</th>
                    <th>Started</th>
                    <th>Submitted</th>
                    <th>Score</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((a) => (
                    <tr key={a.id}>
                      <td>
                        {a.user?.name}
                        <span className="muted small block">{a.user?.email}</span>
                      </td>
                      <td>{formatDateTime(a.started_at)}</td>
                      <td>{a.submitted_at ? formatDateTime(a.submitted_at) : <Badge tone="warn">In progress</Badge>}</td>
                      <td>{a.submitted_at ? `${formatScore(a.score)} / ${formatScore(a.max_score)}` : '—'}</td>
                      <td>
                        {a.submitted_at && (
                          <Link to={`/attempts/${a.id}`} state={{ quiz }}>
                            Review
                          </Link>
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

      {editing && <QuizDialog offeringId={quiz.course_offering_id} modules={modules} quiz={quiz} onClose={() => setEditing(false)} />}
      {question && <QuestionDialog quiz={quiz} question={question === 'new' ? null : question} onClose={() => setQuestion(null)} />}
    </>
  )
}

interface DraftOption {
  text: string
  is_correct: boolean
}

function QuestionDialog({ quiz, question, onClose }: { quiz: Quiz; question: QuizQuestion | null; onClose: () => void }) {
  const editing = question !== null
  const [type, setType] = useState<QuestionType>(question?.type ?? 'single_choice')
  const [prompt, setPrompt] = useState(question?.prompt ?? '')
  const [points, setPoints] = useState(String(question?.points ?? 1))
  const [options, setOptions] = useState<DraftOption[]>(() =>
    question && question.type !== 'true_false' ? question.options.map((o) => ({ text: o.text, is_correct: !!o.is_correct })) : question?.type === 'true_false' ? [] : [{ text: '', is_correct: true }, { text: '', is_correct: false }],
  )
  const [correct, setCorrect] = useState(question?.type === 'true_false' ? !!question.options.find((o) => o.text === 'True')?.is_correct : true)

  const changeType = (next: QuestionType) => {
    setType(next)
    if (next === 'short_answer') setOptions((o) => (o.length ? [{ text: o[0].text, is_correct: true }] : [{ text: '', is_correct: true }]))
    else if (next === 'single_choice') setOptions((o) => (o.length >= 2 ? o.map((x, i) => ({ ...x, is_correct: i === o.findIndex((y) => y.is_correct) || (i === 0 && !o.some((y) => y.is_correct)) })) : [{ text: '', is_correct: true }, { text: '', is_correct: false }]))
    else if (next === 'multiple_choice') setOptions((o) => (o.length >= 2 ? o : [{ text: '', is_correct: true }, { text: '', is_correct: false }]))
  }
  const setOption = (index: number, changes: Partial<DraftOption>) => setOptions((rows) => rows.map((row, i) => (i === index ? { ...row, ...changes } : row)))
  const chooseSingle = (index: number) => setOptions((rows) => rows.map((row, i) => ({ ...row, is_correct: i === index })))

  const save = useApiMutation(
    () => {
      const base: Record<string, unknown> = { prompt: prompt.trim(), points: Number(points) || 1 }
      if (type === 'true_false') base.correct = correct
      else if (type !== 'essay') base.options = options.map((o) => ({ text: o.text.trim(), is_correct: type === 'short_answer' ? true : o.is_correct }))
      return editing ? api.patch(`/questions/${question.id}`, base) : api.post(`/quizzes/${quiz.id}/questions`, { ...base, type })
    },
    { invalidate: [['quiz', quiz.id]], success: editing ? 'Question saved.' : 'Question added.', onSuccess: onClose },
  )

  const filled = options.every((o) => o.text.trim())
  const valid =
    prompt.trim() !== '' &&
    (type === 'true_false' ||
      type === 'essay' ||
      (type === 'short_answer' ? options.length >= 1 && filled : options.length >= 2 && filled && (type === 'single_choice' ? options.filter((o) => o.is_correct).length === 1 : options.some((o) => o.is_correct))))

  return (
    <Modal title={editing ? 'Edit question' : 'Add a question'} onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {!editing && (
          <SelectField label="Type" value={type} onChange={(e) => changeType(e.target.value as QuestionType)}>
            {(Object.keys(QUESTION_TYPE_LABELS) as QuestionType[]).map((t) => (
              <option key={t} value={t}>
                {QUESTION_TYPE_LABELS[t]}
              </option>
            ))}
          </SelectField>
        )}
        <TextArea label="Question" rows={3} value={prompt} onChange={(e) => setPrompt(e.target.value)} error={fieldError(save.error, 'prompt')} required autoFocus />
        <TextField label="Points" type="number" min={1} max={1000} value={points} onChange={(e) => setPoints(e.target.value)} error={fieldError(save.error, 'points')} />
        {type === 'true_false' && (
          <fieldset className="field">
            <legend>Correct answer</legend>
            <div className="segmented">
              <label className={correct ? 'on' : ''}>
                <input type="radio" name="tf" checked={correct} onChange={() => setCorrect(true)} /> True
              </label>
              <label className={!correct ? 'on' : ''}>
                <input type="radio" name="tf" checked={!correct} onChange={() => setCorrect(false)} /> False
              </label>
            </div>
          </fieldset>
        )}
        {type !== 'true_false' && type !== 'essay' && (
          <fieldset className="field">
            <legend>{type === 'short_answer' ? 'Accepted answers (case and extra spaces are ignored)' : type === 'single_choice' ? 'Options: pick the one correct answer' : 'Options: tick every correct answer'}</legend>
            {options.map((o, i) => (
              <div key={i} className="option-edit">
                {type === 'single_choice' && <input type="radio" name="correct-option" aria-label={`Option ${i + 1} is correct`} checked={o.is_correct} onChange={() => chooseSingle(i)} />}
                {type === 'multiple_choice' && <input type="checkbox" aria-label={`Option ${i + 1} is correct`} checked={o.is_correct} onChange={(e) => setOption(i, { is_correct: e.target.checked })} />}
                <input type="text" aria-label={type === 'short_answer' ? `Accepted answer ${i + 1}` : `Option ${i + 1} text`} value={o.text} maxLength={500} onChange={(e) => setOption(i, { text: e.target.value })} />
                {options.length > (type === 'short_answer' ? 1 : 2) && (
                  <Button small variant="ghost" onClick={() => setOptions((rows) => rows.filter((_, j) => j !== i))}>
                    Remove
                  </Button>
                )}
              </div>
            ))}
            {options.length < 10 && (
              <Button small onClick={() => setOptions((rows) => [...rows, { text: '', is_correct: false }])}>
                {type === 'short_answer' ? 'Add another accepted answer' : 'Add an option'}
              </Button>
            )}
            {fieldError(save.error, 'options') && <div className="field-error">{fieldError(save.error, 'options')}</div>}
          </fieldset>
        )}
        {save.error && !['prompt', 'points', 'options'].some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!valid}>
            Save question
          </Button>
        </div>
      </form>
    </Modal>
  )
}
