// Shapes of what the backend returns. Dates arrive as ISO strings (UTC).

export const ROLES = ['super-admin', 'university-admin', 'registrar', 'department-admin', 'lecturer', 'teaching-assistant', 'student'] as const
export type RoleName = (typeof ROLES)[number]

export const ROLE_LABELS: Record<string, string> = {
  'super-admin': 'Super administrator',
  'university-admin': 'University administrator',
  registrar: 'Registrar',
  'department-admin': 'Department administrator',
  lecturer: 'Lecturer',
  'teaching-assistant': 'Teaching assistant',
  student: 'Student',
}

export type Permission =
  | 'manage-users'
  | 'manage-courses'
  | 'manage-enrolments'
  | 'teach-courses'
  | 'submit-assignments'
  | 'grade-submissions'
  | 'resolve-appeals'
  | 'manage-settings'
  | 'manage-system'

/** Laravel's paginator. */
export interface Page<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

/** The paging block used by the gradebook, progress, attendance and roster views. */
export interface Meta {
  page: number
  per_page: number
  total: number
  last_page: number
}

export interface User {
  id: number
  name: string
  email: string
  is_active?: boolean
  last_login_at?: string | null
  digest_frequency?: DigestFrequency
  low_data_mode?: boolean
  roles?: { id: number; name: string }[]
  permissions?: Permission[]
  created_at?: string
}

export interface Me extends User {
  roles: { id: number; name: string }[]
  permissions: Permission[]
}

export interface Person {
  id: number
  name: string
  email?: string
  roles?: { id: number; name: string }[]
}

export interface LoginResult {
  token: string
  user: { id: number; name: string; email: string }
  roles: string[]
}

export interface PasswordPolicy {
  min_length: number
  mixed_case: boolean
  number: boolean
  symbol: boolean
}

export interface Institution {
  name: string
  short_name: string | null
  support_email: string | null
  support_phone: string | null
  website: string | null
  timezone: string
  locale: string
}

export interface AuthConfig {
  sso_enabled: boolean
  sso_label: string
  password_login: boolean
  privacy_url: string | null
  terms_url: string | null
  institution: Institution
  password_policy: PasswordPolicy
  maintenance: { enabled: boolean; message: string | null }
}

export type DigestFrequency = 'off' | 'daily' | 'weekly'

export interface Preferences {
  digest_frequency: DigestFrequency
  digest_sent_at: string | null
  low_data_mode: boolean
}

export interface DigestSummary {
  since: string
  summary: null | {
    notifications?: { total: number; groups: { label: string; count: number; titles: string[] }[] }
    deadlines?: { type: 'assignment' | 'quiz'; title: string; course: string; due_at: string }[]
    to_grade?: { assignment: string; course: string; awaiting: number }[]
    open_appeals?: number
  }
}

export interface Notification {
  id: string
  type: string
  data: Record<string, unknown> & { title?: string }
  read_at: string | null
  created_at: string
}

// ---- catalogue -------------------------------------------------------------------------------------------------
export interface Term {
  id: number
  name: string
  starts_on: string
  ends_on: string
  academic_year?: string | null
  registration_opens_on?: string | null
  registration_closes_on?: string | null
  add_drop_deadline?: string | null
  is_current?: boolean
}

export const COURSE_LEVELS = ['certificate', 'diploma', 'undergraduate', 'postgraduate', 'doctoral'] as const
export type CourseLevel = (typeof COURSE_LEVELS)[number]

export interface Department {
  id: number
  code: string
  name: string
  archived_at?: string | null
  courses_count?: number
}

export interface Course {
  id: number
  code: string
  title: string
  description: string | null
  department_id?: number | null
  department?: { id: number; code: string; name: string } | null
  credits?: number | null
  level?: CourseLevel | null
  archived_at?: string | null
}

export interface Teacher {
  id: number
  user_id: number
  user?: { id: number; name: string; email?: string } | null
  consultation_hours?: string | null
}

export interface Offering {
  id: number
  course_id: number
  academic_term_id: number
  section: string
  published: boolean
  capacity?: number | null
  self_enrolment?: boolean
  archived_at?: string | null
  course?: Course
  term?: Term
  modules?: Module[]
  assignments?: Assignment[]
  quizzes?: Quiz[]
  teachers?: Teacher[]
  abilities?: { manage: boolean }
}

