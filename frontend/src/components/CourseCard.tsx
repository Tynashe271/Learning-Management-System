import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import type { Offering, Progress } from '../api/types'
import { formatPercent } from '../lib/format'
import { Avatar, Badge } from './ui'

/** A Classroom-style tile with a colored banner, the teacher's name, and the student's own progress. */
export function CourseCard({ offering }: { offering: Offering }) {
  const progress = useQuery({ queryKey: ['progress', offering.id, 'me'], queryFn: () => api.get<Progress>(`/offerings/${offering.id}/progress`) })
  const percent = progress.data?.percent ?? 0
  const hasItems = (progress.data?.items_total ?? 0) > 0
  const teacherName = offering.teachers?.[0]?.user?.name

  return (
    <Link to={`/courses/${offering.id}`} state={{ offering }} className="course-card">
      <div className="course-card-banner" style={{ background: accentFor(offering.course?.code ?? '') }}>
        <div className="course-card-banner-text">
          <strong className="course-card-code">{offering.course?.code}</strong>
          <span className="course-card-title">{offering.course?.title}</span>
        </div>
        {teacherName && <span className="course-card-teacher">{teacherName}</span>}
        <span className="course-card-avatar">
          <Avatar name={teacherName ?? offering.course?.code ?? ''} />
        </span>
      </div>
      <div className="course-card-body">
        <span className="muted small">
          {offering.term?.name} · Section {offering.section}
        </span>
        {!offering.published && <Badge tone="warn">Draft</Badge>}
        {hasItems && (
          <div className="course-card-progress">
            <div className="bar" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(percent ?? 0)}>
              <div style={{ width: `${percent ?? 0}%` }} />
            </div>
            <span className="muted small">{formatPercent(percent)} complete</span>
          </div>
        )}
      </div>
    </Link>
  )
}

const ACCENTS = ['#5b9cf0', '#7fd19a', '#f3c56b', '#e08ac4', '#7cc7ea', '#f0a67e']

/** A stable, cheerful accent color per course code so cards read as distinct subjects rather than identical rows. */
function accentFor(code: string) {
  let hash = 0
  for (let i = 0; i < code.length; i++) hash = (hash * 31 + code.charCodeAt(i)) >>> 0
  return ACCENTS[hash % ACCENTS.length]
}
