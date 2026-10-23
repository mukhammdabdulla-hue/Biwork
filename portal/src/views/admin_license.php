<?php /** @var array $l @var array $devices @var array $events */ ?>
<div class="crumbs"><a href="<?= e(Util::url('/admin/licenses')) ?>">Litsenziyalar</a> / <?= e($l['license_key']) ?></div>
<div class="page-h"><div><h1><?= e($l['tool_name']) ?> — <?= e($l['customer_name']) ?></h1><p><?= Ui::state($l['state']) ?> &nbsp;<?= Ui::type($l['type']) ?></p></div>
  <div class="actions">
  <?php if ($l['status'] === 'active'): ?>
    <form method="post" action="<?= e(Util::url('/admin/licenses/' . $l['id'] . '/status')) ?>" class="inline" data-confirm="Litsenziya to'xtatilsinmi? Ilova keyingi tekshiruvda bloklanadi."><?= Ui::csrf() ?><input type="hidden" name="status" value="suspended"><button class="btn" type="submit">To'xtatish</button></form>
  <?php elseif ($l['status'] === 'suspended'): ?>
    <form method="post" action="<?= e(Util::url('/admin/licenses/' . $l['id'] . '/status')) ?>" class="inline"><?= Ui::csrf() ?><input type="hidden" name="status" value="active"><button class="btn btn-primary" type="submit">Qayta yoqish</button></form>
  <?php endif; ?>
  <?php if ($l['status'] !== 'revoked'): ?>
    <form method="post" action="<?= e(Util::url('/admin/licenses/' . $l['id'] . '/status')) ?>" class="inline" data-confirm="Litsenziya BEKOR QILINSINMI? Ilova keyingi tekshiruvda bloklanadi."><?= Ui::csrf() ?><input type="hidden" name="status" value="revoked"><button class="btn btn-danger" type="submit">Bekor qilish</button></form>
  <?php else: ?>
    <form method="post" action="<?= e(Util::url('/admin/licenses/' . $l['id'] . '/status')) ?>" class="inline"><?= Ui::csrf() ?><input type="hidden" name="status" value="active"><button class="btn" type="submit">Qayta yoqish</button></form>
  <?php endif; ?>
  </div>
</div>
<div class="grid" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);align-items:start">
<div class="stack">
  <section class="card"><div class="card-h"><h2>Litsenziya</h2></div><div class="card-b">
    <div class="keybox" style="margin-bottom:16px"><code><?= e($l['license_key']) ?></code><button class="btn btn-sm" type="button" data-copy="<?= e($l['license_key']) ?>">Nusxalash</button></div>
    <dl class="kv">
      <dt>Korxona</dt><dd><a href="<?= e(Util::url('/admin/customers/' . $l['customer_id'])) ?>"><?= e($l['customer_name']) ?></a></dd>
      <dt>Tarif</dt><dd><?= e($l['plan_name']) ?></dd>
      <dt>Boshlanishi</dt><dd><?= e(Util::date((int)$l['valid_from'])) ?></dd>
      <dt>Tugashi</dt><dd><?= $l['valid_until'] !== null ? e(Util::date((int)$l['valid_until'])) . ' · ' . e(Ui::daysText($l)) : 'Muddatsiz' ?></dd>
      <dt>O'rindiqlar</dt><dd><?= (int)$l['seats_used'] ?> / <?= (int)$l['seats'] ?> band</dd>
      <dt>Tekshiruv</dt><dd>har <?= (int)$l['check_days'] ?> kunda; internetsiz qo'shimcha <?= (int)$l['grace_days'] ?> kun</dd>
      <dt>Imkoniyatlar</dt><dd><?= $l['features'] ? e(implode(', ', $l['features'])) : '—' ?><?= $l['limits'] ? ' · ' . e(json_encode($l['limits'], JSON_UNESCAPED_UNICODE)) : '' ?></dd>
      <?php if ($l['instance_url'] !== ''): ?><dt>Brauzer nusxasi</dt><dd><?= e($l['instance_url']) ?></dd><?php endif; ?>
      <?php if ($l['note'] !== ''): ?><dt>Izoh</dt><dd><?= e($l['note']) ?></dd><?php endif; ?>
      <dt>Berilgan</dt><dd><?= e(Util::dateTime((int)$l['created_at'])) ?></dd>
    </dl>
  </div></section>

  <section class="card"><div class="card-h"><h2>Qurilmalar</h2></div>
  <?php if (!$devices): ?><div class="empty">Hali faollashtirilmagan</div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Qurilma</th><th>Versiya</th><th>Birinchi / oxirgi</th><th>IP</th><th></th></tr></thead><tbody>
  <?php foreach ($devices as $d): ?>
    <tr>
      <td><b><?= e($d['device_name'] ?: 'Nomsiz') ?></b> <?= $d['status'] === 'released' ? '<span class="badge">Bo\'shatilgan</span>' : '<span class="badge ok">Faol</span>' ?><div class="muted small"><?= e($d['platform']) ?> · <code><?= e(substr($d['device_id'], 0, 12)) ?></code></div></td>
      <td>v<?= e($d['app_version'] ?: '?') ?></td>
      <td class="nowrap small"><?= e(Util::dateTime((int)$d['first_seen'])) ?><div class="muted"><?= e(Util::dateTime((int)$d['last_seen'])) ?></div></td>
      <td class="small muted"><?= e($d['last_ip']) ?></td>
      <td class="right"><?php if ($d['status'] === 'active'): ?><form method="post" action="<?= e(Util::url('/admin/devices/' . $d['id'] . '/release')) ?>" class="inline" data-confirm="Qurilma bo'shatilsinmi?"><?= Ui::csrf() ?><button class="btn btn-sm btn-danger" type="submit">Bo'shatish</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?></section>

  <section class="card"><div class="card-h"><h2>Hodisalar</h2></div><?= View::render('_events', ['rows' => $events, 'showCustomer' => false]) ?></section>
