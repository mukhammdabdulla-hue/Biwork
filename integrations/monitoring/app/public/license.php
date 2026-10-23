<?php
declare(strict_types=1);

/** Biwork litsenziyasi: holat va faollashtirish (faqat administrator). Litsenziya majburiy ('enforce') bo'lmasa — 404. */
require_once __DIR__ . '/../src/bootstrap.php';

if (!Biwork::enforced()) {
    http_response_code(404);
    exit('Topilmadi');
}
$base = Util::basePath();
$b = Util::e($base);
$auth = Auth::state();
if ($auth === 'login' || $auth === 'setup') {
    header('Location: ' . $base . '/login.php', true, 302);
    exit;
}
$e = static fn($s): string => Util::e((string)$s);
$isAdmin = Auth::can('admin');
try {
    $bw = Biwork::client();
    $bw->deviceId();
} catch (Throwable $ex) {
    Biwork::page(503, 'Litsenziya sozlamasi', $isAdmin ? $ex->getMessage() : 'Litsenziya tekshiruvi sozlanmagan. Administratorga murojaat qiling.');
}
$msg = '';
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isAdmin) {
        $err = 'Litsenziyani faqat administrator boshqaradi';
    } elseif (!Auth::checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        $err = "Sahifa eskirgan. Qayta urinib ko'ring.";
    } else {
        try {
            $op = (string)($_POST['op'] ?? 'activate');
            if ($op === 'deactivate') {
                $bw->deactivate();
                $msg = "Litsenziya shu qurilmadan olib tashlandi. O'rindiq bo'shatildi.";
            } elseif ($op === 'refresh') {
                $bw->refresh();
                $msg = 'Litsenziya serverda tekshirildi.';
            } else {
                $bw->activate(trim((string)($_POST['key'] ?? '')));
                $msg = 'Litsenziya faollashtirildi.';
            }
        } catch (Throwable $ex) {
            $err = $ex instanceof BiworkException ? $ex->getMessage() : 'Amal bajarilmadi: ' . $ex->getMessage();
        }
    }
}
try {
    $st = $bw->state();
} catch (Throwable $ex) {
    Biwork::page(503, 'Litsenziya sozlamasi', $isAdmin ? $ex->getMessage() : 'Litsenziya tekshiruvi sozlanmagan. Administratorga murojaat qiling.');
}
$lic = $st['license'] ?? null;
Auth::pageHeaders();
$csrf = Auth::csrfToken();
?><!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Litsenziya · Biwork Monitoring</title>
<link rel="icon" type="image/svg+xml" href="<?= $b ?>/assets/favicon.svg">
<link rel="stylesheet" href="<?= $b ?>/assets/app.css?v=<?= MON_VERSION ?>">
</head>
<body>
<div class="lg-wrap"><main class="lg-main"><div class="lg-card">
  <img src="<?= $b ?>/assets/biwork-logo.png" width="150" height="40" alt="Biwork" style="margin-bottom:22px">
  <h1>Litsenziya</h1>
  <p class="lg-lead"><?= $st['allowed'] ? 'Litsenziya faol.' : 'Ishlatish uchun faol litsenziya kerak.' ?></p>
<?php if ($msg !== ''): ?><div class="lg-info" style="margin-bottom:14px"><b><?= $e($msg) ?></b></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="lg-err" role="alert"><?= $e($err) ?></div><?php endif; ?>
  <div class="lg-info" style="margin-bottom:18px">
    <p><b><?= $e(['valid' => 'Faol', 'grace' => 'Faol (onlayn tekshiruv kerak)', 'none' => 'Faollashtirilmagan', 'expired' => 'Muddati tugagan', 'locked' => 'Onlayn tekshiruv kerak',
        'blocked' => 'Bloklangan', 'invalid' => 'Yaroqsiz', 'clock' => "Soat noto'g'ri"][$st['state']] ?? $st['state']) ?></b> — <?= $e($st['message']) ?></p>
<?php if ($lic): ?>
    <p>Korxona: <b><?= $e($lic['cust']['name'] ?? '') ?></b><br>
    Tarif: <?= $e($lic['plan'] ?? '') ?> · O'rindiqlar: <?= (int)($lic['seats'] ?? 0) ?><br>
    Muddat: <?= !empty($lic['exp']) ? $e(date('d.m.Y', (int)$lic['exp'])) . ($st['days_left'] !== null ? ' (' . (int)$st['days_left'] . ' kun)' : '') : 'muddatsiz' ?><br>
    Qurilma: <code><?= $e(substr($bw->deviceId(), 0, 10)) ?></code></p>
<?php endif; ?>
  </div>
<?php if ($isAdmin): ?>
  <form method="post" action="<?= $b ?>/license.php">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="activate">
    <label class="field"><span class="field-lbl">Litsenziya kaliti</span><input class="input" name="key" placeholder="BW-MON-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false" required></label>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Faollashtirish</button>
  </form>
<?php if ($lic): ?>
  <div style="display:flex;gap:8px;margin-top:12px">
    <form method="post" action="<?= $b ?>/license.php" style="flex:1"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="refresh"><button class="btn btn-block" type="submit">Hozir tekshirish</button></form>
    <form method="post" action="<?= $b ?>/license.php" onsubmit="return confirm('Litsenziya shu qurilmadan olib tashlansinmi?')" style="flex:1"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="deactivate"><button class="btn btn-block" type="submit">Qurilmadan olib tashlash</button></form>
  </div>
<?php endif; ?>
<?php else: ?>
  <div class="lg-info"><p>Litsenziyani faollashtirish uchun administrator hisobi bilan kiring.</p></div>
<?php endif; ?>
<?php if ($st['allowed']): ?><p style="margin-top:16px"><a href="<?= $b ?>/">← Monitoringga qaytish</a></p><?php endif; ?>
  <div class="lg-foot">Biwork<?= Biwork::portalUrl() !== '' ? ' · ' . $e(Biwork::portalUrl()) : '' ?></div>
</div></main></div>
</body>
</html>
