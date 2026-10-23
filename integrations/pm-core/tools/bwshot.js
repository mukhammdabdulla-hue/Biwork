// Playwright yordamchisi: portaldan "Ochish" orqali namunaga kiradi va ekran rasmlarini oladi.
//   const { openDemo } = require('./bwshot.js');
//   const { page, close } = await openDemo({ portal, email, pass, slug, width, height });
// Muhit: NODE_PATH=/opt/node-tools/node_modules, PW_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome
const { chromium } = require(process.env.PW_MODULE || 'playwright');
async function openDemo(o) {
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROMIUM || undefined, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: o.width || 1440, height: o.height || 900 }, deviceScaleFactor: o.scale || 1, locale: o.locale || process.env.PW_LOCALE || 'ru-RU', timezoneId: 'Asia/Tashkent' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  page.on('response', r => { if (r.status() >= 400 && !/favicon/.test(r.url())) errors.push(r.status() + ' ' + r.url()); });
  await page.goto(o.portal + '/login');
  await page.fill('input[name=email]', o.email);
  await page.fill('input[name=password]', o.pass);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.goto(o.portal + '/app/open/' + o.slug, { waitUntil: 'networkidle' });
  await page.waitForTimeout(o.settle || 1500);
  return { page, ctx, errors, close: () => browser.close() };
}
module.exports = { openDemo };
