import { useOutletContext } from 'react-router-dom'
import type { Offering } from '../../api/types'

export interface OfferingContext {
  offering: Offering
  id: number
  /** may edit this course: build content, grade, take attendance (teachers of it, and administrators) */
  manage: boolean
  /** may grade submissions here */
  canGrade: boolean
  /** may decide grade appeals here */
  canResolve: boolean
  /** an ordinary participant: a student who is enrolled */
  student: boolean
  /** may run the catalogue: create/copy/publish offerings, assign teachers */
  admin: boolean
  /** a registrar looking at an offering they may enrol into but not open */
  enrolmentOnly: boolean
  /** may enrol students */
  registrar: boolean
}

export function useOffering(): OfferingContext {
  return useOutletContext<OfferingContext>()
}
