<?php /** @var string $error @var array $in @var ?array $tool */ ?>
<div class="auth-wrap">
  <aside class="auth-art">
    <img src="<?= e(Util::url('/assets/biwork-logo-white.png')) ?>" alt="Biwork" width="200" height="52">
    <?php $rt = $tool ? Trial::of($tool) : null; $rd = $rt ? $rt['days'] : Catalog::trialDays(); ?>
    <div><h2><?= $tool ? e($tool['name']) . ' — ' . $rd . ' kun bepul sinov' : $rd . " kun bepul sinab ko'ring" ?></h2>
      <?php if ($rt && !$rt['can']): ?><p><?= e($rt['note']) ?> Hisob ochsangiz, boshqa mahsulotlarni brauzerda sinab ko'rishingiz mumkin.</p>
      <?php else: ?><p>Ro'yxatdan o'tgach mahsulot darhol brauzerda ochiladi. Sinov namuna ma'lumot bilan ishlaydi, haqiqiy ma'lumotingiz yuklanmaydi.</p><?php endif; ?></div>
    <small style="color:#C3D0E0;font-size:14px">Smart data. Better decisions.</small>
  </aside>
  <main class="auth-main"><div class="auth-card">
    <h1>Ro'yxatdan o'tish</h1>
    <p class="sub">Korxonangiz uchun hisob yarating</p>
    <?php if ($error !== ''): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(Util::url('/register')) ?>" data-once>
      <?= Ui::csrf() ?><input type="hidden" name="tool" value="<?= e($tool['slug'] ?? '') ?>">
      <div class="field"><label class="f" for="company">Korxona nomi</label><input class="in" id="company" name="company" required maxlength="120" autofocus value="<?= e($in['company'] ?? '') ?>"></div>
      <div class="row2">
        <div class="field"><label class="f" for="name">Ismingiz</label><input class="in" id="name" name="name" required maxlength="100" autocomplete="name" value="<?= e($in['name'] ?? '') ?>"></div>
        <div class="field"><label class="f" for="phone">Telefon</label><input class="in" id="phone" name="phone" maxlength="40" autocomplete="tel" placeholder="+998 ..." value="<?= e($in['phone'] ?? '') ?>"></div>
      </div>
      <div class="field"><label class="f" for="email">Email</label><input class="in" id="email" name="email" type="email" required autocomplete="username" value="<?= e($in['email'] ?? '') ?>"></div>
      <div class="field"><label class="f" for="password">Parol</label><input class="in" id="password" name="password" type="password" required minlength="10" autocomplete="new-password"><div class="hint">Kamida 10 belgi</div></div>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Hisob yaratish</button>
    </form>
    <p class="muted" style="margin-top:18px">Hisobingiz bormi? <a href="<?= e(Util::url('/login')) ?>">Kirish</a></p>
  </div></main>
</div>
