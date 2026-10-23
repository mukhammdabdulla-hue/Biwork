/* Biwork Monitoring — mijoz qismi (vanilla JS, hash-marshrut, internetsiz). */

const BASE = document.documentElement.dataset.base || '';
const TZ = 5 * 3600; // Toshkent vaqti (UTC+5)

/* ------------------------------------------------------------------ ikonlar */
const IC = {
  home: '<path d="M3.5 10.5 12 3.8l8.5 6.7"/><path d="M5.5 9v11h4.8v-6h3.4v6h4.8V9"/>',
  bell: '<path d="M6 17.5V11a6 6 0 0 1 12 0v6.5l1.5 1.5h-15z"/><path d="M10 21a2.2 2.2 0 0 0 4 0"/>',
  orders: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 3h6v3H9z"/><path d="M8.5 10.5h7M8.5 14h7M8.5 17.5h4"/>',
  machine: '<path d="M3 20.5h18"/><path d="M4.5 20.5V10l4.5 3v-3l4.5 3v-3l4.5 3v7.5"/><path d="M17.5 13V4.5h2.5v16"/><path d="M8 17h2M12.5 17h2"/>',
  gantt: '<path d="M3.5 4v16.5H21"/><rect x="6.5" y="6" width="7" height="3" rx="1"/><rect x="10" y="11" width="8.5" height="3" rx="1"/><rect x="7.5" y="16" width="5" height="2.5" rx="1"/>',
  sliders: '<path d="M4 6.5h9M17 6.5h3M4 12h3M11 12h9M4 17.5h11M19 17.5h1"/><circle cx="15" cy="6.5" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="17.5" r="2"/>',
  db: '<ellipse cx="12" cy="6" rx="7" ry="2.8"/><path d="M5 6v12c0 1.5 3.1 2.8 7 2.8s7-1.3 7-2.8V6"/><path d="M5 12c0 1.5 3.1 2.8 7 2.8s7-1.3 7-2.8"/>',
  collapse: '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M9.5 4.5v15"/><path d="m15.5 10-2 2 2 2"/>',
  logout: '<path d="M14.5 4.5h3a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2h-3"/><path d="M10 16.5 5.5 12 10 7.5"/><path d="M5.5 12h10"/>',
  back: '<path d="M10 6 4 12l6 6"/><path d="M4.5 12H20"/>',
  menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.2V12l3.2 2.2"/>',
  play: '<path d="M7.5 5.2v13.6a.8.8 0 0 0 1.2.7l11-6.8a.8.8 0 0 0 0-1.4l-11-6.8a.8.8 0 0 0-1.2.7z"/>',
  chevronD: '<path d="m6 9.5 6 6 6-6"/>',
  chevronR: '<path d="m9.5 6 6 6-6 6"/>',
  moon: '<path d="M19.5 14.6A8 8 0 0 1 9.4 4.5a8 8 0 1 0 10.1 10.1z"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.8v2M12 19.2v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.8 12h2M19.2 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
  shield: '<path d="M12 3.5 19 6v5.5c0 4.3-3 7.7-7 9-4-1.3-7-4.7-7-9V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
  alert: '<path d="M10.3 4.2 2.6 17.8a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4.5"/><path d="M12 17.3v.01"/>',
  check: '<circle cx="12" cy="12" r="8.5"/><path d="m8.2 12.3 2.6 2.6 5-5.6"/>',
  info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5"/><path d="M12 7.8v.01"/>',
  x: '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
  search: '<circle cx="10.8" cy="10.8" r="6.3"/><path d="m20 20-4.6-4.6"/>',
  refresh: '<path d="M19.5 8.5A8 8 0 0 0 5 7.5"/><path d="M4.5 3.8v4.2h4.2"/><path d="M4.5 15.5A8 8 0 0 0 19 16.5"/><path d="M19.5 20.2V16h-4.2"/>',
  server: '<rect x="4" y="4" width="16" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="16" height="6.5" rx="1.5"/><path d="M7.5 7.2h.01M7.5 16.8h.01"/>',
  sheet: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M3.5 9.5h17M3.5 14.5h17M9.5 9.5v10"/>',
  arrowR: '<path d="M5 12h14M13.5 6.5 19 12l-5.5 5.5"/>',
  timer: '<circle cx="12" cy="13.5" r="7.5"/><path d="M12 9.5v4l2.5 1.5"/><path d="M9.5 3h5"/><path d="M12 3v2.5"/>',
  chart: '<path d="M4 20h16"/><path d="M7 16v-5M11 16V8M15 16v-3M19 16V6"/>',
  tool: '<path d="M14.6 5.6a4.2 4.2 0 0 0-5.1 5.6L3.8 16.9a1.6 1.6 0 0 0 0 2.3l1 1a1.6 1.6 0 0 0 2.3 0l5.7-5.7a4.2 4.2 0 0 0 5.6-5.1l-2.6 2.6-2.5-.6-.6-2.5z"/>',
  inbox: '<path d="M3.5 13.5 6 5.5h12l2.5 8"/><path d="M3.5 13.5v5a1.5 1.5 0 0 0 1.5 1.5h14a1.5 1.5 0 0 0 1.5-1.5v-5h-5a3.5 3.5 0 0 1-7 0z"/>',
  user: '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.8 20a7.2 7.2 0 0 1 14.4 0"/>',
  lock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5"/><path d="M12 14.5v2.2"/>',
  dot: '<circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>',
};
const icon = (n, s = 16) => `<svg class="ic" width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${IC[n] || IC.dot}</svg>`;
const fillIcons = (root = document) => root.querySelectorAll('i[data-icon]').forEach((el) => { el.outerHTML = icon(el.dataset.icon, +el.dataset.size || 17).replace('class="ic"', `class="ic ${el.className || ''}"`); });

/* ------------------------------------------------------------------ yordamchilar */
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const p2 = (n) => String(n).padStart(2, '0');
const D = (t) => new Date((t + TZ) * 1000);
const hm = (t) => { const d = D(t); return `${p2(d.getUTCHours())}:${p2(d.getUTCMinutes())}`; };
const dm = (t) => { const d = D(t); return `${p2(d.getUTCDate())}.${p2(d.getUTCMonth() + 1)}`; };
const dmhm = (t) => `${dm(t)} ${hm(t)}`;
const MONTHS = ['yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentyabr', 'oktyabr', 'noyabr', 'dekabr'];
const dayIdx = (t) => Math.floor((t + TZ) / 86400);
const dur = (m) => {
  m = Math.round(m);
  if (m < 1) return '1 daqiqadan kam';
  if (m < 60) return `${m} daqiqa`;
  const h = Math.floor(m / 60), r = m % 60;
  if (h < 48) return r ? `${h} soat ${r} daqiqa` : `${h} soat`;
  const d = Math.floor(h / 24), hh = h % 24;
  return hh ? `${d} kun ${hh} soat` : `${d} kun`;
};
const short = (m) => {
  m = Math.round(m);
  if (m < 1) return 'hozirgina';
  if (m < 60) return `${m} daq`;
  const h = Math.floor(m / 60), r = m % 60;
  if (h < 48) return r ? `${h} soat ${r} daq` : `${h} soat`;
  const d = Math.floor(h / 24), hh = h % 24;
  return hh ? `${d} kun ${hh} soat` : `${d} kun`;
};
/** Tor joy (uskuna kartochkasi) uchun juda qisqa ko'rinish: "1 s 05 d" */
const tiny = (m) => {
  m = Math.round(m);
  if (m < 1) return 'hozirgina';
  if (m < 60) return `${m} d`;
  const h = Math.floor(m / 60), r = m % 60;
  if (h < 48) return r ? `${h} s ${p2(r)} d` : `${h} s`;
  return `${Math.floor(h / 24)} kun`;
};
const when = (t) => { const d = dayIdx(t) - dayIdx(NOW); return `${d === 0 ? 'bugun' : d === -1 ? 'kecha' : d === 1 ? 'ertaga' : dm(t)} ${hm(t)}`; };
const num = (v, d = 0) => (v == null ? '—' : Number(v).toLocaleString('uz-UZ', { maximumFractionDigits: d, minimumFractionDigits: 0 }).replace(/ /g, ' '));

const ST = { run: 'Ishlayapti', setup: 'Sozlanmoqda', stop: "To'xtagan", wait: 'Kutmoqda', idle: "Bo'sh" };
const REASON = { tamir: "Ta'mir", material: 'Material kutilmoqda', qolip: 'Qolip kutilmoqda', tozalash: 'Tozalash', boshqa_uchastka: 'Boshqa uchastkada ishlagan', tanaffus: 'Tanaffus', ish_yoq: "Ish yo'q", boshqa: 'Sababi yozilmagan' };
const SEC = { kesish: 'Kesish', bosma: 'Bosma', lam: 'Laminatsiya', tigel: 'Tigel va shtanz', yelim: 'Yelimlash' };
const SEV = { crit: 'Jiddiy', warn: 'Diqqat', info: "Ma'lumot" };
const SEV_PILL = { crit: 'pill-danger', warn: 'pill-warn', info: 'pill-info' };
const sevIcon = (s) => (s === 'info' ? 'info' : 'alert');
const sevPill = (s) => `<span class="pill ${SEV_PILL[s] || 'pill-muted'}"><i class="dot"></i>${SEV[s] || s}</span>`;
const statePill = (s) => `<span class="pill ${{ run: 'pill-success', setup: 'pill-info', stop: 'pill-danger', wait: 'pill-warn', idle: 'pill-muted' }[s] || 'pill-muted'}"><i class="dot"></i>${ST[s] || s}</span>`;

