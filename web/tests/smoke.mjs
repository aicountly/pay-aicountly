/**
 * Does the app actually render, against a real API?
 *
 * A type check proves the props line up and the integration suite proves the
 * API is right. Neither notices a screen that renders nothing, a route the
 * shell never reaches, or a page that throws on a shape the server really
 * returns. This opens every route in a browser against the running stack and
 * fails on a console error, a 4xx/5xx, an empty page or a visible crash.
 *
 * Run it through the stack script, which brings up everything it needs:
 *
 *     server-php/tests/devstack.sh --smoke
 *
 * Needs Playwright. PLAYWRIGHT_CHROMIUM points at a browser when the one
 * Playwright would download is already on the machine.
 */

const BASE = process.env.APP_BASE ?? 'http://127.0.0.1:5199'
const CHECKOUT_TOKEN = process.env.CHECKOUT_TOKEN ?? ''

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ?? 'playwright')

/** Every route in the signed-in shell, and what it should be able to show. */
const ROUTES = [
  '/',
  '/dashboards/collections',
  '/dashboards/gateways',
  '/dashboards/settlements',
  '/dashboards/pay-pulse',
  '/payments',
  '/payments/record-external',
  '/requests',
  '/requests/new',
  '/links',
  '/customers',
  '/mandates',
  '/refunds',
  '/settlements',
  '/reconciliation',
  '/gateways',
  '/developers',
  '/settings',
]

/** Below this a page has rendered its chrome and nothing else. */
const MIN_CONTENT = 250

const launch = {}
if (process.env.PLAYWRIGHT_CHROMIUM) launch.executablePath = process.env.PLAYWRIGHT_CHROMIUM
if (process.env.PLAYWRIGHT_NO_PROXY) launch.args = ['--no-proxy-server']

const browser = await chromium.launch(launch)
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })

// The stub portal mints a session for any auth_token, so this is a sign-in.
await context.addInitScript(() => {
  try {
    localStorage.setItem('auth_token', 'demo-owner')
  } catch {
    /* a browser with storage disabled is not what this test is about */
  }
})

let failures = 0

function report(route, problems, detail) {
  if (problems.length === 0) {
    console.log(`  ok    ${route}${detail ? '  ' + detail : ''}`)
    return
  }
  failures++
  console.log(`  FAIL  ${route}`)
  for (const problem of problems) console.log(`        ${problem}`)
}

/** Opens a page, watching everything that would make it a bad one. */
async function visit(route, { expect = [], minContent = MIN_CONTENT } = {}) {
  const page = await context.newPage()
  const problems = []

  page.on('console', (message) => {
    if (message.type() === 'error') problems.push('console: ' + message.text().slice(0, 200))
  })
  page.on('pageerror', (error) => problems.push('threw: ' + String(error).slice(0, 200)))
  page.on('response', (response) => {
    if (response.status() >= 400) {
      problems.push(`${response.status()} ${response.url().replace(/^https?:\/\/[^/]+/, '')}`)
    }
  })

  try {
    await page.goto(BASE + route, { waitUntil: 'networkidle' })
    // Panels fetch after mount; networkidle is the start of rendering, not the end.
    await page.waitForTimeout(900)

    const text = (await page.evaluate(() => document.body.innerText)).trim()

    if (!page.url().startsWith(BASE)) problems.push('left the app for ' + page.url())
    if (text.length < minContent) problems.push(`rendered ${text.length} chars: ${text.replace(/\n/g, ' / ').slice(0, 160)}`)
    if (/Which company\?/.test(text)) problems.push('stuck on the company picker')
    if (/Something went wrong|Unexpected error|Failed to fetch/i.test(text)) problems.push('an error is on the screen')
    for (const wanted of expect) {
      if (!text.includes(wanted)) problems.push(`does not mention "${wanted}"`)
    }

    report(route, problems, `${text.length} chars`)
  } catch (error) {
    report(route, [...problems, 'navigation: ' + String(error).slice(0, 200)])
  } finally {
    await page.close()
  }
}

// --- sign in and choose a company -------------------------------------------
console.log('\nSigning in')
const boot = await context.newPage()
await boot.goto(BASE + '/', { waitUntil: 'networkidle' })
const picker = boot.getByText('Stub Trading Co').first()
if (await picker.isVisible().catch(() => false)) {
  await picker.click()
  await boot.waitForTimeout(1200)
  console.log('  ok    company chosen')
} else {
  console.log('  ok    already scoped')
}
await boot.close()

// --- the signed-in app ------------------------------------------------------
console.log('\nEvery route in the shell')
for (const route of ROUTES) await visit(route)

// --- the page a stranger sees -----------------------------------------------
console.log('\nThe public checkout')
if (CHECKOUT_TOKEN === '') {
  console.log('  ——    no CHECKOUT_TOKEN given, skipped')
} else {
  // The whole point: no sign-in, no portal jump, and the amount on the screen.
  const stranger = await browser.newContext({ viewport: { width: 390, height: 844 } })
  const page = await stranger.newPage()
  const problems = []
  page.on('pageerror', (error) => problems.push('threw: ' + String(error).slice(0, 200)))
  page.on('response', (r) => { if (r.status() >= 400) problems.push(`${r.status()} ${r.url().replace(/^https?:\/\/[^/]+/, '')}`) })

  await page.goto(`${BASE}/pay/p/${CHECKOUT_TOKEN}`, { waitUntil: 'networkidle' }).catch((e) => problems.push(String(e).slice(0, 160)))
  await page.waitForTimeout(800)

  const text = (await page.evaluate(() => document.body.innerText).catch(() => '')).trim()
  if (!page.url().startsWith(BASE)) problems.push('a payment link bounced the payer to ' + page.url())
  if (!/is requesting a payment/.test(text)) problems.push('the checkout did not render')
  if (!/₹/.test(text)) problems.push('no amount on the screen')

  report('/pay/p/{token}', problems, `${text.length} chars`)
  await stranger.close()
}

await browser.close()

console.log(failures === 0 ? '\nEvery screen rendered.\n' : `\n${failures} screen(s) with problems.\n`)
process.exit(failures === 0 ? 0 : 1)
