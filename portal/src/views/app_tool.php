<?php /** @var array $t @var array $user @var array $all @var ?array $best @var array $devices @var array $requests @var array $plans @var array $releases @var bool $canTrial */
$isOwner = $user['role'] === 'owner';
$active = $best && $best['state'] === 'active';
$paid = $best && $best['type'] !== 'trial';
?>
<div class="crumbs"><a href="<?= e(Util::url('/app')) ?>">Mening mahsulotlarim</a> / <?= e($t['name']) ?></div>
<div class="page-h">
  <div class="actions" style="gap:14px;flex-wrap:nowrap">
    <div class="tool-ic green" style="width:54px;height:54px"><?= Ui::toolIcon($t['icon']) ?></div>
    <div><h1><?= e($t['name']) ?></h1><p><?= e($t['tagline']) ?></p></div>
  </div>
  <div class="actions">
    <?php if ($active): ?><a class="btn btn-primary" href="<?= e(Util::url('/app/open/' . $t['slug'])) ?>" target="_blank" rel="noopener"><?= Ui::icon('external') ?> Brauzerda ochish</a>
      <?php if (class_exists('Demos') && ($dv = Demos::current((int)$t['id']))): ?><span class="muted small"><?= e(Demos::label($dv)) ?></span><?php endif; ?><?php endif; ?>
    <?php if ($canTrial): ?><form method="post" action="<?= e(Util::url('/app/' . $t['slug'] . '/trial')) ?>" data-once><?= Ui::csrf() ?><button class="btn btn-primary" type="submit"><?= (int)$t['trial_days'] ?> kunlik sinovni boshlash</button></form><?php endif; ?>
  </div>
</div>