let NOW = 0;
let ME = null;
let CSRF = '';

/* ------------------------------------------------------------------ tarmoq */
async function api(method, path, body) {
  const opt = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (method !== 'GET') {
    opt.headers['Content-Type'] = 'application/json';
    opt.headers['X-CSRF-Token'] = CSRF;
    opt.body = JSON.stringify(body || {});
  }
  let r;
  try { r = await fetch(`${BASE}/api${path}`, opt); } catch (e) { throw new Error("Server bilan aloqa yo'q. Internetni tekshiring."); }
  let j = null;
  try { j = await r.json(); } catch (e) { /* bo'sh javob */ }
  if (r.status === 401) { location.href = `${BASE}/login.php`; throw new Error('Kirish kerak'); }
  if (!r.ok) throw Object.assign(new Error(j?.error?.message || 'Xatolik yuz berdi'), { code: j?.error?.code, status: r.status });
  return j;
}

/* ------------------------------------------------------------------ toast va modal */
function toast(msg, sub = '', kind = 'success') {
  const el = document.createElement('div');
  el.className = `toast toast-${kind}`;
  el.innerHTML = `<span class="toast-ic">${icon(kind === 'error' ? 'alert' : kind === 'info' ? 'info' : 'check', 15)}</span><div><div class="toast-msg">${esc(msg)}</div>${sub ? `<div class="toast-sub">${esc(sub)}</div>` : ''}</div>`;
  $('#toasts').appendChild(el);
  requestAnimationFrame(() => el.classList.add('in'));
  setTimeout(() => { el.classList.remove('in'); setTimeout(() => el.remove(), 250); }, kind === 'error' ? 6000 : 3800);
}

function modal({ title, body, ok = 'Tasdiqlash', cancel = 'Bekor qilish', danger = false }) {
  return new Promise((res) => {
    const back = document.createElement('div');
    back.className = 'modal-back';
    back.innerHTML = `<div class="modal" role="dialog" aria-modal="true"><div class="modal-hd"><h3>${esc(title)}</h3><button class="icon-btn sm" data-x>${icon('x', 16)}</button></div><div class="modal-bd">${body}</div><div class="modal-ft"><button class="btn btn-ghost" data-x>${esc(cancel)}</button><button class="btn ${danger ? 'btn-danger-soft' : 'btn-primary'}" data-ok>${esc(ok)}</button></div></div>`;
    document.body.appendChild(back);
    requestAnimationFrame(() => back.classList.add('in'));
    const close = (v) => { back.classList.remove('in'); setTimeout(() => back.remove(), 200); document.removeEventListener('keydown', onKey); res(v); };
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(null); } };
    document.addEventListener('keydown', onKey);
    back.addEventListener('click', (e) => {
      if (e.target === back || e.target.closest('[data-x]')) close(null);
      else if (e.target.closest('[data-ok]')) close($('textarea,input', back)?.value ?? true);
    });
    setTimeout(() => $('textarea,input', back)?.focus(), 60);
  });
}

/* ------------------------------------------------------------------ umumiy bo'laklar */
const skeleton = (n = 3) => `<div class="stack">${Array.from({ length: n }, () => '<div class="card card-pad"><div class="sk" style="height:14px;width:30%;margin-bottom:14px"></div><div class="sk" style="height:56px"></div></div>').join('')}</div>`;
const empty = (ic, text, hint = '') => `<div class="empty"><div class="empty-ic">${icon(ic, 22)}</div><p>${esc(text)}</p>${hint ? `<small>${esc(hint)}</small>` : ''}</div>`;
const errorCard = (e) => `<div class="card error-card">${icon('alert', 22)}<div><h3>Ma'lumotni yuklab bo'lmadi</h3><p>${esc(e.message)}</p><button class="btn btn-ghost btn-sm" data-act="reload">${icon('refresh', 14)}Qayta urinish</button></div></div>`;

function spark(vals, w = 100, h = 28) {
  const v = vals.filter((x) => x != null);
  if (v.length < 2) return '';
  const lo = Math.min(...v), hi = Math.max(...v), rng = hi - lo || 1;
  const pts = vals.map((x, i) => (x == null ? null : [(i / (vals.length - 1)) * w, h - 3 - ((x - lo) / rng) * (h - 8)])).filter(Boolean);
  const d = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join('');
  const last = pts[pts.length - 1];
  return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none"><path class="a" d="${d}L${w} ${h}L0 ${h}Z"/><path class="l" d="${d}"/><circle cx="${last[0]}" cy="${last[1]}" r="2.4" vector-effect="non-scaling-stroke"/></svg>`;
}

function deltaPill(cur, prev, unit = '', goodUp = true, ref = 'kechagiga nisbatan') {
  if (cur == null || prev == null) return '';
  const d = Math.round((cur - prev) * 10) / 10;
  if (Math.abs(d) < 0.5) return `<span class="delta neutral">o'zgarmadi</span> ${ref}`;
  const good = goodUp ? d > 0 : d < 0;
  return `<span class="delta ${good ? 'good' : 'bad'}">${d > 0 ? '+' : '−'}${num(Math.abs(d), 1)}${unit}</span> ${ref}`;
}

/** Ustunli diagramma (SVG). vals — sonlar, labels — pastki yozuvlar. */
function barsSvg(vals, labels, o2 = {}) {
  const W = o2.W || 224, H = o2.H || 84, slot = W / vals.length, bw = Math.min(o2.bw || 22, slot - 10), base = H - 20, maxH = base - 18;
  const hi = Math.max(o2.max || 0, ...vals.map((v) => v || 0), 1);
  const fs = o2.fs ? ` style="font-size:${o2.fs}px"` : '';
  const g = vals.map((v, i) => {
    const hh = v == null ? 0 : Math.max(2, (v / hi) * maxH);
    const x = i * slot + (slot - bw) / 2;
    const last = i === vals.length - 1;
    return `<rect class="b ${last ? 'last' : ''}" x="${x.toFixed(1)}" y="${(base - hh).toFixed(1)}" width="${bw}" height="${hh.toFixed(1)}" rx="3"/>${v == null ? '' : `<text${fs} x="${(x + bw / 2).toFixed(1)}" y="${(base - hh - 4).toFixed(1)}">${o2.fmt ? o2.fmt(v) : v}</text>`}<text${fs} x="${(x + bw / 2).toFixed(1)}" y="${H - 5}">${labels[i]}</text>`;
  }).join('');
  return `<svg class="bars-svg" viewBox="0 0 ${W} ${H}"><line class="g" x1="0" x2="${W}" y1="${base}" y2="${base}"/>${g}</svg>`;
}

/** Ikki vaqt oralig'i: "bugun 20:15–23:30" yoki "bugun 20:15 – ertaga 09:00" */
const span = (a, b) => (dayIdx(a) === dayIdx(b) ? `${when(a)}–${hm(b)}` : `${when(a)} – ${when(b)}`);

/** Holatlar chizig'i: items = [boshlanish, tugash, holat, sabab, buyurtma] */
function stripHtml(items, from, to, cls = '') {
  const span = to - from;
  const bar = items.map(([s, e, st, rs, ord]) => {
    s = Math.max(s, from); e = Math.min(e, to);
    if (e <= s) return '';
    const l = ((s - from) / span) * 100, w = ((e - s) / span) * 100;
    const soft = st === 'stop' && (rs === 'tanaffus') ? ' soft' : '';
    const tip = `${ST[st] || st}${rs && st === 'stop' ? ` · ${REASON[rs] || rs}` : ''} · ${hm(s)}–${hm(e)} (${dur((e - s) / 60)})${ord ? ` · Buyurtma ${ord}` : ''}`;
    return `<i class="${st}${soft}" style="left:${l.toFixed(2)}%;width:${w.toFixed(2)}%" title="${esc(tip)}"></i>`;
  }).join('');
  return `<div class="strip ${cls}">${bar}</div>`;
}

