import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import type { Permission, RoleName, RolesResult } from '../../api/types'
import { ROLE_LABELS } from '../../api/types'
import { useAuth } from '../../auth/AuthContext'
import { Alert, Badge, Button, Card, EmptyState, FormError, PageHeader, QueryView, Table, useConfirm } from '../../components/ui'
import { plural } from '../../lib/format'
import { useApiMutation, useTitle } from '../../lib/hooks'

/** Who can do what. Everyone with the permission can see it; changing it needs the system permission (super administrators). */
export function RolesPage() {
  useTitle('Roles and permissions')
  const { can } = useAuth()
  const edit = can('manage-system')
  const query = useQuery({ queryKey: ['roles'], queryFn: () => api.get<RolesResult>('/roles') })
  return (
    <>
      <PageHeader title="Roles and permissions" subtitle="Every account has one role, and each role holds a set of permissions" />
      {!edit && <Alert tone="info">You can see what each role may do. Only a super administrator can change it.</Alert>}
      <QueryView query={query} isEmpty={(d) => d.roles.length === 0} empty={<EmptyState title="No roles" />}>
        {(data) => <Matrix data={data} edit={edit} />}
      </QueryView>
    </>
  )
}

function Matrix({ data, edit }: { data: RolesResult; edit: boolean }) {
  const confirm = useConfirm()
  const [selected, setSelected] = useState<RoleName>(data.roles.find((r) => !r.locked)?.name ?? data.roles[0].name)
  const role = data.roles.find((r) => r.name === selected) ?? data.roles[0]
  const [draft, setDraft] = useState<Permission[]>(role.permissions)
  useEffect(() => setDraft(role.permissions), [role])

  const changed = JSON.stringify([...draft].sort()) !== JSON.stringify([...role.permissions].sort())
  const isDefault = JSON.stringify([...role.permissions].sort()) === JSON.stringify([...role.defaults].sort())
  const save = useApiMutation(() => api.put(`/roles/${role.name}/permissions`, { permissions: draft }), { invalidate: [['roles'], ['me']], success: `${ROLE_LABELS[role.name]} saved.` })
  const reset = useApiMutation(() => api.post(`/roles/${role.name}/reset`), { invalidate: [['roles'], ['me']], success: `${ROLE_LABELS[role.name]} is back to its starting permissions.` })

  const lockRisk = draft.includes('manage-system') !== role.permissions.includes('manage-system') && !draft.includes('manage-system')

  const submit = async () => {
    if (lockRisk && !(await confirm({ title: 'Remove system access?', message: `${ROLE_LABELS[role.name]}s will lose the backups, roles and technical screens.`, confirmLabel: 'Remove', danger: true }))) return
    save.mutate()
  }

  return (
    <>
      <Card title="At a glance">
        <Table caption="Permissions by role">
          <thead>
            <tr>
              <th>Permission</th>
              {data.roles.map((r) => (
                <th key={r.name} className="center">
                  <button type="button" className={`link-button${r.name === selected ? ' active' : ''}`} onClick={() => setSelected(r.name)}>
                    {ROLE_LABELS[r.name]}
                  </button>
                  <div className="muted small">{plural(r.users, 'person', 'people')}</div>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.permissions.map((p) => (
              <tr key={p.name}>
                <td>
                  <code>{p.name}</code>
                  <div className="muted small">{p.description}</div>
                </td>
                {data.roles.map((r) => (
                  <td key={r.name} className="center" aria-label={`${ROLE_LABELS[r.name]}: ${r.permissions.includes(p.name) ? 'yes' : 'no'}`}>
                    {r.permissions.includes(p.name) ? <Badge tone="good">Yes</Badge> : <span className="muted">—</span>}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </Table>
        <p className="muted small">Teaching and marking rights within a course also depend on being assigned to that course, whatever the role says.</p>
      </Card>

      <Card title={`Change what a ${ROLE_LABELS[role.name]} may do`}>
        {role.locked ? (
          <Alert tone="info">The super administrator always holds every permission and cannot be edited. This is so nobody can lock the system out of its own administrators.</Alert>
        ) : (
          <>
            <div className="role-tabs" role="tablist" aria-label="Role to edit">
              {data.roles
                .filter((r) => !r.locked)
                .map((r) => (
                  <button key={r.name} type="button" role="tab" aria-selected={r.name === selected} className={`btn btn-small ${r.name === selected ? 'btn-primary' : 'btn-secondary'}`} onClick={() => setSelected(r.name)}>
                    {ROLE_LABELS[r.name]}
                  </button>
                ))}
            </div>
            <fieldset className="permissions" disabled={!edit}>
              <legend className="sr-only">Permissions for {ROLE_LABELS[role.name]}</legend>
              {data.permissions.map((p) => (
                <label key={p.name} className="perm">
                  <input type="checkbox" checked={draft.includes(p.name)} onChange={(e) => setDraft((d) => (e.target.checked ? [...d, p.name] : d.filter((x) => x !== p.name)))} />
                  <span>
                    <code>{p.name}</code>
                    <span className="hint block">{p.description}</span>
                    {role.defaults.includes(p.name) !== draft.includes(p.name) && <span className="hint block">{role.defaults.includes(p.name) ? 'Normally held by this role.' : 'Not normally held by this role.'}</span>}
                  </span>
                </label>
              ))}
            </fieldset>
            {(save.error || reset.error) && <FormError error={save.error ?? reset.error} />}
            {edit && (
              <div className="form-actions">
                <Button onClick={() => reset.mutate()} loading={reset.isPending} disabled={isDefault && !changed}>
                  Back to the starting permissions
                </Button>
                <Button variant="primary" loading={save.isPending} disabled={!changed} onClick={() => void submit()}>
                  Save {ROLE_LABELS[role.name]}
                </Button>
              </div>
            )}
            <p className="muted small">Changes apply the next time each person&apos;s screen loads. Every change is recorded in the audit log and the security log.</p>
          </>
        )}
      </Card>
    </>
  )
}
