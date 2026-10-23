<?php
/** "So'rov qoldiring" bloki (to'q fon + shakl). @var ?array $tool @var string $err @var array $in @var string $heading @var string $text @var string $btn @var list<string> $points @var string $placeholder @var bool $disabled */
?>
<section class="band" id="sorov"><div class="wrap band-in">
  <div class="band-copy">
    <h2><?= e($heading) ?></h2>
    <p><?= e($text) ?></p>
    <?php if (!empty($points)): ?><ul class="ticks ticks-w"><?php foreach ($points as $pt): ?><li><?= e($pt) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <div class="card band-form"><div class="card-b">
    <?= View::render('_lead_form', ['tool' => $tool ?? null, 'err' => $err ?? '', 'in' => $in ?? [], 'btn' => $btn ?? "So'rov yuborish", 'placeholder' => $placeholder ?? '', 'disabled' => $disabled ?? false]) ?>
  </div></div>
</div></section>