/** Reja (yuqori) va fakt (pastki) yo'laklari */
function lanesHtml(plan, fact, from, to, now) {
  const span = to - from;
  const x = (t) => (Math.min(Math.max(t, from), to) - from) / span * 100;
  const pl = plan.map((b) => {
    const l = x(b.start), w = x(b.end) - l;
    if (w <= 0) return '';
    const tip = `Buyurtma ${b.no} · ${b.name} · ${SEC[b.kind] || b.kind} · reja ${hm(b.start)}–${hm(b.end)}`;
    return `<i class="${b.status === 'done' ? 'done' : ''}" style="left:${l.toFixed(2)}%;width:${w.toFixed(2)}%" title="${esc(tip)}">${w > (span > 30 * 3600 ? 5 : 3.2) ? esc(b.no) : ''}</i>`;
  }).join('');
  const nowL = x(now);
  return `<div class="pf-lanes"><div class="lane plan">${pl}</div><div class="lane fact">${stripInner(fact, from, to)}<span class="lane-fut" style="left:${nowL.toFixed(2)}%;right:0"></span></div><span class="pf-now" style="left:${nowL.toFixed(2)}%"></span></div>`;
}
function stripInner(items, from, to) {
  return stripHtml(items, from, to).replace(/^<div class="strip ">/, '').replace(/<\/div>$/, '');
}

/* ------------------------------------------------------------------ yo'naltirish */
const ROUTES = [
  ['home', /^\/?$/, 'Bosh sahifa'],
  ['alerts', /^\/alerts(?:\/(\d+))?$/, 'Ogohlantirishlar'],
  ['machines', /^\/machines(?:\/([a-z0-9_]+))?$/, 'Uskunalar'],
  ['orders', /^\/orders$/, 'Buyurtmalar'],
  ['planfact', /^\/planfact$/, 'Reja va fakt'],
  ['rules', /^\/rules$/, 'Qoidalar'],
  ['sources', /^\/sources$/, "Ma'lumot manbalari"],
];
let CUR = { name: '', arg: null };
let TOKEN = 0;

