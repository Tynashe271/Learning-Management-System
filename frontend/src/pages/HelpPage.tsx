import { useState } from 'react'
import { api } from '../api/client'
import type { SupportRequest, SupportRequestCategory } from '../api/types'
import { Button, Card, PageHeader, SelectField, TextArea, TextField } from '../components/ui'
import { fieldError, useApiMutation, useTitle } from '../lib/hooks'

const CATEGORY_LABELS: Record<SupportRequestCategory, string> = {
  sign_in: 'I cannot sign in',
  submission: 'A submission or upload failed',
  technical: 'Something is not working',
  other: 'Something else',
}

/** Anyone signed in can report a problem here; an administrator triages it in the Support centre. */
export function HelpPage() {
  useTitle('Get help')
  const [category, setCategory] = useState<SupportRequestCategory>('technical')
  const [subject, setSubject] = useState('')
  const [description, setDescription] = useState('')
  const file = useApiMutation(() => api.post<SupportRequest>('/support-requests', { category, subject: subject.trim(), description: description.trim() }), {
    success: "Thanks - we've received your request.",
    onSuccess: () => {
      setSubject('')
      setDescription('')
    },
  })

  return (
    <>
      <PageHeader title="Get help" subtitle="Tell us what went wrong. An administrator will follow up." />
      <Card>
        <form
          onSubmit={(event) => {
            event.preventDefault()
            file.mutate()
          }}
        >
          <SelectField label="What kind of problem is this?" value={category} onChange={(e) => setCategory(e.target.value as SupportRequestCategory)}>
            {Object.entries(CATEGORY_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </SelectField>
          <TextField label="Subject" value={subject} onChange={(e) => setSubject(e.target.value)} error={fieldError(file.error, 'subject')} required maxLength={150} />
          <TextArea label="What happened?" rows={5} value={description} onChange={(e) => setDescription(e.target.value)} error={fieldError(file.error, 'description')} required />
          <div className="form-actions">
            <Button type="submit" variant="primary" loading={file.isPending} disabled={!subject.trim() || !description.trim()}>
              Send request
            </Button>
          </div>
        </form>
      </Card>
    </>
  )
}
