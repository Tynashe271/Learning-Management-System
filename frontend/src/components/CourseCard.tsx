import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import type { Offering, Progress } from '../api/types'
import { formatPercent } from '../lib/format'
import { Badge } from './ui'

/** A visual course tile with the student's own progress, used by the student dashboard and the student courses view. */
export function CourseCard({ offering }: { offering: Offering }) {
  const progress = useQuery({ queryKey: ['progress', offering.id, 'me'], queryFn: () => api.get<Progress>(`/offerings/${offering.id}/progress`) })
  const percent = progress.data?.percent ?? 0
  const hasItems = (progress.data?.items_total ?? 0) > 0

  return (
    <Link to={`/courses/${offering.id}`} state={{ offering }} className="course-card">
      <div className="course-card-accent" style={{ background: accentFor(offering.course?.code ?? '') }} />
      <div className="course-card-body">
        <strong>{offering.course?.code}</strong>
        <span>{offering.course?.title}</span>
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
