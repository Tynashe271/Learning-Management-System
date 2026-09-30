import { useQuery } from '@tanstack/react-query'
import { Link, Outlet, useLocation, useParams } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import type { Offering, Page } from '../../api/types'
import { useAuth } from '../../auth/AuthContext'
import { Alert, Badge, Button, ErrorState, Loading, PageHeader, PublishedBadge, Tabs } from '../../components/ui'
import { useApiMutation, useTitle } from '../../lib/hooks'
import type { OfferingContext } from './context'

/** Looks an offering up in the paged course list, for people who are allowed the list but not the offering itself. */
async function findInList(id: number): Promise<Offering | null> {
  for (let page = 1; page <= 25; page++) {
    const result = await api.get<Page<Offering>>('/offerings', { page })
    const found = result.data.find((o) => o.id === id)
    if (found) return found
    if (page >= result.last_page) break
  }
  return null
}

export function OfferingLayout() {
  const { id } = useParams()
  const offeringId = Number(id)
  const { can } = useAuth()
  const location = useLocation()
  const passed = (location.state as { offering?: Offering } | null)?.offering

  const query = useQuery({ queryKey: ['offering', offeringId], queryFn: () => api.get<Offering>(`/offerings/${offeringId}`), retry: (count, error) => !(error instanceof ApiError && error.status < 500 && error.status !== 429) && count < 2 })
  const setPublished = useApiMutation((published: boolean) => api.patch(`/offerings/${offeringId}`, { published }), { invalidate: [['offering', offeringId], ['offerings']], success: 'Course updated.', toastError: true })

  const registrar = can('manage-enrolments')
  const admin = can('manage-courses')
  const forbidden = query.error instanceof ApiError && query.error.status === 403
  // Registrars may enrol students in an offering without being allowed to open its teaching material.
  const enrolmentOnly = forbidden && registrar
  // A registrar cannot open the offering itself, so its name comes from the course list (or from the link they followed).
  const listed = useQuery({ queryKey: ['offering-listing', offeringId], queryFn: () => findInList(offeringId), enabled: enrolmentOnly && !passed, staleTime: 5 * 60_000 })
  const hint = passed ?? listed.data ?? undefined
  const offering = query.data ?? (enrolmentOnly ? hint : undefined)
  useTitle(offering?.course ? `${offering.course.code} ${offering.course.title}` : 'Course')

  if (query.isPending) return <Loading label="Loading the course…" />
  if (!offering && enrolmentOnly) {
    return (
      <>
        <PageHeader title={`Course offering #${offeringId}`} subtitle="Enrolment only" />
        <OfferingBody offering={{ id: offeringId, course_id: 0, academic_term_id: 0, section: '', published: true }} enrolmentOnly ctx={{ registrar, admin }} />
      </>
    )
  }
  if (query.isError && !offering) {
    if (forbidden) return <Alert>You do not have access to this course. Students see a course once it is published and they are enrolled in it.</Alert>
    if (query.error instanceof ApiError && query.error.status === 404) return <Alert>This course could not be found. <Link to="/courses">Back to courses</Link></Alert>
    return <ErrorState error={query.error} onRetry={() => void query.refetch()} />
  }
  if (!offering) return null

  const manage = enrolmentOnly ? false : (offering.abilities?.manage ?? false)
  const teachers = offering.teachers?.map((t) => t.user?.name).filter(Boolean).join(', ')

  return (
    <>
      <PageHeader
        title={
          <>
            {offering.course?.code} <span className="light">{offering.course?.title}</span>
          </>
        }
        subtitle={
          <>
            {offering.term?.name} · Section {offering.section}
            {teachers ? ` · Taught by ${teachers}` : ''}
          </>
        }
        actions={
          <>
            <PublishedBadge published={offering.published} />
            {admin && !enrolmentOnly && (
              <Button loading={setPublished.isPending} onClick={() => setPublished.mutate(!offering.published)}>
                {offering.published ? 'Unpublish' : 'Publish'}
              </Button>
            )}
          </>
        }
      />
      {!offering.published && !enrolmentOnly && <Alert tone="warn">This course is not published, so students cannot see it yet.</Alert>}
      <OfferingBody offering={offering} enrolmentOnly={enrolmentOnly} ctx={{ registrar, admin }} manage={manage} />
    </>
  )
}

function OfferingBody({ offering, enrolmentOnly, ctx, manage = false }: { offering: Offering; enrolmentOnly: boolean; ctx: { registrar: boolean; admin: boolean }; manage?: boolean }) {
  const { can } = useAuth()
  const base = `/courses/${offering.id}`

  const context: OfferingContext = {
    offering,
    id: offering.id,
    manage,
    canGrade: manage && can('grade-submissions'),
    canResolve: manage && can('resolve-appeals'),
    student: !manage && !enrolmentOnly,
    admin: ctx.admin && !enrolmentOnly,
    enrolmentOnly,
    registrar: ctx.registrar,
  }

  const tabs = enrolmentOnly
    ? [{ to: `${base}/people`, label: 'People' }]
    : [
        { to: `${base}/stream`, label: 'Stream' },
        { to: `${base}/classwork`, label: 'Classwork' },
        { to: `${base}/announcements`, label: 'Announcements' },
        { to: `${base}/discussions`, label: 'Discussions' },
        { to: `${base}/classes`, label: 'Classes' },
        { to: `${base}/attendance`, label: 'Attendance' },
        { to: `${base}/skills`, label: 'Skills' },
        { to: `${base}/attachment`, label: 'Attachment' },
        { to: `${base}/grades`, label: manage ? 'Gradebook' : 'My grades' },
        ...(manage ? [{ to: `${base}/overview`, label: 'Overview' }, { to: `${base}/progress`, label: 'Progress' }] : []),
        ...(manage || ctx.registrar ? [{ to: `${base}/people`, label: 'People' }] : []),
        ...(manage ? [{ to: `${base}/appeals`, label: 'Appeals' }] : []),
        ...(context.admin ? [{ to: `${base}/settings`, label: 'Settings' }] : []),
      ]

  return (
    <>
      <Tabs tabs={tabs} />
      {enrolmentOnly && (
        <Alert tone="info">
          You can manage enrolments for this offering. Teaching material is visible to its teachers and to enrolled students.
          <Badge tone="info">Enrolment only</Badge>
        </Alert>
      )}
      <Outlet context={context} />
    </>
  )
}
