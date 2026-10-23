(function () {
  'use strict';
  var root = document.documentElement;

  // mavzu
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-theme-toggle]');
    if (t) {
      var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('bw-theme', next); } catch (err) { /* private mode */ }
      return;
    }
    // kalitni nusxalash
    var c = e.target.closest('[data-copy]');
    if (c) {
      var txt = c.getAttribute('data-copy');
      var done = function () { var o = c.textContent; c.textContent = 'Nusxalandi'; setTimeout(function () { c.textContent = o; }, 1400); };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done, function () { fallback(txt, done); }); } else { fallback(txt, done); }
      return;
    }
    // yon menyu (telefon)
    if (e.target.closest('#burger')) { document.body.classList.toggle('nav-open'); return; }
    if (document.body.classList.contains('nav-open') && !e.target.closest('#side')) { document.body.classList.remove('nav-open'); }
  });
  function fallback(txt, done) {
    var ta = document.createElement('textarea'); ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (err) { /* nusxalab bo'lmadi */ }
    document.body.removeChild(ta);
  }

  // ekran rasmini kattalashtirish: <a href="katta-rasm" data-zoom data-caption="...">
  var lb = null;
  function closeLb() { if (lb) { lb.remove(); lb = null; document.removeEventListener('keydown', onKey); } }
  function onKey(e) { if (e.key === 'Escape') closeLb(); }
  document.addEventListener('click', function (e) {
    var z = e.target.closest('a[data-zoom]');
    if (!z || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    closeLb();
    lb = document.createElement('div'); lb.className = 'lb'; lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-modal', 'true');
    var img = document.createElement('img'); img.src = z.getAttribute('href'); img.alt = (z.querySelector('img') || {}).alt || '';
    var btn = document.createElement('button'); btn.type = 'button'; btn.setAttribute('aria-label', 'Yopish'); btn.textContent = '×'; btn.style.fontSize = '24px';
    lb.appendChild(img);
    var cap = z.getAttribute('data-caption');
    if (cap) { var c = document.createElement('div'); c.className = 'cap'; c.textContent = cap; lb.appendChild(c); }
    lb.appendChild(btn);
    lb.addEventListener('click', closeLb);
    document.body.appendChild(lb); btn.focus();
    document.addEventListener('keydown', onKey);
  });

  // tasdiqlash: <form data-confirm="Rostdan ham?">
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-confirm]');
    if (f && !window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
  });

  // ikki marta yuborishdan saqlash
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f.matches('form[data-once]')) return;
    var b = f.querySelector('button[type=submit]');
    if (b) { setTimeout(function () { b.disabled = true; }, 0); }
  });

  // PWA: xizmat ishchisi
  if ('serviceWorker' in navigator && location.protocol.indexOf('http') === 0) {
    var base = root.getAttribute('data-base') || '';
    navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(function () { /* ixtiyoriy */ });
  }
})();
