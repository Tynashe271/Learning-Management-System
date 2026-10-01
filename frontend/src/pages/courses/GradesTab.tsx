import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { Gradebook, MyGrades, Progress } from '../../api/types'
import { Card, EmptyState, Pager, QueryView, Stat, Table, pagerFromMeta } from '../../components/ui'
import { formatPercent, formatScore } from '../../lib/format'
import { DownloadButton } from '../../lib/hooks'
import { useOffering } from './context'

export function GradesTab() {
  const { manage } = useOffering()
  return manage ? <GradebookView /> : <MyGradesView />
}

function MyGradesView() {
  const { id } = useOffering()
  const query = useQuery({ queryKey: ['grades', id, 'mine'], queryFn: () => api.get<MyGrades>(`/offerings/${id}/my-grades`) })
  return (
    <QueryView query={query} isEmpty={(d) => d.columns.length === 0} empty={<Card><EmptyState title="No graded work yet">Published assignments and quizzes will show your marks here.</EmptyState></Card>}>
      {(data) => (
        <>
          <Card>
            <div className="stats">
              <Stat label="Overall" value={formatPercent(data.grades?.percent)} hint="of the work marked so far" />
              <Stat label="Points" value={`${formatScore(data.grades?.total ?? 0)} / ${formatScore(data.grades?.possible ?? 0)}`} />
              {data.grades?.weighted_percent !== null && data.grades?.weighted_percent !== undefined && (
                <Stat label="Continuous assessment total" value={formatPercent(data.grades.weighted_percent)} hint={`weighted over ${data.grades.weight_used}% of the course`} />
              )}
            </div>
          </Card>
          <Card title="Marks">
            <Table caption="Your marks">
              <thead>
                <tr>
                  <th>Work</th>
                  <th>Type</th>
                  <th>Weight</th>
                  <th>Your mark</th>
                </tr>
              </thead>
              <tbody>
                {data.columns.map((c) => {
                  const score = data.grades?.scores[c.key]
                  return (
                    <tr key={c.key}>
                      <td>
                        <Link to={c.type === 'assignment' ? `/assignments/${c.id}` : `/quizzes/${c.id}`}>{c.title}</Link>
                      </td>
                      <td>{c.type === 'assignment' ? 'Assignment' : 'Quiz'}</td>
                      <td>{c.weight !== null ? `${c.weight}%` : <span className="muted">—</span>}</td>
                      <td>{score === null || score === undefined ? <span className="muted">Not marked yet</span> : `${formatScore(score)} / ${formatScore(c.max)}`}</td>
                    </tr>
                  )
                })}
              </tbody>
            </Table>
            <p className="muted small">Assignments show your latest published grade; quizzes show your best attempt. Unmarked work does not lower your percentage.</p>
          </Card>
        </>
      )}
    </QueryView>
  )
}

function GradebookView() {
  const { id } = useOffering()
  const [page, setPage] = useState(1)
  const query = useQuery({ queryKey: ['grades', id, 'book', page], queryFn: () => api.get<Gradebook>(`/offerings/${id}/gradebook`, { page }), placeholderData: (previous) => previous })
  return (
    <Card title="Gradebook" actions={<DownloadButton path={`/offerings/${id}/gradebook`} query={{ format: 'csv' }} filename={`gradebook-offering-${id}.csv`}>Download as spreadsheet (CSV)</DownloadButton>}>
      <QueryView query={query} isEmpty={(d) => d.rows.length === 0} empty={<EmptyState title="No students enrolled">Enrol students to see their marks here.</EmptyState>}>
        {(data) => (
          <>
            {data.columns.length === 0 && <p className="muted">Publish an assignment or quiz to add columns. Only published work is shown.</p>}
            <Table caption="Gradebook">
              <thead>
                <tr>
                  <th>Student</th>
                  {data.columns.map((c) => (
                    <th key={c.key}>
                      {c.title}
                      <span className="muted small block">
                        out of {formatScore(c.max)}
                        {c.weight !== null && ` · weight ${c.weight}%`}
                      </span>
                    </th>
                  ))}
                  <th>Total</th>
                  <th>Percent</th>
                  {data.columns.some((c) => c.weight !== null) && <th>CA total</th>}
                </tr>
              </thead>
              <tbody>
                {data.rows.map((r) => (
                  <tr key={r.user.id}>
                    <td>
                      {r.user.name}
                      <span className="muted small block">{r.user.email}</span>
                    </td>
                    {data.columns.map((c) => (
                      <td key={c.key}>{r.scores[c.key] === null || r.scores[c.key] === undefined ? <span className="muted">—</span> : formatScore(r.scores[c.key])}</td>
                    ))}
                    <td>
                      {formatScore(r.total)} / {formatScore(r.possible)}
                    </td>
                    <td>
                      <strong>{formatPercent(r.percent)}</strong>
                    </td>
                    {data.columns.some((c) => c.weight !== null) && (
                      <td>{r.weighted_percent === null ? <span className="muted">—</span> : <strong>{formatPercent(r.weighted_percent)}</strong>}</td>
                    )}
                  </tr>
                ))}
              </tbody>
            </Table>
            <Pager {...pagerFromMeta(data.meta)} onPage={setPage} />
            <p className="muted small">Assignments count the latest published grade; quizzes count the best submitted attempt. Percent is taken over the work a student has a mark for. The CA total blends only the items you have given a weight, scaled back to 100%.</p>
          </>
        )}
      </QueryView>
    </Card>
  )
}

export function ProgressTab() {
  const { id } = useOffering()
  const [page, setPage] = useState(1)
  const query = useQuery({ queryKey: ['progress', id, 'all', page], queryFn: () => api.get<Progress>(`/offerings/${id}/progress`, { page }), placeholderData: (previous) => previous })
  return (
    <Card title="Student progress">
      <QueryView query={query} isEmpty={(d) => (d.students ?? []).length === 0} empty={<EmptyState title="No students enrolled" />}>
        {(data) => (
          <>
            <p className="muted small">Share of the {data.items_total} published items each student has marked as done.</p>
            <Table caption="Progress">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Items done</th>
                  <th>Progress</th>
                </tr>
              </thead>
              <tbody>
                {data.students?.map((s) => (
                  <tr key={s.user.id}>
                    <td>{s.user.name}</td>
                    <td>
                      {s.completed} / {data.items_total}
                    </td>
                    <td>
                      <div className="bar bar-inline" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(s.percent ?? 0)}>
                        <div style={{ width: `${s.percent ?? 0}%` }} />
                      </div>{' '}
                      {formatPercent(s.percent)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </Table>
            {data.meta && <Pager {...pagerFromMeta(data.meta)} onPage={setPage} />}
          </>
        )}
      </QueryView>
    </Card>
  )
}
