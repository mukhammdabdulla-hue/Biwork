<?php
/** Mahsulot kartasi (bosh sahifa, katalog, "boshqa mahsulotlar"). @var array $t @var string $href @var string $ctaLabel */
$m = $t['marketing'] ?? [];
$thumb = !empty($m['shots'][0]['file']) ? Marketing::shot($t, $m['shots'][0]['file']) : null;
$soon = $t['status'] === 'soon';
?>
<article class="card tool-card<?= $soon ? ' soon' : '' ?>" data-accent="<?= e(Marketing::theme($t)['accent']) ?>">
  <?php if ($thumb): ?>
    <a class="thumb" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e($thumb['url']) ?>" width="<?= $thumb['w'] ?>" height="<?= $thumb['h'] ?>" alt="" loading="lazy" decoding="async"></a>
  <?php elseif (!empty($withThumbs)): // boshqa kartalarda rasm bor — qator bir tekis chiqishi uchun ramka ?>
    <a class="thumb ph" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true"><?= Ui::toolIcon($t['icon']) ?></a>
  <?php endif; ?>
  <div class="head">
    <div class="tool-ic<?= $t['status'] === 'active' ? ' green' : '' ?>"><?= Ui::toolIcon($t['icon']) ?></div>
    <div>
      <h3><a href="<?= e($href) ?>"><?= e($t['name']) ?></a></h3>
      <?php if ($t['category'] !== ''): ?><span class="muted small"><?= e($t['category']) ?></span><?php endif; ?>
    </div>
  </div>
  <p class="tag"><?= e($t['tagline']) ?></p>
  <?php if (!empty($m['badges'])): ?><div class="chips-s"><?php foreach (array_slice($m['badges'], 0, 3) as $bd): ?><span><?= e($bd) ?></span><?php endforeach; ?></div><?php endif; ?>
  <?php $tr = Trial::of($t); $btn = Trial::button($t, Auth::user()); ?>
  <?php if ($tr['badge'] !== ''): ?><p class="ptile-t t-<?= e($tr['state']) ?>"><span class="dot" aria-hidden="true"></span><b><?= e($tr['badge']) ?></b><?php if ($tr['state'] === 'nodemo'): ?><small>demo hali ulanmagan</small><?php endif; ?></p><?php endif; ?>
  <div class="foot">
    <a class="btn btn-sm btn-batafsil" href="<?= e($href) ?>"><?= e($ctaLabel) ?></a>
    <?php if ($btn): ?><a class="btn btn-sm btn-primary" href="<?= e($btn[0]) ?>"><?= e($btn[1]) ?></a>
    <?php else: ?><a class="btn btn-sm btn-ask" href="<?= e($href . '#sorov') ?>"><?= $tr['state'] === 'soon' ? 'Xabar olish' : "So'rov qoldirish" ?></a><?php endif; ?>
  </div>
</article>
