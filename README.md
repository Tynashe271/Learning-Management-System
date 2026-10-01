# University LMS

Laravel 13 JSON API for a university learning management system: accounts and roles with single sign-on, the academic catalogue, course content, assignments with rubrics (including level descriptions), quizzes, grading, a gradebook, progress tracking, attendance with self check-in and online-meeting links, a Practical Skills Passport, an industrial attachment workspace, a research/project workspace, a learning intervention centre, student study groups, an academic integrity investigation workspace, an optional AI learning/teaching assistant, extended-time assessment accommodations, streaks/badges/participation points and personal learning goals, announcements, discussions, grade appeals, summary emails, virus scanning, similarity screening, bulk imports, course copying, notifications, and an audit trail. The backend is in `backend/` and the web frontend, which uses every endpoint, is in `frontend/`.

This document describes what is built and running. `ROADMAP.md` holds the longer-term product vision it was written from; several of its numbered items (or parts of them) have since been built, in which case they're described here instead — this README, not the roadmap, is the source of truth for what exists today.

## Local setup with Podman

1. Copy `backend/.env.example` to `backend/.env`. Set `LMS_ADMIN_EMAIL` and `LMS_ADMIN_PASSWORD` in that file before seeding. Use a unique password. The app connects to the database as a restricted role, `lms_app` (see Security and operations): set `LMS_APP_DB_PASSWORD` in a root `.env` file next to `compose.yaml` before the first `compose up`, and use the same value as `DB_PASSWORD` in `backend/.env`. If changing the owner password or the MinIO credentials, change the matching defaults in `compose.yaml` or provide root Compose variables too.
2. Run `podman compose up --build -d` from this directory (Podman Compose is the provider; the image is built from `backend/Containerfile`).
3. Run `podman compose exec app php artisan migrate --database=pgsql_migrate --force` (migrations use the database owner; the app's own role cannot change tables) and `podman compose exec app php artisan db:seed --force`. After pulling new migrations, run `migrate` again and restart the queue worker (`podman compose restart queue`) so Horizon loads new code.
4. The API is at `http://localhost:8080/api`; liveness is `http://localhost:8080/up`, and a fuller health report is `http://localhost:8080/api/health`. Mailpit is at `http://localhost:8025`, and the MinIO console is at `http://localhost:9001`.

5. The web frontend is at `http://localhost:5173` (see [Frontend](#frontend)). Sign in with the administrator you seeded, then create terms, courses, accounts and offerings from the Administration menu.

The image is built once (`localhost/lms-backend:dev`) and shared by the app, queue, and scheduler containers. Composer dependencies live in the `vendor_data` volume, so a host `vendor/` folder is not needed. After changing `composer.json` or `composer.lock`, run `podman compose build app`, then `podman volume rm university-lms_vendor_data` and `podman compose up -d` to refresh it.

Do not expose this local HTTP setup on a public network. Configure TLS, production secrets, backups, and an institutional identity integration before deployment.

## Frontend

`frontend/` is a single-page web app (React, TypeScript, Vite) that talks to the API in the browser. It covers every endpoint below, for every role:

| Area | Screens |
|---|---|
| Everyone | Sign in (password or single sign-on), forgot and reset password, dashboard ("needs your attention", notifications, courses), notifications, profile (name, password, summary-email preference and preview, a per-device display preference for text size and contrast), footer links to the privacy policy and terms |
| Anyone with a link, no account | A workplace supervisor's one-time attachment feedback page (`/attachment-feedback/:token`) |
| Students | Course content with progress ticks and downloads, an AI study assistant grounded in course material, assignments with submission, rubric, grade breakdown and appeals, quizzes with a timer and results, announcements, discussions, study groups, classes with meeting links and check-in by code, attendance, a Skills Passport logbook with evidence uploads, an attachment placement with a weekly logbook, a project topic with milestones and meeting records, own grades, my appeals, a streak, badges and personal learning goals |
| Lecturers and assistants | Modules, items and files; assignments and rubrics with levels; grading (rubric, drafts, reasons for changes); similarity check; quizzes and all five question types; announcements; discussions and moderation; classes, check-in codes and the roll; competencies and logbook review; attachment placements and supervisor feedback requests; project topic review, supervision, milestones and meetings; at-risk signals and intervention plans; academic-integrity cases; an AI teaching assistant for drafts; extended-time accommodations; class participation points; gradebook (with weighting and a continuous-assessment total) and CSV download; progress; appeals |
| Students (registration) | Register for courses that allow it while the term's registration window is open, see the places left, and drop before the add/drop deadline |
| Registrars and administrators | Enrolment (one at a time, or from a list or CSV, respecting course capacity); teachers; terms with academic years and registration dates, departments, courses and offerings; publishing, archiving; copying a course to another term; accounts and one page per person (role, sign-in problems, sessions, privacy tools); CSV import with a dry run; usage and enrolment reports; audit log with filters and download; security events; system status |
| Institution and system administrators | Settings, roles and permissions, notices to everyone, integrations, system and failed jobs, backups: see [System administration](#system-administration) |

Under Administration the menu is grouped as Academics, Institution, Security and records, and System, and each person sees only the groups their permissions allow.

What each person sees follows their permissions: menus, tabs and buttons that someone may not use are hidden, and the API still enforces every rule. Failed requests show a clear message with a retry, lists have loading and empty states, the app retries dropped connections safely (every change carries an `Idempotency-Key`), and a session that ends returns to the sign-in page.

**Run it.** `podman compose up --build -d` starts it with the rest of the stack at `http://localhost:5173`. For development with hot reload, run `npm install` and `npm run dev` in `frontend/` (it uses the same address). The browser must be allowed by the API: `LMS_FRONTEND_URL` in `backend/.env` must be the address the frontend is served from (default `http://localhost:5173`), and the API's `LMS_CORS_ORIGINS` follows it.

**Point it at the API.** The container reads `LMS_API_URL` when it starts (default `http://localhost:8080/api`; set it in a root `.env` next to `compose.yaml` or in the environment). It writes that address into `config.js` and into the page's Content-Security-Policy, so one built image works everywhere. Under `npm run dev` edit `frontend/public/config.js` or set `VITE_API_URL`.

**Checks.** `npm run typecheck`, `npm test` (unit and screen tests with a fake API) and `npm run build`, all run in CI. Real-browser tests of the whole stack, including phone size, downloads, password flows and every role, are in [`e2e/`](e2e/README.md) (`cd e2e && npm install && npm test`); they need the stack running and clean up after themselves.

**Sessions.** The sign-in token lives in the browser's session storage, so it is gone when the tab closes, unless the person ticks "Keep me signed in on this device" (local storage). Reset and invitation emails link to `<LMS_FRONTEND_URL>/reset-password`, and single sign-on returns to `<LMS_FRONTEND_URL>/sso/callback`.

## System administration

This is a university system, not a school one: terms and academic years, departments and faculties, course levels and credits, registration windows and add/drop deadlines, lecturers and teaching assistants, registrars, and institution-wide reporting. Each of the eighteen duties of a system administrator maps to a screen, an API and a permission below. What a role may do is a set of permissions (`manage-users`, `manage-courses`, `manage-enrolments`, `manage-settings`, `manage-system`, …) that a super administrator can edit under **Roles and permissions**.

| # | Duty | Where it is (frontend screen → API) | Permission |
|---|---|---|---|
| 1 | User management | **People** and each person's page: create, search, import a CSV with a dry run, change name, email and role, activate or deactivate, unlock, email a reset link, sign out everywhere, delete (only if the account never produced anything), export all their data, anonymise. `/users`, `/users/{id}/…` | `manage-users` (anonymise: `manage-system`) |
| 2 | Role and permission management | **Roles and permissions**: a matrix of the seven roles, edit one role's permissions, or put it back to the starting set. The super administrator role is locked so the system can never lock out its own administrators. Every change is audited and security-logged. `/roles` | view: `manage-users`; edit: `manage-system` |
| 3 | Course management | **Terms and courses**: departments, courses with department, level and credits, offerings with capacity and self-registration, publish, archive a course, an offering or a whole term (read-only history, restorable), assign lecturers (a course's People tab), copy a course into another term. `/departments`, `/courses`, `/offerings`, `/terms/{id}/archive` | `manage-courses` |
| 4 | System configuration | **Settings**: institution name, support contacts, time zone and date format, grade appeal window, late-check-in minutes, upload size and allowed file types, password rules, lockout, session length, notification defaults, backups, maintenance. Saved in the database over the server configuration; "Use the default" goes back. `/settings`, `/auth/config` | `manage-settings` |
| 5 | Security management | **Settings > Security** (password length, upper and lower case, number, symbol, lockout, session length), **Security** (sign-ins, failures, lockouts, refused requests, busiest failing addresses; searchable), single sign-on (server configuration), roles. `/security/events`, `/security/summary` | `manage-users` / `manage-settings` |
| 6 | Database management | **System and jobs** shows the database's size, speed, record counts and whether any update (migration) is waiting, and **Housekeeping** removes expired records. Changing the structure is done at the server (`php artisan migrate`), deliberately: the app's database account can read and write rows but cannot alter tables. There is no SQL console. | `manage-system` |
| 7 | Backup and recovery | **Backups**: back up now (with or without uploaded files), automatic nightly backup, list, check against checksums, download, delete. Restore is a server command (see below). `/backups` | `manage-system` |
| 8 | System monitoring | **System status** (health of database, cache, storage, scheduler, queue) and **System and jobs** (versions, disk, storage by kind, queue depth, scheduler heartbeat, and a list of what needs attention). `/health`, `/system` | staff / `manage-system` |
| 9 | Technical support | A person's page shows why they cannot sign in (deactivated, locked, no sessions), unlocks them, emails a reset link and signs them out everywhere. Failed background jobs can be retried or deleted. Integrations can be tested for real. The footer shows the support address. `/users/{id}/unlock`, `/system/failed-jobs` | `manage-users` / `manage-system` |
| 10 | Enrolment administration | Enrol and withdraw one person, or import a list or CSV, on a course's People tab; a course's places (capacity) are enforced under a lock so it can never be over-filled, and a list import says who did not fit. Students may register themselves where an offering allows it. `/offerings/{id}/enrolments…`, `/registration` | `manage-enrolments` |
| 11 | Academic period management | **Terms and courses > Academic terms**: academic year, start and end, the current term, when student registration opens and closes, and the last day to add or drop; the dates are validated against each other. | `manage-courses` |
| 12 | Integration management | **Integrations**: single sign-on, email, file storage, virus scanning, the student-records system, online meetings, payments, API access. Each shows its state and which server setting to change, and the first four can be tested for real (the email test sends a message to you). Connection secrets are never shown or stored in the app. `/integrations` | `manage-settings` |
| 13 | Notification management | **Settings > Notifications** (email on or off, deadline reminders and how many days ahead, default summary-email setting), **Notices to everyone** (a banner across every screen for chosen roles, with a start and end, optionally also as a notification), and each person's own summary-email preference. `/system-announcements` | `manage-settings` |
| 14 | Audit and activity logs | **Audit log** (who did what, filtered by person, words and dates, downloadable as a spreadsheet; entries cannot be edited or deleted) and **Security** (sign-in and access events). `/audit-log` | `manage-users` |
| 15 | Reports and analytics | **Reports**: an overview, usage (sign-ins, submissions, quiz attempts and new accounts per day for 30 days, active people) and enrolment by term and department with the fullest and largest courses, downloadable. Each course also has progress, a gradebook and a CSV. `/reports/…` | `manage-courses` (enrolment: or `manage-enrolments`) |
| 16 | System maintenance and updates | **Settings > Maintenance** (only super administrators can use the system meanwhile; everyone else and the sign-in page see your message), **Housekeeping** (preview and remove old records, refresh saved copies), and a warning when a database update is waiting. Installing a new version is done at the server (below). | `manage-settings` / `manage-system` |
| 17 | Data privacy and access control | Role permissions, the person's data export, deletion or anonymisation (submissions and grades stay, under "Former user"), retention of low-value records, links to the privacy policy and terms, email addresses stored only as a short hash in security logs, and every backup download recorded. | `manage-users` / `manage-system` |
| 18 | System availability | The health endpoint for an uptime monitor, the scheduler heartbeat, maintenance mode with a message, nightly backups, retries, and `Retry-After` on every limit. Running more than one copy behind a load balancer, or a failover database, is infrastructure and is not built here. | `manage-system` |

**Things you do at the server, on purpose.**

- Install or update: pull the new code, `podman compose up --build -d`, then `podman compose exec app php artisan migrate --database=pgsql_migrate --force` and `php artisan db:seed --force` (adds any new permission; it never overwrites a role you have edited). The System screen warns when a migration is waiting.
- `php artisan lms:backup` makes a backup now; `lms:restore` (no argument) lists them and `lms:restore <file name>` puts one back. Restore checks the backup, makes a safety copy of the current data, puts the site into maintenance, replaces the database and the uploaded files, applies any newer migrations, and brings the site back. Add `--skip-files` to restore only the database. Restore uses the migration account (`DB_MIGRATE_*`), because the everyday account cannot reset the counters. `lms:prune [--dry-run]` removes expired sign-ins, used links, old read notifications and old security events; `lms:e2e` is for the browser tests.
- Backups are written to `storage/app/backups` by default (`LMS_BACKUP_PATH`, `LMS_BACKUP_DISK`). Copy them off the machine: a backup on the same disk is lost with the disk. A backup holds every password hash and every grade; protect it as you would the database.
- Backups and the queue: a backup runs on its own worker so a long one never delays emails. `podman compose up` starts it (the `queue` container runs Horizon with a `backups` supervisor); after upgrading from an older version, restart that container.

**Honest limits.**

- Departments are categories for courses, reports and filters. There are no department-scoped administrators yet: a department administrator manages every course.
- The seven roles are fixed; their permissions are editable. You cannot invent an eighth role or edit the super administrator.
- The screens are in English. The institution's chosen locale and time zone change how dates and numbers are written, not the words.
- The student-records system connects by CSV, not by a live vendor connection; payments and fees are not part of this system (tuition belongs in the finance system); video meetings are links, not created automatically.
- Important dates are the term, registration and add/drop dates. There is no general academic calendar (exam timetables, holidays).
- A backup can be restored only at the server.

## API

All routes are under `/api`. `POST /login` returns a Sanctum bearer token; send it as `Authorization: Bearer <token>` on everything else. Send `Accept: application/json`. List endpoints are paginated. Tokens expire after 720 minutes (`SANCTUM_TOKEN_EXPIRATION`, in minutes).

**Roles.** Everyone at the university uses the LMS; administrators are one of seven kinds of user. What each role can do is checked through the real API in `tests/Feature/RoleCapabilitiesTest.php`, so this table cannot drift from the code:

| Role | Can do |
|---|---|
| `student` | In courses they are actively enrolled in: read published material, submit assignments, take quizzes, see their own grades, appeal a published grade, check in to class, and discuss. Nothing else. |
| `lecturer` | In the courses they are assigned to: build content, set assignments and quizzes, grade with rubrics, post announcements, take attendance, and decide grade appeals. |
| `teaching-assistant` | Manage and grade the courses they are assigned to, like a lecturer, except they **cannot decide grade appeals**. |
| `registrar` | Enrol students (one at a time or from a file), and look up users and offerings. They do not touch course content. |
| `department-admin` | Run the catalogue and every course: create terms and courses, assign teachers, copy courses, enrol students, edit any course's content, take attendance, decide appeals. They **cannot create accounts, import users, or read the audit log**, and they do not grade. |
| `university-admin` | Everything a department admin can do, plus create and deactivate accounts, import users from a file, and read the audit log. They do not grade. |
| `super-admin` | Everything, including grading. The one thing a super-admin cannot do is act as a student: submitting work or taking a quiz still needs an active enrolment. |

Course-level powers (content, announcements, attendance, grading) apply only to courses the person is assigned to; department and university admins can manage every course. Students see only offerings they are actively enrolled in, and only published content.
### Account

| Route | Who | Purpose |
|---|---|---|
| `POST /login` | anyone | Email and password for a token. Rate limited; deactivated accounts cannot sign in. |
| `POST /logout` | signed in | Revoke the current token. |
| `GET /me`, `PATCH /me` | signed in | Show the profile, roles and the permissions those roles grant (so a frontend can show the right screens); change your own name. |
| `POST /me/password` | signed in | Change password (needs `current_password`, `password`, `password_confirmation`; the rules are in `GET /auth/config` under `password_policy`, at least 12 characters). Signs out other devices. |
| `GET`/`PATCH /me/preferences` | signed in | Read or change how often you get the summary email: `digest_frequency` is `off` (the default unless the institution changes it), `daily`, or `weekly`. |
| `GET /me/digest-preview` | signed in | What your next summary email would say right now. Nothing is sent. |
| `POST /forgot-password`, `POST /reset-password` | anyone | Email a reset link to the frontend (`LMS_FRONTEND_URL`), then set a new password with its token. The response never reveals whether an account exists. Reset revokes all tokens. |
| `GET /notifications`, `POST /notifications/{id}/read` | signed in | Database notifications (assignment reminders, announcements, published grades). |
| `GET /auth/config` | anyone | Tells the login screen whether single sign-on is on and whether password sign-in is still allowed, plus the institution (name, support contacts, time zone, locale), the password rules and whether maintenance mode is on. |
| `GET /auth/sso`, `POST /auth/sso/callback` | anyone | Sign in with the university's identity provider; see [Single sign-on](#single-sign-on). |

### Administration

| Route | Who | Purpose |
|---|---|---|
| `GET /users` (`?role=`, `?q=`, `?status=`) | user admins, registrars | Search users (with `last_login_at`). |
| `POST /users` | user admins | Create a user with a role. Only super-admins can create super-admins. The password must meet the policy. |
| `POST /users/import` | user admins | Upload a CSV with the columns `name,email,role` (max 1000 rows, columns in any order; commas or semicolons). Each new user gets a welcome email with a link, valid for 7 days, to choose a password. Bad rows are reported by line number and skipped; the rest are created. `dry_run=1` checks the file without creating anything; `send_invitations=0` skips the emails. |
| `GET /users/{id}` | user admins | One account in full: state, lockout, active sessions, courses, recent security events, and what records it owns. |
| `PATCH /users/{id}` | user admins | Change `name`, `email`, `role` and `is_active`. Only a super-admin may grant or remove that role; nobody changes their own role or deactivates themselves; the last active super-admin cannot be demoted or deactivated. Deactivating revokes tokens. |
| `POST /users/{id}/unlock`, `/reset-link`, `/sessions/revoke` | user admins | Clear a lockout; email a password reset link; sign the person out everywhere. |
| `GET /users/{id}/export` | user admins | Everything held about one person, as JSON. |
| `DELETE /users/{id}` | user admins | Delete an account that never produced anything; otherwise refused with the reason. |
| `POST /users/{id}/anonymise` | system admins | Replace name and email, block sign-in, keep grades and submissions. Needs `confirm_email`. Cannot be undone. |
| `GET /roles`, `PUT /roles/{role}/permissions`, `POST /roles/{role}/reset` | view: user admins; change: system admins | The permission catalogue and each role's permissions; set exactly which permissions a role holds; put it back to the starting set. The super-admin role cannot be edited. |
| `GET /settings`, `PUT /settings` | settings admins | Every setting with its value, default and whether it was changed; save some (`settings`) and/or reset others (`reset`). Nothing is saved unless every value is valid. |
| `GET /audit-log` (`?q=`, `?causer_id=`, `?from=`, `?to=`, `?subject_type=`, `?format=csv`) | user admins | Who changed what: grades, deadlines, enrolments, accounts, settings, roles, backups, deletions. CSV holds up to 20,000 entries. |
| `GET /security/events`, `GET /security/summary` | user admins | Sign-ins, failures, lockouts and refused requests (filter by `event`, `level`, `user_id`, `email`, dates); counts for the last day and week and the busiest failing addresses. |
| `GET /reports/overview` | course admins | Institution-wide counts. |
| `GET /reports/usage` | course admins | Per-day sign-ins, submissions, quiz attempts and new accounts for 30 days; active people over 7 and 30 days. |
| `GET /reports/enrolments` (`?term_id=`, `?format=csv`) | course admins, registrars | Enrolment, places and how full, by term and department; the fullest and largest courses. |
| `GET /system`, `GET`/`POST`/`DELETE /system/failed-jobs…`, `POST /system/refresh`, `POST /system/prune` | system admins | Versions, capacity, queue, scheduler, pending migrations and a list of warnings; retry or delete failed jobs; forget saved copies of reports, settings and permissions; remove expired records (`dry_run` to preview). |
| `GET`/`POST /backups`, `POST /backups/{name}/verify`, `GET /backups/{name}/download`, `DELETE /backups/{name}` | system admins | List backups; start one in the background; check one against its checksums; download (recorded in the audit and security logs); delete. There is no restore endpoint, on purpose. |
| `GET /integrations`, `POST /integrations/{key}/test` | settings admins | State of each connection; try single sign-on, email, storage or the virus scanner for real. |
| `GET`/`POST /system-announcements`, `PATCH`/`DELETE /system-announcements/{id}`, `GET /system-announcements/active` | settings admins (`active`: everyone) | Notices for the whole institution, for chosen roles and dates; `notify` also puts one in each recipient's notifications. `active` returns what the caller should see now. |

### Catalogue and offerings

| Route | Who | Purpose |
|---|---|---|
| `GET`/`POST /terms`, `PATCH /terms/{id}`, `POST /terms/{id}/archive` | course admins | Academic terms with `academic_year`, `registration_opens_on`, `registration_closes_on`, `add_drop_deadline` and `is_current` (only one term is current). Archiving a term archives its offerings (`archived`: true or false). |
| `GET`/`POST /departments`, `PATCH`/`DELETE /departments/{id}` | course admins (list also registrars) | Faculties and departments. A department with courses is archived, not deleted. |
| `GET`/`POST /courses`, `PATCH /courses/{id}` | course admins | Catalogue courses (unique code) with `department_id`, `credits` and `level` (certificate, diploma, undergraduate, postgraduate, doctoral); filter by `q`, `department_id`, `level`, `archived`. |
| `GET`/`POST /offerings`, `PATCH /offerings/{id}` | course admins (list also registrars) | A course in a term. `PATCH` changes `published`, `section`, `capacity`, `self_enrolment` and `archived`. The list filters by `term_id`, `department_id`, `q`, `status` and `archived`. |
| `GET /registration`, `POST`/`DELETE /offerings/{id}/register` | students | Courses a student may register for themselves (published, self-registration on, term not over), with whether registration is open, the places left and the drop deadline; register and drop. Registration works only inside the term's window and while places remain; a registrar's enrolment can be changed only by a registrar. |
| `GET /offerings/{id}` | anyone who can view it | Modules, items, assignments, and quizzes (unpublished ones only for managers), the teachers, and `abilities.manage` (whether the caller may edit this course). |
| `POST /offerings/{id}/teachers`, `DELETE /offerings/{id}/teachers/{user}` | course admins | Assign or remove teaching staff. |
| `POST /offerings/{id}/enrolments` | registrars, admins | Enrol or withdraw one user (`status`: `active`/`withdrawn`). |
| `POST /offerings/{id}/enrolments/import` | registrars, admins | Either `{"emails": [...]}` or a CSV `file` with an `email` column (max 500). Enrols or re-activates existing students; returns `enrolled`, `not_found`, `invalid` and `full` (students who did not fit because the course has no places left). This is the way to load a student-record export. |
| `POST /offerings/{id}/copy` | course admins | Build next term's offering from this one: modules, items (files are duplicated, not shared), assignments with rubrics, and quizzes with questions. Body: `academic_term_id`, `section`, optional `copy_teachers`. The copy is unpublished, and copied assignments and quizzes are unpublished with dates shifted by the distance between the two terms' start dates. People, submissions, attempts, announcements, and discussions are never copied. |
| `GET /offerings/{id}/roster` | managers, registrars | Teachers and enrolments. |
| `GET /offerings/{id}/summary` | managers | Enrolled count, per-assignment submitted/graded/awaiting, per-quiz participation. |

### Content, progress, and communication

| Route | Who | Purpose |
|---|---|---|
| `POST /offerings/{id}/modules`, `PATCH`/`DELETE /modules/{id}` | managers | Weekly or topic modules. Deleting also removes the stored files. |
| `POST /modules/{id}/items`, `PATCH`/`DELETE /items/{id}` | managers | Items of type `text`, `link` (http/https only), or `file`. |
| `GET /items/{id}/download` | viewers | Download a file item. |
| `POST`/`DELETE /items/{id}/complete` | enrolled students | Mark material done or not done. |
| `GET /offerings/{id}/progress` | viewers | A student gets their own completion; managers get every student's. |
| `GET`/`POST /offerings/{id}/announcements`, `DELETE /announcements/{id}` | view / managers | Announcements; posting notifies actively enrolled students. |
| `GET`/`POST /offerings/{id}/sessions`, `PATCH`/`DELETE /sessions/{id}` | view / managers | Scheduled class meetings with a start and end, an optional room, and an optional `join_url` (http/https only) for an online meeting on Zoom, Teams, Meet, or any other service. Students also see their own attendance status on each session. |
| `GET`/`PUT /sessions/{id}/attendance` | managers | The roll of actively enrolled students, and marking or correcting attendance (`present`, `late`, `absent`, `excused`, with an optional note) for one or many students at once. |
| `GET /offerings/{id}/attendance` | viewers | A student gets their own totals; managers get every student's. Late counts as attended; excused sessions are left out of the percentage. |
| `POST /sessions/{id}/checkin/open` (`{minutes}`, default 15), `POST /sessions/{id}/checkin/close`, `GET /sessions/{id}/checkin` | managers | Self check-in: the teacher opens a window and reads the six-digit code out in class; the status call shows the code, whether it is open, and how many students have checked in. |
| `POST /sessions/{id}/checkin` (`{code}`) | enrolled students | Marks the student present, or late once more than `LMS_CHECKIN_LATE_AFTER_MINUTES` (default 10) have passed since the session's start. Rate limited to 10 tries a minute. A teacher's own mark always wins, teachers can correct a self check-in afterwards, and the code never appears in session listings. This proves the student had the code, not that they were in the room. |
| `GET`/`POST /offerings/{id}/discussions`, `GET`/`DELETE /discussions/{id}`, `POST /discussions/{id}/posts`, `DELETE /posts/{id}` | enrolled students and staff | Forum threads and replies. Authors delete their own; managers moderate. |
| `GET`/`POST /offerings/{id}/study-groups`, `DELETE /study-groups/{id}`, `POST /study-groups/{id}/join`\|`/leave` | enrolled students (`DELETE`: the creator or managers) | Student-organised study groups, open to any actively enrolled student to start or join - distinct from a lecturer's assignment groups (`is_group_assignment`). An optional `max_members` closes it once full. |

### Practical Skills Passport

| Route | Who | Purpose |
|---|---|---|
| `GET`/`POST /offerings/{id}/competencies`, `PATCH`/`DELETE /competencies/{id}` | view / managers | The practical skills a course tracks. A student's `GET` includes `my_status` (`not_started`, `developing`, or `competent`) and `my_hours` (summed from their own logbook) on each one. A competency with logbook entries cannot be deleted. |
| `GET`/`POST /competencies/{id}/logbook` | owner (`GET`: also managers, optionally `?user_id=`) | A student logs a dated, timed activity (`activity_date`, `hours`, `description`) with an optional `evidence` file (photo or video, same limits as other uploads); their first entry on a competency moves it from `not_started` to `developing`. Students see only their own entries; managers see everyone's. |
| `PATCH /logbook-entries/{id}/review`, `GET /logbook-entries/{id}/evidence` | managers / owner or managers | A supervisor reviews one entry (`status`: `developing` or `competent`, optional `reviewer_comment`), which sets the student's overall status on that competency; downloads the entry's evidence file. |

### Industrial attachment workspace

| Route | Who | Purpose |
|---|---|---|
| `GET`/`POST /offerings/{id}/attachments`, `GET /offerings/{id}/attachments/mine` | managers / owner | A student's work placement: organisation, supervisor's name and email, objectives, and dates. One per student per offering. |
| `GET`/`POST /attachment-placements/{id}/logbook`, `GET /attachment-logbook-entries/{id}/evidence` | owner (`GET`: also managers) | A weekly entry (`week_ending`, `hours`, `activities`, optional `evidence` file); one entry per week per placement. |
| `POST /attachment-placements/{id}/request-supervisor-feedback` | managers | Emails the workplace supervisor — who has no LMS account — a one-time link valid for 14 days. Sending again invalidates any unused earlier link. |
| `GET`/`POST /attachment-feedback/{token}` | anyone with the link | Public, unauthenticated: the supervisor reads the placement summary and submits a 1–5 rating and an optional comment, once. |

### Research and project workspace

| Route | Who | Purpose |
|---|---|---|
| `GET`/`POST /offerings/{id}/projects`, `GET /offerings/{id}/projects/mine` | managers / owner | A student proposes one project topic per offering (`status`: `proposed`). |
| `PATCH /projects/{id}` | managers (`status`, `supervisor_id`, and content) / the owning student (title and description, only while still `proposed`) | Approve or reject a topic and assign a supervisor (any teacher of the offering); a student cannot set either. |
| `GET`/`POST /projects/{id}/milestones`, `PATCH /project-milestones/{id}` | student, the assigned supervisor, or managers (`POST`/`PATCH`: supervisor or managers) | Supervisor-set checkpoints (`title`, `due_on`), toggled `completed`. |
| `GET`/`POST /projects/{id}/meetings` | student, the assigned supervisor, or managers | A dated note either party logs after a supervision meeting. |

Draft chapters, similarity screening and rubric-based marking reuse the existing assignment, similarity and rubric features (create an assignment scoped to the project) rather than a second copy of them.

### Learning intervention centre

| Route | Who | Purpose |
|---|---|---|
| `GET /offerings/{id}/at-risk` | managers | Every actively enrolled student with three signals computed on the fly, not stored: `missing_assignments` (published assignments past their deadline with nothing submitted), `declining` (their recent published-grade average is at least 10 points below their earlier average, once there are enough marks to compare), and `inactive_days` (days since last sign-in, `null` if they never have). `flagged` is true if any signal is tripped (missing work, a decline, or 14+ inactive days); `has_open_plan` says whether an intervention plan is already open for them. |
| `GET`/`POST /offerings/{id}/intervention-plans` (`?user_id=`) | managers | A lecturer's record of reaching out: `reason` and an optional `action_plan`, both internal. An optional `message_to_student` sends the student a private, separate note (database and email) that never includes the reason or action plan. |
| `PATCH /intervention-plans/{id}` | managers | Update the plan or set `status` to `resolved` (stamps `resolved_at`) or back to `open`; can also send a further `message_to_student`. |

Scheduling consultation sessions is deliberately left to the free-text consultation hours in [Catalogue and offerings](#catalogue-and-offerings) - no booking system, for the same reason as item 2's lecturer consultation times.

### Academic integrity centre

| Route | Who | Purpose |
|---|---|---|
| `GET`/`POST /offerings/{id}/integrity-cases` | managers | A lecturer's investigation workspace, separate from a grade appeal: `description` of the concern, an optional `submission_id` (must belong to the named `user_id`), `status` (`open`, `upheld`, `dismissed`). |
| `PATCH /integrity-cases/{id}` | managers | Add an `outcome` and decide `status`; moving off `open` stamps `resolved_at`, moving back to `open` clears it. |

A submission can declare `used_ai` with a required `ai_use_description` when true (see `POST /assignments/{id}/submissions` under [Assignments and grading](#assignments-and-grading)). Plagiarism/similarity screening, submission version history, and the grade-appeal process already cover the rest of this item; citation checking needs an external service this repo does not integrate.

### Assignments and grading

| Route | Who | Purpose |
|---|---|---|
| `POST /offerings/{id}/assignments`, `PATCH`/`DELETE /assignments/{id}` | managers | Changing a deadline needs `change_reason` and is audit-logged. Assignments with submissions cannot be deleted. An optional `weight` (0–100) feeds the gradebook's continuous-assessment total; see the gradebook route below. |
| `GET /assignments/{id}` | anyone who can view it | One assignment with its course and what the caller may do: `abilities.manage`, `.grade`, `.submit`. |
| `POST /assignments/{id}/submissions` | enrolled students | Text and/or file, once, before the deadline. If the assignment has `allow_late_submissions` on, a submission after the deadline is still accepted but needs a `late_explanation` and is flagged `late` for the grader. If `allow_resubmission` is on, sending it again replaces the submission (the prior version is kept, see below) instead of being refused — until a published grade exists, after which it is refused like any other resubmission attempt. For a group assignment (`is_group_assignment`), the caller must already be placed in a group; submitting (or resubmitting) and being graded apply to every member, each of whom keeps their own submission and grade record. Optional `used_ai` (boolean) declares AI use; true requires `ai_use_description`. |
| `GET /assignments/{id}/submissions`, `GET /submissions/{id}/download` | graders / owner | List and download submissions. |
| `GET /submissions/{id}/versions` | graders / owner | Earlier drafts of a resubmitted submission, oldest first, each a snapshot taken just before a resubmission replaced it. |
| `GET`/`POST /submissions/{id}/feedback` | graders / owner (`POST`: graders) | A lightweight comment thread on the current draft, separate from formal grading — for giving feedback before the student resubmits. |
| `GET`/`POST /assignments/{id}/groups`, `PATCH`/`DELETE /assignment-groups/{id}` | managers | Groups for a group assignment (`is_group_assignment`). A student can belong to at most one group per assignment. |
| `GET /assignments/{id}/my-group` | students | The caller's own group and teammates, or `null` if not placed in one yet. |
| `POST /assignments/{id}/peer-reviews/assign` (`{per_student}`) | managers | Randomly hands every student with a submission that many classmates' submissions to review, anonymously. Not available for group assignments. Running it again reshuffles anything not yet reviewed but keeps completed reviews. |
| `GET /assignments/{id}/my-peer-reviews`, `PATCH /peer-reviews/{id}` | the assigned reviewer | The reviewer's assigned submissions (without whose they are) and sending one review's feedback text. |
| `GET /submissions/{id}/peer-reviews` | owner / graders | Completed reviews a submission has received, with no reviewer identity attached. |
| `GET`/`PUT`/`DELETE /assignments/{id}/rubric` | view / managers | A rubric is a list of criteria with maximum points that must add up to the assignment's maximum score. Each criterion can list levels (a title, a description, and points, for example "Excellent, 9 points: a clear thesis backed by evidence") that graders pick from and students can read; level points cannot exceed the criterion's maximum. The rubric is frozen once any grade exists. |
| `POST /submissions/{id}/grades` | graders | Append a grade record (`draft` or `published`). Later changes need `change_reason`. With a rubric, send `criteria: [{criterion_id, level_id or points, comment}]` (one per criterion) instead of `score`; the total is calculated and each criterion's mark is kept. Publishing notifies the student by database notification and email (the email says a grade is ready but never contains the mark). A grade can also carry a `feedback_recording` file (mp3, mp4, wav, m4a, webm or ogg, up to the upload size limit) as a voice or video note alongside or instead of written feedback; download it with `GET /grades/{id}/recording`. |
| `GET /assignments/{id}/similarity` (`?threshold=0.5`) | graders | Screens the class's written answers and plain-text files (.txt, .md, .csv) against each other for copied text. See the note below. |
| `POST /submissions/{id}/appeal` (`{reason}`) | the student | Appeal a published grade once, within `LMS_APPEAL_WINDOW_DAYS` (default 14) days of it being published. Teaching staff are notified. A student can withdraw an open appeal (`DELETE /appeals/{id}`) and file it again while the window is open. |
| `GET /my-appeals`, `GET /offerings/{id}/appeals` (`?status=`), `GET /appeals/{id}` | student / managers / either | Appeals, open ones first. The detail includes the published grade history. |
| `POST /appeals/{id}/resolve` (`{outcome, response}`) | lecturers and course admins (permission `resolve-appeals`) | `upheld` needs a revised grade to have been published first through the normal grading route, with its change reason; `rejected` is refused if the grade was changed. The student is notified of the outcome by email and in the app; the explanation is read after signing in. Decisions are final and audit-logged. |
| `GET /assignments/{id}/my-grade` | students | Your submission and latest published grade. |
| `GET /offerings/{id}/gradebook` (`?format=csv`) | managers | Every student's marks: latest published assignment grade and best submitted quiz attempt. An assignment or quiz may carry an optional `weight` (0–100); each row then also carries `weighted_percent`, a continuous-assessment total blending only the marked items that have a weight (scaled back to 100%), and `weight_used`, the weight actually counted. Items with no weight still count toward the plain, unweighted `percent` as before. CSV cells are protected against spreadsheet formula injection. |
| `GET /offerings/{id}/my-grades` | students | Your own row of the gradebook. |

Percent is calculated over the items a student has a mark for, so work not yet graded does not lower it.

### Quizzes

Question types: `single_choice`, `multiple_choice` (all correct options required, no partial credit), `true_false` (send `"correct": true|false`), `short_answer` (each option is an accepted answer; matching ignores case and extra spaces), and `essay` (no options; marked by hand). All but `essay` are graded automatically on submission; an essay answer is scored 0 and flagged until a grader marks it with `PATCH /quiz-answers/{id}/grade` (`{points}`, up to the question's points), which recalculates the attempt's score. `GET /attempts/{id}` and the submit response both carry `awaiting_manual_grading` (attempt-level) and `needs_manual_grading` (per answer).

| Route | Who | Purpose |
|---|---|---|
| `POST /offerings/{id}/quizzes`, `GET`/`PATCH`/`DELETE /quizzes/{id}` | managers (GET: viewers) | `due_at`, optional `opens_at`, `time_limit_minutes`, `max_attempts`, `questions_per_attempt`, `is_open_book` (a label shown to students only — nothing here can check what they have open elsewhere). Students never receive questions from `GET`. Deadline changes need `change_reason`. |
| `POST /quizzes/{id}/questions`, `PATCH`/`DELETE /questions/{id}` | managers | Author questions. Locked once anyone has attempted the quiz; a quiz with attempts cannot be deleted, only unpublished. |
| `POST /quizzes/{id}/attempts` | enrolled students | Start (or resume) an attempt. The response holds the questions without correct answers, plus the `deadline`. When `questions_per_attempt` is set, a new attempt draws that many questions at random from the quiz's full pool and keeps that draw for its lifetime (so different students, or different attempts, can see different questions); resuming an open attempt returns the same draw it started with. |
| `POST /attempts/{id}/submit` | the student | Body `{"answers": [{"question_id", "option_ids": [...] or "text"}]}`. An attempt still open a minute past its deadline is closed with a zero score. |
| `PUT /attempts/{id}/draft` | the student | Same `answers` shape as submit, but saves it as a draft without grading. The frontend calls this every couple of seconds while an attempt is in progress, and `GET /attempts/{id}` returns the latest draft as `draft_answers` so refreshing, a crashed browser, a cleared cache, or switching devices does not lose an exam in progress. Refused once the attempt is submitted. |
| `GET /attempts/{id}`, `GET /quizzes/{id}/my-attempts`, `GET /quizzes/{id}/attempts` | owner or staff / student / staff | Results and attempt lists. |

### Uploads

Course files and submissions are limited to 25 MB and to the extensions in `LMS_UPLOAD_MIMES` (documents, spreadsheets, slides, text/CSV/Markdown, ZIP, common images, MP3/MP4). The check looks at the file's real content, so a renamed executable is rejected. Files live in a private S3 bucket and are only served through authorized API routes. PHP and nginx are set up for these sizes in `infra/php/lms.ini` and `infra/nginx/default.conf` (mounted by `compose.yaml`); PHP's built-in defaults (2 MB per file) would reject them, so apply the same values if you deploy without Compose.

**Virus scanning** is built in but off by default, because it needs the ClamAV service. To turn it on, run `podman compose --profile scan up -d` (about 1 GB of RAM; the first start downloads the signature database and takes a few minutes), then set `LMS_VIRUS_SCAN=true` in `backend/.env`. Every upload is streamed to ClamAV before it is stored, and infected files are rejected with a 422. If the scanner cannot be reached, uploads are refused (the safe default); set `LMS_VIRUS_SCAN_FAIL_OPEN=true` to accept them with a logged warning instead. Uploads up to 25 MB are scanned. Tested against a real ClamAV with the standard EICAR test file.

**Similarity screening** compares a class against itself using overlapping five-word phrases. It reads written answers and plain-text files only (not PDF or Word), needs at least 20 words per submission, and handles up to 300 submissions at a time. It cannot see the web or earlier terms' work, and a high score is a reason to read two submissions side by side, not proof of copying. It is not a replacement for a commercial service such as Turnitin.

### Single sign-on

Sign-in through any OpenID Connect provider (Microsoft Entra ID, Google Workspace, Okta, Keycloak, and others) using the authorization-code flow with PKCE. It is off until you set these in `backend/.env`:

| Setting | Purpose |
|---|---|
| `LMS_SSO_ENABLED=true` | Turns it on (also needs the three values below). |
| `LMS_SSO_ISSUER`, `LMS_SSO_CLIENT_ID`, `LMS_SSO_CLIENT_SECRET` | From the provider's app registration. The issuer is the address that serves `/.well-known/openid-configuration`. |
| `LMS_FRONTEND_URL` | Where your frontend lives. Register `<LMS_FRONTEND_URL>/sso/callback` as the redirect address at the provider, or set `LMS_SSO_REDIRECT_URI` to another address. |
| `LMS_SSO_AUTO_PROVISION` | `true` creates a student account on someone's first sign-in. Default `false`: they must already have an account. Staff roles are never granted by the provider; an administrator assigns them. |
| `LMS_SSO_ALLOWED_DOMAINS` | Comma-separated email domains allowed to sign in, for example `uni.edu,staff.uni.edu`. |
| `LMS_SSO_REQUIRE_VERIFIED_EMAIL` | Default `true`: the provider must say the email is verified. Set `false` for providers such as Microsoft Entra ID that do not send that claim; only do so when the provider itself controls who can have an address at your domain. |
| `LMS_SSO_PASSWORD_LOGIN` | Set `false` to stop everyone except super-admins from signing in with a password once SSO is on. Keep a super-admin as a break-glass account. |

The frontend calls `GET /auth/sso`, sends the browser to the returned `url`, and when the provider redirects back it posts the `code` and `state` to `POST /auth/sso/callback`, which returns the same token as a normal login. The ID token's signature (RS256 only), issuer, audience, expiry, and nonce are all checked; each `state` works once and expires after 10 minutes. The first sign-in links the account to the provider's stable user id, so a later change of email at the provider can neither lock a person out nor let someone else take over their account. SAML is not supported.

### AI learning assistant

Off by default, like this repo's other optional integrations — a real Claude API key costs money per request. Set these in `backend/.env`:

| Setting | Purpose |
|---|---|
| `LMS_AI_ENABLED=true` | Turns it on (also needs the key below). |
| `ANTHROPIC_API_KEY` | A real Claude API key. Nothing works without one. |
| `LMS_AI_MODEL` | Default `claude-sonnet-5`. |
| `LMS_AI_BASE_URL` | Default the real Claude Messages API; override only for testing. |
| `LMS_AI_MAX_SOURCE_CHARS` | Default 12000. Caps how much course material is sent per request, to bound cost. |

| Route | Who | Purpose |
|---|---|---|
| `POST /offerings/{id}/ai/assist` | enrolled students | Body `{mode, prompt}`, `mode` one of `ask`, `summarise`, `revision_questions`, `flashcards`, `study_plan`. The system prompt is built from the offering's own published, text-type learning items only (file and link items are not read), numbered as sources; the model is instructed to use only that material, say so when it does not cover the question, and never write a complete, submission-ready answer to a specific graded assignment or quiz question. The response is `{answer, sources}`, where `sources` lists the course items the model says it drew on, parsed from a trailing "Sources: " line in its reply. |
| `POST /offerings/{id}/ai/teaching-assist` | managers | Same shape, for `mode` one of `lesson_outline`, `quiz_draft`, `rubric`, `discussion_questions`, `remedial_suggestions`. Still grounded in the course's own material where relevant, but the model may also use general teaching knowledge, since these are drafts for the lecturer to review before use — the material-only restriction is a student-facing honesty rule, not a lecturer one. |

Both routes return a plain-text validation error on the `prompt` field if the assistant is not configured, so the frontend can show it like any other form error. Identifying commonly failed questions and summarising class performance are better served by the real data already in the gradebook and quiz results than by an AI guess, so they are not part of this feature.

### Accessibility and inclusion

| Route | Who | Purpose |
|---|---|---|
| `GET`/`POST /offerings/{id}/accommodations` | managers | A documented extra-time percentage (1–300) for one actively enrolled student in one course, with optional notes. `POST` sets or updates the one record per student per course (`updateOrCreate`), rather than stacking duplicates. |
| `DELETE /accommodations/{id}` | managers | Remove it; the student's quizzes go back to their plain time limit. |

Every timed quiz attempt's deadline (`QuizAttempt::deadline()`) is extended by the student's accommodation in that course, if any, automatically — nothing to configure per quiz. Separately, a per-device, local-only "Display" preference under My account (text size: normal/large/larger, and a high-contrast mode) is applied to the whole app via a `data-font-size`/`data-contrast` attribute on `<html>`; it is not sent to the server, the same way low-data mode already works, so it is not visible to anyone else and does not sync across devices. Captions/transcripts are out of scope (this repo has no recorded media to caption), as are a dedicated screen-reader/keyboard-navigation audit, multi-language support, and automated accessible-document checking (needs an external service).

### Engagement and motivation

Streaks, badges and participation points are all computed from data the app already has, never a separate tracked game layer, so a badge can never claim something the record books disagree with — and none of it feeds a grade or the gradebook (see ROADMAP.md item 18).

| Route | Who | Purpose |
|---|---|---|
| `GET /me/engagement` | anyone signed in | `streak` (`current_days`/`longest_days`, consecutive calendar days with a successful sign-in), `badges` (a skill badge per competency signed off `competent`, a milestone badge at 25/50/75/100% of a course's published content completed, and a badge for a quiz attempt scored full marks), and `participation`, this user's own participation points per actively-enrolled course. |
| `GET /offerings/{id}/engagement` | managers | Every actively enrolled student's participation points in this course, for the course's own staff only — never a public, cross-student leaderboard. Points: attendance marked present or late (1), starting a discussion thread (2), replying to one (1), submitting a quiz attempt (1), submitting an assignment (2). |
| `GET`/`POST /me/learning-goals`, `PATCH`/`DELETE /learning-goals/{id}` | the student themselves | A personal goal (`title`, optional `target_date`), always self-managed — nobody else can read or change another person's goals. `PATCH` with `{"completed": true/false}` stamps or clears `completed_at`. |

Progress celebrations are the frontend showing the badges and streak that already exist here, rather than a separate notification system.

### Scheduled jobs

Every night (in the institution's time zone) the scheduler makes a backup at 02:30 (`LMS_BACKUPS`, keeping the newest `LMS_BACKUPS_KEEP`) and removes expired records at 03:15. It also writes a heartbeat every minute, which the health check and the System screen read. The scheduler queues email and database reminders at 08:00 UTC for assignments due the next calendar day. At 07:00 UTC it also sends summary emails (daily, and weekly on Mondays) to people who chose that setting: what they missed while unread, unsubmitted work due within a week, and, for teaching staff, work waiting for a published grade and open appeals. Summaries contain counts and titles only, never marks, and nothing is sent when there is nothing to say. New accounts start on `LMS_DIGEST_DEFAULT` (`off`, `daily`, or `weekly`; default `off`). Run one by hand with `podman compose exec app php artisan lms:send-digests daily`.

## What is and is not integrated

Some things a university expects are tied to its own systems, so they are provided in the form that works with any institution:

| Need | What is built | What it does not do |
|---|---|---|
| Single sign-on | OpenID Connect (see above) | SAML. Not tested against a live Microsoft, Google, or Okta tenant; it was tested against a stand-in provider that signs real tokens and enforces the client secret and PKCE. Point it at your provider and try it before relying on it. |
| Student-record system | CSV import of users and enrolments; most registration systems can export one | A live connection to a specific vendor's system, and automatic nightly sync. |
| Plagiarism checking | Similarity screening within a class | Turnitin or any external service, comparison with the web or earlier terms, and PDF or Word files. |
| Video meetings | A meeting link on each class session, and attendance | Creating meetings automatically in Zoom, Teams, or Meet. |
| Virus scanning | ClamAV, opt-in (see Uploads) | Scanning files already stored before it was switched on. |
| AI assistant | Claude API, opt-in (see "AI learning assistant"), restricted to a course's own published material for students | Any other model provider; a conversation history (each request is answered on its own); citation checking or detecting AI-written submissions. |

Still not built: second-marker moderation of whole cohorts and location-checked attendance. Quiz attempts that expire while a student is offline are closed the next time the attempt is read or started, not by a background job. Run behind TLS with production secrets and tested backups before a pilot (see Security and operations). The Composer lock is resolved for PHP 8.3, including `spatie/laravel-activitylog` 4.x, which supports Laravel 13 and PHP 8.3.
## Security and operations

Every item below is enforced by code and covered by tests (`SecurityHardeningTest`, `ReliabilityTest`, `PerformanceTest`, `PreflightAndEmptyStatesTest`), and was also checked against the running stack. `php artisan lms:preflight --production` prints a PASS/WARN/FAIL checklist of the settings that must be right before launch and exits non-zero on any FAIL. Run it in the deployed environment.

**Abuse and cost limits**

| Concern | What is done |
|---|---|
| Rate limiting | Per user: 120 requests/minute for the API. Sign-in: 5/minute per address and email. Stricter limits for password reset, submissions, check-in and SSO. Expensive endpoints (gradebook, reports, audit log, imports, course copy, similarity): 10/minute. Public endpoints: 60/minute per address. Limited requests get `429` with `Retry-After`. |
| Spending caps | The system has no paid third-party API, but the resources that cost money are capped: 25 MB per file, 200 MB of uploads per user per day (`LMS_DAILY_UPLOAD_MB`), 512 KB per JSON body (`LMS_JSON_BODY_KB`), 55 MB per request at nginx, and at most 200 rows per page. Also set billing alerts with your cloud storage and email provider. |
| Upload safety | Extension allow-list checked against the file's real content, size limits, optional ClamAV scan, private bucket. |
| Request size | Oversized JSON gets `413`; nginx and PHP limits are aligned (`infra/`). |
| Large results | Lists, and the gradebook, progress and roster views, are paginated (`?page=`, `?per_page=`, capped at 200). CSV export streams in chunks. |
| Repeated requests | Send an `Idempotency-Key` header (8-100 characters) on any POST/PUT/PATCH/DELETE and a retry returns the first result instead of repeating the action (`Idempotent-Replayed: true`). The same key with a different request is refused (`422`), and a duplicate that is still running gets `409`. Use it for enrolments, submissions and anything a user might double-click. GET answers carry an `ETag`, so `If-None-Match` returns a tiny `304`. The reports overview is cached for 60 seconds, and nginx gzip-compresses responses. |

**Accounts and sessions**

- Passwords are bcrypt-hashed (`BCRYPT_ROUNDS`), at least 12 characters, and never logged. Changing a password revokes every other token; resetting one revokes all of them. Reset links expire after 1 hour and work once. Invitation links use their own table, so a long-lived invitation can never act as a reset link. Asking for a reset gives the same answer whether or not the address exists.
- Sign-in is timing-equalised and gives one message for "no such user" and "wrong password". After 5 wrong passwords an email is locked for 15 minutes, even against the right password (`LMS_LOCKOUT_ATTEMPTS`, `LMS_LOCKOUT_MINUTES`). Emails are unique regardless of case.
- Authentication uses bearer tokens that expire (`SANCTUM_TOKEN_EXPIRATION`), not cookies. There is no cookie for another site to abuse and so no CSRF token to forget; the cookie session stack is not enabled. If you ever add cookie sessions, cookies default to `Secure` in production (`SESSION_SECURE_COOKIE`).
- No default admin route or account exists: the only admin is the one you seed from `LMS_ADMIN_EMAIL` and `LMS_ADMIN_PASSWORD`. `/` returns a small JSON status, not a framework welcome page.

**Transport and browser rules**

- CORS accepts only the origins in `LMS_CORS_ORIGINS` (default: your frontend's origin). `lms:preflight` fails if it contains `*`.
- Every response carries `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy` and a `default-src 'none'` content security policy. `Strict-Transport-Security` is added on https requests (or always when `LMS_FORCE_HSTS=true`). Server and PHP versions are hidden and directory listing is off.
- Behind a load balancer or reverse proxy, set `TRUSTED_PROXIES` to its address. Without it every visitor looks like the proxy, so limits and lockouts would apply to everyone at once. Terminate TLS there and redirect http to https.

**Input handling**

- Every request is validated. Database access uses the query builder with bound parameters (no SQL built from strings). Text fields (names, titles, sections, locations, labels) have HTML stripped, and responses are JSON with `nosniff`, so stored text cannot run as a page. The API sends no user text to any AI model, so prompt injection and AI-usage caps do not apply.
- Payments, subscriptions, prices and webhooks do not exist in this system, so "prevent duplicate payments or subscriptions", "verify payment webhooks" and "server-set prices" are not applicable. If you add billing, use the same `Idempotency-Key` mechanism and verify webhook signatures.

**Reliability and monitoring**

- Errors always come back as JSON with a `request_id` (also sent as `X-Request-Id` and written to every log line). When the database, cache or storage is unreachable the API answers `503` with `Retry-After` instead of a stack trace, and with `APP_DEBUG=false` no internals are ever shown. Database, Redis and S3 calls have connection timeouts, nginx and PHP have request timeouts, and queued jobs retry three times with backoff.
- `GET /api/health` (no login, rate limited) reports database, cache, storage, scheduler and queue status: `200` with `ok` or `degraded`, or `503` when something essential is down. Point an uptime monitor (UptimeRobot, Better Stack and similar) at it and alert on anything but `ok`. Set `SENTRY_LARAVEL_DSN` to send exceptions to Sentry.
- Logs go to files and stderr (`podman logs`). Security events are written as JSON to a separate 90-day log (`storage/logs/security-*.log` and stderr): `login.success`, `login.failed`, `login.locked`, `login.blocked_while_locked`, `password.changed`, `password.reset`, `password.reset_requested`, `sso.failed`, `account.activated`, `account.deactivated`, `access.denied` and `throttle.hit`. Emails appear only as a short hash, and passwords and tokens never appear. Administrative actions also go to the audit log.
- Database indexes cover every foreign key and the common filters (see the `add_performance_indexes` migration). Eager loading is enforced: a query that would load related rows one at a time throws in development and is logged in production.
- Loading, empty and error screens are the frontend's job. The API helps by returning an empty list (never an error) when there is nothing to show, one consistent error shape, and `Retry-After` on every limit.

**Database permissions.** The running app uses `lms_app`, created by `infra/postgres/app-role.sql`. It can read and write rows only: it cannot create, alter or drop tables, truncate, create roles, or connect to other databases, and its queries are cancelled after 30 seconds. If the app were ever tricked into running attacker-chosen SQL, that is all an attacker would get. For an existing database, run `psql -U lms -d lms -v app_password='...' -f infra/postgres/app-role.sql` once, then switch `DB_USERNAME` and `DB_PASSWORD` in `backend/.env` and add the `DB_MIGRATE_*` values. Run migrations with `php artisan migrate --database=pgsql_migrate --force`.

**Dependencies.** CI runs `composer audit` on every push; run it before each release too.

**Still yours to do before a launch.** Serve over https with a real certificate: put a TLS-terminating reverse proxy (Caddy, nginx, a cloud load balancer) in front of both the frontend and the API, and set `LMS_API_URL` (frontend container), `LMS_FRONTEND_URL` and `APP_URL` (`backend/.env`) to the https addresses. The frontend's Content-Security-Policy is generated from `LMS_API_URL`, so nothing else needs editing. Set `APP_ENV=production`, `APP_DEBUG=false`, real secrets, `TRUSTED_PROXIES`, `LMS_CORS_ORIGINS`, real mail, a Sentry DSN and an uptime monitor. Turn on virus scanning. Publish a privacy policy and terms page and put their addresses in `LMS_PRIVACY_URL` and `LMS_TERMS_URL` (a frontend can read them from `GET /api/auth/config`). Set up the nightly backup (Administration > Backups), copy the backups off the machine, and test a restore on a copy. Then run `php artisan lms:preflight --production` until it shows no FAIL.

## Checks

Run `podman compose exec app php artisan test` for PHPUnit tests (they use in-memory SQLite). CI runs migrations, tests, and Pint on PHP 8.3 and PostgreSQL 16. Some behaviour differs between SQLite and PostgreSQL, so when changing queries also try them against the running stack. The frontend has its own tests (`cd frontend && npm test`) and the whole stack has real-browser tests in `e2e/`; `php artisan lms:e2e seed|clean` creates and removes the accounts they use, and refuses to run in production.
