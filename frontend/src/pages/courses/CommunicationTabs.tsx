import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import type { Announcement, Offering, Page, Post, StudyGroup, Thread } from '../../api/types'
import { useMe } from '../../auth/AuthContext'
import { Alert, Avatar, Badge, Button, Card, EmptyState, ErrorState, FormError, Loading, Modal, PageHeader, Pager, QueryView, TextArea, TextField, pagerFromPage, useConfirm } from '../../components/ui'
import { formatDateTime, plural, relativeTime } from '../../lib/format'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'
import { useOffering } from './context'

export function AnnouncementsTab() {
  const { id, manage } = useOffering()
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const query = useQuery({ queryKey: ['announcements', id, page], queryFn: () => api.get<Page<Announcement>>(`/offerings/${id}/announcements`, { page }), placeholderData: (previous) => previous })
  const remove = useApiMutation((announcementId: number) => api.delete(`/announcements/${announcementId}`), { invalidate: [['announcements', id]], success: 'Announcement deleted.', toastError: true })

  return (
    <>
      {manage && (
        <p>
          <Button variant="primary" onClick={() => setCreating(true)}>
            New announcement
          </Button>
        </p>
      )}
      <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<Card><EmptyState title="No announcements">{manage ? 'Post one to notify every enrolled student.' : 'Announcements from your teacher will appear here.'}</EmptyState></Card>}>
        {(data) => (
          <>
            {data.data.map((a) => (
              <Card
                key={a.id}
                title={a.title}
                actions={
                  manage && (
                    <Button
                      small
                      variant="danger"
                      onClick={async () => {
                        if (await confirm({ title: 'Delete this announcement?', confirmLabel: 'Delete', danger: true })) remove.mutate(a.id)
                      }}
                    >
                      Delete
                    </Button>
                  )
                }
              >
                <p className="muted small">
                  {a.author?.name} · {formatDateTime(a.created_at)}
                </p>
                <div className="pre">{a.body}</div>
              </Card>
            ))}
            <Pager {...pagerFromPage(data)} onPage={setPage} />
          </>
        )}
      </QueryView>
      {creating && <AnnouncementDialog offering={id} onClose={() => setCreating(false)} />}
    </>
  )
}

function AnnouncementDialog({ offering, onClose }: { offering: number; onClose: () => void }) {
  const { offering: o } = useOffering()
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const save = useApiMutation(() => api.post(`/offerings/${offering}/announcements`, { title: title.trim(), body }), {
    invalidate: [['announcements', offering]],
    success: o.published ? 'Announcement posted. Enrolled students have been notified.' : 'Announcement posted.',
    onSuccess: onClose,
  })
  return (
    <Modal title="New announcement" onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <TextArea label="Message" rows={8} value={body} onChange={(e) => setBody(e.target.value)} error={fieldError(save.error, 'body')} required />
        {!o.published && <Alert tone="info">This course is not published, so students will not be notified until it is.</Alert>}
        {save.error && !fieldError(save.error, 'title') && !fieldError(save.error, 'body') && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim() || !body.trim()}>
            Post
          </Button>
        </div>
      </form>
    </Modal>
  )
}

export function DiscussionsTab() {
  const { id } = useOffering()
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const query = useQuery({ queryKey: ['discussions', id, page], queryFn: () => api.get<Page<Thread>>(`/offerings/${id}/discussions`, { page }), placeholderData: (previous) => previous })

  return (
    <>
      <p>
        <Button variant="primary" onClick={() => setCreating(true)}>
          Start a discussion
        </Button>
      </p>
      <Card>
        <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No discussions yet">Ask a question or start a conversation with your class.</EmptyState>}>
          {(data) => (
            <>
              <ul className="list threads">
                {data.data.map((t) => (
                  <li key={t.id}>
                    <Avatar name={t.author?.name ?? '?'} />
                    <div className="grow">
                      <Link to={`/discussions/${t.id}`}>
                        <strong>{t.title}</strong>
                      </Link>
                      <span className="muted small block">
                        {t.author?.name} · {plural(t.posts_count ?? 0, 'reply', 'replies')} · active {relativeTime(t.updated_at)}
                      </span>
                    </div>
                  </li>
                ))}
              </ul>
              <Pager {...pagerFromPage(data)} onPage={setPage} />
            </>
          )}
        </QueryView>
      </Card>
      {creating && <ThreadDialog offeringId={id} onClose={() => setCreating(false)} onCreated={(thread) => navigate(`/discussions/${thread.id}`)} />}
      <StudyGroupsCard offeringId={id} />
    </>
  )
}