export interface Enrolment {
  id: number
  course_offering_id: number
  user_id: number
  status: 'active' | 'withdrawn'
  user?: { id: number; name: string; email: string } | null
}

export interface Roster {
  teachers: Teacher[]
  enrolments: Enrolment[]
  meta: Meta
}

export interface OfferingSummary {
  enrolled: number
  assignments: { id: number; title: string; due_at: string; published: boolean; max_score: number; submissions: number; graded: number; awaiting_submission: number }[]
  quizzes: { id: number; title: string; due_at: string; published: boolean; students_attempted: number }[]
}

// ---- content ---------------------------------------------------------------------------------------------------
export interface LearningItem {
  id: number
  course_module_id: number
  title: string
  type: 'text' | 'link' | 'file'
  body: string | null
  storage_path?: string | null
  position: number
  published: boolean
}

export interface Module {
  id: number
  course_offering_id: number
  title: string
  position: number
  published: boolean
  prerequisite_module_id?: number | null
  items?: LearningItem[]
}

export interface Progress {
  items_total: number
  // a student's own view
  completed?: number
  percent?: number | null
  completed_item_ids?: number[]
  // a manager's view
  students?: { user: { id: number; name: string; email: string }; completed: number; percent: number | null }[]
  meta?: Meta
}

export interface Badge {
  key: string
  kind: 'skill' | 'milestone' | 'quiz_ace'
  label: string
}

export interface Engagement {
  streak: { current_days: number; longest_days: number }
  badges: Badge[]
  participation: { offering_id: number; course: string | null; points: number }[]
}

export interface OfferingEngagement {
  students: { user: { id: number; name: string; email: string }; points: number }[]
}

export interface LearningGoal {
  id: number
  title: string
  target_date: string | null
  completed_at: string | null
}

// ---- assignments -----------------------------------------------------------------------------------------------
export interface Assignment {
  id: number
  course_offering_id: number
  course_module_id?: number | null
  title: string
  instructions: string | null
  due_at: string
  max_score: number
  weight: number | null
  published: boolean
  allow_late_submissions: boolean
  allow_resubmission: boolean
  is_group_assignment: boolean
  peer_reviews_per_student: number
  created_at?: string
  /** present only in the manage view; absent means visible to the whole class */
  target_user_ids?: number[]
}

export interface RubricLevel {
  id?: number
  title: string
  description: string | null
  points: number
  position?: number
}

export interface RubricCriterion {
  id?: number
  title: string
  description: string | null
  max_points: number
  position?: number
  levels: RubricLevel[]
}

export interface CriterionMark {
  criterion_id: number
  title: string
  max_points: number
  points: number
  level_id: number | null
  level_title: string | null
  comment: string | null
}

export interface GradeRecord {
  id: number
  submission_id: number
  graded_by: number
  score: number | string
  status: 'draft' | 'published'
  feedback: string | null
  feedback_recording_path: string | null
  change_reason: string | null
  criteria_scores: CriterionMark[] | null
  created_at: string
}

export interface Submission {
  id: number
  assignment_id: number
  user_id: number
  body: string | null
  storage_path: string | null
  submitted_at: string
  late: boolean
  late_explanation: string | null
  used_ai: boolean
  ai_use_description: string | null
  version: number
  group?: { id: number; name: string } | null
  grade_records?: GradeRecord[]
  gradeRecords?: GradeRecord[]
}

export type IntegrityCaseStatus = 'open' | 'upheld' | 'dismissed'

export interface Accommodation {
  id: number
  course_offering_id: number
  user_id: number
  student?: { id: number; name: string; email: string }
  extra_time_percent: number
  notes: string | null
}

export interface IntegrityCase {
  id: number
  course_offering_id: number
  user_id: number
  student?: { id: number; name: string; email: string }
  submission_id: number | null
  reporter?: { id: number; name: string }
  description: string
  status: IntegrityCaseStatus
  outcome: string | null
  resolved_at: string | null
  created_at: string
}

export interface AssignmentGroup {
  id: number
  name: string
  members: { id: number; name: string }[]
}

