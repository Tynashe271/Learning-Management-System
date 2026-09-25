import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../api/client'
import type { LearningItem, Module, Offering, Progress } from '../../api/types'
import { Badge, Button, Card, CheckField, EmptyState, FileField, FormError, Modal, ProgressRing, PublishedBadge, TextArea, TextField, useConfirm } from '../../components/ui'
import { DownloadButton, bool, fieldError, useApiMutation } from '../../lib/hooks'
import { formatPercent } from '../../lib/format'
import { useOffering } from './context'

export function ContentTab() {
  const { offering, id, manage, student } = useOffering()
  const modules = offering.modules ?? []
  const [moduleDialog, setModuleDialog] = useState<Module | 'new' | null>(null)

  const progress = useQuery({ queryKey: ['progress', id, 'me'], queryFn: () => api.get<Progress>(`/offerings/${id}/progress`), enabled: student })
  const done = new Set(progress.data?.completed_item_ids ?? [])
  // The first not-yet-completed item, in module/item order, is the one worth pointing a student at next.
  const nextItemId = student ? modules.flatMap((m) => m.items ?? []).find((item) => !done.has(item.id))?.id : undefined

  return (
    <>
      {student && progress.data && progress.data.items_total > 0 && (
        <Card>
          <div className="progress-summary">
            <ProgressRing percent={progress.data.percent ?? 0} size={64} stroke={6} label="Your progress in this course" />
            <div className="progress-line">
              <span>
                Your progress: <strong>{progress.data.completed}</strong> of {progress.data.items_total} items · {formatPercent(progress.data.percent)}
              </span>
              <span className="muted small">{(progress.data.percent ?? 0) >= 100 ? "You've completed everything here. Nicely done." : 'Keep going — pick up where you left off below.'}</span>
            </div>
          </div>
        </Card>
      )}
      {manage && (
        <p>
          <Button variant="primary" onClick={() => setModuleDialog('new')}>
            Add a module
          </Button>
        </p>
      )}
      {modules.length === 0 && (
        <Card>
          <EmptyState title="No content yet" icon="📚" action={manage ? <Button variant="primary" onClick={() => setModuleDialog('new')}>Add the first module</Button> : undefined}>
            {manage ? 'Modules group your readings, links and files. Students see a module once you publish it.' : 'Your teacher has not published any material yet.'}
          </EmptyState>
        </Card>
      )}
      {modules.map((module) => (
        <ModuleCard key={module.id} module={module} offeringId={id} manage={manage} student={student} done={done} nextItemId={nextItemId} onEdit={() => setModuleDialog(module)} />
      ))}
      {moduleDialog && <ModuleDialog offering={offering} module={moduleDialog === 'new' ? null : moduleDialog} onClose={() => setModuleDialog(null)} />}
    </>
  )
}

function ModuleCard({
  module,
  offeringId,
  manage,
  student,
  done,
  nextItemId,
  onEdit,
}: {
  module: Module
  offeringId: number
  manage: boolean
  student: boolean
  done: Set<number>
  nextItemId?: number
  onEdit: () => void
}) {
  const confirm = useConfirm()
  const [itemDialog, setItemDialog] = useState<LearningItem | 'new' | null>(null)
  const refresh = [['offering', offeringId]]
  const remove = useApiMutation(() => api.delete(`/modules/${module.id}`), { invalidate: refresh, success: 'Module deleted.', toastError: true })
  const toggle = useApiMutation((published: boolean) => api.patch(`/modules/${module.id}`, { published }), { invalidate: refresh, toastError: true })
  const items = module.items ?? []
  const doneInModule = items.filter((item) => done.has(item.id)).length

  return (
    <Card
      title={
        <>
          {module.title} {manage && <PublishedBadge published={module.published} />}
          {student && items.length > 0 && (
            <Badge tone={doneInModule === items.length ? 'good' : 'neutral'}>
              {doneInModule}/{items.length} complete
            </Badge>
          )}
        </>
      }
      actions={
        manage && (
          <>
            <Button small onClick={() => setItemDialog('new')}>
              Add item
            </Button>
            <Button small onClick={() => toggle.mutate(!module.published)} loading={toggle.isPending}>
              {module.published ? 'Unpublish' : 'Publish'}
            </Button>
            <Button small onClick={onEdit}>
              Edit
            </Button>
            <Button
              small
              variant="danger"
              onClick={async () => {
                if (await confirm({ title: 'Delete this module?', message: `“${module.title}” and all of its items (and their files) will be removed. This cannot be undone.`, confirmLabel: 'Delete module', danger: true })) remove.mutate()
              }}
            >
              Delete
            </Button>
          </>
        )
      }
    >
      {items.length === 0 && <p className="muted">{manage ? 'This module has no items yet.' : 'Nothing here yet.'}</p>}
      <ul className="items">
        {items.map((item) => (
          <ItemRow key={item.id} item={item} manage={manage} student={student} done={done.has(item.id)} isNext={item.id === nextItemId} offeringId={offeringId} onEdit={() => setItemDialog(item)} />
        ))}
      </ul>
      {itemDialog && <ItemDialog module={module} item={itemDialog === 'new' ? null : itemDialog} offeringId={offeringId} onClose={() => setItemDialog(null)} />}
    </Card>
  )
}

