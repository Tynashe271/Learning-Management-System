import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokenStore } from '../src/api/client'
import type { Assignment, RubricCriterion, Submission } from '../src/api/types'
import type { Quiz } from '../src/api/types'
import { ImportGradesDialog } from '../src/pages/courses/AssignmentPage'
import { AttemptPage } from '../src/pages/courses/AttemptPage'
import { GradeDialog } from '../src/pages/courses/GradeDialog'
import { QuizPage } from '../src/pages/courses/QuizPage'
import { RubricEditor } from '../src/pages/courses/Rubric'
import { mockApi, renderApp } from './helpers'

beforeEach(() => {
  window.__LMS_CONFIG__ = { apiUrl: 'http://api.test/api' }
  tokenStore.set('t', false)
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

const assignment: Assignment = { id: 5, course_offering_id: 1, title: 'Essay', instructions: null, due_at: '2030-01-01T00:00:00Z', max_score: 20, weight: null, published: true, allow_late_submissions: false, allow_resubmission: false, is_group_assignment: false, peer_reviews_per_student: 0 }
const submission: Submission = { id: 9, assignment_id: 5, user_id: 4, body: 'My answer', storage_path: null, submitted_at: '2026-01-01T00:00:00Z', late: false, late_explanation: null, used_ai: false, ai_use_description: null, version: 1 }
const rubric: RubricCriterion[] = [
  { id: 1, title: 'Argument', description: null, max_points: 12, levels: [{ id: 11, title: 'Strong', description: 'Clear', points: 12 }, { id: 12, title: 'Weak', description: null, points: 4 }] },
  { id: 2, title: 'Style', description: null, max_points: 8, levels: [] },
]

describe('rubric editor', () => {
  it('only allows saving when the criteria add up to the assignment’s points', async () => {
    const { calls } = mockApi({ 'PUT /assignments/5/rubric': () => ({ body: [] }) })
    const user = userEvent.setup()
    renderApp(<RubricEditor assignmentId={5} maxScore={20} criteria={[]} onClose={() => undefined} />)
    await user.type(screen.getByLabelText('Title'), 'Argument')
    const save = screen.getByRole('button', { name: 'Save rubric' })
    expect(screen.getByText(/Criteria add up to 10 of 20 points/)).toBeInTheDocument()
    expect(save).toBeDisabled()
    const points = screen.getAllByLabelText('Points')[0]
    await user.clear(points)
    await user.type(points, '20')
    expect(screen.getByText(/Criteria add up to 20 of 20 points\./)).toBeInTheDocument()
    expect(save).toBeEnabled()
    await user.click(save)
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')).toBeTruthy())
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ criteria: [{ title: 'Argument', description: null, max_points: 20 }] })
  })
})

