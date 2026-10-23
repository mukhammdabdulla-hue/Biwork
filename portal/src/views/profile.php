<?php /** @var array $me */ ?>
<div class="page-h"><div><h1>Profil</h1></div></div>
<div class="grid g2" style="align-items:start">
  <section class="card"><div class="card-h"><h2>Hisob</h2></div><div class="card-b"><dl class="kv">
    <dt>Ism</dt><dd><?= e($me['name']) ?></dd>
    <dt>Email</dt><dd><?= e($me['email']) ?></dd>
    <dt>Rol</dt><dd><?= e(Auth::ROLE_UZ[$me['role']] ?? $me['role']) ?></dd>
    <?php if ($me['role'] !== 'staff'): ?><dt>Korxona</dt><dd><?= e($me['customer_name'] ?? '') ?></dd><?php endif; ?>
  </dl></div></section>
  <section class="card"><div class="card-h"><h2>Parolni almashtirish</h2></div>
    <form class="card-b" method="post" data-once>
      <?= Ui::csrf() ?>
      <div class="field"><label class="f" for="c">Joriy parol</label><input class="in" id="c" name="current" type="password" required autocomplete="current-password"></div>
      <div class="field"><label class="f" for="p">Yangi parol</label><input class="in" id="p" name="password" type="password" required minlength="10" autocomplete="new-password"><div class="hint">Kamida 10 belgi</div></div>
      <button class="btn btn-primary" type="submit">Saqlash</button>
    </form>
  </section>
</div>