export interface PeerReviewTask {
  id: number
  submission: { id: number; body: string | null; storage_path: string | null }
  body: string | null
  submitted_at: string | null
}

export interface PeerReviewReceived {
  id: number
  body: string
  submitted_at: string
}

export interface SubmissionVersion {
  version: number
  body: string | null
  storage_path: string | null
  submitted_at: string
}

export interface SubmissionFeedback {
  id: number
  version: number
  body: string
  author: { id: number; name: string }
  created_at: string
}

export interface MyGrade {
  submission: Submission
  grade: GradeRecord | null
}

export interface SimilarityReport {
  threshold: number
  compared: number
  skipped_too_short_or_unreadable: number
  pairs: { similarity: number; a: { submission_id: number; user: Person }; b: { submission_id: number; user: Person } }[]
  note: string
}

export type AppealStatus = 'open' | 'upheld' | 'rejected'

export interface Appeal {
  id: number
  submission_id: number
  user_id: number
  grade_record_id: number
  reason: string
  status: AppealStatus
  response: string | null
  resolved_by: number | null
  resolved_at: string | null
  created_at: string
  student?: { id: number; name: string; email: string }
  resolver?: { id: number; name: string } | null
  submission?: {
    id: number
    assignment?: { id: number; title: string; max_score?: number; course_offering_id?: number }
    grade_records?: GradeRecord[]
    gradeRecords?: GradeRecord[]
  }
}

// ---- gradebook -------------------------------------------------------------------------------------------------
export interface GradeColumn {
  key: string
  type: 'assignment' | 'quiz'
  id: number
  title: string
  max: number
  weight: number | null
}

export interface GradeRow {
  user: { id: number; name: string; email: string }
  scores: Record<string, number | null>
  total: number
  possible: number
  percent: number | null
  weighted_percent: number | null
  weight_used: number | null
}

export interface Gradebook {
  columns: GradeColumn[]
  rows: GradeRow[]
  meta: Meta
}

export interface MyGrades {
  columns: GradeColumn[]
  grades: Omit<GradeRow, 'user'> & { user?: GradeRow['user'] } | null
}

// ---- quizzes ---------------------------------------------------------------------------------------------------
export type QuestionType = 'single_choice' | 'multiple_choice' | 'true_false' | 'short_answer' | 'essay'

export const QUESTION_TYPE_LABELS: Record<QuestionType, string> = {
  single_choice: 'Single choice',
  multiple_choice: 'Multiple choice',
  true_false: 'True / false',
  short_answer: 'Short answer',
  essay: 'Essay (marked by hand)',
}

export interface QuizOption {
  id: number
  text: string
  is_correct?: boolean
  position?: number
}

export interface QuizQuestion {
  id: number
  quiz_id?: number
  type: QuestionType
  prompt: string
  points: number
  position: number
  options: QuizOption[]
}

export interface Quiz {
  id: number
  course_offering_id: number
  course_module_id?: number | null
  title: string
  instructions: string | null
  opens_at: string | null
  due_at: string
  time_limit_minutes: number | null
  max_attempts: number
  questions_per_attempt: number | null
  is_open_book: boolean
  weight: number | null
  published?: boolean
  is_practice?: boolean
  created_at?: string
  questions?: QuizQuestion[]
  questions_count?: number
  total_points?: number
  attempts_used?: number
  /** present only in the manage view; absent means visible to the whole class */
  target_user_ids?: number[]
}

export interface AttemptSummary {
  id: number
  started_at: string
  submitted_at: string | null
  score: number | null
  max_score: number | null
}

export interface AttemptInProgress {
  id: number
  quiz_id: number
  started_at: string
  deadline: string | null
  draft_answers: { question_id: number; option_ids?: number[]; text?: string }[]
  questions: { id: number; type: QuestionType; prompt: string; points: number; options: { id: number; text: string }[] }[]
}

export interface AttemptResult {
  id: number
  quiz_id: number
  started_at: string
  submitted_at: string
  score: number
  max_score: number
  awaiting_manual_grading: boolean
  answers: { id: number; question_id: number; prompt: string | null; max_points: number; response: { option_ids?: number[]; text?: string }; is_correct: boolean; points: number; needs_manual_grading: boolean }[]
}