<div class="grid" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);align-items:start">
<div class="stack">

  <section class="card">
    <div class="card-h"><h2>Litsenziya</h2><?php if ($best): ?><?= Ui::state($best['state']) ?><?php endif; ?></div>
    <?php if (!$best): ?>
      <div class="empty">Bu mahsulot uchun litsenziyangiz yo'q.<?= $canTrial ? ' Sinovni boshlang — mahsulot namuna ma\'lumot bilan brauzerda ochiladi.' : '' ?></div>
    <?php else: ?>
    <div class="card-b">
      <dl class="kv">
        <dt>Turi</dt><dd><?= Ui::type($best['type']) ?></dd>
        <dt>Tarif</dt><dd><?= e($best['plan_name']) ?></dd>
        <dt>Muddat</dt><dd><?= $best['valid_until'] !== null ? e(Util::date((int)$best['valid_from']) . ' — ' . Util::date((int)$best['valid_until'])) . ' · <b>' . e(Ui::daysText($best)) . '</b>' : 'Muddatsiz' ?></dd>
        <?php if ($paid): ?><dt>O'rindiqlar</dt><dd><?= (int)$best['seats_used'] ?> / <?= (int)$best['seats'] ?> band</dd><?php endif; ?>
        <?php if ($paid && $isOwner): ?>
          <dt>Kalit</dt><dd><div class="keybox"><code><?= e($best['license_key']) ?></code><button class="btn btn-sm" type="button" data-copy="<?= e($best['license_key']) ?>">Nusxalash</button></div><div class="hint">Ilovani birinchi marta ishga tushirganda shu kalitni kiriting.</div></dd>
        <?php endif; ?>
      </dl>
      <?php if ($best['type'] === 'trial'): ?>
        <div class="flash info" style="margin:16px 0 0">Sinov faqat brauzerdagi namuna ma'lumot uchun. Ilovani o'z kompyuteringizga yuklab olish uchun pullik litsenziya kerak.</div>
      <?php elseif ($best['state'] === 'active' && $best['days_left'] !== null && $best['days_left'] <= 30): ?>
        <div class="flash info" style="margin:16px 0 0">Litsenziya muddati tez orada tugaydi (<?= e(Ui::daysText($best)) ?>). Uzaytirish uchun pastdagi so'rovni yuboring.</div>
      <?php elseif ($best['state'] !== 'active'): ?>
        <div class="flash err" style="margin:16px 0 0">Litsenziya faol emas: <?= e(Licenses::STATUS_UZ[$best['state']] ?? $best['state']) ?>. Biwork bilan bog'laning yoki yangi so'rov yuboring.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

  <?php if ($paid && $active): ?>
  <section class="card" id="download">
    <div class="card-h"><h2>Yuklab olish</h2></div>
    <?php if (!$releases): ?>
      <div class="empty">Reliz hali joylanmagan. Biwork xodimi bilan bog'laning.</div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Versiya</th><th>Turi</th><th>Hajm</th><th>Sana</th><th></th></tr></thead><tbody>
      <?php foreach ($releases as $r): ?>
        <tr>
          <td><b>v<?= e($r['version']) ?></b> <?= (int)$r['is_latest'] ? '<span class="badge ok">Oxirgi</span>' : '' ?><?php if ($r['notes'] !== ''): ?><div class="muted small"><?= e($r['notes']) ?></div><?php endif; ?></td>
          <td><?= e(Catalog::KIND_UZ[$r['kind']] ?? $r['kind']) ?></td>
          <td class="nowrap"><?= e(Util::bytes((int)$r['size'])) ?></td>
          <td class="nowrap"><?= e(Util::date((int)$r['published_at'])) ?></td>
          <td class="right"><a class="btn btn-sm btn-primary" href="<?= e(Util::url('/app/download/' . $r['id'])) ?>"><?= Ui::icon('download') ?> Yuklab olish</a></td>
        </tr>
        <tr><td colspan="5" style="padding-top:0;border-top:0"><span class="muted small">SHA-256: <code><?= e($r['sha256']) ?></code></span></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <div class="card-b" style="border-top:1px solid var(--border)">
        <h3 style="margin-bottom:8px">O'rnatish</h3>
        <ol style="margin:0;padding-left:20px;display:grid;gap:4px">
          <li>Faylni yuklab oling va papkaga chiqaring.</li>
          <li>Tool bilan kelgan yo'riqnoma (<code>README</code>) bo'yicha ishga tushiring.</li>
          <li>Birinchi ochilishda litsenziya kalitini kiriting — mahsulot serverimiz bilan faollashadi va bir o'rindiqni egallaydi.</li>
        </ol>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($isOwner): ?>
  <section class="card">
    <div class="card-h"><h2>Faol qurilmalar</h2><span class="muted small"><?= count($devices) ?> / <?= (int)$best['seats'] ?></span></div>
    <?php if (!$devices): ?>
      <div class="empty">Hali hech qaysi qurilmada faollashtirilmagan.</div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Qurilma</th><th>Versiya</th><th>Oxirgi faollik</th><th></th></tr></thead><tbody>
      <?php foreach ($devices as $d): ?>
        <tr>
          <td><b><?= e($d['device_name'] ?: 'Nomsiz qurilma') ?></b><div class="muted small"><?= e($d['platform']) ?> · <code><?= e(substr($d['device_id'], 0, 10)) ?></code></div></td>
          <td>v<?= e($d['app_version'] ?: '?') ?></td>
          <td class="nowrap"><?= e(Util::dateTime((int)$d['last_seen'])) ?></td>
          <td class="right"><form method="post" action="<?= e(Util::url('/app/devices/' . $d['id'] . '/release')) ?>" class="inline" data-confirm="Bu qurilmani bo'shatamizmi? Ilova qayta faollashtirilmaguncha unda ishlamaydi."><?= Ui::csrf() ?><button class="btn btn-sm btn-danger" type="submit">Bo'shatish</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <div class="card-b" style="border-top:1px solid var(--border)"><span class="muted small">Kompyuter almashsa, eskisini bo'shating — o'rindiq yangisiga o'tadi.</span></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (count($all) > 1 || ($all && !$best)): ?>
  <section class="card">
    <div class="card-h"><h2>Litsenziyalar tarixi</h2></div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Turi</th><th>Holat</th><th>Muddat</th><?= $isOwner ? '<th>Kalit</th>' : '' ?></tr></thead><tbody>
    <?php foreach ($all as $l): ?>
      <tr><td><?= Ui::type($l['type']) ?></td><td><?= Ui::state($l['state']) ?></td><td class="nowrap"><?= e(Util::date((int)$l['valid_from'])) ?> — <?= e(Util::date($l['valid_until'] !== null ? (int)$l['valid_until'] : null)) ?></td>
      <?php if ($isOwner): ?><td><code><?= $l['type'] === 'trial' ? '—' : e($l['license_key']) ?></code></td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <?php endif; ?>
