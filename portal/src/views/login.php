<?php /** @var string $error @var string $email @var string $next */ ?>
<div class="auth-wrap">
  <aside class="auth-art">
    <img src="<?= e(Util::url('/assets/biwork-logo-white.png')) ?>" alt="Biwork" width="200" height="52">
    <div><h2>Korxonangiz mahsulotlari — bir joyda</h2><p>Kiring, mahsulotni brauzerda oching yoki litsenziyangiz va yuklab olish havolalarini boshqaring.</p></div>
    <small style="color:#C3D0E0;font-size:14px">Smart data. Better decisions.</small>
  </aside>
  <main class="auth-main"><div class="auth-card">
    <h1>Kirish</h1>
    <p class="sub">Biwork hisobingiz bilan kiring</p>
    <?php if ($error !== ''): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(Util::url('/login')) ?>" data-once>
      <?= Ui::csrf() ?><input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field"><label class="f" for="email">Email</label><input class="in" id="email" name="email" type="email" autocomplete="username" required autofocus value="<?= e($email) ?>"></div>
      <div class="field"><label class="f" for="password">Parol</label><input class="in" id="password" name="password" type="password" autocomplete="current-password" required></div>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Kirish</button>
    </form>
    <p class="muted" style="margin-top:18px">Hisobingiz yo'qmi? <a href="<?= e(Util::url('/register')) ?>">Ro'yxatdan o'ting</a></p>
  </div></main>
</div>
