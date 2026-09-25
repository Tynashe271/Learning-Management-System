import { join } from 'node:path'
import { BASE, check, startSuite, uiLogin } from '../lib.mjs'

/** The app on a phone (390 x 844, touch): nothing scrolls sideways, menus and dialogs work, and taps are big enough. */
export async function run({ browser, password, ids, artifacts }) {
  startSuite('mobile')
  const { offering: o, assignment: a, quiz: q } = ids
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true })
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', (e) => errors.push(String(e)))
  page.on('console', (m) => m.type() === 'error' && !/Failed to load resource/.test(m.text()) && errors.push(m.text()))

  async function overflow(label) {
    const r = await page.evaluate(() => {
      const wide = [...document.querySelectorAll('body *')]
        .filter((el) => {
          const b = el.getBoundingClientRect()
          return b.width > 0 && b.right > window.innerWidth + 1 && getComputedStyle(el).position !== 'fixed' && !el.closest('.table-wrap, .tabs, pre, .modal-backdrop')
        })
        .slice(0, 3)
        .map((el) => `${el.tagName}.${el.className}`)
      return { scroll: document.scrollingElement.scrollWidth, inner: window.innerWidth, wide }
    })
    check(`${label}: no sideways scrolling`, r.scroll <= r.inner + 1, `${r.scroll} > ${r.inner}`)
    check(`${label}: nothing pokes out of the screen`, r.wide.length === 0, JSON.stringify(r.wide))
  }
  const shot = (name) => page.screenshot({ path: join(artifacts, `mobile-${name}.png`) })

  // ---- signed out
  await page.goto(`${BASE}/login`)
  await page.waitForSelector('form')
  await overflow('sign in')
  await shot('login')

  // ---- a student
  await uiLogin(page, 'e2e-stu1@example.test', password)
  await page.waitForSelector('h1')
  await overflow('dashboard')
  check('a menu button replaces the sidebar', await page.locator('.menu-btn').isVisible())
  check('the sidebar is hidden until opened', !(await page.locator('.sidebar').isVisible()))
  await page.locator('.menu-btn').click()
  check('the sidebar opens', await page.locator('.sidebar').isVisible())
  await shot('menu')
  await page.locator('.sidebar').getByRole('link', { name: 'Courses', exact: true }).click()
  check('the menu closes after choosing a page', !(await page.locator('.sidebar').isVisible()))
  await page.waitForSelector('table')
  await overflow('courses')
  await shot('courses')

  await page.getByRole('link', { name: /Testing Foundations/ }).click()
  await page.waitForURL(/\/stream/)
  await overflow('course stream')
  await shot('stream')
  check('the course tabs scroll inside their own strip', await page.evaluate(() => document.querySelector('.tabs').scrollWidth > document.querySelector('.tabs').clientWidth))

  await page.goto(`${BASE}/courses/${o}/classwork`)
  await page.waitForSelector('.item')
  await overflow('course classwork')
  await shot('classwork')

  for (const [tab, selector] of [['announcements', '.card'], ['discussions', '.threads'], ['classes', 'table'], ['attendance', '.stats'], ['grades', 'table']]) {
    await page.goto(`${BASE}/courses/${o}/${tab}`)
    await page.waitForSelector(selector, { timeout: 10000 })
    await overflow(`course ${tab}`)
    if (['classes', 'grades'].includes(tab)) await shot(tab)
  }

  await page.goto(`${BASE}/assignments/${a}`)
  await page.getByText('Marks by criterion').waitFor()
  await overflow('a graded assignment')
  await shot('assignment')

  await page.goto(`${BASE}/quizzes/${q}`)
  await page.getByRole('button', { name: /Start|Continue/ }).click()
  await page.waitForURL(/\/attempts\//)
  await page.waitForSelector('.question-take')
  await overflow('a quiz attempt')
  await shot('quiz')
  const choice = await page.locator('.choice').first().boundingBox()
  check('answer choices are easy to tap (at least 40px tall)', choice.height >= 40, String(choice.height))

  await page.goto(`${BASE}/profile`)
  await page.waitForSelector('form')
  await overflow('profile')

  await page.goto(`${BASE}/courses/${o}/classes`)
  await page.waitForSelector('table')
  await page.getByRole('button', { name: 'Check in' }).click()
  await page.waitForSelector('.modal')
  check('a dialog uses the full phone width', (await page.locator('.modal').boundingBox()).width >= 385)
  await overflow('a dialog')
  await shot('dialog')
  await page.keyboard.press('Escape')
  check('Escape closes the dialog', (await page.locator('.modal').count()) === 0)

  // ---- a lecturer
  await page.evaluate(() => {
    sessionStorage.clear()
    localStorage.clear()
  })
  await uiLogin(page, 'e2e-lect@example.test', password)
  await page.goto(`${BASE}/assignments/${a}`)
  await page.getByRole('heading', { name: 'Submissions' }).waitFor()
  await page.waitForSelector('text=Ada Student')
  await overflow('an assignment as its teacher')
  await shot('staff-assignment')
  await page.getByRole('button', { name: 'Regrade' }).first().click()
  await page.waitForSelector('.modal')
  await overflow('the grading dialog')
  await shot('grade-dialog')
  await page.keyboard.press('Escape')
  await page.goto(`${BASE}/courses/${o}/grades`)
  await page.waitForSelector('table')
  await overflow('the gradebook (it scrolls inside its own box)')
  await shot('gradebook')

  // ---- an administrator
  await page.evaluate(() => {
    sessionStorage.clear()
    localStorage.clear()
  })
  await uiLogin(page, 'e2e-admin@example.test', password)
  for (const path of ['/admin/users', '/admin/catalogue', '/admin/audit', '/admin/import', '/admin/reports', '/admin/status']) {
    await page.goto(`${BASE}${path}`)
    await page.waitForSelector('h1')
    await page.waitForTimeout(700)
    await overflow(`administration ${path}`)
  }
  await shot('admin-users')

  check('no browser errors while using the app on a phone', errors.length === 0, errors.slice(0, 3).join(' | '))
  await context.close()
}
