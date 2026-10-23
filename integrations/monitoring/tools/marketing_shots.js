// Biwork Monitoring: reklama sahifasi uchun ekran suratlari (1440x900, ko'rinadigan qism). Ishga tushirish:
//   NODE_PATH=/opt/node-tools/node_modules PW_CHROMIUM=... node tools/marketing_shots.js http://127.0.0.1:8090 ./marketing-src
// Keyin: python3 biwork/tools/product_assets.py monitoring --shot bosh=marketing-src/bosh.png ... (README.md ga qarang)
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const fs = require('fs');
const path = require('path');

const base = (process.argv[2] || 'http://127.0.0.1:8090').replace(/\/$/, '');
const out = path.resolve(process.argv[3] || './marketing-src');
fs.mkdirSync(out, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROMIUM || undefined, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5, locale: 'uz-UZ', timezoneId: 'Asia/Tashkent' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  const shot = async (name, wait = 900) => {
    await page.waitForTimeout(wait);
    await page.screenshot({ path: path.join(out, name + '.png') });
    console.log('ok', name);
  };
  const open = async (hash, wait = 900) => {
    await page.goto(base + '/#' + hash, { waitUntil: 'networkidle' });
    await page.waitForTimeout(wait);
  };

  await page.goto(base + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('.kpi', { timeout: 20000 });
  await shot('bosh', 1200);

  await open('/alerts');
  await shot('ogohlantirishlar');
  const first = await page.$('#al-body tr[data-id]');
  if (first) { await first.click(); await page.waitForSelector('.drawer .callout', { timeout: 8000 }); await shot('tafsilot', 900); }
  await page.keyboard.press('Escape');

  await open('/machines');
  await shot('uskunalar');
  await open('/machines/tg05');
  await shot('uskuna');
  await open('/orders');
  await shot('buyurtmalar');
  await open('/planfact');
  await shot('rejafakt');
  await open('/rules');
  await shot('qoidalar');

  await browser.close();
  console.log(errors.length ? 'XATOLAR:\n' + [...new Set(errors)].join('\n') : 'xatosiz');
})().catch((e) => { console.error(e); process.exit(1); });
