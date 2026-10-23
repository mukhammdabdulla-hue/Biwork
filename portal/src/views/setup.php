<?php /** @var string $error @var array $in */ ?>
<div class="auth-wrap">
  <aside class="auth-art">
    <img src="<?= e(Util::url('/assets/biwork-logo-white.png')) ?>" alt="Biwork" width="200" height="52">
    <div><h2>Birinchi sozlash</h2><p>Portal hali sozlanmagan. Birinchi Biwork xodimini (administrator) yarating.</p></div>
    <small style="color:#C3D0E0;font-size:14px">Smart data. Better decisions.</small>
  </aside>
  <main class="auth-main"><div class="auth-card">
    <h1>Administrator yaratish</h1>
    <p class="sub">Bir martalik sozlash</p>
    <?php if ($error !== ''): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(Util::url('/setup')) ?>" data-once>
      <?= Ui::csrf() ?>
      <div class="field"><label class="f" for="key">O'rnatish kaliti</label><input class="in mono" id="key" name="key" required autocomplete="off" autofocus><div class="hint">Serverdagi <code>data/setup_key.txt</code> faylida (Fayl menejeri orqali oching).</div></div>
      <div class="field"><label class="f" for="name">Ism</label><input class="in" id="name" name="name" required maxlength="100" value="<?= e($in['name'] ?? '') ?>"></div>
      <div class="field"><label class="f" for="email">Email (login)</label><input class="in" id="email" name="email" type="email" required value="<?= e($in['email'] ?? '') ?>"></div>
      <div class="field"><label class="f" for="password">Parol</label><input class="in" id="password" name="password" type="password" required minlength="10" autocomplete="new-password"><div class="hint">Kamida 10 belgi</div></div>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Yaratish</button>
    </form>
  </div></main>
</div>
