<?php /** Rahmat sahifasi (reklama konversiyasi shu manzilga yetib kelganda hisoblanadi: /rahmat). @var ?array $t */ ?>
<section class="section"<?= $t ? ' data-accent="' . e(Marketing::theme($t)['accent']) . '"' : '' ?>><div class="wrap narrow thanks">
  <span class="ok-ic"><?= Ui::icon('check') ?></span>
  <h1>Rahmat! So'rovingiz qabul qilindi</h1>
  <p class="sub">Siz qoldirgan telefon yoki email orqali tez orada bog'lanamiz.<?= $t ? ' Savol: ' . e($t['name']) . '.' : '' ?></p>
  <div class="actions" style="justify-content:center">
    <?php if ($t && $t['status'] === 'active' && trim((string)$t['web_url']) !== ''): ?><a class="btn btn-primary btn-lg" href="<?= e(Util::url('/register?tool=' . $t['slug'])) ?>"><?= !empty($t['trial_enabled']) ? "Kutmasdan, hozir sinab ko'ring" : "Ro'yxatdan o'tish" ?></a><?php endif; ?>
    <a class="btn btn-lg" href="<?= e(Util::url($t ? '/' . $t['slug'] : '/mahsulotlar')) ?>"><?= $t ? e($t['name']) . ' sahifasiga qaytish' : 'Mahsulotlarni ko\'rish' ?></a>
  </div>
</div></section>
