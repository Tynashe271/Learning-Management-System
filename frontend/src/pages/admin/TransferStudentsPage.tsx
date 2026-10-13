import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { Offering, Page, Term } from '../../api/types'
import { Alert, Button, Card, PageHeader, SelectField } from '../../components/ui'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'

interface TransferResult {
  transferred: number
  already_enrolled: number[]
  full: number[]
  not_found: number[]
}

function OfferingPicker({ label, termId, offeringId, onTerm, onOffering }: { label: string; termId: string; offeringId: string; onTerm: (id: string) => void; onOffering: (id: string) => void }) {
  const terms = useQuery({ queryKey: ['terms', 'all'], queryFn: () => api.get<Page<Term>>('/terms', { page: 1 }) })
  const offerings = useQuery({
    queryKey: ['offerings', 'for-transfer', termId],
    queryFn: () => api.get<Page<Offering>>('/offerings', { term_id: termId, archived: 'exclude' }),
    enabled: termId !== '',
  })

  return (
    <div className="filters">
      <SelectField label={`${label}: semester`} value={termId} onChange={(e) => { onTerm(e.target.value); onOffering('') }}>
        <option value="">Choose a semester…</option>
        {(terms.data?.data ?? []).map((t) => (
          <option key={t.id} value={t.id}>
            {t.name}
          </option>
        ))}
      </SelectField>
      <SelectField label={`${label}: offering`} value={offeringId} onChange={(e) => onOffering(e.target.value)} disabled={termId === ''}>
        <option value="">Choose an offering…</option>
        {(offerings.data?.data ?? []).map((o) => (
          <option key={o.id} value={o.id}>
            {o.course?.code} {o.course?.title} - section {o.section}
          </option>
        ))}
      </SelectField>
    </div>
  )
}

/** Bulk-move (or copy) every actively enrolled student from one offering into another - a transfer between sections, or a promotion into next term's course - with a duplicate check so nobody is enrolled twice. */
export function TransferStudentsPage() {
  useTitle('Transfer students')
  const [sourceTerm, setSourceTerm] = useState('')
  const [sourceOffering, setSourceOffering] = useState('')
  const [destTerm, setDestTerm] = useState('')
  const [destOffering, setDestOffering] = useState('')
  const [mode, setMode] = useState<'transfer' | 'copy'>('transfer')
  const transfer = useApiMutation<void, TransferResult>(() => api.post(`/offerings/${destOffering}/transfer-in`, { source_offering_id: Number(sourceOffering), mode }))

  return (
    <>
      <PageHeader title="Transfer students" subtitle="Move or copy every actively enrolled student from one offering into another, for a transfer, a promotion, or any other bulk change of class." />
      <Card>
        <OfferingPicker label="From" termId={sourceTerm} offeringId={sourceOffering} onTerm={setSourceTerm} onOffering={setSourceOffering} />
        <OfferingPicker label="To" termId={destTerm} offeringId={destOffering} onTerm={setDestTerm} onOffering={setDestOffering} />
        <SelectField label="What should happen to the source enrolment?" value={mode} onChange={(e) => setMode(e.target.value as 'transfer' | 'copy')}>
          <option value="transfer">Transfer - withdraw from the source offering once moved</option>
          <option value="copy">Copy - keep the source enrolment too (e.g. taking both at once)</option>
        </SelectField>
        {fieldError(transfer.error, 'source_offering_id') && <Alert tone="error">{fieldError(transfer.error, 'source_offering_id')}</Alert>}
        <div className="form-actions">
          <Button variant="primary" loading={transfer.isPending} disabled={!sourceOffering || !destOffering || sourceOffering === destOffering} onClick={() => transfer.mutate()}>
            Move students
          </Button>
        </div>
        {transfer.data && (
          <Alert tone="success">
            {transfer.data.transferred} student{transfer.data.transferred === 1 ? '' : 's'} moved.
            {transfer.data.already_enrolled.length > 0 && ` ${transfer.data.already_enrolled.length} already had a place in the destination and were left as they were.`}
            {transfer.data.full.length > 0 && ` ${transfer.data.full.length} could not be moved because the destination is full.`}
          </Alert>
        )}
      </Card>
    </>
  )
}
