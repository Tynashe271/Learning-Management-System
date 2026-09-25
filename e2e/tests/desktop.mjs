import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { BASE, MAIL, check, sleep, startSuite, uiLogin } from '../lib.mjs'

/** Real downloads, real password entry (change and email reset), and what each role can and cannot do. */
export async function run({ browser, password, ids }) {
  startSuite('desktop')
  const { offering: o, assignment: a, appeal } = ids
  const downloads = mkdtempSync(join(tmpdir(), 'lms-e2e-'))
  const errors = []

  async function fresh(email, pw = password) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, acceptDownloads: true })
    const page = await context.newPage()
    page.on('pageerror', (e) => errors.push(String(e)))
    page.on('console', (m) => m.type() === 'error' && !/401|Failed to load resource/.test(m.text()) && errors.push(m.text()))
    await uiLogin(page, email, pw)
    return { context, page }
  }
  async function download(page, clickIt) {
    const [d] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), clickIt()])
    const path = join(downloads, `${Date.now()}-${d.suggestedFilename()}`)
    await d.saveAs(path)
    return { name: d.suggestedFilename(), text: readFileSync(path, 'utf8') }
  }

  try {
    // ======================= downloads =======================
    {
      const { context, page } = await fresh('e2e-stu1@example.test')
      check('signing in by typing the password works', (await page.locator('.profile-text strong').textContent()) === 'Ada Student')

      await page.goto(`${BASE}/courses/${o}/classwork`)
      await page.waitForSelector('.item')
      let f = await download(page, () => page.locator('.item', { hasText: 'Lecture notes' }).getByRole('button', { name: 'Download' }).click())
      check('a course file downloads with its real content', f.text.startsWith('LECTURE NOTES CONTENT'), JSON.stringify(f))

      await page.goto(`${BASE}/assignments/${a}`)
      await page.getByRole('button', { name: 'Download your file' }).waitFor()
      f = await download(page, () => page.getByRole('button', { name: 'Download your file' }).click())
      check('a student can download their own submission', f.text === 'ADA ESSAY FILE CONTENT', JSON.stringify(f))
      await context.close()
    }
    {
      const { context, page } = await fresh('e2e-lect@example.test')
      await page.goto(`${BASE}/courses/${o}/grades`)
      await page.waitForSelector('table')
      let f = await download(page, () => page.getByRole('button', { name: /Download as spreadsheet/ }).click())
      check('the gradebook downloads as a spreadsheet with every student', f.name.endsWith('.csv') && f.text.includes('Name,Email') && f.text.includes('Ada Student') && f.text.includes('Ben Student'), JSON.stringify(f))
      await page.goto(`${BASE}/assignments/${a}`)
      await page.getByRole('heading', { name: 'Submissions' }).waitFor()
      await page.locator('tbody tr', { hasText: 'Ada Student' }).getByRole('button', { name: 'Regrade' }).click()
      f = await download(page, () => page.locator('.modal').getByRole('button', { name: 'Download the attached file' }).click())
      check('a grader can download a submission from the grading dialog', f.text === 'ADA ESSAY FILE CONTENT', JSON.stringify(f))
      await context.close()
    }

    // ======================= passwords =======================
    {
      const changed = `Changed-${password}`
      const { context, page } = await fresh('e2e-stu2@example.test')
      await page.goto(`${BASE}/profile`)
      await page.getByLabel('Current password').fill('this-is-not-my-password')
      await page.getByLabel('New password', { exact: true }).fill(changed)
      await page.getByLabel('Repeat the new password').fill(changed)
      await page.getByRole('button', { name: 'Change password' }).click()
      await page.getByText('The current password is incorrect.').waitFor()
      check('a wrong current password is refused, beside the field', true)
      await page.getByLabel('Current password').fill(password)
      await page.getByRole('button', { name: 'Change password' }).click()
      await page.getByText(/Password changed/).waitFor()
      check('the password changes', true)
      await page.getByRole('button', { name: 'Sign out' }).click()
      await page.waitForURL(/\/login/)
      await page.getByLabel('Email').fill('e2e-stu2@example.test')
      await page.getByLabel('Password').fill(password)
      await page.getByRole('button', { name: 'Sign in' }).click()
      await page.getByText('Invalid credentials.').waitFor()
      check('the old password no longer works', true)
      await page.getByLabel('Password').fill(changed)
      await page.getByRole('button', { name: 'Sign in' }).click()
      await page.waitForURL((u) => !u.pathname.startsWith('/login'))
      check('the new password works', true)

      // put it back through the emailed reset link, typing into the real reset form
      await page.getByRole('button', { name: 'Sign out' }).click()
      await page.waitForURL(/\/login/)
      await fetch(`${MAIL}/api/v1/messages`, { method: 'DELETE' })
      await page.getByRole('link', { name: 'Forgot your password?' }).click()
      await page.getByLabel('Email').fill('e2e-stu2@example.test')
      await page.getByRole('button', { name: 'Send reset link' }).click()
      await page.getByText(/If that account exists/).waitFor()
      let link = null
      for (let i = 0; i < 30 && !link; i++) {
        await sleep(1000)
        const list = await (await fetch(`${MAIL}/api/v1/messages`)).json()
        const m = list.messages.find((x) => x.To[0].Address === 'e2e-stu2@example.test' && /password/i.test(x.Subject))
        if (m) {
          const body = await (await fetch(`${MAIL}/api/v1/message/${m.ID}`)).json()
          link = /https?:\/\/[^\s"<>]*reset-password[^\s"<>]*/.exec(body.Text + body.HTML)?.[0].replace(/&amp;/g, '&') ?? null
        }
      }
      check('the reset email arrives with a link to the frontend', !!link && link.startsWith(BASE), String(link))
      await page.goto(link)
      check('the emailed address is filled in', (await page.getByLabel('Email').inputValue()) === 'e2e-stu2@example.test')
      await page.getByLabel('New password', { exact: true }).fill(password)
      await page.getByLabel('Repeat the new password').fill(password)
      await page.getByRole('button', { name: 'Save new password' }).click()
      await page.getByText(/Password reset/).waitFor()
      check('the reset link sets a new password', true)
      await page.goto(link)
      await page.getByLabel('New password', { exact: true }).fill('another-passphrase-123')
      await page.getByLabel('Repeat the new password').fill('another-passphrase-123')
      await page.getByRole('button', { name: 'Save new password' }).click()
      await page.locator('.alert-error, .field-error').first().waitFor()
      check('the same link cannot be used twice', true)
      await page.goto(`${BASE}/login`)
      await page.getByLabel('Email').fill('e2e-stu2@example.test')
      await page.getByLabel('Password').fill(password)
      await page.getByRole('button', { name: 'Sign in' }).click()
      await page.waitForURL((u) => !u.pathname.startsWith('/login'))
      check('signing in with the reset password works', true)
      await context.close()
    }

    // ======================= roles =======================
    const tabsOf = (page) => page.locator('.tab').allTextContents()
    const menuOf = (page) => page.locator('.sidebar .nav-link').allTextContents().then((all) => all.map((s) => s.replace(/\d+$/, '').trim()))
    const refused = async (page) => {
      await page.getByText(/do not have access to this page/).waitFor({ timeout: 8000 }).catch(() => undefined)
      return page.getByText(/do not have access to this page/).isVisible()
    }

    { // teaching assistant
      const { context, page } = await fresh('e2e-ta@example.test')
      const menu = await menuOf(page)
      check('TA: no administration menu', !menu.some((n) => /Terms|People|Import|Reports|Audit|status/i.test(n)), menu.join(','))
      await page.goto(`${BASE}/courses/${o}/classwork`)
      await page.waitForSelector('.tab')
      const tabs = await tabsOf(page)
      check('TA: manages the course (gradebook, overview, people, appeals)', ['Gradebook', 'Overview', 'People', 'Appeals'].every((t) => tabs.includes(t)), tabs.join(','))
      check('TA: no course settings tab', !tabs.includes('Settings'), tabs.join(','))
      check('TA: can add content', await page.getByRole('button', { name: 'Add a topic' }).isVisible())
      await page.goto(`${BASE}/courses/${o}/people`)
      await page.getByText('Teaching staff').waitFor()
      check('TA: cannot assign teachers or enrol students', (await page.getByRole('button', { name: 'Assign a teacher' }).count()) === 0 && (await page.getByRole('button', { name: 'Enrol a student' }).count()) === 0)
      await page.goto(`${BASE}/assignments/${a}`)
      await page.getByRole('heading', { name: 'Submissions' }).waitFor()
      await page.locator('tbody tr', { hasText: 'Ben Student' }).getByRole('button', { name: 'Grade' }).waitFor()
      check('TA: can grade submissions', true)
      await page.goto(`${BASE}/appeals/${appeal}`)
      await page.getByText(/Only staff with permission to decide appeals/).waitFor()
      check('TA: can read an appeal but not decide it', (await page.getByRole('button', { name: 'Record decision' }).count()) === 0)
      await page.goto(`${BASE}/admin/audit`)
      check('TA: an administration address shows an access message', await refused(page))
      await context.close()
    }
    { // lecturer
      const { context, page } = await fresh('e2e-lect@example.test')
      await page.goto(`${BASE}/appeals/${appeal}`)
      await page.getByRole('button', { name: 'Record decision' }).waitFor()
      check('lecturer: can decide an appeal', true)
      await context.close()
    }
    { // department administrator
      const { context, page } = await fresh('e2e-dept@example.test')
      const menu = await menuOf(page)
      check('dept admin: catalogue, people, reports and status are available', ['Terms and courses', 'People', 'Reports', 'System status'].every((n) => menu.includes(n)), menu.join(','))
      check('dept admin: no account import or audit log', !menu.includes('Import accounts') && !menu.includes('Audit log'), menu.join(','))
      await page.goto(`${BASE}/admin/audit`)
      check('dept admin: the audit log address is refused', await refused(page))
      await page.goto(`${BASE}/admin/users`)
      await page.getByRole('heading', { name: 'People' }).waitFor()
      check('dept admin: can look people up but not create accounts', (await page.getByRole('button', { name: 'New account' }).count()) === 0)
      await page.goto(`${BASE}/courses/${o}/classwork`)
      await page.waitForSelector('.tab')
      const tabs = await tabsOf(page)
      check('dept admin: sees every course tab including Settings', ['Gradebook', 'People', 'Appeals', 'Settings'].every((t) => tabs.includes(t)), tabs.join(','))
      check('dept admin: can publish or unpublish the course', await page.getByRole('button', { name: 'Unpublish' }).first().isVisible())
      await page.goto(`${BASE}/courses/${o}/people`)
      await page.getByRole('button', { name: 'Assign a teacher' }).waitFor()
      check('dept admin: can assign teachers and enrol students', await page.getByRole('button', { name: 'Enrol a student' }).isVisible())
      await page.goto(`${BASE}/assignments/${a}`)
      await page.getByRole('heading', { name: 'Submissions' }).waitFor()
      await page.getByText(/grading is done by the course/).waitFor()
      check('dept admin: can edit the assignment but not grade it', (await page.getByRole('button', { name: /^(Grade|Regrade)$/ }).count()) === 0 && (await page.getByRole('button', { name: 'Edit' }).count()) > 0)
      await page.goto(`${BASE}/appeals/${appeal}`)
      await page.getByRole('button', { name: 'Record decision' }).waitFor()
      check('dept admin: can decide appeals', true)
      await page.goto(`${BASE}/courses/${o}/settings`)
      await page.getByRole('button', { name: 'Copy this course' }).waitFor()
      check('dept admin: can copy a course', true)
      await context.close()
    }

    { // registrar: may enrol students in a course without being allowed to open its teaching material
      const { context, page } = await fresh('e2e-reg@example.test')
      await page.goto(`${BASE}/courses/${o}`)
      await page.getByRole('button', { name: 'Enrol a student' }).waitFor()
      const tabs = await tabsOf(page)
      check('registrar: only the People tab, so no teaching material', tabs.length === 1 && tabs[0] === 'People', tabs.join(','))
      check('registrar: the course is still named (looked up from the course list)', await page.getByText('E2E101').first().isVisible())
      check('registrar: can import an enrolment list', await page.getByRole('button', { name: 'Import a list' }).isVisible())
      await page.goto(`${BASE}/courses/${o}/classwork`)
      await page.getByText('Students').first().waitFor()
      check('registrar: the classwork address falls back to People', page.url().endsWith('/people') || (await page.locator('.tab').count()) === 1)
      await context.close()
    }

    check('no unexpected browser errors', errors.length === 0, errors.slice(0, 4).join(' | '))
  } finally {
    rmSync(downloads, { recursive: true, force: true })
  }
}
