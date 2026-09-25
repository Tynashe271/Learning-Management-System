import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { Announcement, Page } from '../../api/types'
import { useMe } from '../../auth/AuthContext'
import { Avatar, Button, Card, EmptyState, ErrorState, Loading, TextArea, TextField } from '../../components/ui'
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
