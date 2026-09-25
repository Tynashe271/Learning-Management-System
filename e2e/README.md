# Browser tests

These drive the real frontend in a real Chrome against the running stack (frontend, API, database, mail catcher and file storage), the way a person would: they type into the sign-in form, click, download files, and use a phone-sized screen. They complement the unit tests in `backend/` and `frontend/`, which use a fake API or an in-memory database.

| Suite | What it checks |
|---|---|
| `mobile` | The app on a 390 x 844 touch screen: no page scrolls sideways, the menu, dialogs and message thread work, taps are large enough, no browser errors. Saves screenshots to `artifacts/`. |
| `admin` | Everything an administrator does, through the real screens: terms with academic year, registration window and add/drop deadline; departments; courses with department, level and credits; offerings with places and self-registration; archiving a term; accounts and one page per person (rename, change role after a confirmation, lock and unlock after wrong passwords, sign out everywhere, download all their data, deactivate, delete); CSV import with a dry run and invitation emails; assign teachers and enrol students; a student registering for and dropping a course, and a full course refusing; copy a course; **settings** (institution name in the header, limits, password rules explained on forms, maintenance mode turning a student away with the message); **roles and permissions** (a permission takes effect at once and is put back); notices to everyone shown to and dismissed by a student; integrations tested for real (storage, and an email that arrives in Mailpit); system and housekeeping; a backup made, checked, downloaded and deleted; security events; usage and enrolment reports with spreadsheet downloads; the audit log (search, filter by person and date, download) and system status; sign-out. It takes about ten minutes, including a wait for the sign-in rate limit to pass. |
| `desktop` | **Downloads:** a course file, a student's own submission, a message attachment, a submission from the grading dialog, and the gradebook CSV all arrive with the right content. **Passwords:** changing a password (wrong current password refused, old one stops working), and the emailed reset link (arrives, opens the frontend with the address filled in, works once). **Roles:** what a teaching assistant, lecturer, department administrator and registrar can and cannot do. |

## Run

Start the stack from the project folder (`podman compose up -d`), then:

```
cd e2e
npm install
npm test                # all three suites, about four minutes
npm run test:mobile     # or test:desktop / test:admin, for one suite
node run.mjs --keep     # leave the test data in place afterwards, to look around by hand
npm run clean           # remove test data left behind (for example after --keep)
```

It needs Google Chrome installed (`E2E_BROWSER=msedge` uses Edge; `E2E_CHROME_PATH` points at any Chromium-based browser).

## What it does to your data

Each run starts by removing any leftovers, creates seven accounts (`e2e-*@example.test`, one per role, with a fresh random password used only by this run) and a course `E2E101` with content, runs the suites, and deletes all of it again, even if a suite crashes. `php artisan lms:e2e clean` removes only those accounts, that course, and the work they produced, so it is safe on a stack that also holds real data. The command refuses to run when `APP_ENV=production`, and the runner refuses to target anything but localhost unless `E2E_ALLOW_REMOTE=1`.

The admin suite also touches things that are not test accounts, and puts each back: institution and security settings (reset to their defaults), maintenance mode (always switched off again, even if a check fails), role permissions (reset), a department, a term and a notice named `E2E…` (removed by `clean`), and the backups it makes (deleted). It clears the cache between busy screens (`php artisan cache:clear`) because the reports and audit log are limited to ten requests a minute per person, and it waits a minute once so the sign-in rate limit does not hide the account lockout it is testing. Do not run it against a stack whose people are working: turning maintenance mode on blocks them for a few seconds.

Exit code: `0` all passed, `1` a check failed, `2` the tests could not start (stack not running, unknown suite, remote address).

## Settings

| Variable | Default |
|---|---|
| `E2E_BASE_URL` | `http://localhost:5173` (the frontend) |
| `E2E_API_URL` | `http://localhost:8080/api` |
| `E2E_MAIL_URL` | `http://localhost:8025` (Mailpit, to read the reset email) |
| `E2E_APP_CONTAINER` | `university-lms_app_1` (where `php artisan` runs) |
| `E2E_CONTAINER_CLI` | `podman` |
| `E2E_ARTISAN` | Overrides the whole command, for example `php artisan` with the working directory set to `backend/` |

## Adding a check

Suites are in `tests/` and export `run({ browser, password, ids, artifacts })`. `ids` holds the ids of the seeded course, assignment, quiz, class and appeal (they change every run, so never hard-code them). Add data in `setup.mjs`, sign in with `uiLogin(page, email, password)`, and report with `check(name, ok, detail)`. When you change something, break it on purpose once and confirm a check fails.