</div>

<aside class="stack">
  <?php if ($isOwner && $t['status'] === 'active'): ?>
  <section class="card">
    <div class="card-h"><h2><?= $paid && $active ? 'Uzaytirish yoki qo\'shish' : 'Litsenziya sotib olish' ?></h2></div>
    <form class="card-b" method="post" action="<?= e(Util::url('/app/' . $t['slug'] . '/request')) ?>" data-once>
      <?= Ui::csrf() ?>
      <?php if ($plans): ?>
        <div class="field"><label class="f" for="plan">Tarif</label>
          <select class="in" id="plan" name="plan">
            <?php foreach ($plans as $p): ?><option value="<?= e($p['code']) ?>"><?= e($p['name']) ?> — <?= $p['price'] !== null ? e(Util::money((int)$p['price'], $p['currency'])) : 'kelishilgan' ?> (<?= $p['days'] !== null ? (int)$p['days'] . ' kun' : 'muddatsiz' ?>, <?= (int)$p['seats'] ?> o'rindiq)</option><?php endforeach; ?>
          </select></div>
      <?php endif; ?>
      <div class="field"><label class="f" for="seats">Kerakli o'rindiqlar (qurilmalar)</label><input class="in" id="seats" name="seats" type="number" min="1" max="999" value="<?= (int)($plans[0]['seats'] ?? 1) ?>"></div>
      <div class="field"><label class="f" for="comment">Izoh (ixtiyoriy)</label><textarea class="in" id="comment" name="comment" maxlength="1000" placeholder="Masalan: 2 ta sex, 5 ta rejalashtiruvchi"></textarea></div>
      <button class="btn btn-primary btn-block" type="submit">So'rov yuborish</button>
      <p class="hint" style="margin-top:10px">Biwork xodimi siz bilan bog'lanib, hisob-faktura yuboradi. To'lovdan so'ng litsenziya shu sahifada paydo bo'ladi.</p>
    </form>
  </section>
  <?php elseif (!$isOwner && !$paid): ?>
    <section class="card"><div class="card-b"><p class="muted" style="margin:0">Litsenziya sotib olishni korxona egasi amalga oshiradi.</p></div></section>
  <?php endif; ?>
  <?php if ($requests): ?>
  <section class="card">
    <div class="card-h"><h2>So'rovlar</h2></div>
    <div class="tbl-wrap"><table class="tbl"><tbody>
    <?php foreach ($requests as $r): ?>
      <tr><td><?= e(Util::date((int)$r['created_at'])) ?><div class="muted small"><?= e($r['plan_code'] ?: '—') ?> · <?= (int)$r['seats'] ?> o'rindiq</div></td>
      <td class="right"><span class="badge <?= ['new' => 'info', 'handled' => 'ok', 'rejected' => 'bad'][$r['status']] ?? '' ?>"><?= e(['new' => 'Ko\'rib chiqilmoqda', 'handled' => 'Bajarildi', 'rejected' => 'Rad etildi'][$r['status']] ?? $r['status']) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <?php endif; ?>
  <section class="card"><div class="card-b">
    <h3 style="margin-bottom:8px"><?= e($t['name']) ?> haqida</h3>
    <p class="muted"><?= e(mb_strimwidth($t['description'], 0, 320, '…')) ?></p>
    <a href="<?= e(Util::url('/' . $t['slug'])) ?>">Batafsil →</a>
  </div></section>
</aside>
</div>
