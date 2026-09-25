import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import type { Permission } from '../api/types'
import { useAuth } from '../auth/AuthContext'
import { Alert } from './ui'

/** Shows its children only to people holding at least one of the permissions; everyone else gets a plain explanation. */
export function AdminGuard({ any, children }: { any: Permission[]; children: ReactNode }) {
  const { can } = useAuth()
  if (any.some(can)) return <>{children}</>
  return (
    <Alert tone="warn">
      You do not have access to this page. <Link to="/">Back to the dashboard</Link>
    </Alert>
  )
}
