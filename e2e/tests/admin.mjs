import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { API, BASE, MAIL, apiLogin, call, check, dateIn, resetLimits, sleep, startSuite, uiLogin } from '../lib.mjs'

/** Everything an administrator does: the catalogue, accounts, imports, enrolment, copying, reports, the audit log and status. */
let mainPage = null

// The settings this suite changes. Whatever happens (a crash, a timeout, being stopped half way), they go back to the defaults, so a
// failed run cannot leave the institution with a changed password rule or in maintenance mode.
const TOUCHED_SETTINGS = ['institution.name', 'security.password_min_length', 'maintenance.enabled', 'maintenance.message']

async function restoreSettings(password) {
  try {
    const token = await apiLogin('e2e-admin@example.test', password)
    await call(token, 'PUT', '/settings', { reset: TOUCHED_SETTINGS })
  } catch {
    /* nothing was changed, or the administrator could not sign in; the next run tries again */
  }
}

/** Runs the suite; if it crashes, keeps a picture of the administrator's screen to show where. */
export async function run(context) {
  await restoreSettings(context.password) // leftovers of an earlier run that was stopped
  try {
    await runSuite(context)
  } catch (error) {
    if (mainPage && context.artifacts) await mainPage.screenshot({ path: join(context.artifacts, 'admin-crash.png'), fullPage: true }).catch(() => undefined)
    throw error
  } finally {
    await restoreSettings(context.password)
  }
}

