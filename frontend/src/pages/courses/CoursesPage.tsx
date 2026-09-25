import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { Department, Offering, Page, Term } from '../../api/types'
import { useAuth } from '../../auth/AuthContext'
import { CourseCard } from '../../components/CourseCard'
import { Badge, Card, EmptyState, PageHeader, Pager, PublishedBadge, QueryView, SelectField, Table, TextField, pagerFromPage, useDebounced } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { useTitle } from '../../lib/hooks'

export function CoursesPage() {
  useTitle('Courses')
  const { can, isStudentOnly } = useAuth()
  const [page, setPage] = useState(1)
  const catalogue = can('manage-courses') || can('manage-enrolments')
  const [text, setText] = useState('')
  const q = useDebounced(text.trim())
  const [termId, setTermId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [status, setStatus] = useState('')
  const [archived, setArchived] = useState('exclude')
  const reset = () => setPage(1)

  const terms = useQuery({ queryKey: ['terms', 'all'], queryFn: () => api.get<Page<Term>>('/terms', { page: 1 }), enabled: catalogue })
  const departments = useQuery({ queryKey: ['departments', false], queryFn: () => api.get<Department[]>('/departments', { archived: 'exclude' }), enabled: catalogue })
  const query = useQuery({
    queryKey: ['offerings', page, q, termId, departmentId, status, archived],
    queryFn: () =>
      api.get<Page<Offering>>('/offerings', catalogue ? { page, q: q || undefined, term_id: termId || undefined, department_id: departmentId || undefined, status: status || undefined, archived } : { page }),
    placeholderData: (previous) => previous,
  })

  return (
    <>
      <PageHeader
        title={isStudentOnly ? 'My courses' : 'Courses'}
        subtitle={catalogue ? 'Every course offering in the university' : can('submit-assignments') ? 'The courses you are enrolled in' : 'The courses you teach'}
        actions={catalogue ? <Link className="btn btn-primary" to="/admin/catalogue">Manage terms and courses</Link> : undefined}
      />
      {isStudentOnly ? (
        <QueryView
          query={query}
          isEmpty={(data) => data.data.length === 0}
          empty={<EmptyState title="No courses to show">You are not enrolled in any published course yet. Register for courses when registration is open, or ask your registrar.</EmptyState>}
        >
          {(data) => (
            <>
              <div className="course-grid course-grid-cards">
                {data.data.map((o) => (
                  <CourseCard key={o.id} offering={o} />
                ))}
              </div>
              <Pager {...pagerFromPage(data)} onPage={setPage} />
            </>
          )}
        </QueryView>
      ) : (
        <Card>
        {catalogue && (
          <div className="filters">
            <TextField label="Search" type="search" value={text} onChange={(e) => { setText(e.target.value); reset() }} placeholder="Course code or title" />
            <SelectField label="Term" value={termId} onChange={(e) => { setTermId(e.target.value); reset() }}>
              <option value="">All terms</option>
              {(terms.data?.data ?? []).map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </SelectField>
            <SelectField label="Department" value={departmentId} onChange={(e) => { setDepartmentId(e.target.value); reset() }}>
              <option value="">All departments</option>
              {(departments.data ?? []).map((d) => (
                <option key={d.id} value={d.id}>
                  {d.code} {d.name}
                </option>
              ))}
            </SelectField>
            <SelectField label="Status" value={status} onChange={(e) => { setStatus(e.target.value); reset() }}>
              <option value="">Published and draft</option>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </SelectField>
            <SelectField label="Show" value={archived} onChange={(e) => { setArchived(e.target.value); reset() }}>
              <option value="exclude">Current</option>
              <option value="only">Archived</option>
              <option value="include">Both</option>
            </SelectField>
          </div>
        )}
        <QueryView
          query={query}
          isEmpty={(data) => data.data.length === 0}
          empty={
            <EmptyState title="No courses to show">
              {can('submit-assignments') ? 'You are not enrolled in any published course yet. Register for courses when registration is open, or ask your registrar.' : catalogue ? 'Nothing matches. Create a course and an offering in the catalogue to get started.' : 'You have not been assigned to any course yet.'}
            </EmptyState>
          }
        >
          {(data) => (
            <>
              <Table caption="Course offerings">
                <thead>
                  <tr>
                    <th>Course</th>
                    <th>Term</th>
                    <th>Section</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((o) => (
                    <tr key={o.id}>
                      <td>
                        <Link to={`/courses/${o.id}`} state={{ offering: o }}>
                          <strong>{o.course?.code}</strong> {o.course?.title}
                        </Link>
                        {o.course?.department && <span className="muted small block">{o.course.department.name}</span>}
                      </td>
                      <td>
                        {o.term?.name}
                        <span className="muted small block">
                          {formatDate(o.term?.starts_on)} – {formatDate(o.term?.ends_on)}
                        </span>
                      </td>
                      <td>{o.section}</td>
                      <td>
                        {o.archived_at ? <Badge tone="neutral">Archived</Badge> : catalogue || !o.published ? <PublishedBadge published={o.published} /> : <Badge tone="good">Open</Badge>}
                        {catalogue && o.capacity != null && <span className="muted small block">{o.capacity} places</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
              <Pager {...pagerFromPage(data)} onPage={setPage} />
            </>
          )}
        </QueryView>
        </Card>
      )}
    </>
  )
}
