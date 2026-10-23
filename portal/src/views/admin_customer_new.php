<?php /** @var string $error @var array $in */ ?>
<div class="crumbs"><a href="<?= e(Util::url('/admin/customers')) ?>">Korxonalar</a> / Yangi</div>
<div class="page-h"><div><h1>Yangi korxona</h1><p>Korxona va uning egasi (birinchi foydalanuvchi) yaratiladi.</p></div></div>
<?php if ($error !== ''): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card" style="max-width:720px" data-once><div class="card-b">
  <?= Ui::csrf() ?>
  <h2 style="margin-bottom:12px">Korxona</h2>
  <div class="field"><label class="f" for="company">Nomi</label><input class="in" id="company" name="company" required maxlength="120" value="<?= e($in['company'] ?? '') ?>"></div>
  <div class="row3">
    <div class="field"><label class="f" for="stir">STIR (INN)</label><input class="in" id="stir" name="stir" maxlength="20" value="<?= e($in['stir'] ?? '') ?>"></div>
    <div class="field"><label class="f" for="city">Shahar</label><input class="in" id="city" name="city" maxlength="60" value="<?= e($in['city'] ?? '') ?>"></div>
    <div class="field"><label class="f" for="phone">Telefon</label><input class="in" id="phone" name="phone" maxlength="40" value="<?= e($in['phone'] ?? '') ?>"></div>
  </div>
  <h2 style="margin:8px 0 12px">Egasi (kirish hisobi)</h2>
  <div class="row2">
    <div class="field"><label class="f" for="name">Ism</label><input class="in" id="name" name="name" required maxlength="100" value="<?= e($in['name'] ?? '') ?>"></div>
    <div class="field"><label class="f" for="email">Email (login)</label><input class="in" id="email" name="email" type="email" required value="<?= e($in['email'] ?? '') ?>"></div>
  </div>
  <div class="field"><label class="f" for="password">Vaqtinchalik parol</label><input class="in" id="password" name="password" type="text" required minlength="10" autocomplete="off"><div class="hint">Kamida 10 belgi. Egasi birinchi kirishda uni almashtiradi.</div></div>
  <div class="actions"><button class="btn btn-primary" type="submit">Yaratish</button><a class="btn btn-ghost" href="<?= e(Util::url('/admin/customers')) ?>">Bekor qilish</a></div>
</div></form>
