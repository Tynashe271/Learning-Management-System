import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { FailedJob, Page, SystemInfo } from '../../api/types'
import { Alert, Badge, Button, Card, EmptyState, PageHeader, Pager, QueryView, Stat, Table, pagerFromPage, useConfirm } from '../../components/ui'
import { formatBytes, formatDateTime, plural } from '../../lib/format'
import { useApiMutation, useTitle } from '../../lib/hooks'

const TABLE_LABELS: Record<string, string> = {
  users: 'Accounts',
  courses: 'Courses',
  course_offerings: 'Course offerings',
  enrolments: 'Enrolments',
  assignments: 'Assignments',
  submissions: 'Submissions',
  grade_records: 'Grades recorded',
  quiz_attempts: 'Quiz attempts',
  activity_log: 'Audit log entries',
  security_events: 'Security events',
}

const PRUNE_LABELS: Record<string, string> = {
  expired_sign_ins: 'Expired sign-ins',
  security_events: 'Old security events',
  read_notifications: 'Old read notifications',
  password_reset_links: 'Used or expired reset links',
  welcome_links: 'Used or expired welcome links',
  failed_jobs: 'Old failed jobs',
}

function ago(seconds: number | null): string {
  if (seconds === null) return 'never'
  if (seconds < 90) return `${seconds} seconds ago`
  return `${Math.round(seconds / 60)} minutes ago`
}