</div>
<div class="stack">
  <section class="card"><div class="card-h"><h2>Muddatni o'zgartirish</h2></div>
    <form class="card-b" method="post" action="<?= e(Util::url('/admin/licenses/' . $l['id'] . '/until')) ?>" data-once>
      <?= Ui::csrf() ?>
      <div class="field"><label class="f" for="until">Yangi tugash sanasi</label><input class="in" id="until" name="until" type="date" value="<?= e(Util::ymd($l['valid_until'] !== null ? (int)$l['valid_until'] : null)) ?>"><div class="hint">Bo'sh qoldirsangiz — muddatsiz. Mahsulot keyingi tekshiruvda yangi muddatni oladi.</div></div>
      <div class="actions"><button class="btn btn-primary" type="submit">Saqlash</button>
      <?php if ($l['valid_until'] !== null): ?><button class="btn" type="button" onclick="var i=document.getElementById('until');var d=new Date(i.value||new Date());d.setFullYear(d.getFullYear()+1);i.value=d.toISOString().slice(0,10)">+1 yil</button><?php endif; ?></div>
    </form></section>
  <section class="card"><div class="card-h"><h2>O'rindiqlar</h2></div>
    <form class="card-b" method="post" action="<?= e(Util::url('/admin/licenses/' . $l['id'] . '/seats')) ?>" data-once>
      <?= Ui::csrf() ?>
      <div class="field"><label class="f" for="seats">Soni</label><input class="in" id="seats" name="seats" type="number" min="1" max="999" value="<?= (int)$l['seats'] ?>"></div>
      <button class="btn btn-primary" type="submit">Saqlash</button>
    </form></section>
  <section class="card"><div class="card-h"><h2>Sozlamalar</h2></div>
    <form class="card-b" method="post" action="<?= e(Util::url('/admin/licenses/' . $l['id'] . '/settings')) ?>" data-once>
      <?= Ui::csrf() ?>
      <div class="row2">
        <div class="field"><label class="f" for="cd">Tekshiruv (kun)</label><input class="in" id="cd" name="check_days" type="number" min="1" max="90" value="<?= (int)$l['check_days'] ?>"></div>
        <div class="field"><label class="f" for="gd">Imtiyoz (kun)</label><input class="in" id="gd" name="grace_days" type="number" min="0" max="60" value="<?= (int)$l['grace_days'] ?>"></div>
      </div>
      <div class="field"><label class="f" for="iu">Brauzer nusxasi</label><input class="in" id="iu" name="instance_url" value="<?= e($l['instance_url']) ?>" placeholder="https://..."></div>
      <div class="field"><label class="f" for="nt">Izoh</label><textarea class="in" id="nt" name="note" maxlength="500"><?= e($l['note']) ?></textarea></div>
      <button class="btn btn-primary" type="submit">Saqlash</button>
    </form></section>
</div></div>