/** Student-organised study groups: any enrolled student can start or join one, unlike a lecturer's assignment groups. */
function StudyGroupsCard({ offeringId }: { offeringId: number }) {
  const { manage } = useOffering()
  const me = useMe()
  const confirm = useConfirm()
  const [creating, setCreating] = useState(false)
  const query = useQuery({ queryKey: ['study-groups', offeringId], queryFn: () => api.get<StudyGroup[]>(`/offerings/${offeringId}/study-groups`) })
  const join = useApiMutation((groupId: number) => api.post(`/study-groups/${groupId}/join`), { invalidate: [['study-groups', offeringId]], toastError: true })
  const leave = useApiMutation((groupId: number) => api.post(`/study-groups/${groupId}/leave`), { invalidate: [['study-groups', offeringId]], toastError: true })
  const remove = useApiMutation((groupId: number) => api.delete(`/study-groups/${groupId}`), { invalidate: [['study-groups', offeringId]], toastError: true })

  return (
    <Card
      title="Study groups"
      actions={
        !manage && (
          <Button small onClick={() => setCreating(true)}>
            Start a group
          </Button>
        )
      }
    >
      <QueryView query={query} isEmpty={(g) => g.length === 0} empty={<EmptyState title="No study groups yet">Students can start one to work together.</EmptyState>}>
        {(groups) => (
          <ul className="list">
            {groups.map((g) => (
              <li key={g.id}>
                <span className="grow">
                  <strong>{g.name}</strong> {g.my_member && <Badge tone="good">You're in</Badge>}
                  <span className="muted small block">
                    {g.description} {g.description && '· '}
                    {g.members.map((m) => m.name).join(', ')}
                    {g.max_members && ` (${g.members.length}/${g.max_members})`}
                  </span>
                </span>
                {!manage && !g.my_member && (
                  <Button small variant="primary" loading={join.isPending && join.variables === g.id} onClick={() => join.mutate(g.id)}>
                    Join
                  </Button>
                )}
                {!manage && g.my_member && (
                  <Button small loading={leave.isPending && leave.variables === g.id} onClick={() => leave.mutate(g.id)}>
                    Leave
                  </Button>
                )}
                {(manage || g.creator.id === me?.id) && (
                  <Button
                    small
                    variant="danger"
                    loading={remove.isPending && remove.variables === g.id}
                    onClick={async () => {
                      if (await confirm({ title: `Delete ${g.name}?`, confirmLabel: 'Delete', danger: true })) remove.mutate(g.id)
                    }}
                  >
                    Delete
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </QueryView>
      {creating && <StudyGroupDialog offeringId={offeringId} onClose={() => setCreating(false)} />}
    </Card>
  )
}

function StudyGroupDialog({ offeringId, onClose }: { offeringId: number; onClose: () => void }) {
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [maxMembers, setMaxMembers] = useState('')
  const save = useApiMutation(
    () => api.post(`/offerings/${offeringId}/study-groups`, { name: name.trim(), description: description.trim() || null, max_members: maxMembers ? Number(maxMembers) : null }),
    { invalidate: [['study-groups', offeringId]], success: 'Group created.', onSuccess: onClose },
  )

  return (
    <Modal title="Start a study group" onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} error={fieldError(save.error, 'name')} maxLength={255} autoFocus required />
        <TextArea label="Description" optional rows={2} value={description} onChange={(e) => setDescription(e.target.value)} error={fieldError(save.error, 'description')} />
        <TextField label="Maximum members" optional type="number" min={2} max={50} value={maxMembers} onChange={(e) => setMaxMembers(e.target.value)} error={fieldError(save.error, 'max_members')} />
        {save.error && !['name', 'description', 'max_members'].some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!name.trim()}>
            Create
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function ThreadDialog({ offeringId, onClose, onCreated }: { offeringId: number; onClose: () => void; onCreated: (thread: Thread) => void }) {
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const save = useApiMutation(() => api.post<Thread>(`/offerings/${offeringId}/discussions`, { title: title.trim(), body }), { invalidate: [['discussions', offeringId]], onSuccess: onCreated })
  return (
    <Modal title="Start a discussion" onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <TextArea label="Your post" rows={6} value={body} onChange={(e) => setBody(e.target.value)} maxLength={10000} error={fieldError(save.error, 'body')} required />
        {save.error && !fieldError(save.error, 'title') && !fieldError(save.error, 'body') && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim() || !body.trim()}>
            Post
          </Button>
        </div>
      </form>
    </Modal>
  )
}

export function DiscussionPage() {
  const { id } = useParams()
  const threadId = Number(id)
  const me = useMe()
  const navigate = useNavigate()
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [reply, setReply] = useState('')

  const query = useQuery({ queryKey: ['discussion', threadId, page], queryFn: () => api.get<{ thread: Thread; posts: Page<Post> }>(`/discussions/${threadId}`, { page }), retry: false, placeholderData: (previous) => previous })
  const offeringId = query.data?.thread.course_offering_id
  const offering = useQuery({ queryKey: ['offering', offeringId], queryFn: () => api.get<Offering>(`/offerings/${offeringId}`), enabled: !!offeringId })
  const moderate = offering.data?.abilities?.manage ?? false
  useTitle(query.data?.thread.title ?? 'Discussion')

  const post = useApiMutation(() => api.post(`/discussions/${threadId}/posts`, { body: reply }), {
    invalidate: [['discussion', threadId], ['discussions']],
    onSuccess: () => {
      setReply('')
      if (query.data) setPage(query.data.posts.last_page)
    },
  })
  const removeThread = useApiMutation(() => api.delete(`/discussions/${threadId}`), { invalidate: [['discussions']], success: 'Discussion deleted.', onSuccess: () => navigate(`/courses/${offeringId}/discussions`), toastError: true })
  const removePost = useApiMutation((postId: number) => api.delete(`/posts/${postId}`), { invalidate: [['discussion', threadId], ['discussions']], success: 'Reply deleted.', toastError: true })

  if (query.isPending) return <Loading />
  if (query.isError) {
    if (query.error instanceof ApiError && (query.error.status === 403 || query.error.status === 404)) return <Alert>This discussion is not available to you. <Link to="/courses">Back to courses</Link></Alert>
    return <ErrorState error={query.error} onRetry={() => void query.refetch()} />
  }
  const { thread, posts } = query.data
  const canRemove = (authorId: number) => authorId === me.id || moderate

  return (
    <>
      <p className="crumbs">
        <Link to={`/courses/${thread.course_offering_id}/discussions`}>
          {offering.data?.course ? `${offering.data.course.code} ${offering.data.course.title}` : 'Course'}
        </Link>{' '}
        / Discussions
      </p>
      <PageHeader title={thread.title} subtitle={`Started by ${thread.author?.name} · ${formatDateTime(thread.created_at)}`} actions={canRemove(thread.user_id) && <Button variant="danger" loading={removeThread.isPending} onClick={async () => { if (await confirm({ title: 'Delete this discussion?', message: 'The whole thread and all replies will be removed.', confirmLabel: 'Delete', danger: true })) removeThread.mutate() }}>Delete discussion</Button>} />
      <Card>
        <div className="pre">{thread.body}</div>
      </Card>
      <h2 className="section-title">{plural(posts.total, 'reply', 'replies')}</h2>
      {posts.data.length === 0 && <p className="muted">No replies yet. Be the first.</p>}
      {posts.data.map((p) => (
        <Card key={p.id}>
          <div className="post-head">
            <Avatar name={p.author?.name ?? '?'} />
            <span className="grow">
              <strong>{p.author?.name}</strong>
              <span className="muted small block">{formatDateTime(p.created_at)}</span>
            </span>
            {canRemove(p.user_id) && (
              <Button
                small
                variant="ghost"
                onClick={async () => {
                  if (await confirm({ title: 'Delete this reply?', confirmLabel: 'Delete', danger: true })) removePost.mutate(p.id)
                }}
              >
                Delete
              </Button>
            )}
          </div>
          <div className="pre">{p.body}</div>
        </Card>
      ))}
      <Pager {...pagerFromPage(posts)} onPage={setPage} />
      <Card title="Reply">
        <form
          onSubmit={(event) => {
            event.preventDefault()
            post.mutate()
          }}
        >
          <TextArea label="Your reply" rows={4} value={reply} maxLength={10000} onChange={(e) => setReply(e.target.value)} error={fieldError(post.error, 'body')} />
          {post.error && !fieldError(post.error, 'body') && <FormError error={post.error} />}
          <Button type="submit" variant="primary" loading={post.isPending} disabled={!reply.trim()}>
            Post reply
          </Button>
        </form>
      </Card>
    </>
  )
}
