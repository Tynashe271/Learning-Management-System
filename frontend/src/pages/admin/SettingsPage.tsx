import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { api } from '../../api/client'
import type { SettingDef, SettingsResult } from '../../api/types'
import { Alert, Badge, Button, Card, CheckField, FormError, PageHeader, QueryView, SelectField, TextField, useConfirm } from '../../components/ui'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'

type Value = SettingDef['value']

const GROUP_HELP: Record<string, string> = {
  Institution: 'Who you are. This appears on the sign-in page, in the header, in emails and in every date shown.',
  'Academic rules': 'Rules the system applies for you.',
  'Files and storage': 'What may be uploaded, and how big.',
  Security: 'Passwords, lockouts and how long a sign-in lasts. Changes apply to the next password set or sign-in.',
  Notifications: 'What the system sends by email and when.',
  Backups: 'The nightly backup. Making and checking backups by hand is under Backups.',
  Maintenance: 'Turn this on while you update or repair the system. Only super administrators can use it meanwhile.',
}

// An emptied box and "not set" are the same thing.
const same = (a: Value, b: Value) => JSON.stringify(a === '' ? null : a) === JSON.stringify(b === '' ? null : b)

export function SettingsPage() {
  useTitle('Settings')
  const query = useQuery({ queryKey: ['settings'], queryFn: () => api.get<SettingsResult>('/settings') })
  return (
    <>
      <PageHeader title="Settings" subtitle="Institution details, academic rules, uploads, security, notifications and maintenance" />
      <QueryView query={query}>{(data) => <SettingsForm data={data} />}</QueryView>
    </>
  )
}

function SettingsForm({ data }: { data: SettingsResult }) {
  const confirm = useConfirm()
  const server = useMemo(() => Object.fromEntries(data.groups.flatMap((g) => g.settings.map((s) => [s.key, s.value]))) as Record<string, Value>, [data])
  const [draft, setDraft] = useState<Record<string, Value>>(server)
  useEffect(() => setDraft(server), [server])

  const changedKeys = Object.keys(draft).filter((key) => !same(draft[key], server[key]))
  const save = useApiMutation((body: { settings?: Record<string, Value>; reset?: string[] }) => api.put<SettingsResult>('/settings', body), {
    // The sign-in page, the header, dates and password hints all read these, so refresh them too.
    invalidate: [['settings'], ['auth-config'], ['system'], ['system-announcements']],
    success: (result) => (result.changed?.length ? 'Settings saved.' : 'Nothing was changed.'),
  })

  const submit = async () => {
    if (draft['maintenance.enabled'] === true && server['maintenance.enabled'] !== true) {
      const ok = await confirm({
        title: 'Turn on maintenance mode?',
        message: 'Everyone except super administrators will be blocked from the system straight away, and will see your message instead. Turn it off here when you are done.',
        confirmLabel: 'Turn it on',
        danger: true,
      })
      if (!ok) return
    }
    save.mutate({ settings: Object.fromEntries(changedKeys.map((k) => [k, draft[k]])) })
  }

  return (
    <>
      <Alert tone="info">
        A setting you have not changed follows the server&apos;s configuration. “Use the default” removes your change. Connection details such as email, storage and single sign-on are kept on the server, not here; see Integrations.
      </Alert>
      {data.groups.map((group) => (
        <Card key={group.group} title={group.group}>
          {GROUP_HELP[group.group] && <p className="muted">{GROUP_HELP[group.group]}</p>}
          {group.settings.map((setting) => (
            <SettingRow
              key={setting.key}
              setting={setting}
              value={draft[setting.key]}
              error={fieldError(save.error, setting.key)}
              changed={!same(draft[setting.key], server[setting.key])}
              onChange={(value) => setDraft((d) => ({ ...d, [setting.key]: value }))}
              onReset={() => save.mutate({ reset: [setting.key] })}
              busy={save.isPending}
            />
          ))}
        </Card>
      ))}
      {save.error && !Object.keys(save.error.errors).some((k) => k in server) && <FormError error={save.error} />}
      <div className="sticky-actions">
        <span className="muted">{changedKeys.length === 0 ? 'No unsaved changes.' : `${changedKeys.length} unsaved ${changedKeys.length === 1 ? 'change' : 'changes'}.`}</span>
        <Button onClick={() => setDraft(server)} disabled={changedKeys.length === 0}>
          Discard changes
        </Button>
        <Button variant="primary" loading={save.isPending} disabled={changedKeys.length === 0} onClick={() => void submit()}>
          Save settings
        </Button>
      </div>
    </>
  )
}

function SettingRow({ setting, value, error, changed, onChange, onReset, busy }: { setting: SettingDef; value: Value; error?: string; changed: boolean; onChange: (value: Value) => void; onReset: () => void; busy: boolean }) {
  const hint = (
    <>
      {setting.help}
      {setting.overridden && (
        <>
          {' '}
          <Badge tone="info">changed from the default</Badge>{' '}
          <button type="button" className="link-button" disabled={busy || changed} onClick={onReset}>
            Use the default
          </button>
        </>
      )}
    </>
  )
  const label = setting.label

  if (setting.type === 'bool') {
    return (
      <div className="setting">
        <CheckField label={label} hint={hint} checked={value === true} onChange={(e) => onChange(e.target.checked)} />
        {error && <div className="field-error">{error}</div>}
      </div>
    )
  }
  if (setting.type === 'enum') {
    return (
      <div className="setting">
        <SelectField label={label} hint={hint} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} error={error}>
          {(setting.options ?? []).map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </SelectField>
      </div>
    )
  }
  if (setting.type === 'list') {
    const chosen = Array.isArray(value) ? value : []
    return (
      <fieldset className="setting checklist">
        <legend>{label}</legend>
        <div className="hint">{hint}</div>
        <div className="checks">
          {(setting.options ?? []).map((option) => (
            <label key={option} className="check-chip">
              <input type="checkbox" checked={chosen.includes(option)} onChange={(e) => onChange(e.target.checked ? [...chosen, option] : chosen.filter((o) => o !== option))} />
              {option}
            </label>
          ))}
        </div>
        {error && <div className="field-error">{error}</div>}
      </fieldset>
    )
  }
  if (setting.type === 'int') {
    return (
      <div className="setting">
        <TextField
          label={label}
          hint={hint}
          type="number"
          inputMode="numeric"
          min={setting.min ?? undefined}
          max={setting.max ?? undefined}
          value={value === null || value === undefined ? '' : String(value)}
          onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
          error={error}
        />
      </div>
    )
  }
  const long = setting.key === 'maintenance.message'
  return (
    <div className="setting">
      <TextField
        label={label}
        hint={hint}
        type={setting.type === 'email' ? 'email' : setting.type === 'url' ? 'url' : 'text'}
        value={typeof value === 'string' ? value : ''}
        maxLength={long ? 500 : undefined}
        onChange={(e) => onChange(e.target.value)}
        error={error}
      />
    </div>
  )
}