async function runSuite({ browser, password, ids, artifacts }) {
  startSuite('admin')
  const { offering: seeded } = ids
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 } })
  const page = await context.newPage()
  mainPage = page
  const errors = []
  page.on('pageerror', (e) => errors.push(String(e)))
  page.on('console', (m) => m.type() === 'error' && !/401|422|Failed to load resource/.test(m.text()) && errors.push(m.text()))
  const modal = () => page.locator('.modal').last()
  // A toast that never comes usually means something went wrong; keep a picture of the screen to see what.
  const toast = (text, timeout = 45000) =>
    page
      .locator('.toast', { hasText: text })
      .first()
      .waitFor({ timeout })
      .catch(async (error) => {
        if (artifacts) await page.screenshot({ path: join(artifacts, 'admin-missing-toast.png') }).catch(() => undefined)
        throw error
      })
  const row = (text) => page.locator('tbody tr', { hasText: text })

  await uiLogin(page, 'e2e-admin@example.test', password)

  // ---------------------------------------------------------------- dashboard and menu
  await page.getByText('The university at a glance').waitFor()
  const menu = (await page.locator('.sidebar .nav-link').allTextContents()).map((s) => s.replace(/\d+$/, '').trim())
  check('admin: every administration page is in the menu', ['Terms and courses', 'People', 'Import accounts', 'Reports', 'Settings', 'Notices to everyone', 'Integrations', 'Roles and permissions', 'Security', 'Audit log', 'System status', 'System and jobs', 'Backups'].every((n) => menu.includes(n)), menu.join(','))
  const headings = await page.locator('.nav-heading').allTextContents()
  check('admin: the menu is grouped under headings', ['Academics', 'Institution', 'Security and records', 'System'].every((h) => headings.includes(h)), headings.join(','))
  check('admin: no student-only "My grade appeals" link', !menu.includes('My grade appeals'))
  check('admin: the dashboard shows real counts', /Accounts\s*\d+/.test((await page.locator('.stats').first().innerText()).replace(/\n/g, ' ')) || (await page.locator('.stat-value').first().innerText()).match(/\d+/) !== null)

  // ---------------------------------------------------------------- terms, courses, offerings
  await page.goto(`${BASE}/admin/catalogue`)
  await page.getByRole('button', { name: 'New term' }).click()
  await modal().getByLabel('Name').fill('E2E Spring')
  await modal().getByLabel('Academic year').fill('2027')
  await modal().getByLabel('Starts').fill('2027-02-01')
  await modal().getByLabel('Ends').fill('2027-06-30')
  await modal().getByLabel('Opens').fill(dateIn(-1))
  await modal().getByLabel('Closes').fill('2027-01-15')
  await modal().getByLabel('Last day to add or drop').fill('2027-01-20')
  await modal().getByRole('button', { name: 'Save' }).click()
  await toast('Term created')
  check('admin: a term can be created', await row('E2E Spring').isVisible())
  check('admin: a term shows its academic year and that registration is open', /Academic year 2027/.test(await row('E2E Spring').innerText()) && /Open now/.test(await row('E2E Spring').innerText()), await row('E2E Spring').innerText())

  // a registration window that closes before it opens is refused, beside the field
  await row('E2E Spring').getByRole('button', { name: 'Edit' }).click()
  await modal().getByLabel('Closes').fill(dateIn(-5))
  await modal().getByRole('button', { name: 'Save' }).click()
  await modal().getByText('Registration must close on or after the day it opens.').waitFor()
  check('admin: a registration window that closes before it opens is refused', true)
  await modal().getByRole('button', { name: 'Cancel' }).click()

  // departments
  await page.getByRole('button', { name: 'New department' }).click()
  await modal().getByLabel('Code').fill('E2EDEP')
  await modal().getByLabel('Name').fill('E2E Faculty')
  await modal().getByRole('button', { name: 'Save' }).click()
  await toast('Department created')
  check('admin: a department can be created', await row('E2EDEP').isVisible())
  await page.getByRole('button', { name: 'New department' }).click()
  await modal().getByLabel('Code').fill('e2edep')
  await modal().getByLabel('Name').fill('Duplicate')
  await modal().getByRole('button', { name: 'Save' }).click()
  await modal().locator('.field-error').first().waitFor()
  check('admin: a duplicate department code is refused', true)
  await modal().getByRole('button', { name: 'Cancel' }).click()

  await row('E2E Spring').getByRole('button', { name: 'Edit' }).click()
  await modal().getByLabel('Ends').fill('2027-01-01')
  await modal().getByRole('button', { name: 'Save' }).click()
  await modal().getByText('The term must end after it starts.').waitFor()
  check('admin: a term that ends before it starts is refused, beside the field', true)
  await modal().getByRole('button', { name: 'Cancel' }).click()

  await page.getByRole('button', { name: 'New course' }).click()
  await modal().getByLabel('Course code').fill('E2E202')
  await modal().getByLabel('Title').fill('Admin Course')
  await modal().getByLabel('Department').selectOption({ label: 'E2EDEP E2E Faculty' })
  await modal().getByLabel('Level').selectOption('postgraduate')
  await modal().getByLabel('Credits').fill('16')
  await modal().getByRole('button', { name: 'Save' }).click()
  await toast('Course created')
  check('admin: a course can be created', await row('E2E202').isVisible())
  const courseRow = await row('E2E202').innerText()
  check('admin: a course shows its department, level and credits', /E2EDEP/.test(courseRow) && /Postgraduate/.test(courseRow) && /16/.test(courseRow), courseRow)
  await page.locator('.filters').getByLabel('Department').last().selectOption({ label: 'E2EDEP E2E Faculty' })
  await page.waitForFunction(() => [...document.querySelectorAll('.card')].find((c) => c.querySelector('h2')?.textContent === 'Courses')?.querySelectorAll('tbody tr').length === 1)
  check('admin: courses can be filtered by department', true)
  await page.locator('.filters').getByLabel('Department').last().selectOption('')
  await page.getByRole('button', { name: 'New course' }).click()
  await modal().getByLabel('Course code').fill('E2E202')
  await modal().getByLabel('Title').fill('Duplicate')
  await modal().getByRole('button', { name: 'Save' }).click()
  await modal().locator('.field-error').first().waitFor()
  check('admin: a duplicate course code is refused', true)
  await modal().getByRole('button', { name: 'Cancel' }).click()
  await row('E2E202').getByRole('button', { name: 'Edit' }).click()
  await modal().getByLabel('Title').fill('Admin Course (renamed)')
  await modal().getByRole('button', { name: 'Save' }).click()
  await toast('Course saved')
  check('admin: a course can be renamed', await row('renamed').isVisible())

  const offeringCard = page.locator('.card', { hasText: 'Offer a course in a term' })
  await offeringCard.getByLabel('Find the course').fill('E2E202')
  await offeringCard.getByLabel('Course', { exact: true }).selectOption({ label: 'E2E202 Admin Course (renamed)' })
  await offeringCard.getByLabel('Term', { exact: true }).selectOption({ label: 'E2E Spring (2027)' })
  await offeringCard.getByLabel('Section').fill('B')
  await offeringCard.getByLabel('Places').fill('3')
  await offeringCard.getByLabel('Students may register for it themselves').check()
  await offeringCard.getByRole('button', { name: 'Create offering' }).click()
  await offeringCard.getByText('Offering created').waitFor()
  await offeringCard.getByRole('link', { name: 'Open it' }).click()
  await page.getByText('This course is not published').waitFor()
  check('admin: a new offering starts as a draft students cannot see', true)
  await page.getByRole('button', { name: 'Publish', exact: true }).click()
  await page.getByRole('button', { name: 'Unpublish' }).first().waitFor()
  check('admin: an offering can be published', true)
  await page.getByRole('button', { name: 'Unpublish' }).first().click()
  await page.getByText('This course is not published').waitFor()
  check('admin: and unpublished again', true)
  const newOffering = /courses\/(\d+)/.exec(page.url())[1]

  // ---------------------------------------------------------------- accounts
  await page.goto(`${BASE}/admin/users`)
  await page.getByRole('button', { name: 'New account' }).click()
  await modal().getByLabel('Full name').fill('E2E Zed')
  await modal().getByLabel('Email').fill('e2e-zed@example.test')
  await modal().getByLabel('Starting password').fill('short')
  check('admin: a too-short starting password cannot be submitted', await modal().getByRole('button', { name: 'Create account' }).isDisabled())
  await modal().getByLabel('Starting password').fill('a-long-starting-pass-9')
  await modal().getByLabel('Role').selectOption('lecturer')
  await modal().getByRole('button', { name: 'Create account' }).click()
  await toast('Account created')
  await page.getByLabel('Search').fill('zed')
  await row('E2E Zed').waitFor()
  await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1)
  check('admin: a new account appears in the list, and search finds it', (await page.locator('tbody tr').count()) === 1)
  await page.getByRole('button', { name: 'New account' }).click()
  await modal().getByLabel('Full name').fill('Zed Again')
  await modal().getByLabel('Email').fill('E2E-ZED@example.test')
  await modal().getByLabel('Starting password').fill('a-long-starting-pass-9')
  await modal().getByRole('button', { name: 'Create account' }).click()
  await modal().getByText('The email has already been taken.').waitFor()
  check('admin: an email that differs only by case is a duplicate', true)
  await modal().getByRole('button', { name: 'Cancel' }).click()
  const token = await apiLogin('e2e-zed@example.test', 'a-long-starting-pass-9').catch(() => null)
  check('admin: the new account can sign in', !!token)

  await page.getByLabel('Search').fill('')
  await page.locator('.filters').getByLabel('Role').selectOption('lecturer')
  await row('E2E Zed').waitFor()
  check('admin: the role filter narrows the list', (await row('Student').count()) === 0 || (await row('Ada Student').count()) === 0)
  await page.locator('.filters').getByLabel('Role').selectOption('')
  await page.getByLabel('Search').fill('zed')
  await row('E2E Zed').getByRole('link', { name: 'Manage' }).click()

  // ---------------------------------------------------------------- one person's account page
  await page.getByRole('heading', { name: 'E2E Zed' }).waitFor()
  check('admin: the account page says the person can sign in', await page.getByText('Can sign in now').isVisible())
  const account = page.locator('.card', { hasText: 'Account details' })
  await account.getByLabel('Name').fill('E2E Zed Renamed')
  await account.getByRole('button', { name: 'Save changes' }).click()
  await toast('Account updated')
  await page.getByRole('heading', { name: 'E2E Zed Renamed' }).waitFor()
  check('admin: an account can be renamed from its page', true)

  // wrong passwords lock the account; the page says so and unlocks it
  resetLimits() // earlier sign-ins of this account count toward the five-a-minute limit, which would hide the lockout
  for (let i = 0; i < 5; i++) await apiLogin('e2e-zed@example.test', 'not-the-right-password-1').catch(() => null)
  await page.reload()
  await page.getByText(/^Locked for/).waitFor()
  check('admin: the account page shows that repeated wrong passwords locked the account', (await page.getByText('Cannot sign in now').count()) === 1)
  check('admin: a locked account cannot sign in even with the right password', (await apiLogin('e2e-zed@example.test', 'a-long-starting-pass-9').catch(() => null)) === null)
  await page.getByRole('button', { name: 'Unlock the account' }).click()
  await toast('The account is unlocked')
  await sleep(61_000) // sign-in attempts for one address are also limited to five a minute; wait that out so only the lockout is being tested
  check('admin: unlocking lets them sign in again', (await apiLogin('e2e-zed@example.test', 'a-long-starting-pass-9').catch(() => null)) !== null)
  await page.reload()
  await page.locator('.card', { hasText: 'Signed-in devices' }).locator('tbody tr').first().waitFor()
  await page.getByRole('button', { name: 'Sign out everywhere' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Sign out everywhere' }).click()
  await toast('Signed out of')
  check('admin: an administrator can sign a person out of every device', true)
  await page.getByRole('heading', { name: 'Recent security events' }).waitFor()
  check('admin: the security events for the account are listed', /Signed in|Wrong password/.test(await page.locator('.card', { hasText: 'Recent security events' }).innerText()))

  // the role is changed after a confirmation, and the change is recorded
  await page.getByLabel('Role').selectOption('teaching-assistant')
  await page.getByRole('button', { name: 'Save changes' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Change role' }).click()
  await toast('Account updated')
  check('admin: a role can be changed after a confirmation', (await page.getByLabel('Role').inputValue()) === 'teaching-assistant')
  await page.getByLabel('Role').selectOption('lecturer')
  await page.getByRole('button', { name: 'Save changes' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Change role' }).click()
  await toast('Account updated')

  // the person's data can be handed over
  const [exported] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download all their data' }).click()])
  check('admin: everything held about a person can be downloaded', /account-\d+-data\.json/.test(exported.suggestedFilename()), exported.suggestedFilename())

  await page.getByLabel('Account is active').uncheck()
  await page.getByRole('button', { name: 'Save changes' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Deactivate', exact: true }).click()
  await toast('Account updated')
  await page.getByText('Cannot sign in now').waitFor()
  check('admin: an account can be deactivated (after a confirmation)', true)
  check('admin: a deactivated account can no longer sign in', (await apiLogin('e2e-zed@example.test', 'a-long-starting-pass-9').catch(() => null)) === null)
  await page.getByLabel('Account is active').check()
  await page.getByRole('button', { name: 'Save changes' }).click()
  await toast('Account updated')
  resetLimits() // the same address has signed in several times this minute
  check('admin: and reactivated', (await apiLogin('e2e-zed@example.test', 'a-long-starting-pass-9').catch(() => null)) !== null)

  // a person who has produced nothing can be deleted; the list forgets them
  await page.goto(`${BASE}/admin/users`)
  await page.getByRole('button', { name: 'New account' }).click()
  await modal().getByLabel('Full name').fill('E2E Gone')
  await modal().getByLabel('Email').fill('e2e-gone@example.test')
  await modal().getByLabel('Starting password').fill('a-long-starting-pass-9')
  await modal().getByRole('button', { name: 'Create account' }).click()
  await toast('Account created')
  await page.getByLabel('Search').fill('E2E Gone')
  await row('E2E Gone').getByRole('link', { name: 'Manage' }).click()
  await page.getByRole('button', { name: 'Delete the account' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Delete the account' }).click()
  await toast('Account deleted')
  await page.waitForURL(/\/admin\/users$/)
  await page.getByLabel('Search').fill('E2E Gone')
  await page.getByText('No one matches').waitFor()
  check('admin: an account with no records can be deleted, and disappears from the list', true)
  await page.getByLabel('Search').fill('zed')

  // ---------------------------------------------------------------- bulk import
  await page.goto(`${BASE}/admin/import`)
  const csv = 'name,email,role\nImp One,e2e-imp1@example.test,student\nImp Two,e2e-imp2@example.test,student\nBad Row,not-an-email,student\nDupe,e2e-zed@example.test,student\n'
  await page.getByLabel('CSV file').setInputFiles({ name: 'people.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) })
  await page.getByRole('button', { name: /Check the file/ }).click()
  await page.getByText('Nothing has been created yet.').waitFor()
  check('admin: the import dry run reports 2 would be created and 2 skipped', (await page.locator('.alert').first().innerText()).includes('2 accounts would be created, 2 rows would be skipped'))
  const problems = await page.locator('tbody tr').allInnerTexts()
  check('admin: skipped rows are explained by line', problems.some((t) => t.includes('not-an-email')) && problems.some((t) => /already exists/.test(t)), problems.join(' | '))
  check('admin: the dry run created nothing', (await apiLogin('e2e-imp1@example.test', 'x').catch(() => 'none')) === 'none')
  await fetch(`${MAIL}/api/v1/messages`, { method: 'DELETE' })
  await page.getByRole('button', { name: 'Create the accounts' }).click()
  await page.getByText(/2 accounts created and invited by email/).waitFor()
  check('admin: the real import creates the valid rows', true)
  let invitation = null
  for (let i = 0; i < 30 && !invitation; i++) {
    await sleep(1000)
    const list = await (await fetch(`${MAIL}/api/v1/messages`)).json()
    invitation = list.messages.find((m) => m.To[0].Address === 'e2e-imp1@example.test') ?? null
  }
  check('admin: each imported person is emailed a link to choose a password', !!invitation)

  // ---------------------------------------------------------------- enrolment and teachers
  await page.goto(`${BASE}/courses/${newOffering}/people`)
  await page.getByRole('button', { name: 'Assign a teacher' }).click()
  await modal().locator('.picker-list li', { hasText: 'E2E Zed Renamed' }).getByRole('button', { name: 'Assign' }).click()
  await toast('Teacher assigned')
  await modal().getByRole('button', { name: 'Close' }).click()
  await page.getByText('E2E Zed Renamed').first().waitFor()
  check('admin: a lecturer can be assigned to an offering', true)
  await page.getByRole('button', { name: 'Enrol a student' }).click()
  await modal().getByRole('searchbox').fill('Imp One')
  await modal().locator('.picker-list li', { hasText: 'Imp One' }).getByRole('button', { name: 'Enrol' }).click()
  await toast('Student enrolled')
  await modal().getByRole('button', { name: 'Close' }).click()
  await row('Imp One').waitFor()
  check('admin: a student can be enrolled through the picker', true)
  await row('Imp One').getByRole('button', { name: 'Withdraw' }).click()
  await row('Imp One').getByText('Withdrawn').waitFor()
  await row('Imp One').getByRole('button', { name: 'Re-enrol' }).click()
  await row('Imp One').getByText('Active').waitFor()
  check('admin: an enrolment can be withdrawn and restored', true)
  await page.getByRole('button', { name: 'Import a list' }).click()
  await modal().getByLabel('Email addresses').fill('e2e-imp2@example.test, nobody@example.test, bad-address')
  await modal().getByRole('button', { name: /^Enrol 2/ }).click()
  await modal().getByText('1 student enrolled.').waitFor()
  const report = await modal().innerText()
  check('admin: a list import enrols matches and reports the unknown and invalid entries', report.includes('nobody@example.test') && report.includes('bad-address'), report)
  await modal().getByRole('button', { name: 'Done' }).click()
  await row('Imp Two').waitFor()
  check('admin: the roster now shows both students', (await page.locator('tbody tr').count()) === 2)

  // ---------------------------------------------------------------- copy a course
  await page.goto(`${BASE}/courses/${seeded}/settings`)
  const springValue = await page.getByLabel('Copy into term').evaluate((el) => [...el.options].find((o) => o.textContent.includes('E2E Spring')).value)
  await page.getByLabel('Copy into term').selectOption(springValue)
  await page.getByLabel('Section for the new offering').fill('Copy')
  await page.getByRole('button', { name: 'Copy this course' }).click()
  await page.getByText(/^Copied\./).waitFor({ timeout: 60000 })
  const summary = await page.locator('.alert-success').innerText()
  check('admin: copying reports what came across', /1 module, 3 items \(1 with files\), 1 assignment \(2 rubric criteria\) and 1 quiz \(4 questions\)/.test(summary), summary)
  await page.getByRole('link', { name: 'Open the new offering' }).click()
  await page.getByText('This course is not published').waitFor()
  await page.getByText('Week 1: Getting started').waitFor()
  check('admin: the copy is an unpublished offering with the content in place', true)

  // ---------------------------------------------------------------- admin on someone else's course
  await page.goto(`${BASE}/assignments/${ids.assignment}`)
  await page.getByRole('heading', { name: 'Submissions' }).waitFor()
  await page.locator('tbody tr', { hasText: 'Ada Student' }).first().waitFor({ timeout: 8000 }).catch(() => undefined)
  check('admin: a super-admin can grade any course', (await page.getByRole('button', { name: 'Regrade' }).count()) > 0)
  check('admin: but cannot submit work as a student', (await page.getByRole('button', { name: 'Submit' }).count()) === 0)

  // ---------------------------------------------------------------- students register for courses themselves
  await page.goto(`${BASE}/courses/${newOffering}`)
  await page.getByRole('button', { name: 'Publish', exact: true }).click()
  await page.getByRole('button', { name: 'Unpublish' }).first().waitFor()
  await page.goto(`${BASE}/courses/${newOffering}/settings`)
  check('admin: an offering shows its places and self-registration setting', (await page.getByLabel('Places').inputValue()) === '3' && (await page.getByLabel('Students may register for it themselves').isChecked()))

  const studentContext = await browser.newContext({ viewport: { width: 1280, height: 800 } })
  const student = await studentContext.newPage()
  await uiLogin(student, 'e2e-stu1@example.test', password)
  const studentMenu = (await student.locator('.sidebar .nav-link').allTextContents()).map((s) => s.replace(/\d+$/, '').trim())
  check('registration: a student has "Register for courses" in the menu', studentMenu.includes('Register for courses'), studentMenu.join(','))
  await student.goto(`${BASE}/register`)
  const open = student.locator('.reg-item', { hasText: 'E2E202' })
  await open.waitFor()
  check('registration: the course shows registration is open and how many places are left', /Open/.test(await open.innerText()) && /1 place left/.test(await open.innerText()), await open.innerText())
  await open.getByRole('button', { name: 'Register' }).click()
  await student.locator('.toast', { hasText: 'You are registered' }).first().waitFor({ timeout: 8000 })
  await open.getByText('Registered').waitFor()
  check('registration: a student can register for a course that allows it', true)
  await open.getByRole('button', { name: 'Drop' }).click()
  await student.getByRole('dialog').getByRole('button', { name: 'Drop the course' }).click()
  await student.locator('.toast', { hasText: 'You have dropped the course' }).first().waitFor({ timeout: 8000 })
  await open.getByRole('button', { name: 'Register' }).waitFor()
  check('registration: and can drop it again before the deadline', true)
  await open.getByRole('button', { name: 'Register' }).click()
  await open.getByText('Registered').waitFor()

  const benContext = await browser.newContext({ viewport: { width: 1280, height: 800 } })
  const ben = await benContext.newPage()
  await uiLogin(ben, 'e2e-stu2@example.test', password)
  await ben.goto(`${BASE}/register`)
  const full = ben.locator('.reg-item', { hasText: 'E2E202' })
  await full.waitFor()
  check('registration: a full course says so and cannot be registered for', /Full/.test(await full.innerText()) && (await full.getByRole('button', { name: 'Register' }).isDisabled()), await full.innerText())
  await benContext.close()
  await studentContext.close()

  // ---------------------------------------------------------------- archiving a term
  await page.goto(`${BASE}/admin/catalogue`)
  await row('E2E Spring').getByRole('button', { name: 'Archive' }).click()
  await page.getByRole('dialog').getByRole('button', { name: /Archive the term/ }).click()
  await toast('archived')
  await page.goto(`${BASE}/courses`)
  await page.getByLabel('Search').fill('E2E202')
  await page.getByText('No courses to show').waitFor()
  check('admin: archiving a term takes its courses out of the current list', true)
  await page.getByLabel('Show').selectOption('only')
  await row('E2E202').waitFor()
  check('admin: they can still be found among the archived courses', /Archived/.test(await row('E2E202').innerText()))
  await page.goto(`${BASE}/admin/catalogue`)
  await row('E2E Spring').getByRole('button', { name: 'Restore' }).click()
  await toast('restored')
  check('admin: and restored', true)

  // ---------------------------------------------------------------- settings
  await page.goto(`${BASE}/admin/settings`)
  const nameSetting = page.locator('.setting', { has: page.getByLabel('Institution name') })
  await page.getByLabel('Institution name').fill('E2E University')
  await page.getByRole('button', { name: 'Save settings' }).click()
  await page.getByText('No unsaved changes.').waitFor({ timeout: 60000 }) // not the toast: an older one may still be showing
  await page.locator('.brand span', { hasText: 'E2E University' }).waitFor()
  check('admin: the institution name changes in the header straight away', true)
  await page.reload()
  check('admin: and is still there after a reload', (await page.getByLabel('Institution name').inputValue()) === 'E2E University')
  await nameSetting.getByRole('button', { name: 'Use the default' }).click()
  await toast('Settings saved')
  await page.waitForFunction(() => !document.querySelector('.brand span')?.textContent?.includes('E2E University'))
  check('admin: a setting can go back to its default', true)

  await page.getByLabel('Minimum password length').fill('3')
  await page.getByRole('button', { name: 'Save settings' }).click()
  await page.locator('.field-error').first().waitFor()
  check('admin: a setting outside its limits is refused, beside the setting', true)
  await page.getByRole('button', { name: 'Discard changes' }).click()

  await page.getByLabel('Minimum password length').fill('14')
  await page.getByRole('button', { name: 'Save settings' }).click()
  await page.getByText('No unsaved changes.').waitFor({ timeout: 60000 }) // not the toast: an older one may still be showing
  await page.goto(`${BASE}/admin/users`)
  await page.getByRole('button', { name: 'New account' }).click()
  check('admin: the password rule is explained where a password is chosen', /At least 14 characters/.test(await modal().innerText()), await modal().innerText())
  await modal().getByRole('button', { name: 'Cancel' }).click()
  await page.goto(`${BASE}/admin/settings`)
  const resetLength = await page.locator('.setting', { has: page.getByLabel('Minimum password length') }).getByRole('button', { name: 'Use the default' })
  await resetLength.click()
  await resetLength.waitFor({ state: 'detached', timeout: 60000 })

  // maintenance mode: everyone but a super administrator is turned away, with the institution's message
  const ada = await apiLogin('e2e-stu1@example.test', password)
  await page.getByLabel('Message shown during maintenance').fill('E2E: back at noon.')
  await page.getByLabel('Maintenance mode').check()
  await page.getByRole('button', { name: 'Save settings' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Turn it on' }).click()
  await page.getByText('No unsaved changes.').waitFor({ timeout: 60000 }) // not the toast: an older one may still be showing
  try {
    const blocked = await fetch(`${API}/me`, { headers: { Accept: 'application/json', Authorization: `Bearer ${ada}` } })
    const body = await blocked.json().catch(() => ({}))
    check('admin: in maintenance mode a student is turned away with the message', blocked.status === 503 && body.message === 'E2E: back at noon.', `${blocked.status} ${JSON.stringify(body)}`)
    const allowed = await fetch(`${API}/me`, { headers: { Accept: 'application/json', Authorization: `Bearer ${await apiLogin('e2e-admin@example.test', password)}` } })
    check('admin: but a super administrator still gets in', allowed.status === 200)
    const visitor = await browser.newContext()
    const login = await visitor.newPage()
    await login.goto(`${BASE}/login`)
    await login.getByText('E2E: back at noon.').waitFor({ timeout: 8000 })
    check('admin: the sign-in page shows the maintenance message', true)
    await visitor.close()
  } finally {
    // always switch it off again, and put both maintenance settings back to their defaults
    await page.reload()
    await page.getByLabel('Maintenance mode').uncheck()
    await page.getByRole('button', { name: 'Save settings' }).click()
    await page.getByText('No unsaved changes.').waitFor({ timeout: 60000 }) // not the toast: an older one may still be showing
    for (const label of ['Maintenance mode', 'Message shown during maintenance']) {
      const reset = page.locator('.setting', { has: page.getByLabel(label) }).getByRole('button', { name: 'Use the default' })
      if (await reset.count()) {
        await reset.click()
        await sleep(600)
      }
    }
  }
  check('admin: switching maintenance mode off lets a student back in', (await fetch(`${API}/me`, { headers: { Accept: 'application/json', Authorization: `Bearer ${ada}` } })).status === 200)

  // ---------------------------------------------------------------- roles and permissions
  resetLimits()
  await page.goto(`${BASE}/admin/roles`)
  await page.getByRole('heading', { name: 'At a glance' }).waitFor()
  check('admin: the roles page lists every permission for every role', (await page.locator('.card', { hasText: 'At a glance' }).locator('tbody tr').count()) >= 9)
  await page.getByRole('tab', { name: 'Registrar' }).click()
  const regToken = await apiLogin('e2e-reg@example.test', password)
  const usageStatus = async () => (await fetch(`${API}/reports/usage`, { headers: { Accept: 'application/json', Authorization: `Bearer ${regToken}` } })).status
  check('admin: a registrar cannot see the usage report to begin with', (await usageStatus()) === 403)
  try {
    await page.locator('.perm', { hasText: 'manage-courses' }).getByRole('checkbox').check()
    await page.getByRole('button', { name: 'Save Registrar' }).click()
    await toast('Registrar saved')
    check('admin: giving a role a permission takes effect straight away', (await usageStatus()) === 200)
  } finally {
    await page.getByRole('button', { name: 'Back to the starting permissions' }).click()
    await toast('starting permissions')
  }
  check('admin: a role can be put back to its starting permissions', (await usageStatus()) === 403)
  check('admin: the super administrator role is not offered for editing', (await page.getByRole('tab', { name: 'Super administrator' }).count()) === 0)

  // ---------------------------------------------------------------- notices to everyone
  await page.goto(`${BASE}/admin/announcements`)
  await page.getByRole('button', { name: 'New notice' }).click()
  await modal().getByLabel('Headline').fill('E2E Exams start Monday')
  await modal().getByLabel('Message').fill('Bring your student card.')
  await modal().getByLabel('Importance').selectOption('warning')
  await modal().getByLabel('Student', { exact: true }).check()
  await modal().getByRole('button', { name: 'Post notice' }).click()
  await toast('Notice posted')
  check('admin: a notice can be posted for one role', await row('E2E Exams start Monday').isVisible())
  const readerContext = await browser.newContext({ viewport: { width: 1280, height: 800 } })
  const reader = await readerContext.newPage()
  await uiLogin(reader, 'e2e-stu1@example.test', password)
  await reader.locator('.notice', { hasText: 'E2E Exams start Monday' }).waitFor({ timeout: 8000 })
  check('admin: the student sees the notice at the top of the screen', true)
  await reader.getByRole('button', { name: /Dismiss: E2E Exams/ }).click()
  await reader.locator('.notice', { hasText: 'E2E Exams' }).waitFor({ state: 'detached' })
  check('admin: and can dismiss it', true)
  await readerContext.close()
  const lecturerToken = await apiLogin('e2e-lect@example.test', password)
  check('admin: a lecturer is not shown a notice meant for students', !(await call(lecturerToken, 'GET', '/system-announcements/active')).some((n) => n.title.startsWith('E2E')))
  await row('E2E Exams start Monday').getByRole('button', { name: 'Remove' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Remove' }).click()
  await toast('Notice removed')
  check('admin: a notice can be removed', true)

  // ---------------------------------------------------------------- integrations
  await page.goto(`${BASE}/admin/integrations`)
  await page.getByRole('heading', { name: 'File storage' }).waitFor()
  const cards = await page.locator('.card h2').allTextContents()
  check('admin: every integration is listed', ['Single sign-on (OpenID Connect)', 'Email', 'File storage', 'Virus scanning (ClamAV)', 'Student records system', 'Online meetings', 'Payments and fees', 'API access for other systems'].every((c) => cards.includes(c)), cards.join(' | '))
  await page.locator('.card', { hasText: 'File storage' }).getByRole('button', { name: 'Test the connection' }).click()
  await page.getByText('A test file was written, read back and deleted.').waitFor({ timeout: 15000 })
  check('admin: file storage can be tested for real', true)
  await fetch(`${MAIL}/api/v1/messages`, { method: 'DELETE' })
  await page.getByRole('button', { name: 'Send me a test email' }).click()
  await page.getByText(/A test email was sent to e2e-admin@example.test/).waitFor({ timeout: 15000 })
  let testMail = null
  for (let i = 0; i < 20 && !testMail; i++) {
    await sleep(1000)
    testMail = (await (await fetch(`${MAIL}/api/v1/messages`)).json()).messages.find((m) => m.To[0].Address === 'e2e-admin@example.test') ?? null
  }
  check('admin: the test email really arrives', !!testMail)

  // ---------------------------------------------------------------- system, backups
  resetLimits()
  await page.goto(`${BASE}/admin/system`)
  await page.getByRole('heading', { name: 'Software' }).waitFor()
  const systemCards = await page.locator('.card h2').allTextContents()
  check('admin: the system page shows software, capacity, records and background work', ['Software', 'Capacity', 'Records held', 'Background work', 'Database updates'].every((t) => systemCards.includes(t)), systemCards.join(' | '))
  await page.getByRole('button', { name: 'See what would be removed' }).click()
  await page.getByText(/Would remove/).waitFor()
  check('admin: old records can be previewed before anything is removed', true)
  await page.getByRole('button', { name: 'Refresh saved copies' }).click()
  await toast('refreshed')
  check('admin: saved copies of reports and settings can be refreshed', true)

  await page.goto(`${BASE}/admin/backups`)
  await page.getByRole('heading', { name: 'Restoring a backup' }).waitFor()
  check('admin: restoring is explained as a server command, not offered as a button', (await page.getByText('php artisan lms:restore').count()) > 0 && (await page.getByRole('button', { name: /^restore/i }).count()) === 0)
  const backupCard = page.locator('.card', { has: page.getByRole('heading', { name: 'Backups', exact: true }) })
  const before = await backupCard.locator('tbody tr').count()
  await page.getByLabel(/Include uploaded files/).uncheck()
  await page.getByRole('button', { name: 'Back up now' }).click()
  await toast('The backup has started', 90000) // the dev machine can take a while to answer while it also runs the backup
  await page.waitForFunction((n) => [...document.querySelectorAll('.card')].some((c) => c.querySelector('h2')?.textContent === 'Backups' && c.querySelectorAll('tbody tr').length === n + 1), before, { timeout: 90000 })
  check('admin: a backup made in the background appears in the list when done', true)
  const newest = backupCard.locator('tbody tr').first()
  await newest.getByRole('button', { name: 'Check it' }).click()
  await newest.getByText(/Intact/).waitFor({ timeout: 30000 })
  check('admin: a backup can be checked against its checksums', true)
  const [backupFile] = await Promise.all([page.waitForEvent('download'), newest.getByRole('button', { name: 'Download' }).click()])
  check('admin: a backup can be downloaded', /^backup-\d{8}-\d{6}\.tar\.gz$/.test(backupFile.suggestedFilename()), backupFile.suggestedFilename())
  await newest.getByRole('button', { name: 'Delete' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click()
  await toast('Backup deleted')
  check('admin: a backup can be deleted', true)

  // ---------------------------------------------------------------- security events
  await page.goto(`${BASE}/admin/security`)
  await page.getByRole('heading', { name: 'Last 24 hours', exact: true }).waitFor()
  const security = await page.locator('main').innerText()
  check('admin: the security page counts sign-ins and lockouts', /Sign-ins\s*\d+/.test(security.replace(/\n/g, ' ')) && /Lockouts/.test(security))
  const kinds = await page.locator('.filters').getByLabel('What happened').locator('option').allTextContents()
  check('admin: security events are named in plain words', kinds.some((k) => /Signed in/.test(k)) && kinds.some((k) => /Account locked after too many tries/.test(k)), kinds.join(' | '))
  await page.locator('.filters').getByLabel('What happened').selectOption({ index: kinds.findIndex((k) => /Account locked/.test(k)) })
  await page.waitForFunction(() => {
    const log = [...document.querySelectorAll('.card')].find((c) => c.querySelector('h2')?.textContent === 'Event log')
    const rows = [...(log?.querySelectorAll('tbody tr') ?? [])]
    return rows.length > 0 && rows.every((r) => /Account locked after too many tries/.test(r.innerText))
  })
  check('admin: security events can be filtered by kind', true)

  // ---------------------------------------------------------------- reports: usage, enrolment, downloads
  resetLimits()
  await page.goto(`${BASE}/admin/reports`)
  await page.getByRole('tab', { name: 'Usage' }).click()
  await page.getByRole('heading', { name: 'The last 30 days' }).waitFor()
  check('admin: the usage report draws 30 days for each measure', (await page.locator('.daybars').first().locator('.bar').count()) === 30 && (await page.locator('.daybars').count()) === 4)
  check('admin: it counts the people who signed in', /[1-9]\d*\s*Active in the last 7 days/.test((await page.locator('.stats').first().innerText()).replace(/\n/g, ' ')))
  await page.getByRole('tab', { name: 'Enrolment' }).click()
  await page.getByRole('heading', { name: 'By department' }).waitFor()
  check('admin: the enrolment report shows each term with its students and places', /E2E Spring/.test(await page.locator('main').innerText()) && /E2E Term/.test(await page.locator('main').innerText()))
  await page.locator('.filters').getByLabel('Term').selectOption({ label: 'E2E Spring' })
  await page.waitForFunction(() => document.querySelectorAll('.card')[0].querySelectorAll('tbody tr').length === 1)
  check('admin: the enrolment report can be limited to one term', true)
  const [enrolCsv] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download as a spreadsheet' }).click()])
  const enrolCsvText = readFileSync(await enrolCsv.path(), 'utf8')
  check('admin: the enrolment report downloads as a spreadsheet', /^enrolment-.*\.csv$/.test(enrolCsv.suggestedFilename()) && /Places left/.test(enrolCsvText) && /E2E202/.test(enrolCsvText), enrolCsvText.slice(0, 200))

  // ---------------------------------------------------------------- reports, audit, status
  await page.goto(`${BASE}/admin/reports`)
  await page.getByRole('heading', { name: 'Accounts by role' }).waitFor()
  const roles = await page.locator('tbody').last().innerText()
  check('admin: the report breaks accounts down by role', ['Student', 'Lecturer', 'Registrar'].every((r) => roles.includes(r)), roles)
  check('admin: the report counts accounts', Number((await page.locator('.stat-value').first().innerText()).trim()) >= 10)

  await page.goto(`${BASE}/admin/audit`)
  await page.locator('tbody tr').first().waitFor()
  // Look each description up and read the answer itself (the screen is slow to redraw, and the log is too long to read from its first page).
  const searchAudit = async (text) => {
    const answered = page.waitForResponse((r) => r.url().includes('/audit-log') && new URL(r.url()).searchParams.get('q') === text, { timeout: 60000 })
    await page.getByLabel('Search the description').fill(text)
    const r = await answered
    return r.status() === 200 ? (await r.json()).total : -1
  }
  const recorded = []
  let asked = 0
  for (const d of ['user created', 'users imported', 'teacher assigned', 'offering copied', 'user updated', 'settings changed', 'role permissions changed', 'system announcement posted', 'backup created', 'integration tested', 'user deleted', 'account unlocked', 'sessions revoked', 'user data exported', 'term archived']) {
    if (asked++ % 6 === 0) resetLimits() // ten a minute, per person, for this screen
    if ((await searchAudit(d)) > 0) recorded.push(d)
  }
  check('admin: the audit log records the changes made above', recorded.length >= 12, `found ${recorded.length}: ${recorded.join(', ')}`)
  resetLimits()
  await searchAudit('copied')
  await page.waitForFunction(() => [...document.querySelectorAll('tbody tr')].length > 0 && [...document.querySelectorAll('tbody tr')].every((r) => /copied/.test(r.innerText)))
  check('admin: the audit log can be searched', (await page.locator('tbody tr').count()) >= 1)
  await page.getByLabel('Search the description').fill('')
  resetLimits()
  await page.getByRole('button', { name: 'Filter by person' }).click()
  await modal().getByRole('searchbox').fill('E2E Admin')
  const filtered = page.waitForResponse((r) => r.url().includes('/audit-log') && new URL(r.url()).searchParams.has('causer_id'), { timeout: 60000 })
  await modal().locator('.picker-list li', { hasText: 'E2E Admin' }).getByRole('button', { name: 'Filter' }).click()
  await filtered
  await page.waitForFunction(() => [...document.querySelectorAll('tbody tr td:nth-child(2)')].every((td) => td.textContent.includes('E2E Admin')))
  const who = await page.locator('tbody tr td:nth-child(2)').allInnerTexts()
  check('admin: the audit log can be filtered to one person', who.length > 0 && who.every((w) => w.includes('E2E Admin')), who.join(','))

  resetLimits()
  await page.getByLabel('From', { exact: true }).fill(dateIn(1))
  await page.getByText('No matching entries').waitFor()
  check('admin: the audit log can be limited by date', true)
  await page.getByLabel('From', { exact: true }).fill('')
  await page.locator('tbody tr').first().waitFor()
  resetLimits()
  const [auditCsv] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download as a spreadsheet' }).click()])
  const auditText = readFileSync(await auditCsv.path(), 'utf8')
  check('admin: the audit log downloads as a spreadsheet', /^audit-log-.*\.csv$/.test(auditCsv.suggestedFilename()) && /"When \(UTC\)",Who,Email,What/.test(auditText) && /E2E Admin/.test(auditText), auditText.slice(0, 200))

  await page.goto(`${BASE}/admin/status`)
  await page.getByText('Everything is working.').waitFor({ timeout: 100000 }) // clearing the cache above removed the scheduler's heartbeat; it is back within a minute, and this page looks again every 30 seconds
  const services = await page.locator('tbody tr').allInnerTexts()
  check('admin: system status shows every service healthy', services.length === 5 && services.every((s) => /ok/.test(s)), services.join(' | '))

  // ---------------------------------------------------------------- signing out
  await page.getByRole('button', { name: 'Sign out' }).click()
  await page.waitForURL(/\/login/)
  await page.goto(`${BASE}/admin/users`)
  await page.waitForURL(/\/login/)
  check('admin: after signing out, administration pages ask for a sign-in again', true)

  check('admin: no unexpected browser errors', errors.length === 0, errors.slice(0, 4).join(' | '))
  await context.close()
}