const ICONS: Record<LearningItem['type'], string> = { text: '📄', link: '🔗', file: '📎' }

function ItemRow({
  item,
  manage,
  student,
  done,
  isNext,
  offeringId,
  onEdit,
}: {
  item: LearningItem
  manage: boolean
  student: boolean
  done: boolean
  isNext: boolean
  offeringId: number
  onEdit: () => void
}) {
  const confirm = useConfirm()
  const [open, setOpen] = useState(false)
  const refresh = [['offering', offeringId]]
  const remove = useApiMutation(() => api.delete(`/items/${item.id}`), { invalidate: refresh, success: 'Item deleted.', toastError: true })
  const complete = useApiMutation((value: boolean) => (value ? api.post(`/items/${item.id}/complete`) : api.delete(`/items/${item.id}/complete`)), { invalidate: [['progress', offeringId]], toastError: true })
  const toggle = useApiMutation((published: boolean) => api.patch(`/items/${item.id}`, { published }), { invalidate: refresh, toastError: true })

  return (
    <li className={`item${done ? ' item-done' : ''}${isNext ? ' item-next' : ''}`}>
      <div className="item-main">
        {student && <input type="checkbox" aria-label={`Mark “${item.title}” as done`} checked={done} disabled={complete.isPending} onChange={(e) => complete.mutate(e.target.checked)} />}
        <span aria-hidden="true">{ICONS[item.type]}</span>
        <div className="grow">
          <strong>{item.title}</strong> {manage && <PublishedBadge published={item.published} />}
          {item.type === 'link' && item.body && (
            <div>
              <a href={item.body} target="_blank" rel="noreferrer noopener">
                {item.body}
              </a>
            </div>
          )}
          {item.type === 'text' && item.body && (
            <div>
              <button type="button" className="link-btn" onClick={() => setOpen((v) => !v)} aria-expanded={open}>
                {open ? 'Hide text' : 'Read'}
              </button>
              {open && <div className="reading pre">{item.body}</div>}
            </div>
          )}
        </div>
        <div className="item-actions">
          {item.type === 'file' && (
            <DownloadButton path={`/items/${item.id}/download`} filename={item.title}>
              Download
            </DownloadButton>
          )}
          {manage && (
            <>
              <Button small onClick={() => toggle.mutate(!item.published)} loading={toggle.isPending}>
                {item.published ? 'Unpublish' : 'Publish'}
              </Button>
              <Button small onClick={onEdit}>
                Edit
              </Button>
              <Button
                small
                variant="danger"
                onClick={async () => {
                  if (await confirm({ title: 'Delete this item?', message: `“${item.title}” will be removed${item.type === 'file' ? ' along with its file' : ''}.`, confirmLabel: 'Delete item', danger: true })) remove.mutate()
                }}
              >
                Delete
              </Button>
            </>
          )}
        </div>
      </div>
    </li>
  )
}

