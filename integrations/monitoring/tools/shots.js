// Biwork Monitoring: ekran suratlari (Playwright). Ishga tushirish:
//   NODE_PATH=/opt/node-tools/node_modules PW_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//   node tools/shots.js http://127.0.0.1:8090 ./screens [only]
// Namuna korxona soati tekshirilgan voqea ketma-ketligi bo'yicha suriladi; xatolar oxirida chop etiladi.
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const fs = require('fs');
const path = require('path');

const base = (process.argv[2] || 'http://127.0.0.1:8090').replace(/\/$/, '');
const out = path.resolve(process.argv[3] || './screens');
const only = process.argv[4] ? new RegExp(process.argv[4]) : null;
fs.mkdirSync(out, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROMIUM || undefined, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, locale: 'uz-UZ', timezoneId: 'Asia/Tashkent' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  page.on('response', (r) => { if (r.status() >= 400 && !/favicon/.test(r.url())) errors.push(r.status() + ' ' + r.url()); });

  const shot = async (name, opts = {}) => {
    if (only && !only.test(name)) return;
    await page.waitForTimeout(opts.wait ?? 700);
    await page.screenshot({ path: path.join(out, name + '.png'), fullPage: !!opts.full });
    console.log('ok', name);
  };
  const open = async (hash, wait = 900) => {
    await page.goto(base + '/#' + hash, { waitUntil: 'networkidle' });
    await page.waitForTimeout(wait);
  };

  await page.goto(base + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('.kpi', { timeout: 20000 });
  await shot('01-bosh-sahifa', { full: true, wait: 1200 });

  await open('/alerts');
  await shot('02-ogohlantirishlar');
  const first = await page.$('#al-body tr[data-id]');
  if (first) { await first.click(); await page.waitForSelector('.drawer .callout', { timeout: 8000 }); await shot('03-ogohlantirish-tafsiloti', { wait: 900 }); }
  await page.keyboard.press('Escape');

  await open('/machines');
  await shot('04-uskunalar', { full: true });
  await open('/machines/tg05');
  await shot('05-uskuna-sahifasi', { full: true });

  await open('/orders');
  await shot('06-buyurtmalar', { full: true });

  await open('/planfact');
  await shot('07-reja-va-fakt', { full: true });

  await open('/rules');
  await shot('08-qoidalar', { full: true });

  await open('/sources');
  await shot('09-manbalar');

  await page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'dark'); });
  await open('/');
  await page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'dark'); });
  await shot('10-qorongi-mavzu', { full: true });

  await browser.close();
  console.log(errors.length ? 'XATOLAR:\n' + [...new Set(errors)].join('\n') : 'xatosiz');
})().catch((e) => { console.error(e); process.exit(1); });
