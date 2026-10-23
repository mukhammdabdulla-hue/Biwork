<?php /** @var array $c @var array $users @var array $licenses @var array $requests @var array $events */ ?>
<div class="crumbs"><a href="<?= e(Util::url('/admin/customers')) ?>">Korxonalar</a> / <?= e($c['name']) ?></div>
<div class="page-h"><div><h1><?= e($c['name']) ?> <?= $c['status'] === 'blocked' ? '<span class="badge bad">Bloklangan</span>' : '' ?></h1><p>Qo'shilgan: <?= e(Util::date((int)$c['created_at'])) ?> (<?= $c['source'] === 'signup' ? "o'zi ro'yxatdan o'tgan" : 'xodim yaratgan' ?>)</p></div>
  <a class="btn btn-primary" href="<?= e(Util::url('/admin/licenses/new?customer=' . $c['id'])) ?>"><?= Ui::icon('plus') ?> Litsenziya berish</a></div>
<div class="grid" style="grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);align-items:start">
<div class="stack">
  <section class="card"><div class="card-h"><h2>Litsenziyalar</h2></div>
  <?php if (!$licenses): ?><div class="empty">Hali litsenziya yo'q</div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Mahsulot</th><th>Kalit</th><th>Tur</th><th>Holat</th><th>Muddat</th></tr></thead><tbody>
  <?php foreach ($licenses as $l): ?>
    <tr><td><?= e($l['tool_name']) ?></td><td><a href="<?= e(Util::url('/admin/licenses/' . $l['id'])) ?>"><code><?= e($l['license_key']) ?></code></a></td><td><?= Ui::type($l['type']) ?></td><td><?= Ui::state($l['state']) ?></td><td class="nowrap small"><?= e(Ui::daysText($l)) ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?></section>

  <section class="card"><div class="card-h"><h2>Foydalanuvchilar</h2></div>
  <div class="tbl-wrap"><table class="tbl"><tbody>
  <?php foreach ($users as $u): ?>
    <tr><td><b><?= e($u['name'] ?: $u['email']) ?></b><div class="muted small"><?= e($u['email']) ?> · <?= e(Auth::ROLE_UZ[$u['role']] ?? $u['role']) ?><?= !(int)$u['active'] ? ' · <span style="color:var(--bad)">o\'chirilgan</span>' : '' ?></div></td>
    <td class="muted small nowrap">Kirdi: <?= e(Util::dateTime($u['last_login_at'] !== null ? (int)$u['last_login_at'] : null)) ?></td>
    <td class="right nowrap">
      <form method="post" action="<?= e(Util::url('/admin/users/' . $u['id'] . '/reset')) ?>" class="inline" data-confirm="Yangi vaqtinchalik parol yaratamizmi? Eski parol ishlamay qoladi."><?= Ui::csrf() ?><button class="btn btn-sm" type="submit">Parolni tiklash</button></form>
      <form method="post" action="<?= e(Util::url('/admin/users/' . $u['id'] . '/toggle')) ?>" class="inline"><?= Ui::csrf() ?><button class="btn btn-sm" type="submit"><?= (int)$u['active'] ? "O'chirish" : 'Yoqish' ?></button></form>
    </td></tr>
  <?php endforeach; ?></tbody></table></div></section>

  <?php if ($requests): ?><section class="card"><div class="card-h"><h2>So'rovlar</h2></div><div class="tbl-wrap"><table class="tbl"><tbody>
  <?php foreach ($requests as $r): ?><tr><td class="nowrap"><?= e(Util::date((int)$r['created_at'])) ?></td><td><?= e($r['tool_name']) ?> · <?= e($r['plan_code'] ?: '—') ?> · <?= (int)$r['seats'] ?> o'rindiq<div class="muted small"><?= e($r['comment']) ?></div></td><td class="right"><?= $r['status'] === 'new' ? '<a class="btn btn-sm btn-primary" href="' . e(Util::url('/admin/licenses/new?request=' . $r['id'])) . '">Litsenziya berish</a>' : '<span class="badge ' . ($r['status'] === 'handled' ? 'ok' : 'bad') . '">' . ($r['status'] === 'handled' ? 'Bajarilgan' : 'Rad etilgan') . '</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section><?php endif; ?>

  <section class="card"><div class="card-h"><h2>Hodisalar</h2></div><?= View::render('_events', ['rows' => $events, 'showCustomer' => false]) ?></section>
</div>
<section class="card"><div class="card-h"><h2>Ma'lumotlar</h2></div>
  <form class="card-b" method="post" action="<?= e(Util::url('/admin/customers/' . $c['id'])) ?>" data-once>
    <?= Ui::csrf() ?>
    <div class="field"><label class="f" for="name">Nomi</label><input class="in" id="name" name="name" required maxlength="120" value="<?= e($c['name']) ?>"></div>
    <div class="row2">
      <div class="field"><label class="f" for="stir">STIR</label><input class="in" id="stir" name="stir" maxlength="20" value="<?= e($c['stir']) ?>"></div>
      <div class="field"><label class="f" for="city">Shahar</label><input class="in" id="city" name="city" maxlength="60" value="<?= e($c['city']) ?>"></div>
    </div>
    <div class="field"><label class="f" for="phone">Telefon</label><input class="in" id="phone" name="phone" maxlength="40" value="<?= e($c['phone']) ?>"></div>
    <div class="field"><label class="f" for="email">Email</label><input class="in" id="email" name="email" maxlength="120" value="<?= e($c['email']) ?>"></div>
    <div class="field"><label class="f" for="notes">Ichki izoh</label><textarea class="in" id="notes" name="notes" maxlength="2000"><?= e($c['notes']) ?></textarea></div>
    <div class="field"><label class="f" for="status">Holat</label><select class="in" id="status" name="status"><option value="active" <?= $c['status'] === 'active' ? 'selected' : '' ?>>Faol</option><option value="blocked" <?= $c['status'] === 'blocked' ? 'selected' : '' ?>>Bloklangan (kira olmaydi, litsenziyalari to'xtaydi)</option></select></div>
    <button class="btn btn-primary" type="submit">Saqlash</button>
  </form>
</section>
</div>
