import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { Course, CourseLevel, Department, Offering, Page, Term } from '../../api/types'
import { COURSE_LEVELS } from '../../api/types'
import { useAuth } from '../../auth/AuthContext'
import { Alert, Badge, Button, Card, CheckField, EmptyState, FormError, Modal, PageHeader, Pager, QueryView, SelectField, Table, TextArea, TextField, pagerFromPage, useConfirm, useDebounced } from '../../components/ui'
import { formatDate, plural, toDateInput } from '../../lib/format'
import { fieldError, useApiMutation, useTitle } from '../../lib/hooks'

const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)
const today = () => new Date().toISOString().slice(0, 10)

export function CataloguePage() {
  useTitle('Terms and courses')
  const { can } = useAuth()
  const edit = can('manage-courses')
  return (
    <>
      <PageHeader title="Terms and courses" subtitle="Academic years and terms, departments, the course catalogue, and what is offered each term" />
      {!edit && <Alert tone="info">You can look up terms and courses to enrol students. Only course administrators can change the catalogue.</Alert>}
      <TermsCard edit={edit} />
      <DepartmentsCard edit={edit} />
      <CoursesCard edit={edit} />
      {edit && <OfferingCard />}
    </>
  )
}

// ---- terms --------------------------------------------------------------------------------------------------------------

function registrationState(t: Term): { tone: 'good' | 'neutral' | 'warn'; label: string } | null {
  if (!t.registration_opens_on || !t.registration_closes_on) return null
  const now = today()
  if (now < toDateInput(t.registration_opens_on)) return { tone: 'warn', label: 'Opens soon' }
  if (now > toDateInput(t.registration_closes_on)) return { tone: 'neutral', label: 'Closed' }
  return { tone: 'good', label: 'Open now' }
}

