import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/client'
import type { Roster } from '../../api/types'
import { SelectField } from '../../components/ui'

/** Who an assignment or quiz is for: the whole class (the default, and how everything worked before targeting existed), or a hand-picked subset of the roster. */
export function TargetPicker({
  offeringId,
  mode,
  ids,
  onModeChange,
  onIdsChange,
}: {
  offeringId: number
  mode: 'everyone' | 'specific'
  ids: number[]
  onModeChange: (mode: 'everyone' | 'specific') => void
  onIdsChange: (ids: number[]) => void
}) {
  const roster = useQuery({ queryKey: ['roster', offeringId, 1], queryFn: () => api.get<Roster>(`/offerings/${offeringId}/roster`), enabled: mode === 'specific' })
  const students = (roster.data?.enrolments ?? []).filter((e) => e.status === 'active' && e.user)

  return (
    <div className="field">
      <label>Assign to</label>
      <div className="segmented">
        <label className={mode === 'everyone' ? 'on' : ''}>
          <input type="radio" name="target-mode" checked={mode === 'everyone'} onChange={() => onModeChange('everyone')} />
          Everyone enrolled
        </label>
        <label className={mode === 'specific' ? 'on' : ''}>
          <input type="radio" name="target-mode" checked={mode === 'specific'} onChange={() => onModeChange('specific')} />
          Specific students
        </label>
      </div>
      {mode === 'specific' && (
        <SelectField
          label="Students"
          multiple
          size={6}
          value={ids.map(String)}
          onChange={(e) => onIdsChange(Array.from(e.target.selectedOptions, (o) => Number(o.value)))}
          hint={roster.isLoading ? 'Loading roster…' : 'Only the selected students will see this and can submit to it. Ctrl/Cmd-click to select several.'}
        >
          {students.map((e) => (
            <option key={e.user_id} value={e.user_id}>
              {e.user?.name}
            </option>
          ))}
        </SelectField>
      )}
    </div>
  )
}