export interface StaffAttempt extends AttemptSummary {
  user_id: number
  quiz_id: number
  user?: { id: number; name: string; email: string }
}

// ---- classes and attendance ------------------------------------------------------------------------------------
export const ATTENDANCE_STATUSES = ['present', 'late', 'absent', 'excused'] as const
export type AttendanceStatus = (typeof ATTENDANCE_STATUSES)[number]

export interface ClassSession {
  id: number
  course_offering_id: number
  title: string
  starts_at: string
  ends_at: string
  join_url: string | null
  location: string | null
  checkin_opens_at?: string | null
  checkin_closes_at?: string | null
  my_status?: AttendanceStatus | null
  checkin_open?: boolean
  my_hand_raised_at?: string | null
}

export interface HandRaise {
  user: { id: number; name: string }
  raised_at: string
}

export interface SessionQuestion {
  id: number
  body: string
  answered_at: string | null
  user: { id: number; name: string }
}

export interface RollEntry {
  user: { id: number; name: string; email: string }
  status: AttendanceStatus | null
  note: string | null
  source: 'staff' | 'self' | null
}

export interface CheckinStatus {
  open: boolean
  code: string | null
  opens_at: string | null
  closes_at: string | null
  self_checked_in: number
}

export interface AttendanceCounts {
  present: number
  late: number
  absent: number
  excused: number
  attended: number
  percent: number | null
}

export interface AttendanceSummary extends Partial<AttendanceCounts> {
  sessions_total: number
  students?: ({ user: { id: number; name: string; email: string } } & AttendanceCounts)[]
  meta?: Meta
}

// ---- communication ---------------------------------------------------------------------------------------------
export interface Announcement {
  id: number
  title: string
  body: string
  urgent: boolean
  created_at: string
  author?: { id: number; name: string }
}

export interface Thread {
  id: number
  course_offering_id: number
  user_id: number
  title: string
  body: string
  created_at: string
  updated_at: string
  posts_count?: number
  author?: { id: number; name: string }
}

export interface Post {
  id: number
  user_id: number
  body: string
  created_at: string
  author?: { id: number; name: string }
}

// ---- administration --------------------------------------------------------------------------------------------
export interface ImportUsersResult {
  dry_run: boolean
  created: number
  would_create: number
  invited: boolean
  errors: { line: number; email: string; errors: string[] }[]
}

export interface EnrolmentImportResult {
  enrolled: number
  not_found: string[]
  invalid: string[]
  /** Students who were not enrolled because the course has no places left. */
  full?: string[]
}

export interface CopyResult {
  offering: Offering
  copied: Record<string, number>
  date_shift_days: number
}

export interface AuditEntry {
  id: number
  description: string
  subject_type: string | null
  subject_id: number | null
  causer?: { id: number; name: string; email: string } | null
  properties: Record<string, unknown> | unknown[]
  created_at: string
}

export interface Overview {
  users: { total: number; active: number; by_role: Record<string, number> }
  offerings: { total: number; published: number }
  enrolments_active: number
  assignments: number
  submissions: number
  quizzes: number
  quiz_attempts_submitted: number
  warnings: string[]
}

// ---- dashboard ---------------------------------------------------------------------------------------------------
export interface AgendaSession {
  type: 'session'
  title: string
  course: string
  at: string
  ends_at: string
  location: string | null
  join_url: string | null
}

export interface AgendaDeadline {
  type: 'assignment' | 'quiz'
  id: number
  title: string
  course: string
  at: string
}

export interface Agenda {
  sessions: AgendaSession[]
  deadlines: AgendaDeadline[]
}

export interface RecentFeedback {
  assignment: string
  course: string
  score: number
  max_score: number
  feedback: string
  at: string
}

export interface WeakTopic {
  offering_id: number
  module_id: number
  title: string
  percent: number
  practice_quiz_ids: number[]
}

export interface Insights {
  missing: AgendaDeadline[]
  recent_feedback: RecentFeedback[]
  weak_topics: WeakTopic[]
}

export interface Health {
  status: 'ok' | 'degraded' | 'down'
  checks: Record<string, string>
  time: string
}

