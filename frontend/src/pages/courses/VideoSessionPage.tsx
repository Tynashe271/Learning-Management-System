import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { api } from '../../api/client'
import { ErrorState, Loading, PageHeader } from '../../components/ui'
import { useTitle } from '../../lib/hooks'

/** The in-app video room for a class session, embedded directly so nobody has to leave for Google Meet or Zoom. */
export function VideoSessionPage() {
  useTitle('Join class')
  const { id } = useParams()
  const query = useQuery({ queryKey: ['session-join', id], queryFn: () => api.post<{ url: string }>(`/sessions/${id}/join`), retry: false })

  return (
    <div className="video-session">
      <PageHeader title="Class video" actions={<Link className="btn btn-ghost btn-small" to="/courses">Leave</Link>} />
      {query.isPending && <Loading label="Connecting…" />}
      {query.isError && <ErrorState error={query.error} onRetry={() => void query.refetch()} />}
      {query.data && <iframe className="video-frame" src={query.data.url} allow="camera; microphone; fullscreen; display-capture; autoplay" title="Class video" />}
    </div>
  )
}