function TermsCard({ edit }: { edit: boolean }) {
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [dialog, setDialog] = useState<Term | 'new' | null>(null)
  const query = useQuery({ queryKey: ['terms', page], queryFn: () => api.get<Page<Term>>('/terms', { page }), placeholderData: (previous) => previous })
  const archive = useApiMutation(({ term, archived }: { term: Term; archived: boolean }) => api.post<{ archived: boolean; offerings: number }>(`/terms/${term.id}/archive`, { archived }), {
    invalidate: [['offerings'], ['terms']],
    success: (r) => (r.archived ? `${plural(r.offerings, 'offering')} archived.` : `${plural(r.offerings, 'offering')} restored.`),
    toastError: true,
  })
  return (
    <Card
      title="Academic terms"
      actions={
        edit && (
          <Button small variant="primary" onClick={() => setDialog('new')}>
            New term
          </Button>
        )
      }
    >
      <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No terms yet">{edit ? 'Create a term (for example “Semester 1, 2026”) before offering courses.' : 'No terms have been created.'}</EmptyState>}>
        {(data) => (
          <>
            <Table caption="Terms">
              <thead>
                <tr>
                  <th>Term</th>
                  <th>Dates</th>
                  <th>Student registration</th>
                  <th>Add/drop until</th>
                  {edit && <th />}
                </tr>
              </thead>
              <tbody>
                {data.data.map((t) => {
                  const reg = registrationState(t)
                  return (
                    <tr key={t.id}>
                      <td>
                        <strong>{t.name}</strong> {t.is_current && <Badge tone="info">Current</Badge>}
                        {t.academic_year && <span className="muted small block">Academic year {t.academic_year}</span>}
                      </td>
                      <td className="small">
                        {formatDate(t.starts_on)} – {formatDate(t.ends_on)}
                      </td>
                      <td className="small">
                        {reg ? (
                          <>
                            <Badge tone={reg.tone}>{reg.label}</Badge>
                            <span className="block">
                              {formatDate(t.registration_opens_on)} – {formatDate(t.registration_closes_on)}
                            </span>
                          </>
                        ) : (
                          <span className="muted">Not set</span>
                        )}
                      </td>
                      <td className="small">{t.add_drop_deadline ? formatDate(t.add_drop_deadline) : reg ? <span className="muted">when registration closes</span> : '—'}</td>
                      {edit && (
                        <td className="actions">
                          <Button small onClick={() => setDialog(t)}>
                            Edit
                          </Button>
                          <Button
                            small
                            variant="ghost"
                            onClick={async () => {
                              if (await confirm({ title: `Archive ${t.name}?`, message: 'Every course offered in this term becomes read-only history and disappears from the current catalogue. Teachers and students can still open them. You can restore them later.', confirmLabel: 'Archive the term’s courses' })) archive.mutate({ term: t, archived: true })
                            }}
                          >
                            Archive
                          </Button>
                          <Button small variant="ghost" onClick={() => archive.mutate({ term: t, archived: false })}>
                            Restore
                          </Button>
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </Table>
            <Pager {...pagerFromPage(data)} onPage={setPage} />
          </>
        )}
      </QueryView>
      {dialog && <TermDialog term={dialog === 'new' ? null : dialog} onClose={() => setDialog(null)} />}
    </Card>
  )
}

function TermDialog({ term, onClose }: { term: Term | null; onClose: () => void }) {
  const [name, setName] = useState(term?.name ?? '')
  const [year, setYear] = useState(term?.academic_year ?? '')
  const [starts, setStarts] = useState(toDateInput(term?.starts_on))
  const [ends, setEnds] = useState(toDateInput(term?.ends_on))
  const [opens, setOpens] = useState(toDateInput(term?.registration_opens_on))
  const [closes, setCloses] = useState(toDateInput(term?.registration_closes_on))
  const [deadline, setDeadline] = useState(toDateInput(term?.add_drop_deadline))
  const [current, setCurrent] = useState(term?.is_current ?? false)
  const body = () => ({
    name: name.trim(),
    academic_year: year.trim() || null,
    starts_on: starts,
    ends_on: ends,
    registration_opens_on: opens || null,
    registration_closes_on: closes || null,
    add_drop_deadline: deadline || null,
    is_current: current,
  })
  const save = useApiMutation(() => (term ? api.patch(`/terms/${term.id}`, body()) : api.post('/terms', body())), { invalidate: [['terms'], ['offerings'], ['registration']], success: term ? 'Term saved.' : 'Term created.', onSuccess: onClose })
  const fields = ['name', 'academic_year', 'starts_on', 'ends_on', 'registration_opens_on', 'registration_closes_on', 'add_drop_deadline', 'is_current']
  return (
    <Modal title={term ? 'Edit term' : 'New term'} onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <div className="row">
          <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} error={fieldError(save.error, 'name')} maxLength={255} placeholder="Semester 1" autoFocus required />
          <TextField label="Academic year" optional value={year} onChange={(e) => setYear(e.target.value)} error={fieldError(save.error, 'academic_year')} maxLength={20} placeholder="2026/2027" />
        </div>
        <div className="row">
          <TextField label="Starts" type="date" value={starts} onChange={(e) => setStarts(e.target.value)} error={fieldError(save.error, 'starts_on')} required />
          <TextField label="Ends" type="date" value={ends} onChange={(e) => setEnds(e.target.value)} error={fieldError(save.error, 'ends_on')} required />
        </div>
        <h3>Course registration</h3>
        <p className="muted">Students can register for courses that allow it only between these dates. Leave both empty if the registrar enrols students.</p>
        <div className="row">
          <TextField label="Opens" optional type="date" value={opens} onChange={(e) => setOpens(e.target.value)} error={fieldError(save.error, 'registration_opens_on')} />
          <TextField label="Closes" optional type="date" value={closes} onChange={(e) => setCloses(e.target.value)} error={fieldError(save.error, 'registration_closes_on')} />
          <TextField label="Last day to add or drop" optional type="date" value={deadline} onChange={(e) => setDeadline(e.target.value)} error={fieldError(save.error, 'add_drop_deadline')} hint="Blank means the day registration closes." />
        </div>
        <CheckField label="This is the current term" hint="Only one term is current: choosing this clears it from the others." checked={current} onChange={(e) => setCurrent(e.target.checked)} />
        {save.error && !fields.some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!name.trim() || !starts || !ends}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

// ---- departments --------------------------------------------------------------------------------------------------------

function DepartmentsCard({ edit }: { edit: boolean }) {
  const confirm = useConfirm()
  const [archived, setArchived] = useState(false)
  const [dialog, setDialog] = useState<Department | 'new' | null>(null)
  const query = useQuery({ queryKey: ['departments', archived], queryFn: () => api.get<Department[]>('/departments', { archived: archived ? 'include' : 'exclude' }) })
  const keys = [['departments']]
  const toggle = useApiMutation((d: Department) => api.patch(`/departments/${d.id}`, { archived: !d.archived_at }), { invalidate: keys, success: 'Department updated.', toastError: true })
  const remove = useApiMutation((d: Department) => api.delete(`/departments/${d.id}`), { invalidate: keys, success: 'Department deleted.', toastError: true })
  return (
    <Card
      title="Departments and faculties"
      actions={
        <>
          <label className="inline-check small">
            <input type="checkbox" checked={archived} onChange={(e) => setArchived(e.target.checked)} /> Show archived
          </label>
          {edit && (
            <Button small variant="primary" onClick={() => setDialog('new')}>
              New department
            </Button>
          )}
        </>
      }
    >
      <QueryView query={query} isEmpty={(d) => d.length === 0} empty={<EmptyState title="No departments yet">{edit ? 'Departments group courses, so the catalogue and the reports can be organised by faculty.' : 'None have been created.'}</EmptyState>}>
        {(rows) => (
          <Table caption="Departments">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Courses</th>
                {edit && <th />}
              </tr>
            </thead>
            <tbody>
              {rows.map((d) => (
                <tr key={d.id}>
                  <td>
                    <strong>{d.code}</strong>
                  </td>
                  <td>
                    {d.name} {d.archived_at && <Badge tone="neutral">Archived</Badge>}
                  </td>
                  <td>{d.courses_count ?? 0}</td>
                  {edit && (
                    <td className="actions">
                      <Button small onClick={() => setDialog(d)}>
                        Edit
                      </Button>
                      <Button small variant="ghost" onClick={() => toggle.mutate(d)}>
                        {d.archived_at ? 'Restore' : 'Archive'}
                      </Button>
                      {!d.courses_count && (
                        <Button
                          small
                          variant="ghost"
                          onClick={async () => {
                            if (await confirm({ title: `Delete ${d.name}?`, confirmLabel: 'Delete', danger: true })) remove.mutate(d)
                          }}
                        >
                          Delete
                        </Button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </QueryView>
      {dialog && <DepartmentDialog department={dialog === 'new' ? null : dialog} onClose={() => setDialog(null)} />}
    </Card>
  )
}

function DepartmentDialog({ department, onClose }: { department: Department | null; onClose: () => void }) {
  const [code, setCode] = useState(department?.code ?? '')
  const [name, setName] = useState(department?.name ?? '')
  const save = useApiMutation(() => (department ? api.patch(`/departments/${department.id}`, { code: code.trim(), name: name.trim() }) : api.post('/departments', { code: code.trim(), name: name.trim() })), {
    invalidate: [['departments'], ['courses']],
    success: department ? 'Department saved.' : 'Department created.',
    onSuccess: onClose,
  })
  return (
    <Modal title={department ? 'Edit department' : 'New department'} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <TextField label="Code" value={code} onChange={(e) => setCode(e.target.value)} error={fieldError(save.error, 'code')} maxLength={20} placeholder="SCI" hint="Letters, numbers, dots, dashes." autoFocus required />
        <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} error={fieldError(save.error, 'name')} maxLength={255} placeholder="Faculty of Science" required />
        {save.error && !['code', 'name'].some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!code.trim() || !name.trim()}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

// ---- courses ------------------------------------------------------------------------------------------------------------

function useDepartments() {
  return useQuery({ queryKey: ['departments', false], queryFn: () => api.get<Department[]>('/departments', { archived: 'exclude' }) })
}

function CoursesCard({ edit }: { edit: boolean }) {
  const [page, setPage] = useState(1)
  const [text, setText] = useState('')
  const q = useDebounced(text.trim())
  const [department, setDepartment] = useState('')
  const [level, setLevel] = useState('')
  const [archived, setArchived] = useState<'exclude' | 'only' | 'include'>('exclude')
  const [dialog, setDialog] = useState<Course | 'new' | null>(null)
  const departments = useDepartments()
  const query = useQuery({
    queryKey: ['courses', q, department, level, archived, page],
    queryFn: () => api.get<Page<Course>>('/courses', { q: q || undefined, department_id: department || undefined, level: level || undefined, archived, page }),
    placeholderData: (previous) => previous,
  })
  const toggle = useApiMutation((c: Course) => api.patch(`/courses/${c.id}`, { archived: !c.archived_at }), {
    invalidate: [['courses'], ['offerings']],
    success: (c) => ((c as Course).archived_at ? 'Course archived.' : 'Course restored.'),
    toastError: true,
  })
  const reset = () => setPage(1)
  return (
    <Card
      title="Courses"
      actions={
        edit && (
          <Button small variant="primary" onClick={() => setDialog('new')}>
            New course
          </Button>
        )
      }
    >
      <div className="filters">
        <TextField label="Search" type="search" value={text} onChange={(e) => { setText(e.target.value); reset() }} placeholder="Code or title" />
        <SelectField label="Department" value={department} onChange={(e) => { setDepartment(e.target.value); reset() }}>
          <option value="">All departments</option>
          {(departments.data ?? []).map((d) => (
            <option key={d.id} value={d.id}>
              {d.code} {d.name}
            </option>
          ))}
        </SelectField>
        <SelectField label="Level" value={level} onChange={(e) => { setLevel(e.target.value); reset() }}>
          <option value="">All levels</option>
          {COURSE_LEVELS.map((l) => (
            <option key={l} value={l}>
              {cap(l)}
            </option>
          ))}
        </SelectField>
        <SelectField label="Show" value={archived} onChange={(e) => { setArchived(e.target.value as typeof archived); reset() }}>
          <option value="exclude">Current courses</option>
          <option value="only">Archived courses</option>
          <option value="include">Both</option>
        </SelectField>
      </div>
      <QueryView query={query} isEmpty={(d) => d.data.length === 0} empty={<EmptyState title="No courses">{edit && !q && !department && !level && archived === 'exclude' ? 'Create a course, then offer it in a term.' : 'No courses match these filters.'}</EmptyState>}>
        {(data) => (
          <>
            <Table caption="Courses">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Title</th>
                  <th>Department</th>
                  <th>Level</th>
                  <th>Credits</th>
                  {edit && <th />}
                </tr>
              </thead>
              <tbody>
                {data.data.map((c) => (
                  <tr key={c.id}>
                    <td>
                      <strong>{c.code}</strong>
                    </td>
                    <td>
                      {c.title} {c.archived_at && <Badge tone="neutral">Archived</Badge>}
                      {c.description && <span className="muted small block">{c.description.length > 100 ? `${c.description.slice(0, 97)}…` : c.description}</span>}
                    </td>
                    <td>{c.department ? c.department.code : <span className="muted">—</span>}</td>
                    <td>{c.level ? cap(c.level) : <span className="muted">—</span>}</td>
                    <td>{c.credits ?? <span className="muted">—</span>}</td>
                    {edit && (
                      <td className="actions">
                        <Button small onClick={() => setDialog(c)}>
                          Edit
                        </Button>
                        <Button small variant="ghost" onClick={() => toggle.mutate(c)}>
                          {c.archived_at ? 'Restore' : 'Archive'}
                        </Button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </Table>
            <Pager {...pagerFromPage(data)} onPage={setPage} />
          </>
        )}
      </QueryView>
      {dialog && <CourseDialog course={dialog === 'new' ? null : dialog} onClose={() => setDialog(null)} />}
    </Card>
  )
}

function CourseDialog({ course, onClose }: { course: Course | null; onClose: () => void }) {
  const [code, setCode] = useState(course?.code ?? '')
  const [title, setTitle] = useState(course?.title ?? '')
  const [description, setDescription] = useState(course?.description ?? '')
  const [department, setDepartment] = useState(course?.department_id ? String(course.department_id) : '')
  const [level, setLevel] = useState<'' | CourseLevel>(course?.level ?? '')
  const [credits, setCredits] = useState(course?.credits ? String(course.credits) : '')
  const departments = useDepartments()
  const save = useApiMutation(
    () => {
      const body = { code: code.trim(), title: title.trim(), description: description.trim() || null, department_id: department ? Number(department) : null, level: level || null, credits: credits ? Number(credits) : null }
      return course ? api.patch(`/courses/${course.id}`, body) : api.post('/courses', body)
    },
    { invalidate: [['courses'], ['departments'], ['offerings']], success: course ? 'Course saved.' : 'Course created.', onSuccess: onClose },
  )
  const fields = ['code', 'title', 'description', 'department_id', 'level', 'credits']
  return (
    <Modal title={course ? 'Edit course' : 'New course'} onClose={onClose} wide>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <div className="row">
          <TextField label="Course code" value={code} onChange={(e) => setCode(e.target.value)} error={fieldError(save.error, 'code')} maxLength={50} placeholder="CSC101" autoFocus required />
          <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError(save.error, 'title')} maxLength={255} required />
        </div>
        <div className="row">
          <SelectField label="Department" optional value={department} onChange={(e) => setDepartment(e.target.value)} error={fieldError(save.error, 'department_id')}>
            <option value="">None</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.code} {d.name}
              </option>
            ))}
          </SelectField>
          <SelectField label="Level" optional value={level} onChange={(e) => setLevel(e.target.value as '' | CourseLevel)} error={fieldError(save.error, 'level')}>
            <option value="">Not set</option>
            {COURSE_LEVELS.map((l) => (
              <option key={l} value={l}>
                {cap(l)}
              </option>
            ))}
          </SelectField>
          <TextField label="Credits" optional type="number" min={1} max={60} value={credits} onChange={(e) => setCredits(e.target.value)} error={fieldError(save.error, 'credits')} />
        </div>
        <TextArea label="Description" optional value={description} onChange={(e) => setDescription(e.target.value)} error={fieldError(save.error, 'description')} />
        {save.error && !fields.some((f) => fieldError(save.error, f)) && <FormError error={save.error} />}
        <div className="form-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!code.trim() || !title.trim()}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  )
}

// ---- offerings ----------------------------------------------------------------------------------------------------------

function OfferingCard() {
  const [text, setText] = useState('')
  const q = useDebounced(text.trim())
  const courses = useQuery({ queryKey: ['courses', 'pick', q], queryFn: () => api.get<Page<Course>>('/courses', { q: q || undefined, archived: 'exclude' }), placeholderData: (previous) => previous })
  const terms = useQuery({ queryKey: ['terms', 'all'], queryFn: () => api.get<Page<Term>>('/terms', { page: 1 }) })
  const [courseId, setCourseId] = useState('')
  const [termId, setTermId] = useState('')
  const [section, setSection] = useState('A')
  const [capacity, setCapacity] = useState('')
  const [selfEnrol, setSelfEnrol] = useState(false)
  const [published, setPublished] = useState(false)
  const create = useApiMutation(() => api.post<Offering>('/offerings', { course_id: Number(courseId), academic_term_id: Number(termId), section: section.trim(), capacity: capacity ? Number(capacity) : null, self_enrolment: selfEnrol, published }), { invalidate: [['offerings']] })
  const created = create.data
  const chosen = (courses.data?.data ?? []).some((c) => String(c.id) === courseId)

  return (
    <Card title="Offer a course in a term">
      <p className="muted">An offering is one course in one term and section. Lecturers, students, content and grades belong to an offering. Assign lecturers on the offering&apos;s People tab after creating it.</p>
      {created && (
        <Alert tone="success">
          Offering created. <Link to={`/courses/${created.id}`}>Open it</Link> to assign lecturers and add content.
        </Alert>
      )}
      <QueryView query={terms}>
        {(termPage) => (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              create.mutate()
            }}
          >
            <TextField label="Find the course" type="search" value={text} onChange={(e) => setText(e.target.value)} placeholder="Type a code or title" />
            <div className="row">
              <SelectField label="Course" value={chosen ? courseId : ''} onChange={(e) => setCourseId(e.target.value)} error={fieldError(create.error, 'course_id')} required>
                <option value="">{courses.isPending ? 'Loading…' : (courses.data?.data.length ?? 0) === 0 ? 'No course matches' : 'Choose a course…'}</option>
                {(courses.data?.data ?? []).map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.code} {c.title}
                  </option>
                ))}
              </SelectField>
              <SelectField label="Term" value={termId} onChange={(e) => setTermId(e.target.value)} error={fieldError(create.error, 'academic_term_id')} required>
                <option value="">Choose a term…</option>
                {termPage.data.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name}
                    {t.academic_year ? ` (${t.academic_year})` : ''}
                  </option>
                ))}
              </SelectField>
              <TextField label="Section" value={section} onChange={(e) => setSection(e.target.value)} error={fieldError(create.error, 'section')} maxLength={100} required />
              <TextField label="Places" optional type="number" min={1} value={capacity} onChange={(e) => setCapacity(e.target.value)} error={fieldError(create.error, 'capacity')} hint="Blank means no limit." />
            </div>
            <CheckField label="Students may register for it themselves" hint="Only while the term's registration window is open. Otherwise the registrar enrols them." checked={selfEnrol} onChange={(e) => setSelfEnrol(e.target.checked)} />
            <CheckField label="Published" hint="Students can see published offerings they are enrolled in." checked={published} onChange={(e) => setPublished(e.target.checked)} />
            {create.error && !['course_id', 'academic_term_id', 'section', 'capacity'].some((f) => fieldError(create.error, f)) && <FormError error={create.error} />}
            <Button type="submit" variant="primary" loading={create.isPending} disabled={!courseId || !chosen || !termId || !section.trim()}>
              Create offering
            </Button>
          </form>
        )}
      </QueryView>
    </Card>
  )
}