// ---- administration --------------------------------------------------------------------------------------------
export interface SettingDef {
  key: string
  label: string
  help: string
  type: 'string' | 'email' | 'url' | 'int' | 'bool' | 'enum' | 'list'
  options: string[] | null
  min: number | null
  max: number | null
  value: string | number | boolean | string[] | null
  default: string | number | boolean | string[] | null
  overridden: boolean
}
export interface SettingsResult {
  groups: { group: string; settings: SettingDef[] }[]
  changed?: string[]
}

export interface RolesResult {
  permissions: { name: Permission; description: string }[]
  roles: { name: RoleName; users: number; permissions: Permission[]; defaults: Permission[]; locked: boolean }[]
}

export interface UserDetail {
  user: {
    id: number
    name: string
    email: string
    is_active: boolean
    last_login_at: string | null
    created_at: string
    anonymised_at: string | null
    digest_frequency: DigestFrequency
    roles: { id: number; name: string }[]
    has_sso_link: boolean
  }
  signin: { locked: boolean; locked_for_seconds: number; can_sign_in: boolean }
  sessions: { id: number; kind: string; last_used_at: string | null; created_at: string; expires_at: string | null }[]
  enrolments: { offering_id: number; code: string; title: string; term: string; section: string; status: string }[]
  teaching: { offering_id: number; code: string; title: string; term: string; section: string }[]
  events: SecurityEventRow[]
  records: Record<string, number>
}

export interface SecurityEventRow {
  id: number
  event: string
  level: 'info' | 'warning' | 'error'
  ip: string | null
  created_at: string
  user_id?: number | null
  user_name?: string | null
  context?: Record<string, unknown> | null
}

export interface SecuritySummary {
  last_24_hours: SecurityCounts
  last_7_days: SecurityCounts
  busiest_failing_addresses: { ip: string; total: number }[]
  events: Record<string, number>
}
export interface SecurityCounts {
  sign_ins: number
  failed_sign_ins: number
  lockouts: number
  password_changes: number
  access_denied: number
}

export interface SystemInfo {
  warnings: string[]
  application: { name: string; version: string; environment: string; debug: boolean; timezone: string; php: string; laravel: string; server_time: string }
  database: { driver: string; ok: boolean; latency_ms: number; size_bytes: number | null; tables: Record<string, number>; pending_migrations: string[] }
  storage: { ok: boolean; error?: string; total_bytes: number; total_files: number; truncated: boolean; by_kind: Record<string, { label: string; files: number; bytes: number }> }
  disk: { free: number | null; total: number | null }
  queue: { driver: string; waiting: number | null; waiting_backups: number | null; failed: number }
  scheduler: { last_beat_seconds_ago: number | null; ok: boolean }
  cache: { store: string }
  mail: { mailer: string; from: string }
  backups: { count: number; last: BackupItem | null; age_hours: number | null; status: BackupStatus }
}

export interface FailedJob {
  id: number
  uuid: string
  queue: string
  job: string
  error: string
  failed_at: string
}

export interface BackupItem {
  name: string
  size: number
  created_at: string
  include_files: boolean
  tables: number | null
  rows: number | null
  files: number | null
  file_bytes: number | null
  missing_files: number | null
  database: string | null
  migrations: number | null
}
export interface BackupStatus {
  running: boolean
  last_ok_at?: string | null
  last_error?: string | null
  [key: string]: unknown
}
export interface BackupsResult {
  backups: BackupItem[]
  status: BackupStatus
  automatic: { enabled: boolean; keep: number; include_files: boolean; at: string }
  location: string
  restore_command: string
}
export interface BackupVerification {
  ok: boolean
  problems: string[]
  summary: Record<string, unknown>
  checked: number
}

export interface Integration {
  key: string
  label: string
  can_test: boolean
  status: 'ok' | 'warn' | 'error' | 'off' | 'manual' | 'info'
  summary: string
  details: Record<string, string | number | null>
  change: string
}

export interface SystemAnnouncement {
  id: number
  title: string
  body: string
  severity: 'info' | 'warning' | 'critical'
  audience: RoleName[] | null
  starts_at: string | null
  ends_at: string | null
  created_at?: string
}

