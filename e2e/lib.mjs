import { execFileSync } from 'node:child_process'
import { chromium } from 'playwright-core'

// ---- where everything is (all can be overridden from the environment) -----------------------------------------------
export const BASE = (process.env.E2E_BASE_URL ?? 'http://localhost:5173').replace(/\/+$/, '')
export const API = (process.env.E2E_API_URL ?? 'http://localhost:8080/api').replace(/\/+$/, '')
export const MAIL = (process.env.E2E_MAIL_URL ?? 'http://localhost:8025').replace(/\/+$/, '')
// How to run `php artisan` inside the backend. The default is the Podman Compose container; use E2E_ARTISAN="php artisan"
// (with the working directory set to backend/) to use a local PHP instead.
const ARTISAN = (process.env.E2E_ARTISAN ?? `${process.env.E2E_CONTAINER_CLI ?? 'podman'} exec ${process.env.E2E_APP_CONTAINER ?? 'university-lms_app_1'} php artisan`).split(/\s+/)

/** These tests create accounts and data, so they only run against a local stack unless told otherwise. */
export function assertLocal() {
  const hosts = [BASE, API].map((u) => new URL(u).hostname)
  const local = hosts.every((h) => ['localhost', '127.0.0.1', '[::1]', '::1'].includes(h) || h.endsWith('.localhost'))
  if (!local && process.env.E2E_ALLOW_REMOTE !== '1') {
    throw new Error(`Refusing to run against ${hosts.join(' and ')}: these tests create and delete accounts. Set E2E_ALLOW_REMOTE=1 only for a disposable environment.`)
  }
}

export function artisan(...args) {
  const [cmd, ...prefix] = ARTISAN
  return execFileSync(cmd, [...prefix, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
}

// ---- checks ------------------------------------------------------------------------------------------------------------
const results = { passed: 0, failed: [] }
let currentSuite = ''
export function startSuite(name) {
  currentSuite = name
}
export function check(name, ok, detail = '') {
  if (ok) results.passed++
  else {
    results.failed.push(`[${currentSuite}] ${name}${detail ? `  -> ${detail}` : ''}`)
    console.log(`  FAIL: ${name}${detail ? `  -> ${detail}` : ''}`)
  }
}
export const getResults = () => results

// ---- browser -----------------------------------------------------------------------------------------------------------
export async function launch() {
  const executablePath = process.env.E2E_CHROME_PATH
  return chromium.launch(executablePath ? { executablePath, headless: true } : { channel: process.env.E2E_BROWSER ?? 'chrome', headless: true })
}

/** Signs in by typing the email and password into the real form. */
export async function uiLogin(page, email, password) {
  await page.goto(`${BASE}/login`)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 45000 })
}

// ---- talking to the API directly, to set data up quickly -----------------------------------------------------------------
export async function apiLogin(email, password) {
  const r = await fetch(`${API}/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email, password }) })
  const j = await r.json()
  if (!j.token) throw new Error(`Could not sign in as ${email}: ${JSON.stringify(j)}`)
  return j.token
}

export async function call(token, method, path, body) {
  const headers = { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Idempotency-Key': crypto.randomUUID() }
  let payload
  if (body instanceof FormData) payload = body
  else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }
  const r = await fetch(`${API}${path}`, { method, headers, body: payload })
  const text = await r.text()
  if (!r.ok) throw new Error(`${method} ${path} -> ${r.status} ${text.slice(0, 300)}`)
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

export const future = (days) => new Date(Date.now() + days * 86400000).toISOString()
export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * Forgets the request counters (and any sign-in lockouts). The busiest pages of the administration screens are limited to ten
 * requests a minute per person, which a test walking through all of them at once would hit; a real person would not.
 */
export function resetLimits() {
  artisan('cache:clear')
}

/** Today's date plus a number of days, as YYYY-MM-DD. */
export const dateIn = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10)
