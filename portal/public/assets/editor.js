/* Admin: mahsulot formasi (qo'shish, tartiblash, hisoblagich, oldindan ko'rish). Bu fayl bo'lmasa ham forma saqlanadi. */
(function () {
  'use strict';
  var form = document.getElementById('ed');
  if (!form) return;
  var dirty = false, busy = false;

  function $all(sel, root) { return Array.prototype.slice.call((root || form).querySelectorAll(sel)); }

  // ---------------------------------------------------------------- takrorlanadigan qatorlar
  function rowsOf(rep) { return $all('.ed-rows > [data-row]', rep); }
  function syncAdd(rep) {
    var max = parseInt(rep.getAttribute('data-max') || '0', 10);
    var add = rep.querySelector('[data-rep-add]');
    if (add && max) add.disabled = rowsOf(rep).length >= max;
  }
  function isEmptyRow(row) {
    return $all('input[type=text],input:not([type]),textarea', row).every(function (f) { return f.value.trim() === ''; });
  }
  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-rep-add]');
    if (add && form.contains(add)) {
      var rep = add.closest('[data-rep]');
      var tpl = rep.querySelector('template');
      var n = parseInt(rep.getAttribute('data-next') || '0', 10);
      rep.setAttribute('data-next', String(n + 1));
      var host = document.createElement('div');
      host.innerHTML = tpl.innerHTML.replace(/__i__/g, String(n));
      var row = host.firstElementChild;
      rep.querySelector('.ed-rows').appendChild(row);
      syncAdd(rep);
      var first = row.querySelector('input,textarea');
      if (first) first.focus();
      dirty = true;
      return;
    }
    var act = e.target.closest('[data-act]');
    if (!act || !form.contains(act)) return;
    var row2 = act.closest('[data-row]');
    var rep2 = act.closest('[data-rep]');
    if (!row2 || !rep2) return;
    var kind = act.getAttribute('data-act');
    if (kind === 'up' && row2.previousElementSibling) row2.parentNode.insertBefore(row2, row2.previousElementSibling);
    else if (kind === 'down' && row2.nextElementSibling) row2.parentNode.insertBefore(row2.nextElementSibling, row2);
    else if (kind === 'del') {
      if (!isEmptyRow(row2) && !window.confirm("Bu qator o'chirilsinmi?")) return;
      if (rowsOf(rep2).length <= 1) { $all('input,textarea,select', row2).forEach(function (f) { if (f.type !== 'hidden') f.value = ''; }); }
      else row2.parentNode.removeChild(row2);
      syncAdd(rep2);
    } else return;
    dirty = true;
    act.focus();
  });
  $all('[data-rep]').forEach(syncAdd);

  // rasmni o'chirish belgisi
  form.addEventListener('change', function (e) {
    var cb = e.target.closest('.ed-rm input');
    if (cb) cb.closest('.ed-row').classList.toggle('removed', cb.checked);
  });

  // ---------------------------------------------------------------- belgilar hisoblagichi
  $all('[data-count]').forEach(function (f) {
    var max = parseInt(f.getAttribute('maxlength') || '0', 10);
    if (!max) return;
    var c = document.createElement('div');
    c.className = 'ed-cnt';
    f.insertAdjacentElement('afterend', c);
    var upd = function () { c.textContent = f.value.length + ' / ' + max; c.classList.toggle('near', f.value.length > max * 0.9); };
    f.addEventListener('input', upd); upd();
  });

  // ---------------------------------------------------------------- belgi (ikonka) ko'rinishi
  var sel = form.querySelector('[data-icon]');
  var prev = form.querySelector('[data-icon-prev]');
  if (sel && prev) sel.addEventListener('change', function () {
    var o = sel.options[sel.selectedIndex];
    prev.innerHTML = o ? (o.getAttribute('data-svg') || '') : '';
  });

  // ---------------------------------------------------------------- nomdan sayt manzili va kod
  var CYR = {а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'yo',ж:'j',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'x',ц:'ts',ч:'ch',ш:'sh',щ:'sh',ъ:'',ы:'i',ь:'',э:'e',ю:'yu',я:'ya',ў:'o',қ:'q',ғ:'g',ҳ:'h'};
  function slugify(name) {
    var s = name.toLowerCase().replace(/['‘’ʻʼ`]/g, '').replace(/[Ѐ-ӿ]/g, function (ch) { return CYR[ch] !== undefined ? CYR[ch] : ''; });
    s = s.replace(/^biwork[\s-]+/, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30).replace(/-+$/, '');
    return s.length >= 2 ? s : 'mahsulot';
  }
  var nameF = form.querySelector('[data-name]'), slugF = form.querySelector('[data-slug]'), codeF = form.querySelector('[data-code]');
  function curSlug() { return slugF ? (slugF.value.trim().toLowerCase() || slugF.placeholder) : ''; }
  function autoIds() {
    if (!nameF) return;
    if (slugF) slugF.placeholder = slugify(nameF.value);
    if (codeF) {
      var l = (curSlug() || '').replace(/[^a-z0-9]/g, '').toUpperCase();
      codeF.placeholder = (l + 'XXX').slice(0, 3);
    }
  }
  if (nameF && slugF) { nameF.addEventListener('input', autoIds); slugF.addEventListener('input', autoIds); autoIds(); }

  // ---------------------------------------------------------------- Google ko'rinishi
  var sT = form.querySelector('[data-serp-title]'), sD = form.querySelector('[data-serp-desc]'), sS = form.querySelector('[data-serp-slug]');
  function serp() {
    if (!sT) return;
    var name = (nameF ? nameF.value.trim() : ''), tag = (form.elements.tagline ? form.elements.tagline.value.trim() : '');
    var st = form.elements['seo[title]'] ? form.elements['seo[title]'].value.trim() : '';
    var sd = form.elements['seo[description]'] ? form.elements['seo[description]'].value.trim() : '';
    var desc = (form.elements.description ? form.elements.description.value.trim().split(/\n{2,}/)[0] : '');
    sT.textContent = st || ((name || 'Mahsulot nomi') + ' — ' + (tag ? tag.charAt(0).toLowerCase() + tag.slice(1) : 'Biwork mahsuloti'));
    sD.textContent = (sd || desc || tag || 'Qisqa tavsif shu yerda chiqadi').slice(0, 170);
    if (sS && (slugF || sS.textContent === '')) sS.textContent = curSlug() || '…';
  }
  ['name', 'tagline', 'description', 'seo[title]', 'seo[description]', 'slug'].forEach(function (n) {
    var f = form.elements[n]; if (f) f.addEventListener('input', serp);
  });
  serp();

  // ---------------------------------------------------------------- yangi rasmlar ko'rinishi
  var nf = form.querySelector('[data-newshots]'), np = form.querySelector('[data-newprev]');
  if (nf && np) nf.addEventListener('change', function () {
    np.innerHTML = '';
    var maxMb = parseInt(nf.getAttribute('data-maxmb') || '12', 10), postMax = parseInt(nf.getAttribute('data-postmax') || '0', 10), total = 0, bad = [];
    Array.prototype.forEach.call(nf.files, function (f) {
      total += f.size;
      var ok = /^image\/(png|jpeg|webp)$/.test(f.type) && f.size <= maxMb * 1048576;
      if (!ok) bad.push(f.name);
      var box = document.createElement('div'); box.className = 'ed-np' + (ok ? '' : ' bad');
      if (ok) { var im = document.createElement('img'); im.alt = ''; im.src = URL.createObjectURL(f); im.onload = function () { URL.revokeObjectURL(im.src); }; box.appendChild(im); }
      var cap = document.createElement('span'); cap.textContent = f.name + (ok ? '' : " — yuklab bo'lmaydi"); box.appendChild(cap);
      np.appendChild(box);
    });
    var msg = '';
    if (bad.length) msg += 'Bu fayllar yuklanmaydi (faqat PNG, JPG, WEBP va ' + maxMb + ' MB gacha): ' + bad.join(', ') + '. ';
    if (postMax && total > postMax * 0.9) msg += 'Rasmlar jami hajmi serverning bir martalik chegarasiga (' + nf.getAttribute('data-postlabel') + ') yaqin: kamroq rasm tanlang yoki ularni kichraytiring. ';
    if (msg) { var w = document.createElement('div'); w.className = 'flash err'; w.style.gridColumn = '1 / -1'; w.textContent = msg; np.insertBefore(w, np.firstChild); }
    if (nf.files.length) dirty = true;
  });

  // ---------------------------------------------------------------- yuborish
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('click', function (e) {
    var b = e.target.closest('button[type=submit], [data-confirm-btn]');
    if (!b) return;
    var q = b.getAttribute('data-confirm-btn');
    if (q && !window.confirm(q)) { e.preventDefault(); return; }
    if (b.hasAttribute('data-preview')) {
      // oldindan ko'rishda rasm fayllari yuborilmaydi (tez bo'lsin, serverning hajm chegarasiga urilmasin)
      var files = $all('input[type=file]');
      files.forEach(function (f) { f.disabled = true; });
      setTimeout(function () { files.forEach(function (f) { f.disabled = false; }); }, 60);
    }
  });
  form.addEventListener('submit', function (e) {
    var s = e.submitter;
    if (s && s.hasAttribute('data-preview')) return;
    if (busy) { e.preventDefault(); return; }
    busy = true; dirty = false;
    if (s) s.classList.add('is-busy');
    setTimeout(function () { busy = false; }, 20000);
  });
  window.addEventListener('pageshow', function () { busy = false; $all('.is-busy').forEach(function (b) { b.classList.remove('is-busy'); }); });
  window.addEventListener('beforeunload', function (e) {
    if (!dirty || busy) return;
    e.preventDefault(); e.returnValue = '';
  });
})();
