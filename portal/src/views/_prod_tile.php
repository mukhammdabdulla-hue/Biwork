<?php
/** Bosh sahifaning birinchi ekranidagi ixcham mahsulot kartasi. @var array $t */
$tr = Trial::of($t);
$href = Util::url('/' . $t['slug']);
$btn = Trial::button($t, Auth::user());
$soon = $tr['state'] === 'soon';
?>
<article class="ptile<?= $soon ? ' soon' : '' ?>" data-accent="<?= e(Marketing::theme($t)['accent']) ?>">
  <a class="ptile-h" href="<?= e($href) ?>">
    <span class="tool-ic<?= $t['status'] === 'active' ? ' green' : '' ?>"><?= Ui::toolIcon($t['icon']) ?></span>
    <span class="ptile-n"><b><?= e($t['name']) ?></b><?php if ($t['category'] !== ''): ?><small><?= e($t['category']) ?></small><?php endif; ?></span>
  </a>
  <p class="ptile-d"><?= e($t['tagline'] !== '' ? $t['tagline'] : $t['name']) ?></p>
  <?php if ($tr['badge'] !== ''): ?>
    <p class="ptile-t t-<?= e($tr['state']) ?>"><span class="dot" aria-hidden="true"></span><b><?= e($tr['badge']) ?></b><?php if ($tr['state'] === 'nodemo'): ?><small>demo hali ulanmagan</small><?php endif; ?></p>
  <?php endif; ?>
  <div class="ptile-b">
    <a class="btn btn-sm btn-batafsil" href="<?= e($href) ?>">Batafsil</a>
    <?php if ($btn): ?><a class="btn btn-sm btn-primary" href="<?= e($btn[0]) ?>"><?= e($btn[1]) ?></a>
    <?php else: ?><a class="btn btn-sm btn-ask" href="<?= e($href . '#sorov') ?>"><?= $soon ? 'Xabar olish' : "So'rov qoldirish" ?></a><?php endif; ?>
  </div>
</article>
