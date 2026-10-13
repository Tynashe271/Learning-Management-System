import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../api/client'
import type { Offering, Page, SharedResourceModule } from '../api/types'
import { Button, FormError, Modal, PageHeader, QueryView, SelectField, Table } from '../components/ui'
import { useApiMutation, useTitle } from '../lib/hooks'

function ImportDialog({ module, onClose }: { module: SharedResourceModule; onClose: () => void }) {
  const offerings = useQuery({ queryKey: ['offerings', 'mine-for-import'], queryFn: () => api.get<Page<Offering>>('/offerings', { archived: 'exclude', per_page: 100 }) })
  const [destination, setDestination] = useState('')
  const importModule = useApiMutation<void, SharedResourceModule>(() => api.post(`/resource-library/modules/${module.id}/import`, { destination_offering_id: Number(destination) }), {
    success: 'Topic imported into the chosen course.',
    onSuccess: onClose,
  })

  return (
    <Modal title={`Import "${module.title}"`} onClose={onClose}>
      <p className="muted">
        This copies the topic and its materials (including files) into a course you manage. The original, at {module.course.code}, is left untouched.
      </p>
      <SelectField label="Import into" value={destination} onChange={(e) => setDestination(e.target.value)}>
        <option value="">Choose a course…</option>
        {(offerings.data?.data ?? []).map((o) => (
          <option key={o.id} value={o.id}>
            {o.course?.code} {o.course?.title} - section {o.section}
          </option>
        ))}
      </SelectField>
      <FormError error={importModule.error} />
      <div className="form-actions">
        <Button variant="primary" loading={importModule.isPending} disabled={!destination} onClick={() => importModule.mutate()}>
          Import
        </Button>
      </div>
    </Modal>
  )
}

/** A cross-course library of topics other lecturers have chosen to share, so a topic can be reused instead of rebuilt from scratch. */
export function ResourceLibraryPage() {
  useTitle('Resource library')
  const [importing, setImporting] = useState<SharedResourceModule | null>(null)
  const query = useQuery({ queryKey: ['resource-library', 'modules'], queryFn: () => api.get<SharedResourceModule[]>('/resource-library/modules') })

  return (
    <>
      <PageHeader title="Resource library" subtitle="Topics other lecturers have shared across courses. Import one into a course you manage instead of building it from scratch." />
      <QueryView query={query} isEmpty={(data) => data.length === 0} empty={<p className="muted">Nobody has shared a topic yet.</p>}>
        {(modules) => (
          <Table caption="Shared topics">
            <thead>
              <tr>
                <th>Topic</th>
                <th>Course</th>
                <th>Semester</th>
                <th>Shared by</th>
                <th>Materials</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {modules.map((m) => (
                <tr key={m.id}>
                  <td>{m.title}</td>
                  <td>
                    {m.course.code} {m.course.title} ({m.section})
                  </td>
                  <td>{m.term}</td>
                  <td>{m.teachers.join(', ') || <span className="muted">Unknown</span>}</td>
                  <td>{m.items_count}</td>
                  <td>
                    <Button small onClick={() => setImporting(m)}>
                      Import into…
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </QueryView>
      {importing && <ImportDialog module={importing} onClose={() => setImporting(null)} />}
    </>
  )
}
