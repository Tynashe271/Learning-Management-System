import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import type { Notification, Page, SystemAnnouncement } from '../api/types'
import { ROLE_LABELS } from '../api/types'
import { useAuth } from '../auth/AuthContext'
import { useDisplayPrefs } from '../lib/display'
import { institutionName, useAuthConfig } from '../lib/institution'
import { useOnline } from '../lib/online'
import { Alert, Avatar } from './ui'

interface NavItem {
  to: string
  label: string
  show: boolean
  end?: boolean
}

export function Layout() {
  const { user, can, hasRole, logout, isStudentOnly } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [menuOpen, setMenuOpen] = useState(false)
  const online = useOnline()
  useDisplayPrefs() // applies the remembered font size and contrast to the whole app, not just the account page

  useEffect(() => setMenuOpen(false), [location.pathname])

  const config = useAuthConfig()
  const notifications = useQuery({ queryKey: ['notifications', 'bell'], queryFn: () => api.get<Page<Notification>>('/notifications'), refetchInterval: 60_000, staleTime: 30_000 })

  const unreadNotifications = notifications.data?.data.filter((n) => !n.read_at).length ?? 0
  const staffAdmin = can('manage-users') || can('manage-courses') || can('manage-enrolments')
  const name = institutionName(config.data)

  const main: NavItem[] = [
    { to: '/', label: isStudentOnly ? 'My learning' : 'Dashboard', show: true, end: true },
    { to: '/courses', label: isStudentOnly ? 'My courses' : 'Courses', show: true },
    { to: '/notifications', label: 'Notifications', show: true },
    { to: '/register', label: 'Register for courses', show: hasRole('student') },
    { to: '/my-appeals', label: 'My grade appeals', show: can('submit-assignments') && !hasRole('super-admin') },
  ]
  const academics: NavItem[] = [
    { to: '/admin/catalogue', label: 'Terms and courses', show: can('manage-courses') || can('manage-enrolments') },
    { to: '/admin/users', label: 'People', show: can('manage-users') || can('manage-enrolments') },
    { to: '/admin/import', label: 'Import accounts', show: can('manage-users') },
    { to: '/admin/reports', label: 'Reports', show: can('manage-courses') || can('manage-enrolments') },
  ]
  const institution: NavItem[] = [
    { to: '/admin/settings', label: 'Settings', show: can('manage-settings') },
    { to: '/admin/announcements', label: 'Notices to everyone', show: can('manage-settings') },
    { to: '/admin/roles', label: 'Roles and permissions', show: can('manage-users') || can('manage-system') },
  ]
  const oversight: NavItem[] = [
    { to: '/admin/security', label: 'Security', show: can('manage-users') },
    { to: '/admin/audit', label: 'Audit log', show: can('manage-users') },
  ]
  const system: NavItem[] = [
    { to: '/admin/status', label: 'System status', show: staffAdmin },
    { to: '/admin/system', label: 'System and jobs', show: can('manage-system') },
    { to: '/admin/backups', label: 'Backups', show: can('manage-system') },
  ]
  const adminGroups: [string, NavItem[]][] = [
    ['Academics', academics],
    ['Institution', institution],
    ['Security and records', oversight],
    ['System', system],
  ]

  const renderNav = (items: NavItem[]) =>
    items
      .filter((item) => item.show)
      .map((item) => (
        <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => `nav-link${isActive ? ' nav-active' : ''}`}>
          {item.label}
          {item.to === '/notifications' && unreadNotifications > 0 && <span className="count" aria-label={`${unreadNotifications} unread`}>{unreadNotifications}</span>}
        </NavLink>
      ))

  const roleLabel = (user?.roles ?? []).map((r) => ROLE_LABELS[r.name] ?? r.name).join(', ')

  return (
    <div className={`shell${isStudentOnly ? ' student-mode' : ''}`}>
      <a className="skip-link" href="#content">
        Skip to content
      </a>
      <header className="topbar">
        <button type="button" className="icon-btn menu-btn" aria-label="Menu" aria-expanded={menuOpen} onClick={() => setMenuOpen((open) => !open)}>
          ☰
        </button>
        <Link to="/" className="brand">
          <img src="/favicon.svg" alt="" width="28" height="28" />
          <span>{name}</span>
        </Link>
        <div className="topbar-right">
          <Link to="/notifications" className="bell" aria-label={unreadNotifications ? `Notifications, ${unreadNotifications} unread` : 'Notifications'}>
            <span aria-hidden="true">🔔</span>
            {unreadNotifications > 0 && <span className="count">{unreadNotifications}</span>}
          </Link>
          <Link to="/profile" className="profile-link">
            <Avatar name={user?.name ?? ''} />
            <span className="profile-text">
              <strong>{user?.name}</strong>
              <span className="muted small">{roleLabel}</span>
            </span>
          </Link>
          <button
            type="button"
            className="btn btn-ghost btn-small"
            onClick={async () => {
              await logout()
              navigate('/login', { replace: true })
            }}
          >
            Sign out
          </button>
        </div>
      </header>
      {!online && (
        <div className="offline-banner" role="alert">
          You are offline. Changes cannot be saved until the connection returns.
        </div>
      )}
      <div className="body">
        <nav className={`sidebar${menuOpen ? ' sidebar-open' : ''}`} aria-label="Main">
          <div className="nav-group">{renderNav(main)}</div>
          {adminGroups.map(
            ([heading, items]) =>
              items.some((item) => item.show) && (
                <div className="nav-group" key={heading}>
                  <div className="nav-heading">{heading}</div>
                  {renderNav(items)}
                </div>
              ),
          )}
        </nav>
        <main id="content" className="content" tabIndex={-1}>
          <Notices />
          <Outlet />
        </main>
      </div>
      <footer className="footer">
        <span>{name}</span>
        {config.data?.institution?.support_email && <a href={`mailto:${config.data.institution.support_email}`}>Help: {config.data.institution.support_email}</a>}
        {config.data?.privacy_url && (
          <a href={config.data.privacy_url} target="_blank" rel="noreferrer noopener">
            Privacy policy
          </a>
        )}
        {config.data?.terms_url && (
          <a href={config.data.terms_url} target="_blank" rel="noreferrer noopener">
            Terms of use
          </a>
        )}
      </footer>
    </div>
  )
}

