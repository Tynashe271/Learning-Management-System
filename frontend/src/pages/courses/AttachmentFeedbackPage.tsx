import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import type { AttachmentFeedbackForm } from '../../api/types'
import { Alert, Button, Card, ErrorState, FormError, Loading, TextArea, TextField } from '../../components/ui'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'

/** A public, unauthenticated page a workplace supervisor opens from an emailed link - no LMS account needed. */
export function AttachmentFeedbackPage() {
  const { token } = useParams()
  useTitle('Attachment feedback')
  const query = useQuery({ queryKey: ['attachment-feedback', token], queryFn: () => api.get<AttachmentFeedbackForm>(`/attachment-feedback/${token}`) })
  const [rating, setRating] = useState('')
  const [comment, setComment] = useState('')
  const submit = useApiMutation(() => api.post(`/attachment-feedback/${token}`, { rating: Number(rating), comment: comment.trim() || null }, { anonymous: true }))

  return (
    <div className="auth-page">
      <div className="auth-card">
        <h1>Attachment feedback</h1>
        {query.isPending && <Loading />}
        {query.isError && (query.error instanceof ApiError && query.error.status === 404 ? <Alert>This link is invalid or has expired.</Alert> : <ErrorState error={query.error} onRetry={() => void query.refetch()} />)}
        {query.data && (
          <>
            <Card>
              <p>
                <strong>{query.data.student_name}</strong> — {query.data.course}
              </p>
              <p className="muted small">
                {query.data.organisation} · {query.data.starts_on} – {query.data.ends_on}
              </p>
              {query.data.objectives && <p>{query.data.objectives}</p>}
            </Card>
            {submit.isSuccess ? (
              <Alert tone="success">Thank you — your feedback has been recorded.</Alert>
            ) : query.data.already_submitted ? (
              <Alert tone="info">Feedback has already been submitted for this attachment. Thank you.</Alert>
            ) : (
              <form
                onSubmit={(event) => {
                  event.preventDefault()
                  submit.mutate()
                }}
              >
                <TextField label="Rating (1-5)" type="number" min={1} max={5} value={rating} onChange={(e) => setRating(e.target.value)} error={fieldError(submit.error, 'rating')} required />
                <TextArea label="Comments" optional rows={4} value={comment} onChange={(e) => setComment(e.target.value)} error={fieldError(submit.error, 'comment')} />
                {submit.error && !fieldError(submit.error, 'rating') && !fieldError(submit.error, 'comment') && <FormError error={submit.error} />}
                <Button type="submit" variant="primary" loading={submit.isPending} disabled={!rating} className="btn-block">
                  Submit feedback
                </Button>
              </form>
            )}
          </>
        )}
      </div>
    </div>
  )
}