/** The technical side: versions, capacity, queues, failed jobs and housekeeping. For super administrators. */
export function SystemPage() {
  useTitle('System and jobs')
  const query = useQuery({ queryKey: ['system', 'info'], queryFn: () => api.get<SystemInfo>('/system'), refetchInterval: 60_000 })
  return (
    <>
      <PageHeader title="System and jobs" subtitle="What is running, how full it is, and what needs attention" actions={<Button onClick={() => void query.refetch()} loading={query.isFetching}>Refresh</Button>} />
      <QueryView query={query}>
        {(s) => (
          <>
            {s.warnings.length === 0 ? (
              <Alert tone="success">Nothing needs attention.</Alert>
            ) : (
              <Alert tone="warn">
                <strong>Needs attention</strong>
                <ul className="plain-list">
                  {s.warnings.map((w) => (
                    <li key={w}>{w}</li>
                  ))}
                </ul>
              </Alert>
            )}
            <Card title="Software">
              <dl className="facts">
                <dt>Application</dt>
                <dd>
                  {s.application.name} {s.application.version}
                </dd>
                <dt>Environment</dt>
                <dd>
                  {s.application.environment} {s.application.debug && <Badge tone="bad">debug on</Badge>}
                </dd>
                <dt>Server time</dt>
                <dd>
                  {formatDateTime(s.application.server_time)} ({s.application.timezone})
                </dd>
                <dt>PHP / Laravel</dt>
                <dd>
                  {s.application.php} / {s.application.laravel}
                </dd>
                <dt>Email</dt>
                <dd>
                  {s.mail.mailer} from {s.mail.from}
                </dd>
                <dt>Cache</dt>
                <dd>{s.cache.store}</dd>
              </dl>
            </Card>
            <Card title="Capacity">
              <div className="stats">
                <Stat label="Database" value={s.database.size_bytes === null ? '—' : formatBytes(s.database.size_bytes)} hint={`${s.database.driver}, answers in ${s.database.latency_ms} ms`} />
                <Stat label="Uploaded files" value={s.storage.ok ? formatBytes(s.storage.total_bytes) : 'unknown'} hint={s.storage.ok ? `${s.storage.total_files.toLocaleString()} files${s.storage.truncated ? ' (at least)' : ''}` : s.storage.error} />
                <Stat label="Disk free" value={s.disk.free !== null && s.disk.total ? `${Math.round((s.disk.free / s.disk.total) * 100)}%` : '—'} hint={s.disk.free !== null ? `${formatBytes(s.disk.free)} free` : undefined} />
              </div>
              {s.storage.ok && Object.keys(s.storage.by_kind).length > 0 && (
                <Table caption="Files by kind">
                  <thead>
                    <tr>
                      <th>Kind of file</th>
                      <th>Files</th>
                      <th>Size</th>
                    </tr>
                  </thead>
                  <tbody>
                    {Object.entries(s.storage.by_kind).map(([key, k]) => (
                      <tr key={key}>
                        <td>{k.label}</td>
                        <td>{k.files.toLocaleString()}</td>
                        <td>{formatBytes(k.bytes)}</td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
            <Card title="Records held">
              <div className="stats">
                {Object.entries(s.database.tables).map(([table, count]) => (
                  <Stat key={table} label={TABLE_LABELS[table] ?? table} value={count.toLocaleString()} />
                ))}
              </div>
            </Card>
            <Card title="Background work">
              <div className="stats">
                <Stat label="Jobs waiting" value={s.queue.waiting ?? '?'} hint={s.queue.driver} />
                <Stat label="Backups waiting" value={s.queue.waiting_backups ?? '?'} />
                <Stat label="Jobs failed" value={s.queue.failed} />
                <Stat label="Scheduler" value={s.scheduler.ok ? 'Running' : 'Not running'} hint={`last seen ${ago(s.scheduler.last_beat_seconds_ago)}`} />
              </div>
              <p className="muted small">The scheduler sends reminders and summaries, makes the nightly backup and removes old records. If it is not running, none of those happen.</p>
            </Card>
            <Card title="Database updates">
              {s.database.pending_migrations.length === 0 ? (
                <p>The database is up to date.</p>
              ) : (
                <Alert tone="warn">
                  {plural(s.database.pending_migrations.length, 'update')} not applied yet: {s.database.pending_migrations.join(', ')}. Run <code>php artisan migrate --database=pgsql_migrate --force</code> on the server.
                </Alert>
              )}
            </Card>
            <Card title="Backups">
              <p>
                {s.backups.count === 0 ? 'No backup has been made.' : `${plural(s.backups.count, 'backup')} kept. The latest is ${s.backups.age_hours === null ? '' : `${s.backups.age_hours} hours old`}.`} <Link to="/admin/backups">Open Backups</Link>
              </p>
            </Card>
            <FailedJobs count={s.queue.failed} />
            <Housekeeping />
          </>
        )}
      </QueryView>
    </>
  )
}

function FailedJobs({ count }: { count: number }) {
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const query = useQuery({ queryKey: ['system', 'failed-jobs', page], queryFn: () => api.get<Page<FailedJob>>('/system/failed-jobs', { page }), placeholderData: (previous) => previous })
  const keys = [['system']]
  const retry = useApiMutation((uuid: string) => api.post(`/system/failed-jobs/${uuid}/retry`), { invalidate: keys, success: 'The job was put back in the queue.', toastError: true })
  const retryAll = useApiMutation(() => api.post<{ retried: number }>('/system/failed-jobs/retry'), { invalidate: keys, success: (r) => `${plural(r.retried, 'job')} put back in the queue.`, toastError: true })
  const forget = useApiMutation((uuid: string) => api.delete(`/system/failed-jobs/${uuid}`), { invalidate: keys, success: 'Deleted.', toastError: true })
  const clear = useApiMutation(() => api.delete('/system/failed-jobs'), { invalidate: keys, success: 'Failed jobs cleared.', toastError: true })

  return (
    <Card
      title={`Failed jobs${count ? ` (${count})` : ''}`}
      actions={
        count > 0 && (
          <>
            <Button small onClick={() => retryAll.mutate()} loading={retryAll.isPending}>
              Retry all
            </Button>
            <Button
              small
              variant="danger"
              loading={clear.isPending}
              onClick={async () => {
                if (await confirm({ title: 'Delete every failed job?', message: 'They will not be retried. Emails or notifications they were meant to send will not go out.', confirmLabel: 'Delete all', danger: true })) clear.mutate()
              }}
            >
              Delete all
            </Button>
          </>
        )
      }
    >
      <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No failed jobs">Everything that was queued has run.</EmptyState>}>
        {(data) => (
          <>
            <Table caption="Failed jobs">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Job</th>
                  <th>What went wrong</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {data.data.map((job) => (
                  <tr key={job.uuid}>
                    <td>{formatDateTime(job.failed_at)}</td>
                    <td>
                      {job.job.split('\\').pop()}
                      <span className="muted small block">queue: {job.queue}</span>
                    </td>
                    <td className="small">{job.error}</td>
                    <td className="actions">
                      <Button small loading={retry.isPending && retry.variables === job.uuid} onClick={() => retry.mutate(job.uuid)}>
                        Retry
                      </Button>
                      <Button small variant="ghost" onClick={() => forget.mutate(job.uuid)}>
                        Delete
                      </Button>
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
  )
}

function Housekeeping() {
  const confirm = useConfirm()
  const refresh = useApiMutation(() => api.post<{ message: string }>('/system/refresh'), { invalidate: [['overview'], ['settings'], ['roles']], success: (r) => r.message, toastError: true })
  const dry = useApiMutation(() => api.post<{ removed: Record<string, number> }>('/system/prune', { dry_run: true }), { toastError: true })
  const run = useApiMutation(() => api.post<{ removed: Record<string, number> }>('/system/prune'), { invalidate: [['system']], success: 'Old records removed.', toastError: true })
  const result = run.data ?? dry.data
  const total = result ? Object.values(result.removed).reduce((a, b) => a + b, 0) : 0

  return (
    <Card title="Housekeeping">
      <p className="muted">The system tidies itself every night. You can do it now, or refresh the saved copies it keeps of reports, settings and permissions if something looks out of date.</p>
      <div className="form-actions left">
        <Button onClick={() => refresh.mutate()} loading={refresh.isPending}>
          Refresh saved copies
        </Button>
        <Button onClick={() => dry.mutate()} loading={dry.isPending}>
          See what would be removed
        </Button>
        <Button
          variant="danger"
          loading={run.isPending}
          onClick={async () => {
            if (await confirm({ title: 'Remove old records now?', message: 'Expired sign-ins, used links, old read notifications and old security events (kept as long as your retention settings say) are deleted. Grades, submissions and the audit log are never touched.', confirmLabel: 'Remove them', danger: true })) run.mutate()
          }}
        >
          Remove old records now
        </Button>
      </div>
      {result && (
        <>
          <p>
            <strong>{run.data ? 'Removed' : 'Would remove'}:</strong> {total === 0 ? 'nothing; there is nothing old enough.' : ''}
          </p>
          {total > 0 && (
            <ul className="plain-list">
              {Object.entries(result.removed)
                .filter(([, n]) => n > 0)
                .map(([key, n]) => (
                  <li key={key}>
                    {PRUNE_LABELS[key] ?? key}: {n.toLocaleString()}
                  </li>
                ))}
            </ul>
          )}
        </>
      )}
    </Card>
  )
}
