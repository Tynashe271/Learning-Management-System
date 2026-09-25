// Runs the browser tests against the local stack:
//   1. removes any leftovers from an earlier run, 2. creates the test accounts and course, 3. fills the course with content,
//   4. runs the suites, 5. removes everything it created (even if a suite crashed).
//
//   node run.mjs                 all suites
//   node run.mjs admin           only the named suite(s): mobile, desktop, admin
//   node run.mjs --keep          leave the data in place afterwards, to look around by hand
//   node run.mjs --clean-only    just remove leftovers
//
// Exit code: 0 all passed, 1 a check failed or cleanup failed, 2 the tests could not start.
import { randomBytes } from 'node:crypto'
import { mkdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { API, BASE, artisan, assertLocal, getResults, launch } from './lib.mjs'
import { setupData } from './setup.mjs'

const here = dirname(fileURLToPath(import.meta.url))
const args = process.argv.slice(2)
const keep = args.includes('--keep')
const cleanOnly = args.includes('--clean-only')
const requested = args.filter((a) => !a.startsWith('--'))
const available = ['mobile', 'desktop', 'admin']

function cleanUp() {
  try {
    console.log(`  cleaned up: ${artisan('lms:e2e', 'clean').trim()}`)
    return true
  } catch (error) {
    console.error(`Could not clean up the test data: ${error.stderr || error.message}`)
    return false
  }
}

async function main() {
  const unknown = requested.find((s) => !available.includes(s))
  if (unknown) {
    console.error(`Unknown suite "${unknown}". Choose from: ${available.join(', ')}`)
    return 2
  }
  try {
    assertLocal()
  } catch (error) {
    console.error(error.message)
    return 2
  }

  console.log(`Frontend ${BASE}, API ${API}`)
  try {
    const health = await fetch(`${API}/health`)
    if (health.status >= 500 && health.status !== 503) throw new Error(`the API answered ${health.status}`)
    const config = await fetch(`${BASE}/config.js`)
    if (!config.ok) throw new Error(`the frontend answered ${config.status}`)
  } catch (error) {
    console.error(`The stack is not reachable (${error.cause?.code ?? error.message}). Start it with "podman compose up -d" from the project folder.`)
    return 2
  }

  if (!cleanUp()) return 1
  if (cleanOnly) return 0

  const artifacts = join(here, 'artifacts')
  mkdirSync(artifacts, { recursive: true })
  // A fresh throwaway password every run, used only by accounts that are deleted at the end.
  const password = `E2e-${randomBytes(9).toString('hex')}-Ok1`
  const chosen = requested.length ? requested : available
  let code = 0
  let browser
  try {
    const seeded = JSON.parse(artisan('lms:e2e', 'seed', `--password=${password}`).trim().split('\n').pop())
    console.log(`  seeded test accounts, offering ${seeded.offering}`)
    const ids = await setupData(password, seeded.offering)
    console.log('  filled the course with content')

    browser = await launch()
    for (const name of chosen) {
      console.log(`\n== ${name} ==`)
      const { run } = await import(`./tests/${name}.mjs`)
      try {
        await run({ browser, password, ids, artifacts })
      } catch (error) {
        getResults().failed.push(`[${name}] the suite stopped early: ${String(error.message).split('\n')[0]}`)
        console.log(`  CRASH: ${String(error.message).split('\n').slice(0, 4).join('\n        ')}`)
      }
    }
  } catch (error) {
    console.error(`\nSetup failed: ${error.stderr || error.message}`)
    code = 2
  } finally {
    await browser?.close()
    if (keep) console.log(`\n--keep: test data left in place (accounts e2e-*@example.test, password ${password}). Remove it with "npm run clean".`)
    else if (!cleanUp()) code = 1
  }

  const { passed, failed } = getResults()
  console.log(`\nPASSED: ${passed}   FAILED: ${failed.length}`)
  for (const f of failed) console.log(`  - ${f}`)
  console.log(`Screenshots: ${artifacts}`)
  return code || (failed.length ? 1 : 0)
}

// Set the exit code and let Node finish on its own; forcing an exit with connections still closing crashes it on Windows.
process.exitCode = await main()
