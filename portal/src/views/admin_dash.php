<?php /** @var array $stats @var array $expiring @var array $requests @var array $events */ ?>
<div class="page-h"><div><h1>Boshqaruv</h1><p>Sotuv va litsenziyalar holati</p></div>
  <div class="actions"><a class="btn btn-primary" href="<?= e(Util::url('/admin/licenses/new')) ?>"><?= Ui::icon('plus') ?> Litsenziya berish</a><a class="btn" href="<?= e(Util::url('/admin/customers/new')) ?>">Yangi korxona</a></div>
</div>
<div class="grid g4 stats">
  <div class="card stat"><b><?= $stats['active'] ?></b><span>Faol pullik litsenziyalar</span></div>
  <div class="card stat"><b><?= $stats['trials'] ?></b><span>Faol sinovlar</span></div>
  <div class="card stat"><b><?= $stats['expiring'] ?></b><span>30 kunda tugaydi</span></div>
  <div class="card stat"><b><?= $stats['leads'] ?></b><span><a href="<?= e(Util::url('/admin/leads')) ?>">Yangi murojaatlar (saytdan)</a></span></div>
  <div class="card stat"><b><?= $stats['requests'] ?></b><span>Yangi sotib olish so'rovlari</span></div>
  <div class="card stat"><b><?= $stats['devices'] ?></b><span>Faollashtirilgan qurilmalar</span></div>
  <div class="card stat"><b><?= $stats['customers'] ?></b><span>Korxonalar</span></div>
  <div class="card stat"><b><?= $stats['tools'] ?></b><span>Saytdagi faol mahsulotlar</span></div>
</div>
<div class="grid g2" style="align-items:start">
  <section class="card"><div class="card-h"><h2>Yangi so'rovlar</h2><a class="small" href="<?= e(Util::url('/admin/requests')) ?>">Hammasi →</a></div>
  <?php if (!$requests): ?><div class="empty">Yangi so'rov yo'q</div><?php else: ?>
    <div class="tbl-wrap"><table class="tbl"><tbody>
    <?php foreach ($requests as $r): ?>
      <tr><td><b><?= e($r['customer_name']) ?></b><div class="muted small"><?= e($r['tool_name']) ?> · <?= e($r['plan_code'] ?: '—') ?> · <?= (int)$r['seats'] ?> o'rindiq · <?= e(Util::date((int)$r['created_at'])) ?></div></td>
      <td class="right"><a class="btn btn-sm btn-primary" href="<?= e(Util::url('/admin/licenses/new?request=' . $r['id'])) ?>">Litsenziya berish</a></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?></section>
  <section class="card"><div class="card-h"><h2>Tez tugaydigan litsenziyalar</h2></div>
  <?php if (!$expiring): ?><div class="empty">30 kun ichida tugaydigan litsenziya yo'q</div><?php else: ?>
    <div class="tbl-wrap"><table class="tbl"><tbody>
    <?php foreach ($expiring as $l): ?>
      <tr><td><a href="<?= e(Util::url('/admin/licenses/' . $l['id'])) ?>"><b><?= e($l['customer_name']) ?></b></a><div class="muted small"><?= e($l['tool_name']) ?> · <code><?= e($l['license_key']) ?></code></div></td>
      <td class="right nowrap"><span class="badge warn"><?= e(Ui::daysText($l)) ?></span></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?></section>
</div>
<section class="card" style="margin-top:16px"><div class="card-h"><h2>So'nggi hodisalar</h2><a class="small" href="<?= e(Util::url('/admin/events')) ?>">Jurnal →</a></div>
  <?= View::render('_events', ['rows' => $events, 'showCustomer' => true]) ?>
</section>
