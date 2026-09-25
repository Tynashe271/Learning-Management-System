import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { CopyResult, Page, Term } from '../../api/types'
import { Alert, Badge, Button, Card, CheckField, FormError, PublishedBadge, QueryView, SelectField, TextField, useConfirm } from '../../components/ui'
import { formatDate, plural } from '../../lib/format'
import { fieldError, useApiMutation } from '../../lib/hooks'
import { useOffering } from './context'

export function SettingsTab() {
  const { offering, id } = useOffering()
  const confirm = useConfirm()
  const [section, setSection] = useState(offering.section)
  const [capacity, setCapacity] = useState(offering.capacity ? String(offering.capacity) : '')
  const [selfEnrol, setSelfEnrol] = useState(offering.self_enrolment ?? false)
  const rename = useApiMutation(() => api.patch(`/offerings/${id}`, { section: section.trim(), capacity: capacity ? Number(capacity) : null, self_enrolment: selfEnrol }), { invalidate: [['offering', id], ['offerings'], ['registration']], success: 'Saved.' })
  const archive = useApiMutation((archived: boolean) => api.patch(`/offerings/${id}`, { archived }), { invalidate: [['offering', id], ['offerings']], success: 'Updated.', toastError: true })
  const dirty = section.trim() !== offering.section || (capacity ? Number(capacity) : null) !== (offering.capacity ?? null) || selfEnrol !== (offering.self_enrolment ?? false)

  return (
    <>
      <Card title="Offering">
        <dl className="details">
          <dt>Course</dt>
          <dd>
            {offering.course?.code} {offering.course?.title}
          </dd>
          <dt>Term</dt>
          <dd>{offering.term?.name}</dd>
          <dt>Status</dt>
          <dd>
            <PublishedBadge published={offering.published} /> {offering.archived_at && <Badge tone="neutral">Archived</Badge>} <span className="muted small">Use the button at the top of the page to publish or unpublish.</span>
          </dd>
        </dl>
        <form
          onSubmit={(event) => {
            event.preventDefault()
            rename.mutate()
          }}
        >
          <TextField label="Section" value={section} onChange={(e) => setSection(e.target.value)} maxLength={100} error={fieldError(rename.error, 'section')} required />
          <TextField label="Places" optional type="number" min={1} value={capacity} onChange={(e) => setCapacity(e.target.value)} error={fieldError(rename.error, 'capacity')} hint="How many students may be enrolled. Blank means no limit. It cannot go below the number already enrolled." />
          <CheckField label="Students may register for it themselves" hint="Only while the term's registration window is open. Otherwise the registrar enrols students." checked={selfEnrol} onChange={(e) => setSelfEnrol(e.target.checked)} />
          {rename.error && !['section', 'capacity'].some((f) => fieldError(rename.error, f)) && <FormError error={rename.error} />}
          <Button type="submit" variant="primary" loading={rename.isPending} disabled={!section.trim() || !dirty}>
            Save
          </Button>
        </form>
        <div className="form-actions left">
          {offering.archived_at ? (
            <Button onClick={() => archive.mutate(false)} loading={archive.isPending}>
              Restore from the archive
            </Button>
          ) : (
            <Button
              onClick={async () => {
                if (await confirm({ title: 'Archive this offering?', message: 'It becomes read-only history and leaves the current catalogue. Students and teachers can still open it, and you can restore it later.', confirmLabel: 'Archive' })) archive.mutate(true)
              }}
              loading={archive.isPending}
            >
              Archive this offering
            </Button>
          )}
        </div>
      </Card>
      <CopyCard />
    </>
  )
}

function CopyCard() {
  const { offering, id } = useOffering()
  const terms = useQuery({ queryKey: ['terms', 'all'], queryFn: () => api.get<Page<Term>>('/terms', { page: 1 }) })
  const [termId, setTermId] = useState('')
  const [section, setSection] = useState(offering.section)
  const [copyTeachers, setCopyTeachers] = useState(true)

  const copy = useApiMutation(() => api.post<CopyResult>(`/offerings/${id}/copy`, { academic_term_id: Number(termId), section: section.trim(), copy_teachers: copyTeachers }), { invalidate: [['offerings']] })
  const result = copy.data

  return (
    <Card title="Copy into another term">
      <p className="muted">Builds a new offering from this one: modules, items and their files, assignments with rubrics, and quizzes with questions. The copy starts unpublished, and dates move by the difference between the two terms’ start dates. Students, submissions, attempts, announcements and discussions are never copied.</p>
      {result ? (
        <>
          <Alert tone="success">
            Copied. The new offering has {plural(result.copied.modules ?? 0, 'module')}, {plural(result.copied.items ?? 0, 'item')} ({result.copied.files ?? 0} with files), {plural(result.copied.assignments ?? 0, 'assignment')} ({result.copied.rubric_criteria ?? 0} rubric criteria) and {plural(result.copied.quizzes ?? 0, 'quiz', 'quizzes')} ({result.copied.questions ?? 0} questions). Dates moved by {result.date_shift_days} day(s).
          </Alert>
          <p>
            <Link className="btn btn-primary" to={`/courses/${result.offering.id}`}>
              Open the new offering
            </Link>{' '}
            <Button onClick={() => copy.reset()}>Copy again</Button>
          </p>
          <p className="muted small">Review it before publishing: assignments and quizzes come across as drafts.</p>
        </>
      ) : (
        <QueryView query={terms}>
          {(page) => (
            <form
              onSubmit={(event) => {
                event.preventDefault()
                copy.mutate()
              }}
            >
              <SelectField label="Copy into term" value={termId} onChange={(e) => setTermId(e.target.value)} error={fieldError(copy.error, 'academic_term_id')} required>
                <option value="">Choose a term…</option>
                {page.data.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name} ({formatDate(t.starts_on)} – {formatDate(t.ends_on)})
                  </option>
                ))}
              </SelectField>
              <TextField label="Section for the new offering" value={section} onChange={(e) => setSection(e.target.value)} maxLength={100} error={fieldError(copy.error, 'section')} required />
              <CheckField label="Assign the same teachers" checked={copyTeachers} onChange={(e) => setCopyTeachers(e.target.checked)} />
              {copy.error && !fieldError(copy.error, 'section') && !fieldError(copy.error, 'academic_term_id') && <FormError error={copy.error} />}
              <Button type="submit" variant="primary" loading={copy.isPending} disabled={!termId || !section.trim()}>
                Copy this course
              </Button>
            </form>
          )}
        </QueryView>
      )}
    </Card>
  )
}
