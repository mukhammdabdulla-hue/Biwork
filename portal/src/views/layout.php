<?php
/** @var string $content @var array $o @var ?array $user @var array $flash */
$b = e(Util::basePath());
$u = fn(string $p) => e(Util::url($p));
$area = $o['area'];
$isStaff = $user !== null && $user['role'] === 'staff';
$navItems = [];
if ($area === 'app') {
    $navItems = [
        ['', [['app', '/app', 'grid', 'Mening mahsulotlarim'], ['catalog', '/mahsulotlar', 'tool', 'Mahsulotlar katalogi']]],
        ['Korxona', array_values(array_filter([
            $user && $user['role'] === 'owner' ? ['team', '/app/team', 'users', 'Jamoa'] : null,
            ['profile', '/app/profile', 'user', 'Profil'],
        ]))],
    ];
} elseif ($area === 'admin') {
    $newReq = (int)Db::value("SELECT COUNT(*) FROM purchase_requests WHERE status = 'new'");
    $newLead = Leads::newCount();
    $navItems = [
        ['', [['dash', '/admin', 'chart', 'Boshqaruv'], ['leads', '/admin/leads', 'megaphone', "Murojaatlar" . ($newLead ? '|' . $newLead : '')], ['requests', '/admin/requests', 'inbox', "Xarid so'rovlari" . ($newReq ? '|' . $newReq : '')]]],
        ['Sotuv', [['licenses', '/admin/licenses', 'key', 'Litsenziyalar'], ['customers', '/admin/customers', 'building', 'Korxonalar']]],
        ['Katalog', [['tools', '/admin/tools', 'tool', 'Mahsulotlar'], ['events', '/admin/events', 'list', 'Jurnal']]],
        ['Hisob', [['profile', '/admin/profile', 'user', 'Profil']]],
    ];
}
?><!doctype html>
<html lang="uz" data-base="<?= $b ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#041C31">
<?php
$seo = $o['seo'] ?? [];
$index = $area === 'public' && $seo && empty($seo['noindex']);
$ttl = ($seo['title'] ?? '') !== '' ? $seo['title'] : $o['title'] . ' · Biwork';
$desc = (string)($seo['description'] ?? '');
$canon = (string)($seo['canonical'] ?? '');
$img = (string)($seo['image'] ?? '');
$asset = static fn(string $f): string => Util::url('/assets/' . $f) . '?v=' . substr((string)@filemtime(BW_ROOT . '/public/assets/' . $f), -6);
?>
<title><?= e($ttl) ?></title>
<?php if ($desc !== ''): ?><meta name="description" content="<?= e($desc) ?>">
<?php endif; ?>
<?php if (!empty($seo['keywords'])): ?><meta name="keywords" content="<?= e($seo['keywords']) ?>">
<?php endif; ?>
<meta name="robots" content="<?= $index ? 'index,follow,max-image-preview:large' : 'noindex,nofollow' ?>">
<?php if ($index && $canon !== ''): ?>
<link rel="canonical" href="<?= e($canon) ?>">
<meta property="og:type" content="<?= e($seo['type'] ?? 'website') ?>">
<meta property="og:site_name" content="Biwork">
<meta property="og:locale" content="uz_UZ">
<meta property="og:title" content="<?= e($ttl) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canon) ?>">
<?php if ($img !== ''): ?><meta property="og:image" content="<?= e($img) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= e($o['title']) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($ttl) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<?php if ($img !== ''): ?><meta name="twitter:image" content="<?= e($img) ?>">
<?php endif; ?>
<?php foreach ($seo['jsonld'] ?? [] as $ld): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endforeach; ?>
<?php endif; ?>
<link rel="icon" type="image/svg+xml" href="<?= $b ?>/assets/favicon.svg">
<link rel="manifest" href="<?= $b ?>/manifest.webmanifest">
<link rel="apple-touch-icon" href="<?= $b ?>/assets/icon-192.png">
<script>
  (function () {
    try {
      var t = localStorage.getItem('bw-theme');
      if (t !== 'dark' && t !== 'light') t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', t);
    } catch (e) { document.documentElement.setAttribute('data-theme', 'light'); }
  })();
</script>
<link rel="stylesheet" href="<?= e($asset('portal.css')) ?>">
<?php if ($area === 'public'): ?><link rel="stylesheet" href="<?= e($asset('site.css')) ?>"><?php endif; ?>
<?php if ($area === 'public' && is_string($trk = Config::raw('tracking_html', '')) && $trk !== '') { echo $trk, "\n"; } ?>
</head>
<body class="area-<?= e($area) ?>">
<?php if ($area === 'public'): ?>
<a class="skip" href="#asos">Asosiy qismga o'tish</a>
<header class="pub-top"><div class="in-w">
  <a href="<?= $u('/') ?>" aria-label="Biwork — bosh sahifa"><img class="logo logo-l" src="<?= $b ?>/assets/biwork-logo.png" alt="Biwork" width="154" height="40"><img class="logo logo-d" src="<?= $b ?>/assets/biwork-logo-white.png" alt="" width="154" height="40"></a>
  <nav aria-label="Asosiy">
    <a href="<?= $u('/mahsulotlar') ?>">Mahsulotlar</a>
    <a href="<?= $u('/#qollanmalar') ?>">Qanday ishlatiladi</a>
    <a href="<?= $u('/#sorov') ?>">Aloqa</a>
  </nav>
  <button class="icon-btn" type="button" data-theme-toggle aria-label="Mavzuni almashtirish"><?= Ui::icon('moon') ?></button>
  <?php if ($user): ?>
    <a class="btn btn-primary" href="<?= $u($isStaff ? '/admin' : '/app') ?>"><?= $isStaff ? 'Boshqaruv' : 'Mening mahsulotlarim' ?></a>
  <?php else: ?>
    <a class="btn btn-ghost" href="<?= $u('/login') ?>">Kirish</a>
    <a class="btn btn-primary" href="<?= $u('/register') ?>">Sinab ko'rish</a>
  <?php endif; ?>
