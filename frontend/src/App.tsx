import { Link, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import type { Permission } from './api/types'
import { useAuth } from './auth/AuthContext'
import { useInstitutionSettings } from './lib/institution'
import { Layout } from './components/Layout'
import { Alert, Button, ErrorState, Loading } from './components/ui'
import { AdminGuard } from './components/guards'
import { AuditPage, ReportsPage, StatusPage } from './pages/admin/SystemPages'
import { AnnouncementsPage } from './pages/admin/AnnouncementsPage'
import { BackupsPage } from './pages/admin/BackupsPage'
import { CataloguePage } from './pages/admin/CataloguePage'
import { RolesPage } from './pages/admin/RolesPage'
import { SecurityPage } from './pages/admin/SecurityPage'
import { SettingsLayout, SettingsPage } from './pages/admin/SettingsPage'
import { SystemPage } from './pages/admin/SystemPage'
import { UserPage } from './pages/admin/UserPage'
import { ImportUsersPage, UsersPage } from './pages/admin/UsersPage'
import { ForgotPasswordPage, LoginPage, ResetPasswordPage, SsoCallbackPage } from './pages/auth'
import { Dashboard } from './pages/Dashboard'
import { NotificationsPage, ProfilePage } from './pages/account'
import { AppealPage, AppealsTab, MyAppealsPage } from './pages/courses/Appeals'
import { AssignmentPage } from './pages/courses/AssignmentPage'
import { AttemptPage } from './pages/courses/AttemptPage'
import { AttachmentFeedbackPage } from './pages/courses/AttachmentFeedbackPage'
import { AttachmentTab } from './pages/courses/AttachmentTab'
import { AttendanceTab, ClassesTab } from './pages/courses/ClassesTab'
import { ProjectsTab } from './pages/courses/ProjectsTab'
import { SupportTab } from './pages/courses/SupportTab'
import { SkillsTab } from './pages/courses/SkillsTab'
import { AnnouncementsTab, DiscussionPage, DiscussionsTab } from './pages/courses/CommunicationTabs'
import { ClassworkTab } from './pages/courses/ClassworkTab'
import { useOffering } from './pages/courses/context'
import { CoursesPage } from './pages/courses/CoursesPage'
import { RegisterPage } from './pages/courses/RegisterPage'
import { GradesTab, ProgressTab } from './pages/courses/GradesTab'
import { OfferingLayout } from './pages/courses/OfferingLayout'
import { OverviewTab, PeopleTab } from './pages/courses/PeopleTab'
import { QuizPage } from './pages/courses/QuizPage'
import { SettingsTab } from './pages/courses/SettingsTab'
import { StreamTab } from './pages/courses/StreamTab'

function RequireAuth() {
  const { user, loading, checkError, retryCheck, logout } = useAuth()
  const location = useLocation()
  if (loading) return <Loading label="Signing you in…" />
  // The server could not be asked (a timeout, an error, maintenance). The person is still signed in, so say so and let them try again
  // rather than showing the sign-in page as if their session had ended.
  if (!user && checkError) {
    return (
      <div className="not-found">
        <ErrorState error={checkError} onRetry={retryCheck} />
        <p>
          <Button variant="ghost" onClick={() => void logout()}>
            Sign out instead
          </Button>
        </p>
      </div>
    )
  }
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  return <Layout />
}

function CourseIndex() {
  const { enrolmentOnly } = useOffering()
  const location = useLocation()
  return <Navigate to={enrolmentOnly ? 'people' : 'stream'} replace state={location.state} />
}

/** The Settings landing tab needs manage-settings; people who only hold a narrower permission are sent straight to the first tab they can open. */
function SettingsIndex() {
  const { can } = useAuth()
  if (can('manage-settings')) return <SettingsPage />
  if (can('manage-users')) return <Navigate to="security" replace />
  if (can('manage-system')) return <Navigate to="system" replace />
  if (can('manage-courses') || can('manage-enrolments')) return <Navigate to="status" replace />
  return (
    <Alert tone="warn">
      You do not have access to this page. <Link to="/">Back to the dashboard</Link>
    </Alert>
  )
}

/** A tab only some people may open; anyone else is sent to the course's front page. */
function OnlyIf({ allowed, children }: { allowed: boolean; children: React.ReactNode }) {
  return allowed ? <>{children}</> : <Navigate to=".." relative="path" replace />
}

function ManagerOnly({ children }: { children: React.ReactNode }) {
  const { manage } = useOffering()
  return <OnlyIf allowed={manage}>{children}</OnlyIf>
}
function PeopleOnly({ children }: { children: React.ReactNode }) {
  const { manage, registrar } = useOffering()
  return <OnlyIf allowed={manage || registrar}>{children}</OnlyIf>
}
function AdminOnly({ children }: { children: React.ReactNode }) {
  const { admin } = useOffering()
  return <OnlyIf allowed={admin}>{children}</OnlyIf>
}
function NotEnrolmentOnly({ children }: { children: React.ReactNode }) {
  const { enrolmentOnly } = useOffering()
  return <OnlyIf allowed={!enrolmentOnly}>{children}</OnlyIf>
}

export function NotFound() {
  return (
    <div className="not-found">
      <h1>Page not found</h1>
      <p className="muted">The page you are looking for does not exist or has moved.</p>
      <p>
        <Link className="btn btn-primary" to="/">
          Go to the dashboard
        </Link>
      </p>
    </div>
  )
}

const guard = (permissions: Permission[], element: React.ReactNode) => <AdminGuard any={permissions}>{element}</AdminGuard>

export function App() {
  // Loads the institution's name, date format and time zone once, so every screen below uses them.
  useInstitutionSettings()
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      <Route path="/reset-password" element={<ResetPasswordPage />} />
      <Route path="/sso/callback" element={<SsoCallbackPage />} />
      <Route path="/attachment-feedback/:token" element={<AttachmentFeedbackPage />} />

      <Route element={<RequireAuth />}>
        <Route index element={<Dashboard />} />
        <Route path="courses" element={<CoursesPage />} />
        <Route path="courses/:id" element={<OfferingLayout />}>
          <Route index element={<CourseIndex />} />
          <Route path="stream" element={<NotEnrolmentOnly><StreamTab /></NotEnrolmentOnly>} />
          <Route path="classwork" element={<NotEnrolmentOnly><ClassworkTab /></NotEnrolmentOnly>} />
          <Route path="announcements" element={<NotEnrolmentOnly><AnnouncementsTab /></NotEnrolmentOnly>} />
          <Route path="discussions" element={<NotEnrolmentOnly><DiscussionsTab /></NotEnrolmentOnly>} />
          <Route path="classes" element={<NotEnrolmentOnly><ClassesTab /></NotEnrolmentOnly>} />
          <Route path="attendance" element={<NotEnrolmentOnly><AttendanceTab /></NotEnrolmentOnly>} />
          <Route path="skills" element={<NotEnrolmentOnly><SkillsTab /></NotEnrolmentOnly>} />
          <Route path="attachment" element={<NotEnrolmentOnly><AttachmentTab /></NotEnrolmentOnly>} />
          <Route path="projects" element={<NotEnrolmentOnly><ProjectsTab /></NotEnrolmentOnly>} />
          <Route path="grades" element={<NotEnrolmentOnly><GradesTab /></NotEnrolmentOnly>} />
          <Route path="overview" element={<ManagerOnly><OverviewTab /></ManagerOnly>} />
          <Route path="progress" element={<ManagerOnly><ProgressTab /></ManagerOnly>} />
          <Route path="support" element={<ManagerOnly><SupportTab /></ManagerOnly>} />
          <Route path="people" element={<PeopleOnly><PeopleTab /></PeopleOnly>} />
          <Route path="appeals" element={<ManagerOnly><AppealsTab /></ManagerOnly>} />
          <Route path="settings" element={<AdminOnly><SettingsTab /></AdminOnly>} />
        </Route>
        <Route path="assignments/:id" element={<AssignmentPage />} />
        <Route path="quizzes/:id" element={<QuizPage />} />
        <Route path="attempts/:id" element={<AttemptPage />} />
        <Route path="discussions/:id" element={<DiscussionPage />} />
        <Route path="appeals/:id" element={<AppealPage />} />
        <Route path="my-appeals" element={<MyAppealsPage />} />
        <Route path="register" element={<RegisterPage />} />
        <Route path="notifications" element={<NotificationsPage />} />
        <Route path="profile" element={<ProfilePage />} />

        <Route path="admin/catalogue" element={guard(['manage-courses', 'manage-enrolments'], <CataloguePage />)} />
        <Route path="admin/users" element={guard(['manage-users', 'manage-enrolments'], <UsersPage />)} />
        <Route path="admin/users/:id" element={guard(['manage-users'], <UserPage />)} />
        <Route path="admin/import" element={guard(['manage-users'], <ImportUsersPage />)} />
        <Route path="admin/reports" element={guard(['manage-courses', 'manage-enrolments'], <ReportsPage />)} />
        <Route path="admin/settings" element={<SettingsLayout />}>
          <Route index element={<SettingsIndex />} />
          <Route path="notices" element={guard(['manage-settings'], <AnnouncementsPage />)} />
          <Route path="security" element={guard(['manage-users'], <SecurityPage />)} />
          <Route path="system" element={guard(['manage-system'], <SystemPage />)} />
          <Route path="roles" element={guard(['manage-users', 'manage-system'], <RolesPage />)} />
          <Route path="audit" element={guard(['manage-users'], <AuditPage />)} />
          <Route path="status" element={guard(['manage-users', 'manage-courses', 'manage-enrolments'], <StatusPage />)} />
          <Route path="backups" element={guard(['manage-system'], <BackupsPage />)} />
        </Route>
        <Route path="*" element={<NotFound />} />
      </Route>
      <Route path="*" element={<Alert>Page not found</Alert>} />
    </Routes>
  )
}