function ModuleDialog({ offering, module, onClose }: { offering: Offering; module: Module | null; onClose: () => void }) {
  const [title, setTitle] = useState(module?.title ?? '')
  const [position, setPosition] = useState(String(module?.position ?? (offering.modules?.length ?? 0)))
  const [published, setPublished] = useState(module?.published ?? false)
  const save = useApiMutation(
    () => {
      const body = { title: title.trim(), position: Number(position) || 0, published }
      return module ? api.patch(`/modules/${module.id}`, body) : api.post(`/offerings/${offering.id}/modules`, body)
    },
    { invalidate: [['offering', offering.id]], success: module ? 'Module saved.' : 'Module added.', onSuccess: onClose },
  )
  return (
    <Modal title={module ? 'Edit module' : 'Add a module'} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        <TextField label="Position" type="number" min={0} value={position} onChange={(e) => setPosition(e.target.value)} hint="Modules are shown in ascending order." error={fieldError(save.error, 'position')} />
        <CheckField label="Published" hint="Students can see published modules." checked={published} onChange={(e) => setPublished(e.target.checked)} />
        {save.error && !fieldError(save.error, 'title') && !fieldError(save.error, 'position') && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim()}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function ItemDialog({ module, item, offeringId, onClose }: { module: Module; item: LearningItem | null; offeringId: number; onClose: () => void }) {
  const editing = item !== null
  const [type, setType] = useState<LearningItem['type']>(item?.type ?? 'text')
  const [title, setTitle] = useState(item?.title ?? '')
  const [body, setBody] = useState(item?.body ?? '')
  const [position, setPosition] = useState(String(item?.position ?? (module.items?.length ?? 0)))
  const [published, setPublished] = useState(item?.published ?? false)
  const [file, setFile] = useState<File | null>(null)

  const save = useApiMutation(
    () => {
      if (editing) {
        const changes: Record<string, unknown> = { title: title.trim(), position: Number(position) || 0, published }
        if (item.type !== 'file') changes.body = body
        return api.patch(`/items/${item.id}`, changes)
      }
      if (type === 'file') {
        const form = new FormData()
        form.append('title', title.trim())
        form.append('type', 'file')
        form.append('position', String(Number(position) || 0))
        form.append('published', bool(published))
        if (file) form.append('file', file)
        return api.upload(`/modules/${module.id}/items`, form)
      }
      return api.post(`/modules/${module.id}/items`, { title: title.trim(), type, body, position: Number(position) || 0, published })
    },
    { invalidate: [['offering', offeringId]], success: editing ? 'Item saved.' : 'Item added.', onSuccess: onClose },
  )

  return (
    <Modal title={editing ? 'Edit item' : `Add an item to “${module.title}”`} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {!editing && (
          <fieldset className="field">
            <legend>Type</legend>
            <div className="segmented">
              {(['text', 'link', 'file'] as const).map((t) => (
                <label key={t} className={type === t ? 'on' : ''}>
                  <input type="radio" name="item-type" value={t} checked={type === t} onChange={() => setType(t)} />
                  {t === 'text' ? 'Reading' : t === 'link' ? 'Link' : 'File'}
                </label>
              ))}
            </div>
          </fieldset>
        )}
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} autoFocus required />
        {type === 'text' && <TextArea label="Text" rows={8} value={body} onChange={(e) => setBody(e.target.value)} error={fieldError(save.error, 'body')} required />}
        {type === 'link' && <TextField label="Web address" type="url" placeholder="https://" value={body} onChange={(e) => setBody(e.target.value)} error={fieldError(save.error, 'body')} required />}
        {type === 'file' && !editing && (
          <FileField label="File" onChange={(e) => setFile(e.target.files?.[0] ?? null)} error={fieldError(save.error, 'file')} hint="Documents, slides, spreadsheets, images, audio, video or ZIP files up to 25 MB." required />
        )}
        {editing && item.type === 'file' && <p className="muted small">The file cannot be replaced. Add a new item to upload a different one.</p>}
        <TextField label="Position" type="number" min={0} value={position} onChange={(e) => setPosition(e.target.value)} error={fieldError(save.error, 'position')} />
        <CheckField label="Published" hint="Students can see published items in published modules." checked={published} onChange={(e) => setPublished(e.target.checked)} />
        {save.error && !fieldError(save.error, 'title') && !fieldError(save.error, 'body') && !fieldError(save.error, 'file') && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!title.trim() || (!editing && type === 'file' && !file)}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

