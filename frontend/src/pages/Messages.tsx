import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, useLocation, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api/client'
import type { Conversations, DirectMessage, Page } from '../api/types'
import { useMe } from '../auth/AuthContext'
import { PersonPicker } from '../components/PersonPicker'
import { Alert, Avatar, Button, EmptyState, ErrorState, FileField, FormError, Loading, Modal, PageHeader, QueryView, TextArea } from '../components/ui'
import { formatBytes, formatDateTime, relativeTime } from '../lib/format'
import { DownloadButton, fieldError, useApiMutation, useTitle } from '../lib/hooks'

const MAX_FILES = 5
const MAX_FILE_BYTES = 10 * 1024 * 1024

export function MessagesPage() {
  useTitle('Messages')
  const { userId } = useParams()
  const navigate = useNavigate()
  const [picking, setPicking] = useState(false)
  const conversations = useQuery({ queryKey: ['messages', 'conversations'], queryFn: () => api.get<Conversations>('/messages'), refetchInterval: 30_000 })

  return (
    <>
      <PageHeader
        title="Messages"
        subtitle="Private conversations with your teachers, classmates and staff"
        actions={
          <Button variant="primary" onClick={() => setPicking(true)}>
            New message
          </Button>
        }
      />
      <div className={`messenger${userId ? ' messenger-open' : ''}`}>
        <aside className="conversation-list" aria-label="Conversations">
          <QueryView query={conversations} isEmpty={(c) => c.conversations.length === 0} empty={<EmptyState title="No conversations yet">Start one with the “New message” button.</EmptyState>}>
            {(data) => (
              <ul>
                {data.conversations.map((c) =>
                  c.user ? (
                    <li key={c.user.id}>
                      <NavLink to={`/messages/${c.user.id}`} state={{ name: c.user.name }} className={({ isActive }) => `conversation${isActive ? ' conversation-active' : ''}`}>
                        <Avatar name={c.user.name} />
                        <span className="grow">
                          <strong>{c.user.name}</strong>
                          <span className="muted small block clip">{c.last_message ? preview(c.last_message) : ''}</span>
                        </span>
                        {c.unread > 0 && <span className="count">{c.unread}</span>}
                      </NavLink>
                    </li>
                  ) : null,
                )}
              </ul>
            )}
          </QueryView>
        </aside>
        <section className="thread-pane">
          {userId ? <Thread key={userId} otherId={Number(userId)} fallbackName={conversations.data?.conversations.find((c) => c.user?.id === Number(userId))?.user?.name} /> : <EmptyState title="Choose a conversation">Pick someone from the list, or start a new message.</EmptyState>}
        </section>
      </div>
      {picking && (
        <Modal title="New message" onClose={() => setPicking(false)}>
          <p className="muted">You can write to the teachers and classmates of your courses. Staff can write to anyone.</p>
          <PersonPicker
            source="contacts"
            actionLabel="Write"
            onPick={(person) => {
              setPicking(false)
              navigate(`/messages/${person.id}`, { state: { name: person.name } })
            }}
          />
        </Modal>
      )}
    </>
  )
}

function preview(message: DirectMessage): string {
  const text = message.body.trim()
  if (text) return text
  return message.attachments.length ? `📎 ${message.attachments.length} attachment${message.attachments.length === 1 ? '' : 's'}` : ''
}

