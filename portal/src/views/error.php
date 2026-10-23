<?php /** @var int $status @var string $message @var string $heading */ ?>
<div class="empty" style="padding:80px 20px">
  <div style="font-size:56px;font-weight:700;letter-spacing:-.04em;color:var(--faint)"><?= (int)$status ?></div>
  <h1 style="margin:6px 0 8px"><?= e($heading) ?></h1>
  <p><?= e($message) ?></p>
  <p style="margin-top:18px"><a class="btn" href="<?= e(Util::url('/')) ?>">Bosh sahifaga</a></p>
</div>
