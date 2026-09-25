import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import type { AuditEntry, EnrolmentReport, Health, Overview, Page, Person, Term, UsageReport } from '../../api/types'
import { ROLE_LABELS } from '../../api/types'
import { useAuth } from '../../auth/AuthContext'
import { PersonPicker } from '../../components/PersonPicker'
import { Alert, Badge, Button, Card, EmptyState, Modal, PageHeader, Pager, QueryView, SelectField, Stat, Table, TextField, pagerFromPage, useDebounced } from '../../components/ui'
import { formatDate, formatDateTime } from '../../lib/format'
import { DownloadButton, useTitle } from '../../lib/hooks'

export function AuditPage() {
  useTitle('Audit log')
  const [page, setPage] = useState(1)
  const [text, setText] = useState('')
  const q = useDebounced(text.trim())
  const [who, setWho] = useState<Person | null>(null)
  const [picking, setPicking] = useState(false)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const filters = { q: q || undefined, causer_id: who?.id, from: from || undefined, to: to || undefined }
  const query = useQuery({ queryKey: ['audit', q, who?.id, from, to, page], queryFn: () => api.get<Page<AuditEntry>>('/audit-log', { ...filters, page }), placeholderData: (previous) => previous })

  return (
    <>
      <PageHeader
        title="Audit log"
        subtitle="Who did what: grade and deadline changes, enrolments, account and settings changes, backups and more. Entries cannot be edited or deleted."
        actions={
          <DownloadButton path="/audit-log" filename="audit-log.csv" query={{ ...filters, format: 'csv' }} small={false}>
            Download as a spreadsheet
          </DownloadButton>
        }
      />
      <Card>
        <div className="filters">
          <TextField label="From" type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1) }} />
          <TextField label="To" type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1) }} />
          <TextField label="Search the description" type="search" value={text} onChange={(e) => { setText(e.target.value); setPage(1) }} placeholder="for example: grade recorded" />
          <div className="field">
            <span className="label">Done by</span>
            <div className="inline">
              {who ? (
                <>
                  <Badge tone="info">{who.name}</Badge>
                  <Button small variant="ghost" onClick={() => { setWho(null); setPage(1) }}>
                    Clear
                  </Button>
                </>
              ) : (
                <Button small onClick={() => setPicking(true)}>
                  Filter by person
                </Button>
              )}
            </div>
          </div>
        </div>
        <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No matching entries">Nothing has been recorded that matches your filters.</EmptyState>}>
          {(data) => (
            <>
              <Table caption="Audit log">
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Who</th>
                    <th>What</th>
                    <th>Details</th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((e) => (
                    <tr key={e.id}>
                      <td>{formatDateTime(e.created_at)}</td>
                      <td>{e.causer ? e.causer.name : <span className="muted">System</span>}</td>
                      <td>
                        {e.description}
                        {e.subject_type && (
                          <span className="muted small block">
                            {e.subject_type.split('\\').pop()} #{e.subject_id}
                          </span>
                        )}
                      </td>
                      <td>
                        <code className="small">{describe(e.properties)}</code>
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
      {picking && (
        <Modal title="Filter by person" onClose={() => setPicking(false)}>
          <PersonPicker
            source="users"
            actionLabel="Filter"
            onPick={(p) => {
              setWho(p)
              setPage(1)
              setPicking(false)
            }}
          />
        </Modal>
      )}
    </>
  )
}

function describe(properties: AuditEntry['properties']): string {
  const values = Array.isArray(properties) ? {} : properties
  const text = Object.keys(values).length ? JSON.stringify(values) : ''
  return text.length > 160 ? `${text.slice(0, 157)}…` : text
}

type ReportTab = 'overview' | 'usage' | 'enrolment'

export function ReportsPage() {
  useTitle('Reports')
  const { can } = useAuth()
  const full = can('manage-courses')
  const [tab, setTab] = useState<ReportTab>(full ? 'overview' : 'enrolment')
  const tabs: { id: ReportTab; label: string; show: boolean }[] = [
    { id: 'overview', label: 'Overview', show: full },
    { id: 'usage', label: 'Usage', show: full },
    { id: 'enrolment', label: 'Enrolment', show: true },
  ]
  return (
    <>
      <PageHeader title="Reports" subtitle="Numbers for the whole institution. Overview is refreshed about once a minute." />
      <div className="role-tabs" role="tablist" aria-label="Report">
        {tabs
          .filter((t) => t.show)
          .map((t) => (
            <button key={t.id} type="button" role="tab" aria-selected={tab === t.id} className={`btn btn-small ${tab === t.id ? 'btn-primary' : 'btn-secondary'}`} onClick={() => setTab(t.id)}>
              {t.label}
            </button>
          ))}
      </div>
      {tab === 'overview' && <OverviewReport />}
      {tab === 'usage' && <UsageReportView />}
      {tab === 'enrolment' && <EnrolmentReportView />}
    </>
  )
}

function OverviewReport() {
  const query = useQuery({ queryKey: ['overview'], queryFn: () => api.get<Overview>('/reports/overview'), staleTime: 60_000 })
  return (
    <QueryView query={query}>
      {(o) => (
        <>
          <Card
            title="Overview"
            actions={
              <Button small onClick={() => void query.refetch()} loading={query.isFetching}>
                Refresh
              </Button>
            }
          >
            <div className="stats">
              <Stat label="Accounts" value={o.users.total} hint={`${o.users.active} active`} />
              <Stat label="Course offerings" value={o.offerings.total} hint={`${o.offerings.published} published`} />
              <Stat label="Active enrolments" value={o.enrolments_active} />
              <Stat label="Assignments" value={o.assignments} />
              <Stat label="Submissions" value={o.submissions} />
              <Stat label="Quizzes" value={o.quizzes} />
              <Stat label="Quiz attempts submitted" value={o.quiz_attempts_submitted} />
            </div>
          </Card>
          <Card title="Accounts by role">
            {Object.keys(o.users.by_role).length === 0 ? (
              <EmptyState title="No accounts" />
            ) : (
              <Table caption="Accounts by role">
                <thead>
                  <tr>
                    <th>Role</th>
                    <th>Accounts</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.entries(o.users.by_role)
                    .sort((x, y) => y[1] - x[1])
                    .map(([role, total]) => (
                      <tr key={role}>
                        <td>{ROLE_LABELS[role] ?? role}</td>
                        <td>{total}</td>
                      </tr>
                    ))}
                </tbody>
              </Table>
            )}
          </Card>
        </>
      )}
    </QueryView>
  )
}

/** A row of bars, one per day, with the values available as text for screen readers. */
function DayBars({ title, days }: { title: string; days: { date: string; count: number }[] }) {
  const max = Math.max(1, ...days.map((d) => d.count))
  const total = days.reduce((sum, d) => sum + d.count, 0)
  return (
    <div className="daybars">
      <h3>
        {title} <span className="muted small">{total.toLocaleString()} in 30 days</span>
      </h3>
      <div className="bars" role="img" aria-label={`${title}: ${days.map((d) => `${d.date} ${d.count}`).join(', ')}`}>
        {days.map((d) => (
          <div key={d.date} className="bar" title={`${formatDate(d.date)}: ${d.count}`} style={{ height: `${Math.max(2, (d.count / max) * 100)}%` }} />
        ))}
      </div>
      <div className="bar-axis muted small">
        <span>{formatDate(days[0]?.date)}</span>
        <span>{formatDate(days[days.length - 1]?.date)}</span>
      </div>
    </div>
  )
}

function UsageReportView() {
  const query = useQuery({ queryKey: ['usage'], queryFn: () => api.get<UsageReport>('/reports/usage'), staleTime: 60_000 })
  return (
    <QueryView query={query}>
      {(u) => (
        <>
          <Card title="Who is using the system">
            <div className="stats">
              <Stat label="Active in the last 7 days" value={u.active_people.last_7_days} hint={`of ${u.active_people.accounts} accounts`} />
              <Stat label="Active in the last 30 days" value={u.active_people.last_30_days} hint={u.active_people.accounts ? `${Math.round((u.active_people.last_30_days / u.active_people.accounts) * 100)}% of accounts` : undefined} />
            </div>
            <p className="muted small">“Active” means signed in at least once. Days are counted in UTC.</p>
          </Card>
          <Card title="The last 30 days">
            <DayBars title="Sign-ins" days={u.sign_ins_per_day} />
            <DayBars title="Assignments submitted" days={u.submissions_per_day} />
            <DayBars title="Quiz attempts submitted" days={u.quiz_attempts_per_day} />
            <DayBars title="New accounts" days={u.new_accounts_per_day} />
          </Card>
        </>
      )}
    </QueryView>
  )
}

function EnrolmentReportView() {
  const [termId, setTermId] = useState('')
  const terms = useQuery({ queryKey: ['terms', 'all'], queryFn: () => api.get<Page<Term>>('/terms', { page: 1 }) })
  const query = useQuery({ queryKey: ['enrolment-report', termId], queryFn: () => api.get<EnrolmentReport>('/reports/enrolments', { term_id: termId || undefined }), placeholderData: (previous) => previous })
  return (
    <QueryView query={query}>
      {(r) => (
        <>
          <Card
            title="Enrolment"
            actions={
              <DownloadButton path="/reports/enrolments" filename="enrolment.csv" query={{ term_id: termId || undefined, format: 'csv' }}>
                Download as a spreadsheet
              </DownloadButton>
            }
          >
            <div className="filters">
              <SelectField label="Term" value={termId} onChange={(e) => setTermId(e.target.value)}>
                <option value="">All terms</option>
                {(terms.data?.data ?? []).map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name}
                  </option>
                ))}
              </SelectField>
            </div>
            <div className="stats">
              <Stat label="Active enrolments" value={r.total_enrolled.toLocaleString()} />
            </div>
            {r.terms.length === 0 ? (
              <EmptyState title="No offerings yet" />
            ) : (
              <Table caption="Enrolment by term">
                <thead>
                  <tr>
                    <th>Term</th>
                    <th>Offerings</th>
                    <th>Students enrolled</th>
                    <th>Places</th>
                    <th>How full</th>
                  </tr>
                </thead>
                <tbody>
                  {r.terms.map((t) => (
                    <tr key={t.id}>
                      <td>
                        {t.name}
                        {t.academic_year && <span className="muted small block">{t.academic_year}</span>}
                      </td>
                      <td>
                        {t.offerings} <span className="muted small">({t.published} published)</span>
                      </td>
                      <td>{t.enrolled.toLocaleString()}</td>
                      <td>{t.capacity ? t.capacity.toLocaleString() : <span className="muted">no limits set</span>}</td>
                      <td>{t.fill_percent === null ? '—' : `${t.fill_percent}%`}</td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
          <Card title="By department">
            <Table caption="Enrolment by department">
              <thead>
                <tr>
                  <th>Department</th>
                  <th>Offerings</th>
                  <th>Students enrolled</th>
                </tr>
              </thead>
              <tbody>
                {r.departments.map((d) => (
                  <tr key={d.code ?? 'none'}>
                    <td>{d.name}</td>
                    <td>{d.offerings}</td>
                    <td>{d.enrolled.toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </Card>
          <div className="grid-2">
            <Card title="Fullest courses">
              {r.fullest.length === 0 ? (
                <EmptyState title="No course has a limit set" />
              ) : (
                <Table caption="Fullest courses">
                  <tbody>
                    {r.fullest.map((c) => (
                      <tr key={c.offering_id}>
                        <td>
                          <Link to={`/courses/${c.offering_id}`}>{c.course}</Link>
                          <span className="muted small block">
                            {c.term} · section {c.section}
                          </span>
                        </td>
                        <td>
                          {c.enrolled} of {c.capacity}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
            <Card title="Largest courses">
              {r.largest.length === 0 ? (
                <EmptyState title="Nobody is enrolled yet" />
              ) : (
                <Table caption="Largest courses">
                  <tbody>
                    {r.largest.map((c) => (
                      <tr key={c.offering_id}>
                        <td>
                          <Link to={`/courses/${c.offering_id}`}>{c.course}</Link>
                          <span className="muted small block">
                            {c.term} · section {c.section}
                          </span>
                        </td>
                        <td>{c.enrolled} students</td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
          </div>
        </>
      )}
    </QueryView>
  )
}

const CHECK_LABELS: Record<string, string> = { database: 'Database', cache: 'Cache', storage: 'File storage', scheduler: 'Scheduled jobs', queue: 'Background jobs' }

export function StatusPage() {
  useTitle('System status')
  const query = useQuery({
    queryKey: ['health'],
    // A 503 still carries the detailed report, so read it instead of treating it as a failure.
    queryFn: async () => {
      try {
        return await api.get<Health>('/health')
      } catch (error) {
        if (error instanceof ApiError && error.status === 503) return { status: 'down', checks: {}, time: new Date().toISOString() } as Health
        throw error
      }
    },
    refetchInterval: 30_000,
  })
  return (
    <>
      <PageHeader title="System status" subtitle="Checked every 30 seconds" actions={<Button onClick={() => void query.refetch()} loading={query.isFetching}>Check now</Button>} />
      <QueryView query={query}>
        {(h) => (
          <>
            <Alert tone={h.status === 'ok' ? 'success' : h.status === 'degraded' ? 'warn' : 'error'}>
              {h.status === 'ok' ? 'Everything is working.' : h.status === 'degraded' ? 'The system is working, but something needs attention.' : 'An essential service is down. Users may see errors.'}
            </Alert>
            <Card>
              <Table caption="Service checks">
                <thead>
                  <tr>
                    <th>Service</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.entries(h.checks).map(([name, value]) => (
                    <tr key={name}>
                      <td>{CHECK_LABELS[name] ?? name}</td>
                      <td>
                        <Badge tone={value === 'ok' ? 'good' : value === 'stale' || value === 'backlog' || value === 'failing' ? 'warn' : 'bad'}>{value}</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
              <p className="muted small">Last checked {formatDateTime(h.time)}.</p>
            </Card>
          </>
        )}
      </QueryView>
    </>
  )
}
