import { apiLogin, call, future } from './lib.mjs'

/**
 * Fills the seeded course with realistic content through the API (faster than clicking, and the content itself is not what the
 * tests check): a module with a reading, a link and a file; an assignment with a rubric; a quiz with all four question types; a
 * class; an announcement and a discussion; two submissions (one graded and appealed); and a message with an attachment.
 * Returns the ids the tests need, since they differ on every run.
 */
export async function setupData(password, offering) {
  const lect = await apiLogin('e2e-lect@example.test', password)
  const ada = await apiLogin('e2e-stu1@example.test', password)
  const ben = await apiLogin('e2e-stu2@example.test', password)

  const mod = await call(lect, 'POST', `/offerings/${offering}/modules`, { title: 'Week 1: Getting started', published: true })
  await call(lect, 'POST', `/modules/${mod.id}/items`, { title: 'Welcome reading', type: 'text', body: 'Welcome to the course.', published: true })
  await call(lect, 'POST', `/modules/${mod.id}/items`, { title: 'Course syllabus', type: 'link', body: 'https://example.org/syllabus', published: true })
  const file = new FormData()
  file.append('title', 'Lecture notes')
  file.append('type', 'file')
  file.append('published', '1')
  file.append('file', new Blob(['LECTURE NOTES CONTENT\nline two\n'], { type: 'text/plain' }), 'notes.txt')
  await call(lect, 'POST', `/modules/${mod.id}/items`, file)

  const assignment = await call(lect, 'POST', `/offerings/${offering}/assignments`, { title: 'Essay 1: Testing', instructions: 'Write about why testing matters.', due_at: future(5), max_score: 20, published: true })
  await call(lect, 'PUT', `/assignments/${assignment.id}/rubric`, {
    criteria: [
      { title: 'Argument', max_points: 12, description: 'Is the case convincing?', levels: [{ title: 'Strong', points: 12, description: 'Clear thesis with evidence' }, { title: 'Weak', points: 4 }] },
      { title: 'Style', max_points: 8 },
    ],
  })

  const quiz = await call(lect, 'POST', `/offerings/${offering}/quizzes`, { title: 'Quiz 1: Basics', due_at: future(6), time_limit_minutes: 10, max_attempts: 2, published: true })
  await call(lect, 'POST', `/quizzes/${quiz.id}/questions`, { type: 'single_choice', prompt: 'Which tool runs PHP tests here?', points: 1, options: [{ text: 'PHPUnit', is_correct: true }, { text: 'Jasmine' }] })
  await call(lect, 'POST', `/quizzes/${quiz.id}/questions`, { type: 'multiple_choice', prompt: 'Select the testing tools.', points: 2, options: [{ text: 'Vitest', is_correct: true }, { text: 'Hammer' }, { text: 'PHPUnit', is_correct: true }] })
  await call(lect, 'POST', `/quizzes/${quiz.id}/questions`, { type: 'true_false', prompt: 'PHP is compiled ahead of time.', points: 1, correct: false })
  await call(lect, 'POST', `/quizzes/${quiz.id}/questions`, { type: 'short_answer', prompt: 'Name the JS test runner used here.', points: 1, options: [{ text: 'vitest' }] })

  // A class that started 20 minutes ago, with self check-in open for an hour.
  const session = await call(lect, 'POST', `/offerings/${offering}/sessions`, { title: 'Lecture 1', starts_at: new Date(Date.now() - 20 * 60000).toISOString(), ends_at: future(0.05), location: 'Room 204', join_url: 'https://meet.example.org/abc' })
  await call(lect, 'POST', `/sessions/${session.id}/checkin/open`, { minutes: 60 })
  await call(lect, 'POST', `/offerings/${offering}/announcements`, { title: 'Welcome to E2E101', body: 'Classes start on Monday.' })
  await call(lect, 'POST', `/offerings/${offering}/discussions`, { title: 'Question about the quiz', body: 'Can we retake it?' })

  // Ada submits with a file, is graded and appeals. Ben submits and is left ungraded.
  const adaForm = new FormData()
  adaForm.append('body', 'Testing matters because it catches regressions.')
  adaForm.append('file', new Blob(['ADA ESSAY FILE CONTENT'], { type: 'text/plain' }), 'ada-essay.txt')
  const adaSubmission = await call(ada, 'POST', `/assignments/${assignment.id}/submissions`, adaForm)
  const benForm = new FormData()
  benForm.append('body', 'A short answer from Ben.')
  await call(ben, 'POST', `/assignments/${assignment.id}/submissions`, benForm)
  const rubric = await call(lect, 'GET', `/assignments/${assignment.id}/rubric`)
  await call(lect, 'POST', `/submissions/${adaSubmission.id}/grades`, {
    status: 'published',
    feedback: 'Clear argument. Work on style.',
    criteria: [{ criterion_id: rubric[0].id, level_id: rubric[0].levels[0].id }, { criterion_id: rubric[1].id, points: 5 }],
  })
  const appeal = await call(ada, 'POST', `/submissions/${adaSubmission.id}/appeal`, { reason: 'I believe the style criterion deserved more points.' })

  const me = await call(lect, 'GET', '/me')
  const message = new FormData()
  message.append('body', 'Hello Dr Lena, a question about style.')
  message.append('attachments[]', new Blob(['MESSAGE ATTACHMENT CONTENT'], { type: 'text/plain' }), 'question.txt')
  await call(ada, 'POST', `/messages/${me.id}`, message)

  return { offering, assignment: assignment.id, quiz: quiz.id, session: session.id, appeal: appeal.id, lecturerId: me.id }
}
