<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
$base = Util::basePath();
if (isset($_GET['bw_launch']) && Biwork::ssoEnabled()) {
    Biwork::handleLaunch(); // Biwork portalidan "Ochish": tokenni tekshirib sessiya ochadi
}
$auth = Auth::state();
if ($auth === 'login' || $auth === 'setup') {
    header('Location: ' . $base . '/login.php', true, 302);
    exit;
}
if (($gate = Biwork::gate()) !== null) {
    Biwork::blockPage($gate);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
Auth::pageHeaders();
$b = Util::e($base);
$v = MON_VERSION;
$warn = Biwork::warning();
?><!doctype html>
<html lang="uz" data-base="<?= $b ?>" data-ver="<?= $v ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Biwork Monitoring</title>
<link rel="icon" type="image/svg+xml" href="<?= $b ?>/assets/favicon.svg">
<script>
  (function () {
    try {
      var t = localStorage.getItem('mon-theme');
      document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
      if (localStorage.getItem('mon-sidebar') === 'collapsed') document.documentElement.classList.add('sb-collapsed');
    } catch (e) { document.documentElement.setAttribute('data-theme', 'light'); }
  })();
</script>
<link rel="preload" href="<?= $b ?>/assets/fonts/Inter-Regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $b ?>/assets/app.css?v=<?= $v ?>">
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">
      <a href="#/" class="sb-logo" title="Biwork Monitoring"><img src="<?= $b ?>/assets/biwork-mark.png" width="32" height="32" alt="Biwork"></a>
      <div class="sb-title"><img class="sb-word" src="<?= $b ?>/assets/biwork-word-white.png" width="70" height="18" alt="biwork"><span id="sb-sub">Monitoring · Namuna korxona</span></div>
    </div>
    <nav class="sb-nav" aria-label="Asosiy menyu">
      <a href="#/" data-nav="home"><i data-icon="home"></i><span>Bosh sahifa</span></a>
      <a href="#/alerts" data-nav="alerts"><i data-icon="bell"></i><span>Ogohlantirishlar</span><em class="sb-count sb-p1" id="nav-alerts"></em></a>
      <a href="#/machines" data-nav="machines"><i data-icon="machine"></i><span>Uskunalar</span></a>
      <a href="#/orders" data-nav="orders"><i data-icon="orders"></i><span>Buyurtmalar</span><em class="sb-count" id="nav-orders"></em></a>
      <a href="#/planfact" data-nav="planfact"><i data-icon="gantt"></i><span>Reja va fakt</span></a>
      <div class="sb-grp">Sozlash</div>
      <a href="#/rules" data-nav="rules"><i data-icon="sliders"></i><span>Qoidalar</span></a>
      <a href="#/sources" data-nav="sources"><i data-icon="db"></i><span>Ma'lumot manbalari</span><em class="sb-count" id="nav-sources"></em></a>
    </nav>
    <div class="sb-foot">
      <div class="sb-urow">
        <div class="sb-user" id="sb-user"><span class="sb-av">?</span><div class="sb-uinfo"><b></b><span></span></div></div>
        <button class="sb-collapse sb-cl" id="sb-collapse" title="Panelni yig'ish / yoyish"><i data-icon="collapse"></i></button>
      </div>
      <button class="sb-collapse" id="sb-logout" hidden><i data-icon="logout"></i><span>Chiqish</span></button>
      <a class="sb-collapse" id="sb-portal" href="#" hidden><i data-icon="back"></i><span>Biwork portaliga qaytish</span></a>
    </div>
  </aside>
  <div class="main-col">
    <header class="topbar">
      <button class="icon-btn tb-menu" id="tb-menu" aria-label="Menyu"><i data-icon="menu"></i></button>
      <div class="tb-title"><h1 id="page-title">Bosh sahifa</h1><span id="page-sub" class="tb-sub"></span></div>
      <div class="tb-right">
        <div class="clock" id="clock" hidden>
          <span class="chip chip-clock" id="clock-chip"><i data-icon="clock"></i><span id="clock-text">—</span></span>
          <div class="split" id="clock-split">
            <button class="btn btn-primary split-main" id="clock-adv" title="Namuna korxona soatini 1 soatga suradi"><i data-icon="play"></i><span>+1 soat</span></button><button class="btn btn-primary split-caret" id="clock-caret" aria-haspopup="menu" aria-expanded="false" aria-label="Boshqa qadamlar"><i data-icon="chevronD"></i></button>
            <div class="split-menu" id="clock-menu" role="menu" hidden></div>
          </div>
        </div>
        <span class="chip chip-live" id="live-chip" hidden><i class="dot"></i><span>Jonli</span></span>
        <button class="icon-btn theme-btn" id="theme-btn" aria-label="Mavzuni almashtirish" title="Yorug' / qorong'i mavzu"><i data-icon="moon" class="ti-moon"></i><i data-icon="sun" class="ti-sun"></i></button>
      </div>
    </header>
<?php if ($warn !== null): ?>
    <div class="banner"><i data-icon="shield"></i><span><?= Util::e($warn) ?></span><?php if (Auth::can('admin')): ?><a href="<?= $b ?>/license.php">Litsenziya</a><?php endif; ?></div>
<?php endif; ?>
    <main id="app" tabindex="-1"></main>
  </div>
</div>
<div class="sb-scrim" id="sb-scrim"></div>
<div id="drawer-root"></div>
<div id="toasts" aria-live="polite"></div>
<script>
(function () {
  var errs = [];
  addEventListener('error', function (e) { errs.push((e && e.message) || 'yuklanmadi: ' + ((e.target && (e.target.src || e.target.href)) || '')); }, true);
  setTimeout(function () {
    if (window.__monBoot) return;
    var d = document.createElement('div');
    d.style.cssText = 'position:fixed;left:50%;top:70px;transform:translateX(-50%);z-index:9999;max-width:560px;padding:14px 18px;background:#fff3cd;border:1px solid #e0b43c;border-radius:10px;font:14px/1.5 system-ui,sans-serif;color:#4a3b00';
    d.textContent = "Sahifa to'liq yuklanmadi. Ctrl+F5 bosing." + (errs.length ? ' [' + errs[0] + ']' : '');
    document.body.appendChild(d);
  }, 6000);
})();
</script>
<script type="module" src="<?= $b ?>/assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
