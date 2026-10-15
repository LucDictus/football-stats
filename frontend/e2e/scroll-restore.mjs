// End-to-end check: Back returns to the scroll position deep in the ranking.
// Needs the API (:8010) and Vite (:5173) running and a local Chrome:
//   CHROME_PATH=/usr/bin/google-chrome npm run e2e
import assert from 'node:assert/strict'
import puppeteer from 'puppeteer-core'

const base = process.env.APP_URL ?? 'http://127.0.0.1:5173'
const browser = await puppeteer.launch({ executablePath: process.env.CHROME_PATH ?? '/usr/bin/google-chrome', headless: true })
try {
  const page = await browser.newPage()
  await page.setViewport({ width: 1280, height: 800 })
  await page.goto(base, { waitUntil: 'networkidle0' })

  // Filter first: a same-path navigation must not stop positions from being saved.
  await page.click('button[aria-label="Central mids"]')
  await page.waitForFunction(() => location.search === '?position=CM')
  await page.waitForNetworkIdle()

  await page.click('button ::-p-text(Show more)')
  await page.waitForFunction(() => document.querySelectorAll('ol li').length > 60)
  await page.evaluate(() => window.scrollTo(0, 6000))
  await new Promise((r) => setTimeout(r, 400))

  // Open a player whose page is shorter than the saved position: the case that used to fail.
  await page.evaluate(() => [...document.querySelectorAll('ol li a')].find((a) => a.getBoundingClientRect().top > 300).click())
  await page.waitForFunction(() => location.pathname.startsWith('/players/'))
  await page.waitForSelector('h1')
  await new Promise((r) => setTimeout(r, 900))
  assert.equal(await page.evaluate(() => window.scrollY), 0, 'a new page starts at the top')

  await page.goBack()
  await page.waitForFunction(() => location.pathname === '/')
  await new Promise((r) => setTimeout(r, 1200))
  assert.equal(await page.evaluate(() => window.scrollY), 6000, 'Back returns to the ranking position')

  console.log('scroll restore: ok')
} finally {
  await browser.close()
}
