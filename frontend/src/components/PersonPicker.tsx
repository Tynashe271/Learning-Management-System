import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../api/client'
import type { Page, Person, RoleName } from '../api/types'
import { ROLE_LABELS } from '../api/types'
import { Avatar, ErrorState, Loading, useDebounced } from './ui'

/** Search for a person by name and choose one. Searches every account (administrators and registrars only). */
export function PersonPicker({
  role,
  onPick,
  exclude = [],
  actionLabel = 'Choose',
  placeholder = 'Search by name or email',
  busy = false,
}: {
  role?: RoleName
  onPick: (person: Person) => void
  exclude?: number[]
  actionLabel?: string
  placeholder?: string
  /** True while a previous pick is still being processed, so a fast second click cannot fire it twice. */
  busy?: boolean
}) {
  const [text, setText] = useState('')
  const q = useDebounced(text.trim())
  const query = useQuery({
    queryKey: ['picker', role ?? '', q],
    queryFn: () => api.get<Page<Person>>('/users', { q: q || undefined, role }),
    staleTime: 15_000,
  })

  return (
    <div className="picker">
      <label className="sr-only" htmlFor="person-search">
        {placeholder}
      </label>
      <input id="person-search" type="search" value={text} onChange={(event) => setText(event.target.value)} placeholder={placeholder} autoComplete="off" />
      {query.isPending && <Loading label="Searching…" />}
      {query.isError && <ErrorState error={query.error} onRetry={() => void query.refetch()} />}
      {query.data && (
        <ul className="picker-list">
          {query.data.data.filter((person) => !exclude.includes(person.id)).map((person) => (
            <li key={person.id}>
              <Avatar name={person.name} />
              <span className="grow">
                <strong>{person.name}</strong>
                <span className="muted small block">
                  {person.email ?? ''}
                  {person.email && person.roles?.length ? ' · ' : ''}
                  {person.roles?.map((r) => ROLE_LABELS[r.name] ?? r.name).join(', ')}
                </span>
              </span>
              <button type="button" className="btn btn-secondary btn-small" disabled={busy} onClick={() => onPick(person)}>
                {actionLabel}
              </button>
            </li>
          ))}
          {query.data.data.filter((person) => !exclude.includes(person.id)).length === 0 && <li className="muted">No one matches{q ? ` “${q}”` : ''}.</li>}
        </ul>
      )}
      {query.data && query.data.last_page > 1 && <p className="muted small">Showing the first {query.data.per_page} matches. Type more of the name to narrow them down.</p>}
    </div>
  )
}