describe('grading', () => {
  it('works out the total from the chosen levels and points, and sends one mark per criterion', async () => {
    const { calls } = mockApi({ 'POST /submissions/9/grades': () => ({ status: 201, body: { id: 1 } }) })
    const user = userEvent.setup()
    renderApp(<GradeDialog assignment={assignment} submission={submission} rubric={rubric} studentName="Ada" onClose={() => undefined} />)
    expect(screen.getByText('My answer')).toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText('Level'), '11')
    expect(screen.getByRole('button', { name: 'Publish grade' })).toBeDisabled() // Style still has no points
    await user.type(screen.getByLabelText('Points'), '5')
    expect(screen.getByText('Total: 17 / 20')).toBeInTheDocument()
    await user.type(screen.getByLabelText(/Feedback for the student/), 'Well argued.')
    await user.click(screen.getByRole('button', { name: 'Publish grade' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST')).toBe(true))
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({
      status: 'published',
      feedback: 'Well argued.',
      criteria: [
        { criterion_id: 1, level_id: 11, comment: null },
        { criterion_id: 2, points: 5, comment: null },
      ],
    })
  })

  it('needs a reason before an existing grade can be changed, and can save a draft instead of publishing', async () => {
    const graded: Submission = { ...submission, grade_records: [{ id: 3, submission_id: 9, graded_by: 2, score: 10, status: 'published', feedback: null, feedback_recording_path: null, change_reason: null, criteria_scores: null, created_at: '2026-01-02T00:00:00Z' }] }
    const { calls } = mockApi({ 'POST /submissions/9/grades': () => ({ status: 201, body: { id: 4 } }) })
    const user = userEvent.setup()
    renderApp(<GradeDialog assignment={assignment} submission={graded} rubric={[]} studentName="Ada" onClose={() => undefined} />)
    const score = screen.getByLabelText(/Score/)
    expect(score).toHaveValue(10)
    await user.clear(score)
    await user.type(score, '15')
    const publish = screen.getByRole('button', { name: 'Publish grade' })
    expect(publish).toBeDisabled()
    await user.type(screen.getByLabelText('Reason for changing the grade'), 'Second marker agreed')
    await user.click(screen.getByLabelText(/Publish now/))
    await user.click(screen.getByRole('button', { name: 'Save draft' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST')).toBe(true))
    expect(calls.find((c) => c.method === 'POST')?.body).toMatchObject({ status: 'draft', score: 15, change_reason: 'Second marker agreed' })
  })

  it('refuses a mark above the assignment’s maximum', async () => {
    mockApi({})
    const user = userEvent.setup()
    renderApp(<GradeDialog assignment={assignment} submission={submission} rubric={[]} studentName="Ada" onClose={() => undefined} />)
    await user.type(screen.getByLabelText(/Score/), '25')
    expect(screen.getByRole('button', { name: 'Publish grade' })).toBeDisabled()
  })
})

describe('offline marking import', () => {
  it('previews row errors with a dry run, then commits on confirmation', async () => {
    const { calls } = mockApi({
      'POST /assignments/5/grades/import': (req) => {
        const dryRun = req.body instanceof FormData && req.body.get('dry_run') === '1'
        return { body: dryRun ? { dry_run: true, created: 0, would_create: 1, errors: [{ line: 3, submission_id: '9999', errors: ['There is no submission with this id on this assignment.'] }] } : { dry_run: false, created: 1, would_create: 1, errors: [] } }
      },
    })
    const user = userEvent.setup()
    renderApp(<ImportGradesDialog assignment={assignment} onClose={() => undefined} />)
    const file = new File(['submission_id,score,feedback\n9,18,Nice\n9999,10,\n'], 'grades.csv', { type: 'text/csv' })
    await user.upload(screen.getByLabelText('Spreadsheet'), file)
    await user.click(screen.getByRole('button', { name: 'Check the file' }))
    await waitFor(() => expect(screen.getByText(/1 grade ready to import/)).toBeInTheDocument())
    expect(screen.getByText('There is no submission with this id on this assignment.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /Import 1 grade/ }))
    await waitFor(() => expect(calls.filter((c) => c.method === 'POST')).toHaveLength(2))
  })
})

describe('taking a quiz', () => {
  const attempt = {
    id: 3,
    quiz_id: 1,
    started_at: '2026-01-01T00:00:00Z',
    deadline: null,
    draft_answers: [],
    questions: [
      { id: 10, type: 'single_choice', prompt: 'Pick one', points: 1, options: [{ id: 100, text: 'Red' }, { id: 101, text: 'Blue' }] },
      { id: 11, type: 'multiple_choice', prompt: 'Pick many', points: 2, options: [{ id: 110, text: 'A' }, { id: 111, text: 'B' }] },
      { id: 12, type: 'short_answer', prompt: 'Say it', points: 1, options: [] },
    ],
  }

  function renderAttempt() {
    return renderApp(
      <Routes>
        <Route path="/attempts/:id" element={<AttemptPage />} />
      </Routes>,
      '/attempts/3',
    )
  }

  it('sends each answer in the shape the server expects, after a confirmation', async () => {
    const { calls } = mockApi({
      'GET /attempts/3': () => ({ body: attempt }),
      'POST /attempts/3/submit': () => ({ body: { id: 3, quiz_id: 1, started_at: attempt.started_at, submitted_at: '2026-01-01T00:05:00Z', score: 3, max_score: 4, answers: [] } }),
    })
    const user = userEvent.setup()
    renderAttempt()
    await user.click(await screen.findByLabelText('Blue'))
    await user.click(screen.getByLabelText('A'))
    await user.click(screen.getByLabelText('B'))
    await user.click(screen.getByLabelText('A')) // untick
    await user.type(screen.getByLabelText('Answer to question 3'), 'hello')
    await user.click(screen.getByRole('button', { name: 'Submit answers' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Submit' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/attempts/3/submit')).toBe(true))
    expect(calls.find((c) => c.path === '/attempts/3/submit')?.body).toEqual({
      answers: [
        { question_id: 10, option_ids: [101] },
        { question_id: 11, option_ids: [111] },
        { question_id: 12, text: 'hello' },
      ],
    })
  })

  it('warns how many questions are unanswered before submitting', async () => {
    mockApi({ 'GET /attempts/3': () => ({ body: attempt }) })
    const user = userEvent.setup()
    renderAttempt()
    await user.click(await screen.findByRole('button', { name: 'Submit answers' }))
    expect(await screen.findByText('3 questions are unanswered.')).toBeInTheDocument()
  })

  it('counts down and sends the answers by itself when the time is up', async () => {
    const deadline = new Date(Date.now() + 1500).toISOString()
    const { calls } = mockApi({
      'GET /attempts/3': () => ({ body: { ...attempt, deadline } }),
      'POST /attempts/3/submit': () => ({ status: 422, body: { message: 'x', errors: { attempt: ['The time limit has passed.'] } } }),
    })
    renderAttempt()
    expect(await screen.findByRole('timer')).toBeInTheDocument()
    await waitFor(() => expect(calls.some((c) => c.path === '/attempts/3/submit')).toBe(true), { timeout: 5000 })
  })

  it('shows a submitted attempt as a result, with the wording of the options that were chosen', async () => {
    sessionStorage.setItem('lms.attempt.options.3', JSON.stringify({ 101: 'Blue' }))
    mockApi({
      'GET /attempts/3': () => ({
        body: {
          id: 3,
          quiz_id: 1,
          started_at: attempt.started_at,
          submitted_at: '2026-01-01T00:05:00Z',
          score: 1,
          max_score: 4,
          answers: [
            { question_id: 10, prompt: 'Pick one', response: { option_ids: [101] }, is_correct: true, points: 1 },
            { question_id: 12, prompt: 'Say it', response: { text: '' }, is_correct: false, points: 0 },
          ],
        },
      }),
    })
    renderAttempt()
    expect(await screen.findByText('Quiz result')).toBeInTheDocument()
    expect(document.querySelector('.big-score')?.textContent).toMatch(/1\s*\/\s*4/)
    expect(screen.getByText('Blue')).toBeInTheDocument()
    expect(screen.getByText('Correct')).toBeInTheDocument()
    expect(screen.getByText('Incorrect')).toBeInTheDocument()
    expect(screen.getByText('No answer')).toBeInTheDocument()
  })
})

describe('a quiz’s question bank', () => {
  const quiz: Quiz = {
    id: 1, course_offering_id: 7, title: 'Week 1 quiz', instructions: null, opens_at: null, due_at: '2030-01-01T00:00:00Z',
    time_limit_minutes: null, max_attempts: 1, questions_per_attempt: null, is_open_book: false, weight: null, published: false,
    questions: [{ id: 10, type: 'essay', prompt: 'Discuss photosynthesis', points: 5, position: 1, options: [] }],
  }

  function renderQuiz() {
    return renderApp(
      <Routes>
        <Route path="/quizzes/:id" element={<QuizPage />} />
      </Routes>,
      '/quizzes/1',
    )
  }

  const shared = {
    'GET /quizzes/1': () => ({ body: quiz }),
    'GET /offerings/7': () => ({ body: { id: 7, course: { id: 1, code: 'BIO101', title: 'Biology' } } }),
    'GET /quizzes/1/attempts': () => ({ body: { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null } }),
  }

  it('saves an authored question to the lecturer’s own question bank', async () => {
    const { calls } = mockApi({ ...shared, 'POST /questions/10/save-to-bank': () => ({ status: 201, body: { id: 50 } }) })
    const user = userEvent.setup()
    renderQuiz()
    await user.click(await screen.findByRole('button', { name: 'Save to bank' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/questions/10/save-to-bank')).toBe(true))
  })

  it('inserts chosen bank items into the quiz', async () => {
    const { calls } = mockApi({
      ...shared,
      'GET /question-bank': () => ({
        body: { data: [{ id: 99, owner_id: 2, course_offering_id: null, type: 'single_choice', prompt: 'Capital of France?', points: 1, tags: ['geography'], options: [], created_at: '2026-01-01T00:00:00Z' }], current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1 },
      }),
      'POST /quizzes/1/questions/from-bank': () => ({ status: 201, body: [{ id: 11, type: 'single_choice', prompt: 'Capital of France?', points: 1, position: 2, options: [] }] }),
    })
    const user = userEvent.setup()
    renderQuiz()
    await user.click(await screen.findByRole('button', { name: 'From question bank' }))
    await screen.findByText('Capital of France?')
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: /Add 1 question/ }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/quizzes/1/questions/from-bank')).toBe(true))
    expect(calls.find((c) => c.path === '/quizzes/1/questions/from-bank')?.body).toEqual({ bank_item_ids: [99] })
  })
})
