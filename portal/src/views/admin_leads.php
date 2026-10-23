<?php /** @var array $rows @var string $status @var int $toolId @var array $counts @var array $tools */
$qs = static fn(array $o): string => '?' . http_build_query(array_filter($o + ['status' => $status, 'tool' => $toolId], static fn($v) => $v !== '' && $v !== 0));
?>
<div class="page-h"><div><h1>Murojaatlar</h1><p>Ochiq saytdan "So'rov qoldirish" orqali kelgan odamlar. Bog'lanib bo'lgach holatini belgilang.</p></div></div>
<div class="toolbar">
  <div class="chips">
  <?php foreach (['new' => 'Yangi', 'contacted' => "Bog'lanildi", 'won' => "Mijoz bo'ldi", 'lost' => 'Rad etdi', 'all' => 'Hammasi'] as $k => $v): ?>
    <a class="chip <?= $status === $k ? 'on' : '' ?>" href="<?= e($qs(['status' => $k])) ?>"><?= e($v) ?><?= isset($counts[$k]) ? ' · ' . (int)$counts[$k] : '' ?></a>
  <?php endforeach; ?>
  </div>
  <form class="grow right" method="get"><input type="hidden" name="status" value="<?= e($status) ?>">
    <select class="in" style="max-width:220px;display:inline-block" name="tool" aria-label="Mahsulot" onchange="this.form.submit()"><option value="0">Barcha mahsulotlar</option><?php foreach ($tools as $t): ?><option value="<?= (int)$t['id'] ?>"<?= $toolId === (int)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
  </form>
</div>
<?php if (!$rows): ?><section class="card"><div class="empty">Murojaat yo'q</div></section><?php endif; ?>
<?php foreach ($rows as $r): ?>
<section class="card lead-row">
  <div class="card-b">
    <div class="lead-top">
      <div>
        <b><?= e($r['name']) ?></b><?= $r['company'] !== '' ? ' · ' . e($r['company']) : '' ?>
        <div class="muted small"><?= e(Util::dateTime((int)$r['created_at'])) ?><?= $r['tool_name'] ? ' · ' . e($r['tool_name']) : ' · umumiy' ?><?= $r['source'] !== '' ? ' · manba: ' . e($r['source']) : '' ?></div>
      </div>
      <span class="badge <?= ['new' => 'info', 'contacted' => 'warn', 'won' => 'ok', 'lost' => ''][$r['status']] ?? '' ?>"><?= e(Leads::STATUS_UZ[$r['status']] ?? $r['status']) ?></span>
    </div>
    <div class="lead-contact">
      <?php if ($r['phone'] !== ''): ?><a class="btn btn-sm" href="tel:<?= e($r['phone']) ?>"><?= Ui::icon('phone') ?> <?= e($r['phone']) ?></a><?php endif; ?>
      <?php if ($r['email'] !== ''): ?><a class="btn btn-sm" href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php endif; ?>
    </div>
    <?php if ($r['message'] !== ''): ?><p class="lead-msg"><?= nl2br(e($r['message'])) ?></p><?php endif; ?>
    <form method="post" action="<?= e(Util::url('/admin/leads/' . $r['id'])) ?>" class="lead-edit" data-once>
      <?= Ui::csrf() ?><input type="hidden" name="back" value="<?= e('/admin/leads' . $qs([])) ?>">
      <select class="in" name="status" aria-label="Holat"><?php foreach (Leads::STATUS_UZ as $k => $v): ?><option value="<?= e($k) ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
      <input class="in" name="note" value="<?= e($r['note']) ?>" placeholder="Izoh (ichki)" maxlength="1000" aria-label="Ichki izoh">
      <button class="btn btn-primary" type="submit">Saqlash</button>
    </form>
  </div>
</section>
<?php endforeach; ?>
