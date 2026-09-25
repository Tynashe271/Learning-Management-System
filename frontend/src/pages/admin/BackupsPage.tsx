import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { BackupItem, BackupsResult, BackupVerification } from '../../api/types'
import { Alert, Badge, Button, Card, CheckField, EmptyState, PageHeader, QueryView, Table, useConfirm } from '../../components/ui'
import { formatBytes, formatDateTime, plural, relativeTime } from '../../lib/format'
import { DownloadButton, useApiMutation, useTitle } from '../../lib/hooks'

/** Making, checking, downloading and deleting backups. Restoring is deliberately not a button: it is done at the server. */
export function BackupsPage() {
  useTitle('Backups')
  const [files, setFiles] = useState(true)
  const confirm = useConfirm()
  const [checked, setChecked] = useState<Record<string, BackupVerification>>({})
  const query = useQuery({
    queryKey: ['backups'],
    queryFn: () => api.get<BackupsResult>('/backups'),
    // While one is being made, look again every few seconds so it appears in the list when it is done.
    refetchInterval: (q) => (q.state.data?.status.running ? 4000 : 60_000),
  })
  const create = useApiMutation(() => api.post<{ message: string }>('/backups', { include_files: files }), { invalidate: [['backups'], ['system']], success: 'The backup has started. It appears in the list when it is done.', toastError: true })
  const verify = useApiMutation((name: string) => api.post<BackupVerification>(`/backups/${name}/verify`).then((r) => ({ name, r })), {
    onSuccess: ({ name, r }) => setChecked((c) => ({ ...c, [name]: r })),
    toastError: true,
  })
  const remove = useApiMutation((name: string) => api.delete(`/backups/${name}`), { invalidate: [['backups'], ['system']], success: 'Backup deleted.', toastError: true })

  return (
    <>
      <PageHeader title="Backups" subtitle="A copy of every account, course, grade and message, and the uploaded files, that you can go back to" />
      <QueryView query={query}>
        {(data) => (
          <>
            {data.status.last_error && <Alert tone="error">The last backup failed: {data.status.last_error}</Alert>}
            <Card title="Make a backup now">
              <p className="muted">
                {data.automatic.enabled ? `A backup is also made automatically every night at ${data.automatic.at}, and the newest ${data.automatic.keep} are kept.` : 'Automatic backups are switched off (Settings > Backups).'} Kept in: <code>{data.location}</code>.
              </p>
              <CheckField label="Include uploaded files" hint="Course files, submissions and message attachments. Much bigger, but without them a restore has the records and not the documents." checked={files} onChange={(e) => setFiles(e.target.checked)} />
              <Button variant="primary" onClick={() => create.mutate()} loading={create.isPending || data.status.running} disabled={data.status.running}>
                {data.status.running ? 'A backup is running…' : 'Back up now'}
              </Button>
            </Card>

            <Card title="Backups">
              {data.backups.length === 0 ? (
                <EmptyState title="No backups yet">Make one above. A system without a tested backup is one bad day from losing everything.</EmptyState>
              ) : (
                <Table caption="Backups">
                  <thead>
                    <tr>
                      <th>Made</th>
                      <th>Size</th>
                      <th>Contains</th>
                      <th>Check</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {data.backups.map((b) => (
                      <BackupRow
                        key={b.name}
                        backup={b}
                        result={checked[b.name]}
                        verifying={verify.isPending && verify.variables === b.name}
                        onVerify={() => verify.mutate(b.name)}
                        onDelete={async () => {
                          if (await confirm({ title: 'Delete this backup?', message: `${b.name} will be gone for good.`, confirmLabel: 'Delete', danger: true })) remove.mutate(b.name)
                        }}
                      />
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>

            <Card title="Restoring a backup">
              <p>
                Restoring replaces everything with the contents of a backup, and signs everyone out. Because it cannot be undone, it is not a button here. A person with access to the server does it, on purpose:
              </p>
              <pre className="code">{data.restore_command}</pre>
              <p className="muted">
                The command checks the backup first, makes a safety copy of the current data, turns the site off while it works, and turns it back on when it is done. Add <code>--skip-files</code> to restore only the database. Try a restore on a test copy at least once, so you know it works before the day you need it.
              </p>
              <p className="muted small">Downloads are recorded in the security log: a backup holds every password hash and every grade, so keep downloaded copies somewhere safe.</p>
            </Card>
          </>
        )}
      </QueryView>
    </>
  )
}

function BackupRow({ backup: b, result, verifying, onVerify, onDelete }: { backup: BackupItem; result?: BackupVerification; verifying: boolean; onVerify: () => void; onDelete: () => void }) {
  return (
    <tr>
      <td>
        {formatDateTime(b.created_at)}
        <span className="muted small block">{relativeTime(b.created_at)}</span>
      </td>
      <td>{formatBytes(b.size)}</td>
      <td className="small">
        {b.rows !== null ? `${b.rows.toLocaleString()} records` : ''}
        {b.include_files ? (
          <span className="block">
            {plural(b.files ?? 0, 'file')} ({formatBytes(b.file_bytes ?? 0)})
            {(b.missing_files ?? 0) > 0 && <Badge tone="warn">{b.missing_files} missing</Badge>}
          </span>
        ) : (
          <span className="block muted">database only</span>
        )}
      </td>
      <td>
        {result ? (
          result.ok ? (
            <Badge tone="good">Intact ({result.checked} items checked)</Badge>
          ) : (
            <span>
              <Badge tone="bad">Damaged</Badge>
              <ul className="plain-list small">
                {result.problems.slice(0, 5).map((p) => (
                  <li key={p}>{p}</li>
                ))}
              </ul>
            </span>
          )
        ) : (
          <span className="muted">Not checked</span>
        )}
      </td>
      <td className="actions">
        <Button small onClick={onVerify} loading={verifying}>
          Check it
        </Button>
        <DownloadButton path={`/backups/${b.name}/download`} filename={b.name}>
          Download
        </DownloadButton>
        <Button small variant="ghost" onClick={onDelete}>
          Delete
        </Button>
      </td>
    </tr>
  )
}
