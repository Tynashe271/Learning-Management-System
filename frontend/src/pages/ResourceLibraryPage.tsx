import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../api/client'
import { LIBRARY_RESOURCE_CATEGORIES, LIBRARY_RESOURCE_CATEGORY_LABELS } from '../api/types'
import type { LibraryResource, LibraryResourceCategory, Offering, Page, SharedResourceModule } from '../api/types'
import { useAuth } from '../auth/AuthContext'
import { Button, FileField, FormError, Modal, PageHeader, QueryView, SelectField, Table, TextArea, TextField, useConfirm } from '../components/ui'
import { formatDate } from '../lib/format'
import { DownloadButton, fieldError, useApiMutation, useTitle } from '../lib/hooks'

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

/** A table of shared topics, visible only to teaching staff (importing changes a course they manage). */
function SharedTopicsSection() {
  const [importing, setImporting] = useState<SharedResourceModule | null>(null)
  const query = useQuery({ queryKey: ['resource-library', 'modules'], queryFn: () => api.get<SharedResourceModule[]>('/resource-library/modules') })

  return (
    <section>
      <h2>Shared topics</h2>
      <p className="muted">Topics other lecturers have shared across courses. Import one into a course you manage instead of building it from scratch.</p>
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
    </section>
  )
}

function UploadMaterialDialog({ onClose }: { onClose: () => void }) {
  const [title, setTitle] = useState('')
  const [category, setCategory] = useState<LibraryResourceCategory>('textbook')
  const [courseCode, setCourseCode] = useState('')
  const [description, setDescription] = useState('')
  const [file, setFile] = useState<File | null>(null)

  const upload = useApiMutation<void, LibraryResource>(
    () => {
      const form = new FormData()
      form.append('title', title.trim())
      form.append('category', category)
      if (courseCode.trim()) form.append('course_code', courseCode.trim())
      if (description.trim()) form.append('description', description.trim())
      if (file) form.append('file', file)
      return api.upload('/resource-library/materials', form)
    },
    { invalidate: [['resource-library', 'materials']], success: 'Material uploaded to the resource library.', onSuccess: onClose },
  )

  return (
    <Modal title="Upload a textbook, past paper or reading material" onClose={onClose}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          upload.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(upload.error, 'title')} required />
        <SelectField label="Category" value={category} onChange={(e) => setCategory(e.target.value as LibraryResourceCategory)} error={fieldError(upload.error, 'category')}>
          {LIBRARY_RESOURCE_CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {LIBRARY_RESOURCE_CATEGORY_LABELS[c]}
            </option>
          ))}
        </SelectField>
        <TextField label="Course code" optional value={courseCode} onChange={(e) => setCourseCode(e.target.value)} error={fieldError(upload.error, 'course_code')} hint="e.g. CSC301 - helps others filter the library." />
        <TextArea label="Description" optional value={description} onChange={(e) => setDescription(e.target.value)} error={fieldError(upload.error, 'description')} />
        <FileField label="File" onChange={(e) => setFile(e.target.files?.[0] ?? null)} error={fieldError(upload.error, 'file')} hint="Documents, slides, spreadsheets or images up to 25 MB." />
        {upload.error && !fieldError(upload.error, 'title') && !fieldError(upload.error, 'category') && !fieldError(upload.error, 'file') && <FormError error={upload.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={upload.isPending} disabled={!title.trim() || !file}>
            Upload
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** Textbooks, past exam papers and other reading material teaching staff share institution-wide; anyone signed in can browse and download. */
function MaterialsSection({ canUpload }: { canUpload: boolean }) {
  const confirm = useConfirm()
  const [category, setCategory] = useState('')
  const [uploading, setUploading] = useState(false)
  const query = useQuery({
    queryKey: ['resource-library', 'materials', category],
    queryFn: () => api.get<LibraryResource[]>('/resource-library/materials', { category: category || undefined }),
  })
  const remove = useApiMutation<number, void>((id) => api.delete(`/resource-library/materials/${id}`), {
    invalidate: [['resource-library', 'materials']],
    success: 'Material removed.',
  })

  return (
    <section>
      <div className="card-head">
        <h2>Textbooks, past papers and reading material</h2>
        {canUpload && <Button onClick={() => setUploading(true)}>Upload material</Button>}
      </div>
      <p className="muted">Reading material teaching staff have made available across the institution.</p>
      <div className="filters">
        <SelectField label="Category" value={category} onChange={(e) => setCategory(e.target.value)}>
          <option value="">All categories</option>
          {LIBRARY_RESOURCE_CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {LIBRARY_RESOURCE_CATEGORY_LABELS[c]}
            </option>
          ))}
        </SelectField>
      </div>
      <QueryView query={query} isEmpty={(data) => data.length === 0} empty={<p className="muted">Nothing has been uploaded yet.</p>}>
        {(materials) => (
          <Table caption="Textbooks, past papers and reading material">
            <thead>
              <tr>
                <th>Title</th>
                <th>Category</th>
                <th>Course</th>
                <th>Shared by</th>
                <th>Uploaded</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {materials.map((m) => (
                <tr key={m.id}>
                  <td>
                    {m.title}
                    {m.description && <div className="muted small">{m.description}</div>}
                  </td>
                  <td>{LIBRARY_RESOURCE_CATEGORY_LABELS[m.category]}</td>
                  <td>{m.course_code || <span className="muted">—</span>}</td>
                  <td>{m.uploaded_by || <span className="muted">Unknown</span>}</td>
                  <td>{formatDate(m.created_at)}</td>
                  <td>
                    <div className="form-actions">
                      <DownloadButton path={`/resource-library/materials/${m.id}/download`} filename={m.title}>
                        Download
                      </DownloadButton>
                      {m.can_delete && (
                        <Button
                          small
                          onClick={async () => {
                            if (await confirm({ title: 'Remove this material?', message: `"${m.title}" will be removed from the resource library.`, confirmLabel: 'Remove', danger: true })) {
                              remove.mutate(m.id)
                            }
                          }}
                        >
                          Remove
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </QueryView>
      {uploading && <UploadMaterialDialog onClose={() => setUploading(false)} />}
    </section>
  )
}

export function ResourceLibraryPage() {
  useTitle('Resource library')
  const { can } = useAuth()
  const staff = can('teach-courses') || can('manage-courses')

  return (
    <>
      <PageHeader title="Resource library" subtitle="Reuse what other teaching staff have already shared, instead of building a course or finding reading material from scratch." />
      {staff && <SharedTopicsSection />}
      <MaterialsSection canUpload={staff} />
    </>
  )
}
