<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
$base = Util::basePath();
$b = Util::e($base);
$state = Auth::state();
$err = '';
if ($state === 'ok') {
    header('Location: ' . $base . '/', true, 302);
    exit;
}
if ($state === 'login' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $u = Auth::attempt(trim((string)($_POST['login'] ?? '')), (string)($_POST['password'] ?? ''));
        if ($u && Auth::checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            Auth::loginAs($u);
            header('Location: ' . $base . '/', true, 303);
            exit;
        }
        $err = $u ? "Sahifa eskirgan. Qayta urinib ko'ring." : "Login yoki parol noto'g'ri.";
    } catch (ApiError $e) {
        $err = $e->getMessage();
    }
}
Auth::pageHeaders();
$csrf = Auth::csrfToken();
?><!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Kirish · Biwork Monitoring</title>
<link rel="icon" type="image/svg+xml" href="<?= $b ?>/assets/favicon.svg">
<link rel="stylesheet" href="<?= $b ?>/assets/app.css?v=<?= MON_VERSION ?>">
</head>
<body>
<div class="lg-wrap"><main class="lg-main"><div class="lg-card">
  <img src="<?= $b ?>/assets/biwork-logo.png" width="150" height="40" alt="Biwork" style="margin-bottom:24px">
  <h1>Biwork Monitoring</h1>
<?php if ($state === 'setup'): ?>
  <div class="lg-info"><p><b>Tizim sozlanmagan.</b> Birinchi foydalanuvchini yaratish uchun serverda quyidagini ishga tushiring:</p>
  <p><code>php bin/set_password.php admin 'kamida-10-belgi'</code></p></div>
<?php else: ?>
  <p class="lg-lead">Korxona jarayonlari nazorati. Davom etish uchun kiring.</p>
<?php if ($err !== ''): ?><div class="lg-err" role="alert"><?= Util::e($err) ?></div><?php endif; ?>
  <form method="post" action="<?= $b ?>/login.php">
    <input type="hidden" name="csrf" value="<?= Util::e($csrf) ?>">
    <label class="field"><span class="field-lbl">Login</span><input class="input" name="login" autocomplete="username" required autofocus></label>
    <label class="field"><span class="field-lbl">Parol</span><input class="input" type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Kirish</button>
  </form>
<?php endif; ?>
  <div class="lg-foot">Biwork Monitoring · <?= MON_VERSION ?></div>
</div></main></div>
</body>
</html>