const SEVERITY_TONE = { info: 'info', warning: 'warn', critical: 'error' } as const

/** Notices from the institution ("Registration closes Friday", "The system will be down on Saturday"), shown until dismissed. */
function Notices() {
  const query = useQuery({ queryKey: ['system-announcements', 'active'], queryFn: () => api.get<SystemAnnouncement[]>('/system-announcements/active'), refetchInterval: 5 * 60_000, staleTime: 60_000 })
  const [hidden, setHidden] = useState<number[]>(() => {
    try {
      return JSON.parse(sessionStorage.getItem('lms.hidden-notices') ?? '[]') as number[]
    } catch {
      return []
    }
  })
  const dismiss = (id: number) => {
    const next = [...hidden, id]
    setHidden(next)
    try {
      sessionStorage.setItem('lms.hidden-notices', JSON.stringify(next))
    } catch {
      /* it will show again next time */
    }
  }
  const shown = (query.data ?? []).filter((n) => !hidden.includes(n.id))
  if (shown.length === 0) return null
  return (
    <div className="notices">
      {shown.map((n) => (
        <div key={n.id} className="notice">
          <Alert tone={SEVERITY_TONE[n.severity] ?? 'info'}>
            <strong>{n.title}</strong> {n.body}
            {n.severity !== 'critical' && (
              <button type="button" className="icon-btn notice-close" aria-label={`Dismiss: ${n.title}`} onClick={() => dismiss(n.id)}>
                ×
              </button>
            )}
          </Alert>
        </div>
      ))}
    </div>
  )
}
