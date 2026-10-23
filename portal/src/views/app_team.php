<?php /** @var array $members @var array $me */ ?>
<div class="page-h"><div><h1>Jamoa</h1><p>Korxonangiz xodimlari mahsulotni ochishi va yuklashi mumkin. Litsenziyani faqat egasi boshqaradi.</p></div></div>
<div class="grid" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);align-items:start">
  <section class="card"><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Xodim</th><th>Rol</th><th>Oxirgi kirish</th><th></th></tr></thead><tbody>
  <?php foreach ($members as $m): ?>
    <tr>
      <td><b><?= e($m['name'] ?: $m['email']) ?></b><div class="muted small"><?= e($m['email']) ?></div></td>
      <td><span class="badge <?= $m['role'] === 'owner' ? 'info' : '' ?>"><?= e(Auth::ROLE_UZ[$m['role']] ?? $m['role']) ?></span><?= !(int)$m['active'] ? ' <span class="badge bad">O\'chirilgan</span>' : '' ?></td>
      <td class="nowrap"><?= e(Util::dateTime($m['last_login_at'] !== null ? (int)$m['last_login_at'] : null)) ?></td>
      <td class="right"><?php if ((int)$m['id'] !== (int)$me['id']): ?><form method="post" action="<?= e(Util::url('/app/team/' . $m['id'] . '/toggle')) ?>" class="inline"><?= Ui::csrf() ?><button class="btn btn-sm" type="submit"><?= (int)$m['active'] ? "O'chirish" : 'Yoqish' ?></button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></section>
  <section class="card"><div class="card-h"><h2>Xodim qo'shish</h2></div>
    <form class="card-b" method="post" action="<?= e(Util::url('/app/team/add')) ?>" data-once>
      <?= Ui::csrf() ?>
      <div class="field"><label class="f" for="n">Ism</label><input class="in" id="n" name="name" required maxlength="100"></div>
      <div class="field"><label class="f" for="e">Email</label><input class="in" id="e" name="email" type="email" required></div>
      <div class="field"><label class="f" for="p">Vaqtinchalik parol</label><input class="in" id="p" name="password" type="text" required minlength="10" autocomplete="off"><div class="hint">Kamida 10 belgi. Birinchi kirishda xodim o'zi almashtiradi.</div></div>
      <button class="btn btn-primary btn-block" type="submit">Qo'shish</button>
    </form>
  </section>
</div>
