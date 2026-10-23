<?php
/** So'rov qoldirish shakli (hisobsiz). @var ?array $tool @var string $err @var array $in @var string $btn @var string $placeholder */
$in = $in ?? [];
$ph = ($placeholder ?? '') !== '' ? $placeholder : "Masalan: qaysi jarayonni avtomatlashtirmoqchisiz, korxonada nechta kishi ishlaydi?";
$tool = $tool ?? null;
$v = static fn(string $k): string => e($in[$k] ?? '');
?>
<form class="lead-form" method="post" action="<?= e(Util::url('/sorov')) ?>#sorov" data-once novalidate>
  <?php if (!empty($disabled)): ?><fieldset disabled class="lead-fs"><?php endif; ?>
  <?= Ui::csrf() ?>
  <input type="hidden" name="tool" value="<?= e($tool['slug'] ?? '') ?>">
  <input type="hidden" name="t" value="<?= ctype_digit((string)($in['t'] ?? '')) && (int)$in['t'] <= Util::now() ? (int)$in['t'] : Util::now() ?>">
  <div class="hp" aria-hidden="true"><label>Sayt manzili <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
  <?php if (($err ?? '') !== ''): ?><div class="flash err" role="alert"><?= e($err) ?></div><?php endif; ?>
  <div class="row2">
    <div class="field"><label class="f" for="lf-name">Ismingiz</label><input class="in" id="lf-name" name="name" value="<?= $v('name') ?>" autocomplete="name" required maxlength="100"></div>
    <div class="field"><label class="f" for="lf-phone">Telefon</label><input class="in" id="lf-phone" name="phone" type="tel" value="<?= $v('phone') ?>" placeholder="+998 90 123 45 67" autocomplete="tel" maxlength="40"></div>
  </div>
  <div class="row2">
    <div class="field"><label class="f" for="lf-company">Korxona <span class="faint">(ixtiyoriy)</span></label><input class="in" id="lf-company" name="company" value="<?= $v('company') ?>" autocomplete="organization" maxlength="150"></div>
    <div class="field"><label class="f" for="lf-email">Email <span class="faint">(ixtiyoriy)</span></label><input class="in" id="lf-email" name="email" type="email" value="<?= $v('email') ?>" autocomplete="email" maxlength="120"></div>
  </div>
  <div class="field"><label class="f" for="lf-msg">Savol yoki izoh <span class="faint">(ixtiyoriy)</span></label><textarea class="in" id="lf-msg" name="message" rows="3" maxlength="1000" placeholder="<?= e($ph) ?>"><?= $v('message') ?></textarea></div>
  <button class="btn btn-primary btn-lg btn-block" type="submit"><?= Ui::icon('send') ?> <?= e($btn ?? "So'rov yuborish") ?></button>
  <?php if (!empty($disabled)): ?></fieldset><p class="hint">Oldindan ko'rishda shakl ishlamaydi.</p><?php endif; ?>
  <p class="hint">Telefon yoki email yozing — shu orqali bog'lanamiz. Ma'lumotingiz faqat siz bilan bog'lanish uchun ishlatiladi.</p>
</form>
