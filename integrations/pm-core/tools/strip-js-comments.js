// Brauzerga beriladigan JS fayllardan izohlarni olib tashlaydi (ichki izohlarda haqiqiy tashkilot nomlari va ish yozishmalari bor —
// ochiq demo/mijoz paketiga chiqmasin). acorn bilan: satr/regex/shablon ichidagi "//" tegilmaydi. Qator raqamlari va CRLF saqlanadi.
//   node strip-js-comments.js <papka-yoki-fayl> ...    (NODE_PATH ichida acorn bo'lishi kerak)
const fs = require('fs'), path = require('path');
let acorn;
try { acorn = require('acorn'); } catch (e) { console.error('acorn topilmadi (NODE_PATH=/opt/node-tools/node_modules) — izohlar olib tashlanmadi'); process.exit(3); }

function parse(src, comments) {
  const base = { ecmaVersion: 'latest', onComment: comments, allowHashBang: true, allowAwaitOutsideFunction: true, allowReturnOutsideFunction: true };
  try { acorn.parse(src, { ...base, sourceType: 'module' }); return true; } catch (e1) {
    comments.length = 0;
    try { acorn.parse(src, { ...base, sourceType: 'script' }); return true; } catch (e2) { return e2; }
  }
}
function strip(src) {
  const comments = [];
  const ok = parse(src, comments);
  if (ok !== true) return { err: ok };
  let out = '', pos = 0;
  for (const c of comments) {
    out += src.slice(pos, c.start);
    const body = src.slice(c.start, c.end);
    const nl = (body.match(/\r\n|\n|\r/g) || []);
    out += c.type === 'Block' ? (nl.length ? nl.join('') : ' ') : '';
    pos = c.end;
  }
  out += src.slice(pos);
  return { out, removed: src.length - out.length };
}
/** CSS: /* ... *\/ izohlar (qo'shtirnoq ichidagilar tegilmaydi). */
function stripCss(src) {
  let out = '', i = 0, q = '';
  while (i < src.length) {
    const ch = src[i];
    if (q) { out += ch; if (ch === '\\') { out += src[i + 1] || ''; i += 2; continue; } if (ch === q) q = ''; i++; continue; }
    if (ch === '"' || ch === "'") { q = ch; out += ch; i++; continue; }
    if (ch === '/' && src[i + 1] === '*') {
      const e = src.indexOf('*/', i + 2);
      const end = e < 0 ? src.length : e + 2;
      const nl = src.slice(i, end).match(/\r\n|\n|\r/g) || [];
      out += nl.length ? nl.join('') : ' ';
      i = end; continue;
    }
    out += ch; i++;
  }
  return { out, removed: src.length - out.length };
}
function walk(p, acc) {
  const st = fs.statSync(p);
  if (st.isDirectory()) for (const f of fs.readdirSync(p)) walk(path.join(p, f), acc);
  else if (/\.(js|css)$/.test(p)) acc.push(p);
  return acc;
}
let total = 0, bad = 0;
for (const arg of process.argv.slice(2)) for (const f of walk(arg, [])) {
  const src = fs.readFileSync(f, 'utf8');
  const r = /\.css$/.test(f) ? stripCss(src) : strip(src);
  if (r.err) { console.error('o\'tkazib yuborildi (parse xato):', f, r.err.message); bad++; continue; }
  fs.writeFileSync(f, r.out); total += r.removed;
}
console.log(`izohlar olib tashlandi: ${Math.round(total / 1024)} KB` + (bad ? `, ${bad} fayl o'tkazib yuborildi` : ''));
