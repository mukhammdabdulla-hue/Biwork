<?php /** @var array $rows @var array $user */ ?>
<div class="page-h">
  <div><h1>Mening mahsulotlarim</h1><p>Salom, <?= e($user['name'] ?: $user['email']) ?>. <?= e($user['customer_name'] ?? '') ?> uchun mavjud mahsulotlar.</p></div>
</div>
<div class="grid g2">
<?php foreach ($rows as ['tool' => $t, 'lic' => $l]): ?>
  <?php if ($t['status'] === 'soon'): ?>
    <article class="card tool-card soon"><div class="head"><div class="tool-ic"><?= Ui::toolIcon($t['icon']) ?></div><div><h3><?= e($t['name']) ?></h3></div></div><p class="tag"><?= e($t['tagline']) ?></p><div class="foot"><span class="badge info">Tez orada</span></div></article>
    <?php continue; endif; ?>
  <article class="card tool-card">
    <div class="head">
      <div class="tool-ic green"><?= Ui::toolIcon($t['icon']) ?></div>
      <div style="flex:1;min-width:0"><h3><?= e($t['name']) ?></h3><span class="muted small"><?= e($t['tagline']) ?></span></div>
      <?php if ($l): ?><?= Ui::state($l['state']) ?><?php endif; ?>
    </div>
    <?php if ($l): ?>
      <div class="actions"><?= Ui::type($l['type']) ?><span class="muted small"><?= e(Ui::daysText($l)) ?><?= $l['valid_until'] !== null ? ' · ' . e(Util::date((int)$l['valid_until'])) . ' gacha' : '' ?></span></div>
      <?php if ($l['valid_until'] !== null && $l['state'] === 'active' && $l['type'] === 'trial'):
        $total = max(1, (int)$t['trial_days']); $left = max(0, min($total, (int)$l['days_left'])); $pct = (int)round($left / $total * 100); ?>
        <div class="bar <?= $pct < 25 ? 'warn' : '' ?>"><i style="width:<?= $pct ?>%"></i></div>
      <?php endif; ?>
      <div class="foot">
        <?php if ($l['state'] === 'active'): ?>
          <a class="btn btn-primary btn-sm" href="<?= e(Util::url('/app/open/' . $t['slug'])) ?>" target="_blank" rel="noopener"><?= Ui::icon('external') ?> Brauzerda ochish</a>
        <?php endif; ?>
        <a class="btn btn-sm" href="<?= e(Util::url('/app/tools/' . $t['slug'])) ?>"><?= $l['type'] !== 'trial' && $l['state'] === 'active' ? 'Yuklab olish va litsenziya' : 'Batafsil' ?></a>
      </div>
    <?php else: ?>
      <p class="muted" style="margin:0">Hali boshlanmagan. Brauzerda sinab ko'ring yoki litsenziya so'rang.</p>
      <div class="foot">
        <?php if ((int)$t['trial_enabled']): ?>
          <form method="post" action="<?= e(Util::url('/app/tools/' . $t['slug'] . '/trial')) ?>" data-once><?= Ui::csrf() ?><button class="btn btn-primary btn-sm" type="submit"><?= (int)$t['trial_days'] ?> kunlik sinovni boshlash</button></form>
        <?php endif; ?>
        <a class="btn btn-sm" href="<?= e(Util::url('/app/tools/' . $t['slug'])) ?>">Batafsil</a>
      </div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