function parseHash() {
  const p = (location.hash.replace(/^#/, '') || '/').split('?')[0];
  for (const [name, re, title] of ROUTES) {
    const m = re.exec(p);
    if (m) return { name, arg: m[1] || null, title };
  }
  return { name: 'home', arg: null, title: 'Bosh sahifa' };
}
const go = (h) => { if (location.hash === h) route(); else location.hash = h; };

async function route(quiet = false) {
  const r = parseHash();
  const same = CUR.name === r.name && quiet;
  const prevArg = CUR.arg;
  CUR = r;
  const my = ++TOKEN;
  $$('.sb-nav a').forEach((a) => a.classList.toggle('active', a.dataset.nav === r.name));
  $('#page-title').textContent = r.title;
  document.title = `${r.title} · Biwork Monitoring`;
  document.body.classList.remove('sb-open');
  const app = $('#app');
  // Ogohlantirish tafsiloti ro'yxat ustida ochiladi — ro'yxatni qayta chizmaymiz
  if (r.name === 'alerts' && prevArg !== r.arg && AL.rendered && !quiet) {
    if (r.arg) openAlert(+r.arg); else closeDrawer();
    return;
  }
  if (!same) { app.innerHTML = r.name === 'planfact' ? skeleton(1) : skeleton(); window.scrollTo(0, 0); }
  try {
    const v = { home: viewHome, alerts: viewAlerts, machines: viewMachines, orders: viewOrders, planfact: viewPlanFact, rules: viewRules, sources: viewSources }[r.name];
    const html = await v(r.arg, my);
    if (my !== TOKEN) return;
    if (html != null) app.innerHTML = html;
    afterRender(r.name);
  } catch (e) {
    if (my === TOKEN) app.innerHTML = errorCard(e);
  }
  refreshNav();
}

async function afterRender(name) {
  if (name !== 'alerts') { AL.rendered = false; return; }
  const arg = AL.pendingArg;
  AL.pendingArg = null;
  syncAlertToolbar();
  await loadAlerts();
  if (arg) openAlert(+arg);
}

async function refreshNav() {
  try {
    const [a, s] = await Promise.all([api('GET', '/alerts?limit=1'), api('GET', '/sources')]);
    NOW = s.now || NOW;
    const c = a.counts;
    const el = $('#nav-alerts');
    el.textContent = c.active ? String(c.active) : '';
    el.className = `sb-count ${c.crit ? 'sb-p1' : c.warn ? 'sb-p2' : ''}`;
    const bad = s.items.filter((x) => x.state !== 'ok').length;
    $('#nav-sources').textContent = bad ? String(bad) : '';
    $('#nav-sources').className = `sb-count ${bad ? 'sb-p2' : ''}`;
    setClock();
  } catch (e) { /* menyudagi sonlar ixtiyoriy */ }
}

/* ------------------------------------------------------------------ Bosh sahifa */
async function viewHome() {
  const o = await api('GET', '/overview');
  NOW = o.now;
  setClock();
  const t = o.trend;
  const run = o.states.run + o.states.setup;
  const sevTotal = Math.max(1, o.alerts_total);
  const riskN = o.orders.risk.crit + o.orders.risk.warn;
  const stateBar = ['run', 'setup', 'wait', 'idle', 'stop'].map((k) => `<i class="${k}" style="flex:${o.states[k]}" title="${ST[k]}: ${o.states[k]}"></i>`).join('');
  const kpis = [
    { tone: o.alerts.crit ? 'danger' : o.alerts_total ? 'warn' : 'success', ic: o.alerts_total ? 'bell' : 'check', lbl: 'Faol ogohlantirishlar', val: num(o.alerts_total), href: '#/alerts',
      sub: o.alerts.crit ? `<b class="danger-t">${o.alerts.crit} ta jiddiy</b> · yopildi (24 soatda): ${o.closed24}` : `Yopildi (24 soatda): ${o.closed24}`,
      viz: `<div class="kpi-stack">${['crit', 'warn', 'info'].map((k) => (o.alerts[k] ? `<i class="${k}" style="flex:${o.alerts[k] / sevTotal}" title="${SEV[k]}: ${o.alerts[k]}"></i>` : '')).join('')}</div>` },
    { tone: 'navy', ic: 'machine', lbl: 'Uskunalar holati', val: `${run}<small>/ ${o.machines} ishlayapti</small>`, href: '#/machines',
      sub: `To'xtagan: <b>${o.states.stop}</b> · Kutmoqda: <b>${o.states.wait}</b> · Bo'sh: <b>${o.states.idle}</b>`, viz: `<div class="kpi-stack">${stateBar}</div>` },
    { tone: 'info', ic: 'chart', lbl: 'Bugungi bandlik', val: `${num(o.util_today, 0)}<small>%</small>`, href: '#/planfact',
      sub: deltaPill(o.util_today, o.util_prev, ' pt', true, 'kechagi kunga nisbatan') || 'Smena boshidan hisoblanadi', viz: spark(t.map((x) => x.util)) },
    { tone: 'success', ic: 'timer', lbl: 'Vaqtida boshlangan ishlar', val: o.start_ok_today == null ? '—' : `${num(o.start_ok_today, 0)}<small>%</small>`, href: '#/planfact',
      sub: deltaPill(o.start_ok_today, o.start_ok_prev, ' pt', true, 'kechagi kunga nisbatan') || 'Reja bo\'yicha 30 daqiqa ichida', viz: spark(t.map((x) => x.start_ok)) },
    { tone: o.orders.risk.crit ? 'danger' : riskN ? 'warn' : 'success', ic: 'orders', lbl: 'Xavfdagi buyurtmalar', val: `${riskN}<small>/ ${o.orders.open} ochiq</small>`, href: '#/orders',
      sub: riskN ? `<b class="${o.orders.risk.crit ? 'danger-t' : 'warn-t'}">${o.orders.risk.crit} ta muddatga ulgurmaydi</b> · ${o.orders.risk.warn} ta zaxirasi oz` : 'Hammasi muddatida', viz: '' },
  ];
  const kpiHtml = kpis.map((k) => `<div class="card kpi kpi-${k.tone}" data-href="${k.href}"><div class="kpi-top"><span class="kpi-ic">${icon(k.ic, 15)}</span><span class="kpi-lbl">${k.lbl}</span></div><div class="kpi-val">${k.val}</div><div class="kpi-sub">${k.sub}</div><div class="kpi-viz">${k.viz}</div></div>`).join('');

  const top = o.top.length
    ? `<ul class="ins-list">${o.top.map((a) => `<li class="ins lv-${a.severity}" data-href="#/alerts/${a.id}"><span class="ins-ic">${icon(sevIcon(a.severity), 15)}</span><div class="ins-body"><b>${esc(a.title)}</b><p>${esc(a.family_name)} · ${a.status === 'open' ? 'yangi' : 'ko\'rildi'}${a.owner ? ` · ${esc(a.owner)}` : ''}</p></div><span class="ins-age">${short(a.age_min)}</span><span class="ins-go">${icon('chevronR', 15)}</span></li>`).join('')}</ul>`
    : empty('check', "Hozircha e'tibor talab qiladigan holat yo'q", 'Yangi muammo paydo bo\'lsa shu yerda ko\'rinadi.');

  const board = o.sections.map((s) => `<div class="board-sec"><h4>${esc(s.name)}</h4><div class="board-grid">${s.machines.map((m) => {
    const since = m.since ? tiny((NOW - m.since) / 60) : '';
    return `<a class="tile ${m.state}" href="#/machines/${m.id}"><b>${esc(m.name)}${m.alert ? `<i class="al ${m.alert}"></i>` : ''}</b><span>${ST[m.state]}${since ? ` · ${since}` : ''}</span><span>${m.order ? `Buyurtma ${esc(m.order)}` : '—'}</span></a>`;
  }).join('')}</div></div>`).join('');

  const bars = barsSvg;
  const labels = t.map((x) => x.label);
  const lastT = t[t.length - 1] || {};
  const trend = `<div class="card card-pad"><div class="card-hd"><div><h2>So'nggi 7 kun</h2><p>Har kunning ko'rsatkichi. Oxirgi ustun — bugun (hozirgacha).</p></div></div><div class="trend-grid">
    <div class="trend"><h3>Bandlik, %</h3><div class="tv">${num(lastT.util, 0)}<small>%</small></div>${bars(t.map((x) => Math.round(x.util)), labels, { max: 100 })}</div>
    <div class="trend"><h3>O'z vaqtida boshlash, %</h3><div class="tv">${lastT.start_ok == null ? '—' : num(lastT.start_ok, 0)}<small>%</small></div>${bars(t.map((x) => (x.start_ok == null ? null : Math.round(x.start_ok))), labels, { max: 100 })}</div>
    <div class="trend"><h3>To'xtashlar, soat</h3><div class="tv">${num(lastT.stop_h, 1)}<small>soat</small></div>${bars(t.map((x) => x.stop_h), labels, { fmt: (v) => num(v, 1) })}</div>
    <div class="trend"><h3>Yangi ogohlantirishlar</h3><div class="tv">${num(lastT.alerts)}<small>ta</small></div>${bars(t.map((x) => x.alerts), labels)}</div>
  </div></div>`;

  const d = D(NOW);
  $('#page-sub').textContent = `${d.getUTCDate()}-${MONTHS[d.getUTCMonth()]}, ${hm(NOW)}`;
  return `<div class="grid-kpi">${kpiHtml}</div>
    <div class="dash-grid">
      <div class="card card-pad"><div class="card-hd"><div><h2>Hozir e'tibor talab qiladi</h2><p>Eng muhimlari tepada. Bosing — sababi va nima qilish kerakligi chiqadi.</p></div><a class="btn btn-ghost btn-sm" href="#/alerts">Hammasi (${o.alerts_total})${icon('arrowR', 14)}</a></div>${top}</div>
      <div class="card card-pad"><div class="card-hd"><div><h2>Uskunalar</h2><p>Hozirgi holat va nechchidan beri shunday.</p></div><div class="legend"><span><i class="lg run"></i>Ishlayapti</span><span><i class="lg setup"></i>Sozlash</span><span><i class="lg wait"></i>Kutmoqda</span><span><i class="lg stop"></i>To'xtagan</span><span><i class="lg idle"></i>Bo'sh</span></div></div><div class="board">${board}</div></div>
    </div>${trend}`;
}

/* ------------------------------------------------------------------ Ogohlantirishlar */
const AL = { status: 'active', severity: '', family: '', q: '', offset: 0, rendered: false, counts: null };
const FAMILIES = { toxtash: "To'xtashlar", rejafakt: 'Reja va fakt', buyurtma: 'Buyurtma muddati', navbat: 'Navbat', malumot: "Ma'lumot sifati" };

async function viewAlerts(arg) {
  AL.rendered = true;
  const html = `<div class="card" id="al-card">
    <div class="toolbar">
      <div class="seg" id="al-status"><button data-v="active">Faol <span class="n" id="n-active"></span></button><button data-v="closed">Yopilgan <span class="n" id="n-closed"></span></button></div>
      <div class="seg" id="al-sev"><button data-v="">Hammasi</button><button data-v="crit">Jiddiy <span class="n" id="n-crit"></span></button><button data-v="warn">Diqqat <span class="n" id="n-warn"></span></button><button data-v="info">Ma'lumot <span class="n" id="n-info"></span></button></div>
      <select class="input input-sm" id="al-fam" aria-label="Turi"><option value="">Barcha turlar</option>${Object.entries(FAMILIES).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
      <div class="spacer"></div>
      <div class="search">${icon('search', 15)}<input class="input input-sm" id="al-q" placeholder="Uskuna yoki buyurtma" style="width:100%"></div>
    </div>
    <div id="al-body"><div style="padding:24px"><div class="sk" style="height:200px"></div></div></div>
  </div>`;
  AL.pendingArg = arg;
  return html;
}

function syncAlertToolbar() {
  $$('#al-status button').forEach((b) => b.classList.toggle('on', b.dataset.v === AL.status));
  $$('#al-sev button').forEach((b) => b.classList.toggle('on', b.dataset.v === AL.severity));
  const f = $('#al-fam'); if (f) f.value = AL.family;
  const q = $('#al-q'); if (q && q.value !== AL.q) q.value = AL.q;
}

async function loadAlerts() {
  const body = $('#al-body');
  if (!body) return;
  const qs = new URLSearchParams({ status: AL.status, limit: 30, offset: AL.offset });
  if (AL.severity) qs.set('severity', AL.severity);
  if (AL.family) qs.set('family', AL.family);
  if (AL.q) qs.set('q', AL.q);
  try {
    const r = await api('GET', `/alerts?${qs}`);
    if (!$('#al-body')) return;
    const c = r.counts; AL.counts = c;
    const set = (id, v) => { const e = $(id); if (e) e.textContent = v ? String(v) : ''; };
    set('#n-active', c.active); set('#n-closed', c.closed); set('#n-crit', c.crit); set('#n-warn', c.warn); set('#n-info', c.info);
    $('#page-sub').textContent = c.unseen ? `${c.unseen} tasi hali ko'rilmagan` : (c.active ? "Hammasi ko'rilgan" : '');
    if (!r.items.length) {
      body.innerHTML = AL.status === 'closed' ? empty('inbox', "Yopilgan ogohlantirishlar yo'q") : (AL.severity || AL.family || AL.q ? empty('search', "Bu filtr bo'yicha hech narsa topilmadi", "Filtrlarni o'zgartirib ko'ring.") : empty('check', "Hozircha ogohlantirish yo'q", 'Hammasi reja bo\'yicha ketyapti.'));
      return;
    }
    const rows = r.items.map((a) => `<tr data-id="${a.id}" class="${a.status === 'open' ? 'unseen' : ''} lv-${a.severity}">
      <td style="width:22px;padding-right:0"><span class="dot-s ${a.severity}"></span></td>
      <td class="t"><b>${esc(a.title)}</b><small>${esc(a.entity_name)} · ${esc(a.rule_name)}</small></td>
      <td><span class="tag tag-slate">${esc(a.family_name)}</span></td>
      <td class="nowrap">${a.status === 'closed' ? `<span class="muted">${dmhm(a.closed_at)}</span>` : `<span title="Boshlangan: ${dmhm(a.opened_at)}">${short(a.age_min)}</span>`}</td>
      <td class="nowrap">${a.status === 'closed' ? (a.closed_by === 'tizim' ? '<span class="pill pill-success"><i class="dot"></i>O\'zi yopildi</span>' : `<span class="pill pill-muted">${esc(a.closed_by || 'Yopildi')}</span>`) : (a.status === 'open' ? '<span class="pill pill-navy"><i class="dot"></i>Yangi</span>' : '<span class="pill pill-muted">Ko\'rildi</span>')}${a.owner ? ` <span class="xs muted">· ${esc(a.owner)}</span>` : ''}</td>
      <td class="r" style="width:30px;color:var(--faint)">${icon('chevronR', 15)}</td></tr>`).join('');
    const pages = Math.ceil(r.total / 30), cur = Math.floor(AL.offset / 30);
    body.innerHTML = `<div class="table-wrap"><table class="tbl tbl-hover"><thead><tr><th></th><th>Ogohlantirish</th><th>Turi</th><th>${AL.status === 'closed' ? 'Yopilgan' : 'Davomiyligi'}</th><th>Holat</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>
      ${pages > 1 ? `<div class="pager"><span>${AL.offset + 1}–${Math.min(r.total, AL.offset + 30)} / ${r.total}</span><span class="row"><button class="btn btn-ghost btn-sm" data-act="al-page" data-d="-1" ${cur === 0 ? 'disabled' : ''}>Oldingi</button><button class="btn btn-ghost btn-sm" data-act="al-page" data-d="1" ${cur >= pages - 1 ? 'disabled' : ''}>Keyingi</button></span></div>` : ''}`;
  } catch (e) { body.innerHTML = errorCard(e); }
}

/* Ogohlantirish tafsiloti (o'ng tomondan chiqadigan oyna) */
let DR = null;
function drawerRoot() { return $('#drawer-root'); }
function closeDrawer(pushHash = true) {
  const b = $('.drawer-back', drawerRoot());
  if (!b) return;
  b.classList.remove('in');
  setTimeout(() => b.remove(), 260);
  DR = null;
  if (pushHash && /^#\/alerts\/\d+/.test(location.hash)) history.replaceState(null, '', '#/alerts');
  if (pushHash) CUR.arg = null;
}
async function openAlert(id) {
  let back = $('.drawer-back', drawerRoot());
  if (!back) {
    back = document.createElement('div');
    back.className = 'drawer-back';
    back.innerHTML = '<aside class="drawer" role="dialog" aria-modal="true"><div class="drawer-bd"><div class="sk" style="height:18px;width:50%"></div><div class="sk" style="height:90px"></div><div class="sk" style="height:90px"></div></div></aside>';
    drawerRoot().appendChild(back);
    requestAnimationFrame(() => back.classList.add('in'));
  }
  CUR.arg = String(id);
  try {
    const a = await api('GET', `/alerts/${id}`);
    if (!$('.drawer-back', drawerRoot())) return;
    DR = a;
    paintDrawer(a);
  } catch (e) {
    $('.drawer', back).innerHTML = `<div class="drawer-bd">${errorCard(e)}</div>`;
  }
}
function paintDrawer(a) {
  const closed = a.status === 'closed';
  const can = ME.can_act;
  const link = a.entity_type === 'machine' ? `<a class="btn btn-ghost btn-sm" href="#/machines/${esc(a.entity_id)}" data-act="dr-nav">${icon('machine', 14)}Uskuna sahifasi</a>`
    : a.entity_type === 'order' ? '<a class="btn btn-ghost btn-sm" href="#/orders" data-act="dr-nav">' + icon('orders', 14) + 'Buyurtmalar</a>'
      : a.entity_type === 'source' ? '<a class="btn btn-ghost btn-sm" href="#/sources" data-act="dr-nav">' + icon('db', 14) + 'Manbalar</a>' : '';
  const stPill = closed ? (a.closed_by === 'tizim' ? '<span class="pill pill-success"><i class="dot"></i>O\'zi yopildi</span>' : `<span class="pill pill-muted">Yopdi: ${esc(a.closed_by || '')}</span>`)
    : a.status === 'open' ? '<span class="pill pill-navy"><i class="dot"></i>Yangi</span>' : '<span class="pill pill-muted">Ko\'rildi</span>';
  const people = (a.people || []).map((p) => `<option value="${esc(p.name)}" ${a.owner === p.name ? 'selected' : ''}>${esc(p.name)} — ${esc(p.role)}</option>`).join('');
  $('.drawer', drawerRoot()).innerHTML = `
    <div class="drawer-hd"><div><div class="dr-meta">${sevPill(a.severity)}<span class="tag tag-slate">${esc(a.family_name)}</span>${stPill}</div><h3>${esc(a.title)}</h3></div><button class="icon-btn sm" data-act="dr-close" aria-label="Yopish">${icon('x', 17)}</button></div>
    <div class="drawer-bd">
      <div class="callout why"><div class="sec-t">Nima bo'lyapti</div>${esc(a.why)}</div>
      ${a.action && !closed ? `<div class="callout act"><div class="sec-t">Nima qilish kerak</div>${esc(a.action)}</div>` : ''}
      ${a.evidence?.length ? `<div><div class="sec-t">Ko'rsatkichlar</div><div class="ev">${a.evidence.map((e) => `<div><span>${esc(e.k)}</span><b title="${esc(e.v)}">${esc(e.v)}</b></div>`).join('')}</div></div>` : ''}
      ${can && !closed ? `<div><div class="sec-t">Mas'ul va izoh</div>
        <select class="input" id="dr-owner" style="margin-bottom:8px"><option value="">Mas'ul tayinlanmagan</option>${people}</select>
        <textarea class="input" id="dr-comment" rows="2" placeholder="Izoh yozing (masalan: «ta'mirchi chaqirildi, 16:00 gacha tugaydi»)"></textarea>
        <div class="row" style="margin-top:8px"><button class="btn btn-ghost btn-sm" data-act="dr-comment">Izohni saqlash</button></div></div>` : (a.owner ? `<div><div class="sec-t">Mas'ul</div>${esc(a.owner)}</div>` : '')}
      <div><div class="sec-t">Tarix</div><ul class="log">${a.log.map((l) => `<li class="k-${esc(l.kind)}"><i></i><div><p>${esc(l.text)}</p><small>${dmhm(l.at)} · ${l.who === 'tizim' ? 'Tizim' : esc(l.who)}</small></div></li>`).join('')}</ul></div>
    </div>
    <div class="drawer-ft">${link}<span class="spacer"></span>
      ${can ? (closed ? '<button class="btn btn-ghost" data-act="dr-reopen">Qayta ochish</button>'
        : `${a.status === 'open' ? '<button class="btn btn-ghost" data-act="dr-seen">Ko\'rdim</button>' : ''}<button class="btn btn-primary" data-act="dr-close-alert">Yopish</button>`) : ''}
    </div>`;
}
async function alertAct(act, body = {}) {
  if (!DR) return;
  try {
    const a = await api('POST', `/alerts/${DR.id}/${act}`, body);
    DR = a; paintDrawer(a);
    if (CUR.name === 'alerts') loadAlerts();
    refreshNav();
    return a;
  } catch (e) { toast(e.message, '', 'error'); }
}

/* ------------------------------------------------------------------ Uskunalar */
async function viewMachines(arg) {
  if (arg) return viewMachine(arg);
  const r = await api('GET', '/machines');
  NOW = r.now;
  const groups = {};
  r.items.forEach((m) => { (groups[m.section] ||= { name: m.section_name, items: [] }).items.push(m); });
  const from = NOW - 24 * 3600;
  const card = (m) => {
    const o = m.order;
    const now = m.state === 'stop' ? `<b class="danger-t">${esc(REASON[m.reason] || 'Sababi yozilmagan')}</b> · ${dur((NOW - m.since) / 60)}dan beri`
      : o ? `Buyurtma <b>${esc(o.no)}</b> · ${esc(o.name)}` : m.state === 'wait' ? `Navbatdagi ish tayyor: <b>${esc(m.next?.no || '')}</b>` : "Hozir ish yo'q";
    return `<div class="card mcard" data-href="#/machines/${m.id}">
      <div class="mcard-hd"><div><h3>${esc(m.name)}</h3><small>${esc(m.section_name)}</small></div><span class="al">${statePill(m.state)}</span></div>
      <div class="mcard-now">${now}${o ? `<span class="meter" style="margin-top:6px"><i class="${m.state === 'stop' ? 'danger' : ''}" style="width:${o.progress}%"></i></span>` : ''}</div>
      <div><div class="strip">${stripInner(m.timeline, from, NOW)}</div><div class="strip-ax"><span>${hm(from)}</span><span>${hm(from + 8 * 3600)}</span><span>${hm(from + 16 * 3600)}</span><span>hozir</span></div></div>
      <div class="mcard-ft"><span>${icon('chart', 13)}Bandlik: <b>${num(m.util_today, 0)}%</b></span><span>${icon('orders', 13)}Navbat: <b>${m.queue}</b> ta${m.queue ? ` · ${num(m.queue_h, 1)} soat` : ''}</span>${m.alert ? `<span style="margin-left:auto">${sevPill(m.alert.worst)}</span>` : ''}</div></div>`;
  };
  $('#page-sub').textContent = `${r.items.length} ta uskuna`;
  return `<div class="legend" style="margin-bottom:14px"><span><i class="lg run"></i>Ishlayapti</span><span><i class="lg setup"></i>Sozlash</span><span><i class="lg stop"></i>To'xtagan</span><span><i class="lg idle"></i>Bo'sh / tanaffus</span><span class="muted">Chiziq — so'nggi 24 soat</span></div>` +
    Object.values(groups).map((g) => `<div class="fam-t">${esc(g.name)}</div><div class="m-grid">${g.items.map(card).join('')}</div>`).join('');
}

async function viewMachine(id) {
  const r = await api('GET', `/machines/${id}`);
  const m = r.machine;
  NOW = NOW || 0;
  const now = (await api('GET', '/me')).now;
  NOW = now;
  const from72 = now - 72 * 3600;
  const maxMin = Math.max(1, ...r.reasons.map((x) => x.min));
  const win = [now - 6 * 3600, now + 30 * 3600];
  const tx = [];
  for (let t = Math.ceil(win[0] / 3600 / 4) * 4 * 3600; t < win[1]; t += 4 * 3600) tx.push(t);
  const ticks = tx.map((t) => `<span style="left:${((t - win[0]) / (win[1] - win[0]) * 100).toFixed(2)}%">${hm(t)}</span>`).join('');
  $('#page-sub').textContent = `${m.section_name}`;
  return `<div class="crumb"><a href="#/machines">Uskunalar</a>${icon('chevronR', 13)}<span>${esc(m.name)}</span></div>
    <div class="det-hd"><h2>${esc(m.name)}</h2>${statePill(m.state)}<span class="tag tag-slate">${esc(m.section_name)}</span>${m.alert ? sevPill(m.alert.worst) : ''}</div>
    <div class="facts">
      <div class="card fact"><span>Hozirgi holat</span><b>${ST[m.state]}</b><small>${m.since ? `${dur((now - m.since) / 60)}dan beri` : ''}${m.state === 'stop' ? ` · ${esc(REASON[m.reason] || '')}` : ''}</small></div>
      <div class="card fact"><span>Bugungi bandlik</span><b>${num(m.util_today, 0)}%</b><small>smena boshidan</small></div>
      <div class="card fact"><span>Navbatdagi ishlar</span><b>${m.queue} ta</b><small>${m.queue ? `jami ${num(m.queue_h, 1)} soat ish` : 'navbat bo\'sh'}</small></div>
      <div class="card fact"><span>Ta'mir (14 kun)</span><b class="${r.repairs14 >= 3 ? 'danger-t' : ''}">${r.repairs14} marta</b><small>${r.stops_total} ta to'xtash</small></div>
    </div>
    <div class="card card-pad" style="margin-bottom:16px"><div class="card-hd"><div><h2>Reja va fakt</h2><p>Yuqori yo'lak — rejadagi ishlar, pastki — haqiqatda nima bo'lgani. Qizil chiziq — hozirgi vaqt.</p></div><div class="legend"><span><i class="lg plan"></i>Reja</span><span><i class="lg run"></i>Ishlagan</span><span><i class="lg setup"></i>Sozlash</span><span><i class="lg stop"></i>To'xtagan</span></div></div>
      <div class="pf-row" style="grid-template-columns:1fr;border:0;padding:0"><div>${lanesHtml(r.plan, r.timeline72, win[0], win[1], now)}<div class="pf-ticks" style="margin-top:6px">${ticks}</div></div></div></div>
    <div class="grid-2" style="margin-bottom:16px">
      <div class="card card-pad"><div class="card-hd"><div><h2>So'nggi 72 soat</h2><p>Uskuna holati soatma-soat.</p></div></div>
        ${stripHtml(r.timeline72, from72, now, 'tall')}<div class="strip-ax"><span>${dmhm(from72)}</span><span>${dmhm(from72 + 24 * 3600)}</span><span>${dmhm(from72 + 48 * 3600)}</span><span>hozir</span></div>
        <div class="sec-t" style="margin-top:20px">Oxirgi 7 kun</div>
        <div class="trend">${barsSvg(r.days.map((d) => Math.round(d.util)), r.days.map((d) => d.label), { W: 520, H: 120, bw: 36, max: 100, fmt: (v) => `${v}%`, fs: 11 })}</div></div>
      <div class="card card-pad"><div class="card-hd"><div><h2>To'xtash sabablari</h2><p>So'nggi 14 kun, daqiqalarda.</p></div></div>
        ${r.reasons.length ? r.reasons.map((x) => `<div class="reason-row"><span>${esc(x.name)}</span><span class="meter"><i class="${x.reason === 'tamir' ? 'danger' : ''}" style="width:${(x.min / maxMin * 100).toFixed(0)}%"></i></span><span class="r">${dur(x.min)}</span></div>`).join('') : empty('check', "To'xtashlar bo'lmagan")}
        ${r.stops.length ? `<div class="sec-t" style="margin-top:18px">Oxirgi to'xtashlar</div>${r.stops.slice(0, 6).map((s) => `<div class="reason-row" style="grid-template-columns:110px minmax(0,1fr) 118px"><span class="muted">${dmhm(s.start)}</span><span>${esc(s.reason_name)}</span><span class="r">${s.end ? dur(s.min) : '<b class="danger-t">davom etmoqda</b>'}</span></div>`).join('')}` : ''}</div>
    </div>
    <div class="grid-2">
      <div class="card"><div class="card-hd" style="padding:16px 20px 0"><h2>Navbatdagi buyurtmalar</h2></div>${r.queue.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Buyurtma</th><th>Reja</th><th>Me'yor</th><th>Muddat</th><th></th></tr></thead><tbody>${r.queue.slice(0, 8).map((q) => `<tr><td class="t"><b>${esc(q.no)}</b><small>${esc(q.name)}</small></td><td class="nowrap">${span(q.plan_start, q.plan_end)}</td><td class="nowrap">${dur(q.norm_min)}</td><td class="nowrap">${q.due_at ? when(q.due_at) : '—'}</td><td>${q.risk ? sevPill(q.risk) : ''}</td></tr>`).join('')}</tbody></table></div>` : empty('inbox', 'Navbatda buyurtma yo\'q')}</div>
      <div class="card card-pad"><div class="card-hd"><h2>Shu uskunadagi ogohlantirishlar</h2></div>${r.alerts.length ? `<ul class="ins-list">${r.alerts.map((a) => `<li class="ins lv-${a.severity}" data-href="#/alerts/${a.id}"><span class="ins-ic">${icon(sevIcon(a.severity), 15)}</span><div class="ins-body"><b>${esc(a.title)}</b><p>${esc(a.rule_name)}</p></div><span class="ins-age">${short(a.age_min)}</span></li>`).join('')}</ul>` : empty('check', "Faol ogohlantirish yo'q")}</div>
    </div>`;
}

/* ------------------------------------------------------------------ Buyurtmalar */
const OR = { only: false, all: false };
async function viewOrders() {
  const r = await api('GET', '/orders');
  const me = await api('GET', '/me');
  NOW = me.now;
  const risk = r.items.filter((o) => o.risk).length;
  $('#nav-orders').textContent = r.items.length ? String(r.items.length) : '';
  $('#page-sub').textContent = `${r.items.length} ta ochiq${risk ? `, ${risk} tasi xavfda` : ''}`;
  const filtered = OR.only ? r.items.filter((o) => o.risk) : r.items;
  const items = OR.all || OR.only ? filtered : filtered.slice(0, 12);
  const step = (o, x, i) => {
    const done = x.status === 'done';
    const stopped = !done && x.machine_state === 'stop';
    const late = !done && x.slip_min >= 30;
    const cls = done ? 'done' : stopped ? 'stopped' : x.status === 'run' ? 'run' : x.status === 'setup' ? 'setup' : 'wait';
    const t = done ? `${hm(x.started_at || x.plan_start)}–${hm(x.ended_at || x.plan_end)}` : span(x.f_start, x.f_end);
    return `<div class="step ${cls} ${late ? 'late' : ''}" title="${esc(`${x.kind_name} · ${x.machine} · reja ${span(x.plan_start, x.plan_end)}`)}"><div class="step-bar"><i></i></div><b>${esc(x.kind_name)}</b><small>${esc(x.machine)}</small><small class="${late ? 'slip' : ''}">${done ? 'tugagan ' : ''}${t}</small>${late ? `<small class="slip">+${short(x.slip_min)} kechikish</small>` : ''}</div>`;
  };
  const card = (o) => {
    const d = o.delta_min;
    const pill = o.risk === 'crit' ? '<span class="pill pill-danger"><i class="dot"></i>Muddatga ulgurmaydi</span>' : o.risk === 'warn' ? '<span class="pill pill-warn"><i class="dot"></i>Zaxira oz</span>' : '<span class="pill pill-success"><i class="dot"></i>Muddatida</span>';
    const dd = o.due_at == null ? '' : d == null ? '' : d > 0 ? `<span class="danger-t">muddatdan ${dur(d)} keyin</span>` : `<span class="success-t">${dur(-d)} zaxira</span>`;
    return `<div class="card ord"><div><h3>${esc(o.no)} ${o.urgent ? '<span class="tag tag-danger">Shoshilinch</span>' : ''}</h3><small>${esc(o.name)}</small><div style="margin-top:8px">${pill}</div></div>
      <div class="steps">${o.ops.map((x, i) => step(o, x, i)).join('')}</div>
      <div class="ord-end"><span>Muddat</span><b>${o.due_at ? when(o.due_at) : 'belgilanmagan'}</b><span>Taxminiy tugash</span><b>${o.forecast_end ? when(o.forecast_end) : '—'}</b><span>${dd}</span></div></div>`;
  };
  const doneBlock = r.done.length ? `<div class="fam-t">Yaqinda tugagan${r.on_time_pct != null ? ` · so'nggi 14 kunda <span style="color:var(--success)">${num(r.on_time_pct, 0)}%</span> muddatida (${r.done_total} ta)` : ''}</div><div class="card card-pad"><div class="done-list">${r.done.map((d) => `<div><b style="min-width:70px">${esc(d.no)}</b><span class="muted" style="flex:1">${esc(d.name)}</span><span class="muted">${dmhm(d.finished_at)}</span>${d.late_min == null ? '' : d.late_min > 0 ? `<span class="pill pill-danger">${dur(d.late_min)} kechikdi</span>` : '<span class="pill pill-success">Muddatida</span>'}</div>`).join('')}</div></div>` : '';
  return `<div class="row" style="margin-bottom:14px"><div class="seg" id="or-seg"><button data-v="0" class="${OR.only ? '' : 'on'}">Hammasi <span class="n">${r.items.length}</span></button><button data-v="1" class="${OR.only ? 'on' : ''}">Faqat xavfdagilar <span class="n">${risk}</span></button></div><span class="spacer"></span><div class="legend"><span><i class="lg run"></i>Bajarilgan / ishlayapti</span><span><i class="lg setup"></i>Sozlanmoqda</span><span><i class="lg stop"></i>Uskuna to'xtagan</span></div></div>
    ${items.length ? items.map(card).join('') : `<div class="card">${empty('check', "Muddatga xavf ostida buyurtma yo'q")}</div>`}${items.length < filtered.length ? `<div style="text-align:center;margin-top:14px"><button class="btn btn-ghost" data-act="or-all">Yana ${filtered.length - items.length} ta buyurtmani ko'rsatish</button></div>` : ''}${doneBlock}`;
}

/* ------------------------------------------------------------------ Reja va fakt */
const PF = { h: 12 };
async function viewPlanFact() {
  const r = await api('GET', `/planfact?before=${PF.h}&after=${PF.h}`);
  NOW = r.now;
  const step = PF.h <= 6 ? 1 : PF.h <= 12 ? 2 : 4;
  const span = r.to - r.from;
  let ticks = '';
  for (let t = Math.ceil(r.from / 3600); t * 3600 < r.to; t++) {
    const hh = ((t * 3600 + TZ) / 3600) % 24;
    if (hh % step) continue;
    ticks += `<span style="left:${(((t * 3600 - r.from) / span) * 100).toFixed(2)}%">${p2(hh)}:00</span>`;
  }
  const nowL = ((r.now - r.from) / span) * 100;
  const rows = r.machines.map((m) => `<div class="pf-row"><div class="pf-name"><span class="dot-s ${m.state}" title="${ST[m.state]}"></span><div>${esc(m.name)}<small>${ST[m.state]}</small></div></div>${lanesHtml(m.plan, m.fact, r.from, r.to, r.now)}<div class="pf-stat">${m.due_today ? `Vaqtida boshlandi: <b>${m.ok_today}/${m.due_today}</b>` : 'Bugun yangi ish boshlanmagan'}${m.late_avg_min != null ? `<br>O'rtacha kechikish: <b>${dur(m.late_avg_min)}</b>` : ''}${m.dur_ratio != null ? `<br>Me'yorga nisbatan: <b class="${m.dur_ratio > 1.15 ? 'danger-t' : ''}">${num(m.dur_ratio, 2)}×</b>` : ''}</div></div>`).join('');
  $('#page-sub').textContent = `${hm(r.from)} dan ${hm(r.to)} gacha`;
  return `<div class="card card-pad"><div class="pf-hd"><div class="seg" id="pf-seg">${[6, 12, 24].map((h) => `<button data-v="${h}" class="${PF.h === h ? 'on' : ''}">±${h} soat</button>`).join('')}</div>
      <div class="legend"><span><i class="lg plan"></i>Reja</span><span><i class="lg run"></i>Ishlagan</span><span><i class="lg setup"></i>Sozlash</span><span><i class="lg stop"></i>To'xtagan</span><span><i class="lg idle"></i>Bo'sh / tanaffus</span><span><i class="lg now"></i>Hozir</span></div></div>
    <div class="pf-axis"><div class="muted xs" style="align-self:end">Uskuna</div><div class="pf-ticks"><span class="pf-nowlbl" style="left:${nowL.toFixed(2)}%;color:var(--danger);font-weight:600;top:-18px">${hm(r.now)}</span>${ticks}</div><div class="muted xs" style="align-self:end;text-align:right">Bugun</div></div>
    <div class="pf-rows">${rows}</div>
    <p class="muted xs" style="margin-top:14px">Har uskunada yuqori yo'lak — Biwork Rejadagi reja, pastki yo'lak — haqiqiy holat. Farq (kechikish, to'xtash) ogohlantirishlarga aylanadi.</p></div>`;
}

/* ------------------------------------------------------------------ Qoidalar */
async function viewRules() {
  const r = await api('GET', '/rules');
  const can = ME.can_admin;
  $('#page-sub').textContent = `${r.rules.filter((x) => x.enabled).length} ta qoida yoqilgan`;
  const byFam = {};
  r.rules.forEach((x) => { (byFam[x.family_name] ||= []).push(x); });
  const ruleCard = (x) => `<div class="card rule ${x.enabled ? '' : 'off'}" data-rule="${x.code}">
    <div><h3>${esc(x.name)} ${x.open ? `<span class="pill pill-warn">${x.open} ta faol</span>` : ''}</h3><p>${esc(x.text)}</p></div>
    <div class="params">${x.params.map((p) => `<label class="param"><span>${esc(p.label)}</span><span class="input-unit"><input class="input" type="number" step="any" min="${p.min}" max="${p.max}" value="${p.value}" data-key="${p.key}" ${can ? '' : 'disabled'}><em>${esc(p.unit)}</em></span></label>`).join('')}</div>
    <div class="rule-ft"><label class="row" style="gap:10px;cursor:pointer"><span class="switch"><input type="checkbox" data-en ${x.enabled ? 'checked' : ''} ${can ? '' : 'disabled'}><i></i></span><span>Qoida yoqilgan</span></label><span class="spacer"></span>${can ? '<button class="btn btn-primary btn-sm" data-act="rule-save">Saqlash</button>' : ''}</div></div>`;
  return `${can ? '' : `<div class="card card-pad" style="margin-bottom:16px;display:flex;gap:10px;align-items:center;color:var(--text-2)">${icon('lock', 16)}<span>${ME.shared_demo ? "Umumiy namunada qoidalar o'zgartirilmaydi. O'z korxonangizda administrator sozlaydi." : "Qoidalarni faqat administrator o'zgartira oladi."}</span></div>`}` +
    Object.entries(byFam).map(([fam, list]) => `<div class="fam-t">${esc(fam)}</div>${list.map(ruleCard).join('')}`).join('') +
    `<div class="fam-t">Umumiy sozlamalar</div><div class="card rule" data-rule="general"><div><h3>Hisob-kitob parametrlari</h3><p>Ogohlantirishlar qanday hisoblanishini boshqaradi.</p></div><div class="params">${r.general.map((p) => `<label class="param"><span>${esc(p.label)}</span><span class="input-unit"><input class="input" type="number" step="any" min="${p.min}" max="${p.max}" value="${p.value}" data-key="${p.key}" ${can ? '' : 'disabled'}><em>${esc(p.unit)}</em></span></label>`).join('')}</div>${can ? '<div class="rule-ft"><span class="spacer"></span><button class="btn btn-primary btn-sm" data-act="rule-save">Saqlash</button></div>' : ''}</div>`;
}

/* ------------------------------------------------------------------ Manbalar */
async function viewSources() {
  const r = await api('GET', '/sources');
  NOW = r.now;
  const ST_SRC = { ok: ['pill-success', 'Yangilanib turibdi'], late: ['pill-warn', 'Kechikmoqda'], bad: ['pill-danger', 'Uzilgan'], none: ['pill-muted', 'Hali kelmagan'] };
  const icn = { erp_fakt: 'server', erp_toxtash: 'server', reja: 'gantt', kunlik: 'sheet' };
  const bad = r.items.filter((x) => x.state !== 'ok').length;
  $('#page-sub').textContent = bad ? `${bad} ta manbada muammo` : 'Hammasi joyida';
  return `<div class="card">${r.items.map((s) => `<div class="src"><span class="src-ic">${icon(icn[s.code] || 'db', 18)}</span><div><h3>${esc(s.name)}</h3><small>${esc(s.kind)}</small></div><div class="r"><b>${s.last_at ? (s.lag_min < 1 ? 'hozirgina' : `${short(s.lag_min)} oldin`) : '—'}</b>${s.last_at ? dmhm(s.last_at) : 'ma\'lumot kelmagan'}</div><div class="r"><span class="pill ${ST_SRC[s.state][0]}"><i class="dot"></i>${ST_SRC[s.state][1]}</span></div></div>`).join('')}</div>
    <div class="card card-pad" style="margin-top:16px"><div class="card-hd"><div><h2>Ma'lumot qayerdan keladi</h2><p>Monitoring o'zi hech narsa kiritishni talab qilmaydi — mavjud tizimlardagi ma'lumotlarni o'qiydi.</p></div></div>
      <div class="ev" style="grid-template-columns:repeat(3,minmax(0,1fr))"><div><span>1. Ishlab chiqarish hisoboti</span><b style="white-space:normal">ERPdan avtomatik yuklanadi (fakt, to'xtashlar)</b></div><div><span>2. Reja</span><b style="white-space:normal">Biwork Rejadan olinadi (rejalashtirilgan ishlar va muddatlar)</b></div><div><span>3. Tekshiruv</span><b style="white-space:normal">Manba kechiksa, tizim o'zi xabar beradi</b></div></div></div>`;
}

/* ------------------------------------------------------------------ namuna soati */
function setClock() {
  if (!ME?.demo) return;
  $('#clock').hidden = false;
  $('#clock-text').textContent = `Namuna soat: ${dm(NOW)} · ${hm(NOW)}`;
}
async function advance(min, label) {
  const b = $('#clock-adv');
  b.classList.add('loading'); b.disabled = true;
  closeClockMenu();
  try {
    const before = (await api('GET', '/alerts?limit=1')).counts;
    const r = await api('POST', '/demo/advance', { minutes: min });
    NOW = r.now;
    const after = (await api('GET', '/alerts?limit=1')).counts;
    const closedNow = Math.max(0, after.closed - before.closed);
    const opened = Math.max(0, after.active - before.active + closedNow);
    const parts = [];
    if (opened) parts.push(`yangi: ${opened}`);
    if (closedNow) parts.push(`yopildi: ${closedNow}`);
    toast(`Soat ${hm(NOW)} ga surildi${label ? ` (${label})` : ''}`, parts.length ? parts.join(' · ') : "O'zgarish yo'q", 'info');
    setClock();
    await route(true);
    if (DR) openAlert(DR.id);
  } catch (e) { toast(e.message, '', 'error'); } finally { b.classList.remove('loading'); b.disabled = false; }
}
function clockMenu() {
  const m = $('#clock-menu');
  const nextMorning = (() => { let t = Math.floor((NOW + TZ) / 86400) * 86400 - TZ + 8 * 3600; if (t <= NOW) t += 86400; return Math.round((t - NOW) / 60); })();
  m.innerHTML = `<button data-min="30">${icon('play', 14)}+30 daqiqa</button><button data-min="240">${icon('play', 14)}+4 soat</button><button data-min="${Math.min(1440, nextMorning)}">${icon('play', 14)}Ertalab 08:00 gacha<small>${dur(nextMorning)}</small></button><hr><button data-reset>${icon('refresh', 14)}Boshidan boshlash</button>`;
}
function closeClockMenu() { const m = $('#clock-menu'); if (m) { m.hidden = true; $('#clock-caret').setAttribute('aria-expanded', 'false'); } }

/* ------------------------------------------------------------------ hodisalar */
function bind() {
  document.addEventListener('click', async (e) => {
    const t = e.target;
    const act = t.closest('[data-act]')?.dataset.act;
    if (!t.closest('.split')) closeClockMenu();
    if (act === 'reload') return route();
    if (act === 'dr-close' || (t.classList?.contains('drawer-back') && !act)) return closeDrawer();
    if (act === 'dr-nav') { closeDrawer(false); return; }
    if (act === 'dr-seen') return alertAct('seen');
    if (act === 'dr-reopen') return alertAct('reopen');
    if (act === 'dr-comment') {
      const text = $('#dr-comment').value.trim();
      if (!text) return toast('Izoh matnini yozing', '', 'error');
      const a = await alertAct('comment', { text });
      if (a) toast('Izoh saqlandi');
      return;
    }
    if (act === 'dr-close-alert') {
      const note = await modal({ title: 'Ogohlantirishni yopish', ok: 'Yopish', body: '<p class="muted" style="margin-bottom:10px">Muammo hal bo\'ldimi yoki e\'tiborsiz qoldirilyaptimi — qisqa izoh yozing (ixtiyoriy). Sabab hali bartaraf bo\'lmagan bo\'lsa, 4 soatdan keyin qayta eslatiladi.</p><textarea class="input" rows="3" placeholder="Masalan: ta\'mir tugadi, ish davom etmoqda"></textarea>' });
      if (note === null) return;
      const a = await alertAct('close', { note: typeof note === 'string' ? note : '' });
      if (a) toast('Ogohlantirish yopildi');
      return;
    }
    if (act === 'or-all') { OR.all = true; return route(true); }
    if (act === 'al-page') { AL.offset = Math.max(0, AL.offset + 30 * +t.closest('[data-act]').dataset.d); loadAlerts(); return; }
    if (act === 'rule-save') return saveRule(t.closest('[data-rule]'), t.closest('button'));

    const seg = t.closest('#al-status button, #al-sev button, #or-seg button, #pf-seg button');
    if (seg) {
      const v = seg.dataset.v, id = seg.parentElement.id;
      if (id === 'al-status') { AL.status = v; AL.offset = 0; syncAlertToolbar(); loadAlerts(); }
      else if (id === 'al-sev') { AL.severity = v; AL.offset = 0; syncAlertToolbar(); loadAlerts(); }
      else if (id === 'or-seg') { OR.only = v === '1'; route(true); }
      else if (id === 'pf-seg') { PF.h = +v; route(true); }
      return;
    }
    const row = t.closest('#al-body tr[data-id]');
    if (row) { go(`#/alerts/${row.dataset.id}`); return; }
    const href = t.closest('[data-href]');
    if (href && !t.closest('a,button')) return go(href.dataset.href);

    if (t.closest('#clock-adv')) return advance(60);
    if (t.closest('#clock-caret')) {
      const m = $('#clock-menu');
      if (m.hidden) { clockMenu(); m.hidden = false; $('#clock-caret').setAttribute('aria-expanded', 'true'); } else closeClockMenu();
      return;
    }
    const mb = t.closest('#clock-menu button');
    if (mb) {
      if (mb.dataset.min) return advance(+mb.dataset.min);
      if (mb.hasAttribute('data-reset')) {
        closeClockMenu();
        const ok = await modal({ title: 'Boshidan boshlash', ok: 'Boshidan boshlash', body: '<p class="muted">Namuna korxona dastlabki holatga qaytadi: soat, ogohlantirishlar va izohlar tozalanadi.</p>' });
        if (ok === null) return;
        try { await api('POST', '/demo/reset'); const me = await api('GET', '/me'); NOW = me.now; setClock(); toast('Namuna dastlabki holatga qaytdi', '', 'info'); closeDrawer(false); AL.status = 'active'; AL.severity = ''; AL.family = ''; AL.q = ''; AL.offset = 0; go('#/'); await route(); } catch (er) { toast(er.message, '', 'error'); }
      }
      return;
    }
    if (t.closest('#theme-btn')) {
      const dark = document.documentElement.getAttribute('data-theme') !== 'dark';
      document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
      try { localStorage.setItem('mon-theme', dark ? 'dark' : 'light'); } catch (er) { /* ixtiyoriy */ }
      return;
    }
    if (t.closest('#sb-collapse')) {
      const c = document.documentElement.classList.toggle('sb-collapsed');
      try { localStorage.setItem('mon-sidebar', c ? 'collapsed' : 'open'); } catch (er) { /* ixtiyoriy */ }
      return;
    }
    if (t.closest('#tb-menu')) return document.body.classList.toggle('sb-open');
    if (t.closest('#sb-scrim')) return document.body.classList.remove('sb-open');
    if (t.closest('#sb-logout')) { try { await api('POST', '/logout'); } catch (er) { /* chiqib ketamiz */ } location.href = `${BASE}/login.php`; }
  });
  document.addEventListener('change', (e) => {
    const t = e.target;
    if (t.id === 'al-fam') { AL.family = t.value; AL.offset = 0; loadAlerts(); }
    else if (t.id === 'dr-owner') alertAct('assign', { owner: t.value }).then((a) => a && toast(t.value ? `Mas'ul: ${t.value}` : "Mas'ul olib tashlandi"));
    else if (t.matches('[data-en]')) t.closest('.rule')?.classList.toggle('off', !t.checked);
  });
  let qt = null;
  document.addEventListener('input', (e) => {
    if (e.target.id === 'al-q') { clearTimeout(qt); qt = setTimeout(() => { AL.q = e.target.value.trim(); AL.offset = 0; loadAlerts(); }, 250); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && $('.drawer-back', drawerRoot()) && !$('.modal-back')) closeDrawer();
  });
  window.addEventListener('hashchange', () => route());
}

async function saveRule(card, btn) {
  const code = card.dataset.rule;
  const params = {};
  $$('input[data-key]', card).forEach((i) => { params[i.dataset.key] = i.value === '' ? null : +i.value; });
  const en = $('[data-en]', card);
  btn.classList.add('loading'); btn.disabled = true;
  try {
    await api('PUT', `/rules/${code}`, { enabled: en ? en.checked : undefined, params });
    toast('Saqlandi', 'Ogohlantirishlar yangi chegaralar bilan qayta hisoblandi.');
    await route(true);
    refreshNav();
  } catch (e) { toast(e.message, '', 'error'); } finally { btn.classList.remove('loading'); btn.disabled = false; }
}

/* ------------------------------------------------------------------ boshlash */
async function init() {
  fillIcons();
  try {
    ME = await api('GET', '/me');
  } catch (e) {
    $('#app').innerHTML = errorCard(e);
    window.__monBoot = true;
    return;
  }
  CSRF = ME.csrf; NOW = ME.now;
  const u = ME.user || { name: '?', role: 'user' };
  $('#sb-user').innerHTML = `<span class="sb-av">${esc((u.name || '?').trim().charAt(0).toUpperCase())}</span><div class="sb-uinfo"><b>${esc(u.name)}</b><span>${u.role === 'admin' ? 'Administrator' : 'Foydalanuvchi'}</span></div>`;
  if (ME.portal_url) { const p = $('#sb-portal'); p.href = ME.portal_url; p.hidden = false; } else if (ME.user && ME.user.login) { $('#sb-logout').hidden = false; }
  if (!ME.demo) $('#live-chip').hidden = false;
  setClock();
  bind();
  window.__monBoot = true;
  await route();
  if (!ME.demo) setInterval(() => { if (!document.hidden && !$('.drawer-back') && !$('.modal-back')) route(true); }, 60000);
}
init();
