import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { AiReply, AiStudentMode, AiTeachingMode, Announcement, Page } from '../../api/types'
import { useMe } from '../../auth/AuthContext'
import { Avatar, Button, Card, EmptyState, ErrorState, FormError, Loading, SelectField, TextArea, TextField } from '../../components/ui'
import { formatDateTime, relativeTime } from '../../lib/format'
import { fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

type FeedItem = { key: string; at: string; icon: string; kind: string; title: string; to?: string; body?: string; author?: string }

/** The course's landing tab: a reverse-chronological feed of what's new, Classroom-style. */
export function StreamTab() {
  const { offering, id, manage } = useOffering()
  const announcements = useQuery({ queryKey: ['announcements', id, 1], queryFn: () => api.get<Page<Announcement>>(`/offerings/${id}/announcements`, { page: 1 }) })

  const feed: FeedItem[] = [
    ...(announcements.data?.data.map((a) => ({ key: `an${a.id}`, at: a.created_at, icon: '📣', kind: 'Announcement', title: a.title, body: a.body, author: a.author?.name })) ?? []),
    ...(offering.assignments ?? [])
      .filter((a) => a.published && a.created_at)
      .map((a) => ({ key: `as${a.id}`, at: a.created_at as string, icon: '📝', kind: 'New assignment', title: a.title, to: `/assignments/${a.id}` })),
    ...(offering.quizzes ?? [])
      .filter((q) => q.published && q.created_at)
      .map((q) => ({ key: `qz${q.id}`, at: q.created_at as string, icon: '❓', kind: 'New quiz', title: q.title, to: `/quizzes/${q.id}` })),
  ].sort((a, b) => b.at.localeCompare(a.at))

  const withHours = (offering.teachers ?? []).filter((t) => t.consultation_hours)

  return (
    <>
      <AiAssistantCard offeringId={id} manage={manage} />
      {manage && <Composer offeringId={id} />}
      {withHours.length > 0 && (
        <Card title="Office hours">
          <ul className="plain-list">
            {withHours.map((t) => (
              <li key={t.id}>
                <strong>{t.user?.name}</strong>: {t.consultation_hours}
              </li>
            ))}
          </ul>
        </Card>
      )}
      {announcements.isPending && <Loading />}
      {announcements.isError && <ErrorState error={announcements.error} onRetry={() => void announcements.refetch()} />}
      {announcements.data && feed.length === 0 && (
        <Card>
          <EmptyState title="Nothing posted yet" icon="🎬">
            {manage ? 'Share an announcement, or publish content, an assignment or a quiz — it will show up here for your class.' : 'Announcements and new coursework from your teacher will appear here.'}
          </EmptyState>
        </Card>
      )}
      {feed.map((item) => (
        <Card key={item.key}>
          <div className="stream-item">
            <span className="stream-icon" aria-hidden="true">
              {item.icon}
            </span>
            <div className="grow">
              <div className="muted small">
                {item.kind}
                {item.author ? ` · ${item.author}` : ''} · <span title={formatDateTime(item.at)}>{relativeTime(item.at)}</span>
              </div>
              <strong>{item.to ? <Link to={item.to}>{item.title}</Link> : item.title}</strong>
              {item.body && <p className="pre">{item.body}</p>}
            </div>
          </div>
        </Card>
      ))}
      {offering.course?.description && feed.length === 0 && !announcements.isPending && (
        <Card title="About this course">
          <p className="muted">{offering.course.description}</p>
        </Card>
      )}
    </>
  )
}

/** A lightweight "share something with your class" box, posting straight to the same endpoint the Announcements tab uses. */
const STUDENT_MODES: { value: AiStudentMode; label: string; placeholder: string }[] = [
  { value: 'ask', label: 'Ask a question', placeholder: 'What is a variable?' },
  { value: 'summarise', label: 'Summarise a topic', placeholder: 'The topic or material to summarise' },
  { value: 'revision_questions', label: 'Revision questions', placeholder: 'The topic to generate questions on' },
  { value: 'flashcards', label: 'Flashcards', placeholder: 'The topic to make flashcards for' },
  { value: 'study_plan', label: 'Study plan', placeholder: 'What you need to prepare for, and by when' },
]

const TEACHING_MODES: { value: AiTeachingMode; label: string; placeholder: string }[] = [
  { value: 'lesson_outline', label: 'Lesson outline', placeholder: 'The topic to outline a lesson for' },
  { value: 'quiz_draft', label: 'Draft quiz questions', placeholder: 'The topic to draft questions on' },
  { value: 'rubric', label: 'Draft a rubric', placeholder: 'Describe the assessment to mark' },
  { value: 'discussion_questions', label: 'Discussion questions', placeholder: 'The topic to discuss' },
  { value: 'remedial_suggestions', label: 'Remedial suggestions', placeholder: 'What students are struggling with' },
]

/** The AI learning assistant (students) and teaching assistant (lecturers): grounded in the course's own published material. */
function AiAssistantCard({ offeringId, manage }: { offeringId: number; manage: boolean }) {
  const modes = manage ? TEACHING_MODES : STUDENT_MODES
  const [mode, setMode] = useState(modes[0].value)
  const [prompt, setPrompt] = useState('')
  const ask = useApiMutation(() => api.post<AiReply>(`/offerings/${offeringId}/ai/${manage ? 'teaching-assist' : 'assist'}`, { mode, prompt: prompt.trim() }))
  const current = modes.find((m) => m.value === mode) ?? modes[0]

  return (
    <Card title={manage ? 'Teaching assistant' : 'Study assistant'}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          ask.mutate()
        }}
      >
        <SelectField label="What do you need?" value={mode} onChange={(e) => setMode(e.target.value as typeof mode)}>
          {modes.map((m) => (
            <option key={m.value} value={m.value}>
              {m.label}
            </option>
          ))}
        </SelectField>
        <TextArea label={current.label} rows={2} value={prompt} onChange={(e) => setPrompt(e.target.value)} placeholder={current.placeholder} error={fieldError(ask.error, 'prompt')} required />
        {ask.error && !fieldError(ask.error, 'prompt') && <FormError error={ask.error} />}
        <Button type="submit" variant="primary" loading={ask.isPending} disabled={!prompt.trim()}>
          {manage ? 'Draft it' : 'Ask'}
        </Button>
      </form>
      {ask.data && (
        <div className="reading pre">
          {ask.data.answer}
          {ask.data.sources.length > 0 && (
            <p className="muted small">
              <strong>Sources:</strong> {ask.data.sources.map((s) => s.title).join(', ')}
            </p>
          )}
        </div>
      )}
      {!manage && <p className="muted small">Answers are restricted to this course's own published material, and never complete a graded assignment or quiz for you.</p>}
    </Card>
  )
}

function Composer({ offeringId }: { offeringId: number }) {
  const me = useMe()
  const [open, setOpen] = useState(false)
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const save = useApiMutation(() => api.post(`/offerings/${offeringId}/announcements`, { title: title.trim(), body }), {
    invalidate: [['announcements', offeringId]],
    success: 'Shared with your class.',
    onSuccess: () => {
      setTitle('')
      setBody('')
      setOpen(false)
    },
  })

  if (!open) {
    return (
      <Card>
        <button type="button" className="stream-composer-trigger" onClick={() => setOpen(true)}>
          <Avatar name={me.name} /> <span className="muted">Share something with your class…</span>
        </button>
      </Card>
    )
  }

  return (
    <Card title="Share with your class">
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <TextArea label="Message" rows={4} value={body} onChange={(e) => setBody(e.target.value)} error={fieldError(save.error, 'body')} required />
        <div className="form-actions">
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim() || !body.trim()}>
            Post
          </Button>
        </div>
      </form>
    </Card>
  )
}