export interface UsageReport {
  sign_ins_per_day: { date: string; count: number }[]
  submissions_per_day: { date: string; count: number }[]
  quiz_attempts_per_day: { date: string; count: number }[]
  new_accounts_per_day: { date: string; count: number }[]
  active_people: { last_7_days: number; last_30_days: number; accounts: number }
}

interface EnrolmentTotals {
  offerings: number
  published: number
  enrolled: number
  capacity: number
  enrolled_in_limited: number
}
export interface EnrolmentReport {
  terms: (EnrolmentTotals & { id: number; name: string; academic_year: string | null; fill_percent: number | null })[]
  departments: (EnrolmentTotals & { code: string | null; name: string })[]
  fullest: { offering_id: number; course: string; section: string; term: string; enrolled: number; capacity: number }[]
  largest: { offering_id: number; course: string; section: string; term: string; enrolled: number }[]
  total_enrolled: number
}

export type CompetencyStatusValue = 'not_started' | 'developing' | 'competent'

export interface Competency {
  id: number
  course_offering_id: number
  title: string
  description: string | null
  /** present only for a student's own view */
  my_status?: CompetencyStatusValue
  my_hours?: number
}

export interface LogbookEntry {
  id: number
  competency_id: number
  user_id: number
  user?: { id: number; name: string }
  activity_date: string
  hours: number
  description: string
  evidence_path: string | null
  reviewed_at: string | null
  reviewer_comment: string | null
}

export type AiStudentMode = 'ask' | 'summarise' | 'revision_questions' | 'flashcards' | 'study_plan'
export type AiTeachingMode = 'lesson_outline' | 'quiz_draft' | 'rubric' | 'discussion_questions' | 'remedial_suggestions'

export interface AiReply {
  answer: string
  sources: { id: number; title: string }[]
}

export interface StudyGroup {
  id: number
  name: string
  description: string | null
  max_members: number | null
  creator: { id: number; name: string }
  members: { id: number; name: string }[]
  my_member: boolean
}

export interface AtRiskRow {
  user: { id: number; name: string; email: string }
  has_open_plan: boolean
  missing_assignments: number
  declining: boolean
  inactive_days: number | null
  flagged: boolean
}

export type InterventionStatus = 'open' | 'resolved'

export interface InterventionPlan {
  id: number
  course_offering_id: number
  user_id: number
  student?: { id: number; name: string }
  author?: { id: number; name: string }
  reason: string
  action_plan: string | null
  status: InterventionStatus
  resolved_at: string | null
  created_at: string
}

export type ProjectStatus = 'proposed' | 'approved' | 'rejected'

export interface Project {
  id: number
  course_offering_id: number
  user_id: number
  student?: { id: number; name: string; email: string }
  title: string
  description: string | null
  status: ProjectStatus
  supervisor_id: number | null
  supervisor?: { id: number; name: string } | null
}

export interface ProjectMilestone {
  id: number
  project_id: number
  title: string
  due_on: string
  completed_at: string | null
  notes: string | null
}

export interface ProjectMeeting {
  id: number
  project_id: number
  occurred_on: string
  notes: string
  user?: { id: number; name: string }
}

export interface AttachmentPlacement {
  id: number
  course_offering_id: number
  user_id: number
  student?: { id: number; name: string; email: string }
  organisation: string
  supervisor_name: string
  supervisor_email: string
  objectives: string | null
  starts_on: string
  ends_on: string
  supervisor_rating: number | null
  supervisor_comment: string | null
  supervisor_submitted_at: string | null
}

export interface AttachmentLogbookEntry {
  id: number
  placement_id: number
  week_ending: string
  hours: number
  activities: string
  evidence_path: string | null
}

export interface AttachmentFeedbackForm {
  student_name: string
  course: string
  organisation: string
  objectives: string | null
  starts_on: string
  ends_on: string
  already_submitted: boolean
}

export interface RegistrationOffering extends Offering {
  registration: {
    open: boolean
    opens_on: string | null
    closes_on: string | null
    seats_left: number | null
    full: boolean
    my_status: string | null
    can_register: boolean
    can_drop: boolean
    drop_deadline: string | null
  }
}
