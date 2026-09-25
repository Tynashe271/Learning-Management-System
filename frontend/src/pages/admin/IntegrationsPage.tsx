import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { Integration } from '../../api/types'
import { Alert, Badge, Button, Card, PageHeader, QueryView } from '../../components/ui'
import { useApiMutation, useTitle } from '../../lib/hooks'

const STATUS: Record<Integration['status'], { tone: 'good' | 'warn' | 'bad' | 'neutral' | 'info'; label: string }> = {
  ok: { tone: 'good', label: 'Working' },
  warn: { tone: 'warn', label: 'Check this' },
  error: { tone: 'bad', label: 'Not set up properly' },
  off: { tone: 'neutral', label: 'Off' },
  manual: { tone: 'info', label: 'By file' },
  info: { tone: 'info', label: 'Available' },
}

/** How the system connects to the rest of the institution. Each connection can be tried for real; the details are changed on the server. */
export function IntegrationsPage() {
  useTitle('Integrations')
  const query = useQuery({ queryKey: ['integrations'], queryFn: () => api.get<{ integrations: Integration[] }>('/integrations') })
  return (
    <>
      <PageHeader title="Integrations" subtitle="Single sign-on, email, file storage, virus scanning, student records and more" />
      <Alert tone="info">Connection addresses, passwords and secrets are kept in the server&apos;s configuration, never in the app, so they cannot be read or changed from here. Each card says which setting to change.</Alert>
      <QueryView query={query}>{(data) => data.integrations.map((i) => <IntegrationCard key={i.key} integration={i} />)}</QueryView>
    </>
  )
}

function IntegrationCard({ integration: i }: { integration: Integration }) {
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)
  const test = useApiMutation(() => api.post<{ ok: boolean; message: string }>(`/integrations/${i.key}/test`), { onSuccess: setResult, toastError: true })
  const status = STATUS[i.status]
  return (
    <Card title={i.label} actions={<Badge tone={status.tone}>{status.label}</Badge>}>
      <p>{i.summary}</p>
      {Object.keys(i.details).length > 0 && (
        <dl className="facts">
          {Object.entries(i.details).map(([key, value]) => (
            <div className="fact" key={key}>
              <dt>{key}</dt>
              <dd>{value === null || value === '' ? '—' : String(value)}</dd>
            </div>
          ))}
        </dl>
      )}
      <p className="muted small">To change: {i.change}</p>
      {i.can_test && (
        <div className="form-actions left">
          <Button onClick={() => test.mutate()} loading={test.isPending}>
            {i.key === 'email' ? 'Send me a test email' : 'Test the connection'}
          </Button>
        </div>
      )}
      {result && <Alert tone={result.ok ? 'success' : 'error'}>{result.message}</Alert>}
    </Card>
  )
}