</div></header>
<?php foreach ($flash as [$k, $m]): ?><div class="wrap" style="padding-top:16px"><div class="flash <?= e($k) ?>"><?= e($m) ?></div></div><?php endforeach; ?>
<main id="asos" data-accent="emerald"><?= $content ?></main>
<?php
$contact = Config::raw('contact', []);
$contact = is_array($contact) ? $contact : [];
$footTools = array_slice(Catalog::tools(), 0, 8);
?>
<footer class="pub-foot"><div class="wrap">
  <div class="foot-grid">
    <div>
      <img class="logo-l" src="<?= $b ?>/assets/biwork-logo.png" alt="Biwork" width="124" height="32"><img class="logo-d" src="<?= $b ?>/assets/biwork-logo-white.png" alt="" width="124" height="32">
      <p class="muted">Korxonalar uchun tayyor avtomatlashtirish dasturlari. Brauzerda sinab ko'ring, litsenziya olib o'z korxonangizda ishlating.</p>
    </div>
    <div>
      <b>Mahsulotlar</b>
      <?php foreach ($footTools as $ft): ?><a href="<?= $u('/' . $ft['slug']) ?>"><?= e($ft['name']) ?></a><?php endforeach; ?>
      <a href="<?= $u('/mahsulotlar') ?>">Barchasi</a>
    </div>
    <div>
      <b>Aloqa</b>
      <?php if (!empty($contact['phone'])): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string)$contact['phone'])) ?>"><?= e($contact['phone']) ?></a><?php endif; ?>
      <?php if (!empty($contact['telegram']) && preg_match('/^[A-Za-z0-9_]{4,40}$/', (string)$contact['telegram'])): ?><a href="https://t.me/<?= e($contact['telegram']) ?>" rel="noopener">Telegram: @<?= e($contact['telegram']) ?></a><?php endif; ?>
      <?php if (!empty($contact['email'])): ?><a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a><?php endif; ?>
      <a href="<?= $u('/#sorov') ?>">So'rov qoldirish</a>
      <a href="<?= $u('/login') ?>">Mijoz kabineti</a>
    </div>
  </div>
  <div class="foot-bot"><span>© <?= date('Y') ?> Biwork</span><span>Smart data. Better decisions.</span></div>
</div></footer>

<?php elseif ($area === 'auth'): ?>
<?php foreach ($flash as [$k, $m]): ?><div class="flash <?= e($k) ?>" style="position:fixed;top:14px;left:50%;transform:translateX(-50%);z-index:50;max-width:92vw"><?= e($m) ?></div><?php endforeach; ?>
<?= $content ?>

<?php else: ?>
<div class="shell">
  <aside class="side" id="side">
    <div class="brand">
      <a href="<?= $u($isStaff ? '/admin' : '/app') ?>"><img src="<?= $b ?>/assets/biwork-word-white.png" alt="Biwork" width="86" height="22"></a>
      <?php if ($area === 'admin'): ?><small>Admin</small><?php endif; ?>
    </div>
    <nav aria-label="Menyu">
      <?php foreach ($navItems as [$grp, $items]): ?>
        <?php if ($grp !== ''): ?><div class="grp"><?= e($grp) ?></div><?php endif; ?>
        <?php foreach ($items as [$key, $href, $ic, $label]): $cnt = ''; if (str_contains($label, '|')) { [$label, $cnt] = explode('|', $label, 2); } ?>
          <a href="<?= $u($href) ?>" class="<?= $o['nav'] === $key ? 'on' : '' ?>"><?= Ui::icon($ic) ?><span><?= e($label) ?></span><?php if ($cnt !== ''): ?><em><?= e($cnt) ?></em><?php endif; ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="foot">
      <?php if ($user): ?>
        <b><?= e($user['name'] ?: $user['email']) ?></b>
        <span><?= $isStaff ? 'Biwork xodimi' : e($user['customer_name'] ?? '') ?></span>
        <form method="post" action="<?= $u('/logout') ?>" style="margin-top:10px"><?= Ui::csrf() ?><button class="btn btn-sm btn-block btn-outline-w" style="color:#C7D3E2;border-color:rgba(255,255,255,.2);background:transparent" type="submit"><?= Ui::icon('out') ?> Chiqish</button></form>
      <?php endif; ?>
    </div>
  </aside>
  <div class="main">
    <header class="top">
      <button class="icon-btn burger" type="button" id="burger" aria-label="Menyu"><?= Ui::icon('menu') ?></button>
      <div class="ttl"><?= e($o['title']) ?></div>
      <a class="btn btn-sm btn-ghost" href="<?= $u('/') ?>"><?= Ui::icon('external') ?> <span>Sayt</span></a>
      <button class="icon-btn" type="button" data-theme-toggle aria-label="Mavzuni almashtirish"><?= Ui::icon('moon') ?></button>
    </header>
    <main class="page">
      <?php foreach ($flash as [$k, $m]): ?><div class="flash <?= e($k) ?>" role="status"><?= e($m) ?></div><?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<?php endif; ?>
<script src="<?= e($asset('portal.js')) ?>" defer></script>
</body>
</html>