function Thread({ otherId, fallbackName }: { otherId: number; fallbackName?: string }) {
  const me = useMe()
  const location = useLocation()
  const queryClient = useQueryClient()
  const name = (location.state as { name?: string } | null)?.name ?? fallbackName ?? 'Conversation'
  const bottom = useRef<HTMLDivElement>(null)

  const query = useInfiniteQuery({
    queryKey: ['messages', 'thread', otherId],
    queryFn: ({ pageParam }) => api.get<Page<DirectMessage>>(`/messages/${otherId}`, { page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.current_page < last.last_page ? last.current_page + 1 : undefined),
    refetchInterval: 15_000,
  })

  // Opening a thread marks it read on the server, so the unread counts elsewhere need to catch up.
  useEffect(() => {
    if (query.isSuccess) void queryClient.invalidateQueries({ queryKey: ['messages', 'conversations'] })
  }, [query.isSuccess, queryClient, query.data?.pages[0]?.data[0]?.id])

  // Newest first from the API; shown oldest first.
  const messages = (query.data?.pages.flatMap((p) => p.data) ?? []).slice().reverse()
  const newestId = messages[messages.length - 1]?.id
  useEffect(() => {
    bottom.current?.scrollIntoView?.({ block: 'end' })
  }, [newestId])

  return (
    <div className="thread">
      <div className="thread-head">
        <Link to="/messages" className="back-link" aria-label="Back to conversations">
          ←
        </Link>
        <Avatar name={name} />
        <h2>{name}</h2>
      </div>
      <div className="thread-messages" aria-live="polite">
        {query.isPending && <Loading />}
        {query.isError && <ErrorState error={query.error} onRetry={() => void query.refetch()} />}
        {query.hasNextPage && (
          <div className="center">
            <Button small onClick={() => void query.fetchNextPage()} loading={query.isFetchingNextPage}>
              Show earlier messages
            </Button>
          </div>
        )}
        {query.isSuccess && messages.length === 0 && <EmptyState title="No messages yet">Say hello below.</EmptyState>}
        {messages.map((m) => (
          <Bubble key={m.id} message={m} mine={m.sender_id === me.id} />
        ))}
        <div ref={bottom} />
      </div>
      <Composer otherId={otherId} />
    </div>
  )
}

function Bubble({ message, mine }: { message: DirectMessage; mine: boolean }) {
  return (
    <div className={`bubble${mine ? ' bubble-mine' : ''}`}>
      {message.body && <p className="pre">{message.body}</p>}
      {message.attachments.length > 0 && (
        <ul className="attachments">
          {message.attachments.map((a) => (
            <li key={a.id}>
              <span>📎 {a.original_name}</span> <span className="muted small">({formatBytes(a.size)})</span>{' '}
              <DownloadButton path={`/message-attachments/${a.id}/download`} filename={a.original_name}>
                Download
              </DownloadButton>
            </li>
          ))}
        </ul>
      )}
      <span className="bubble-time" title={formatDateTime(message.created_at)}>
        {relativeTime(message.created_at)}
        {mine && (message.read_at ? ' · read' : '')}
      </span>
    </div>
  )
}

function Composer({ otherId }: { otherId: number }) {
  const [body, setBody] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [fileError, setFileError] = useState<string | null>(null)
  const [inputKey, setInputKey] = useState(0)

  const send = useApiMutation(
    () => {
      const form = new FormData()
      if (body.trim()) form.append('body', body.trim())
      files.forEach((file) => form.append('attachments[]', file))
      return api.upload<DirectMessage>(`/messages/${otherId}`, form)
    },
    {
      invalidate: [['messages']],
      onSuccess: () => {
        setBody('')
        setFiles([])
        setFileError(null)
        setInputKey((k) => k + 1)
      },
    },
  )

  const choose = (list: FileList | null) => {
    const chosen = Array.from(list ?? [])
    if (chosen.length > MAX_FILES) return setFileError(`You can attach up to ${MAX_FILES} files.`)
    const big = chosen.find((f) => f.size > MAX_FILE_BYTES)
    if (big) return setFileError(`“${big.name}” is larger than ${formatBytes(MAX_FILE_BYTES)}.`)
    setFileError(null)
    setFiles(chosen)
  }
  const canSend = (body.trim() !== '' || files.length > 0) && !fileError

  return (
    <form
      className="composer"
      onSubmit={(event) => {
        event.preventDefault()
        if (canSend) send.mutate()
      }}
    >
      <TextArea label="Message" rows={3} value={body} maxLength={5000} onChange={(e) => setBody(e.target.value)} error={fieldError(send.error, 'body')} />
      <FileField key={inputKey} label="Attach files" optional multiple onChange={(e) => choose(e.target.files)} error={fileError ?? fieldError(send.error, 'attachments') ?? fieldError(send.error, 'attachments.0')} hint={`Up to ${MAX_FILES} files, ${formatBytes(MAX_FILE_BYTES)} each.`} />
      {send.error && !fieldError(send.error, 'body') && !fieldError(send.error, 'attachments') && <FormError error={send.error} />}
      {send.isSuccess && <Alert tone="success">Sent.</Alert>}
      <Button type="submit" variant="primary" loading={send.isPending} disabled={!canSend}>
        Send
      </Button>
    </form>
  )
}
