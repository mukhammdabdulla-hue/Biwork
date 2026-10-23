<?php /** @var array $t mahsulot (Catalog::tools) — admin_tools.php kartasidan. Brauzer demosining versiyalari: ro'yxat, qaytarish, yangi versiya. */
$tid = (int)$t['id'];
$slug = (string)$t['slug'];
$demos = Demos::list($tid);
$curId = (int)($t['demo_id'] ?? 0);
$cur = null;
foreach ($demos as $dm) {
    if ((int)$dm['id'] === $curId) {
        $cur = $dm;
    }
}
$open = (int)($_GET['demo'] ?? 0) === $tid;
$in = Demos::takeForm($tid);
$rawUrl = (string)Db::value('SELECT web_url FROM tools WHERE id = ?', [$tid]);
$over = Config::raw('tool_urls', []);
$overUrl = is_array($over) && is_string($over[$slug] ?? null) ? $over[$slug] : '';
$curUrl = $cur ? ($cur['kind'] === 'url' ? (string)$cur['url'] : Demos::packageUrl($slug)) : '';
$href = static fn(string $u): string => str_starts_with($u, '/') ? Util::url($u) : $u;
$upMax = min(Media::iniBytes('upload_max_filesize'), Media::iniBytes('post_max_size'));
$zipOk = class_exists('ZipArchive');
$kind = in_array($in['kind'] ?? '', ['url', 'package'], true) ? $in['kind'] : ($cur['kind'] ?? 'url');
$newer = static fn(array $a, array $b): bool => [(int)$a['created_at'], (int)$a['id']] > [(int)$b['created_at'], (int)$b['id']];
?>
<?php if (!defined('BW_DEMOS_ASSETS')): define('BW_DEMOS_ASSETS', 1); ?>
<link rel="stylesheet" href="<?= e(Util::url('/assets/admin-demos.css')) ?>?v=<?= e(substr((string)@filemtime(BW_ROOT . '/public/assets/admin-demos.css'), -6)) ?>">
<script>
  // zip hajmi serverning yuklash chegarasidan katta bo'lsa — yubormasdan oldin aytamiz
  document.addEventListener('change', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('input[data-demo-max]')) return;
    var max = +f.getAttribute('data-demo-max'), msg = f.parentNode.querySelector('.demo-big');
    var big = max > 0 && f.files && f.files[0] && f.files[0].size > max;
    if (msg) msg.hidden = !big;
    f.setCustomValidity(big ? (msg ? msg.textContent : 'Fayl juda katta') : '');
  });
</script>
<?php endif; ?>
<details class="tool-adm-more demo-adm" id="demo-<?= $tid ?>"<?= $open ? ' open' : '' ?>>
  <summary>Demo versiyalari: <?= count($demos) ?> ta<?= $cur ? ' · joriy: v' . e($cur['version']) : '' ?></summary>
  <div class="card-b demo-now">
    <?php if ($cur): ?>
      <p>Saytdagi «Sinab ko'rish» va mijozning «Ochish» tugmasi hozir <b>v<?= e($cur['version']) ?></b> ni ochadi:
        <a class="demo-url" href="<?= e($href($curUrl)) ?>" target="_blank" rel="noopener"><?= e($curUrl) ?></a></p>
      <?php if ($cur['kind'] === 'package' && !Demos::deployed($slug)): ?>
        <div class="flash err">Joriy paket serverda ochilmagan (portal yangilanganda yoki mahsulot manzili o'zgarganda o'chgan bo'lishi mumkin), shuning uchun saytda «Sinab ko'rish» hozir chiqmaydi.
          <form method="post" action="<?= e(Util::url('/admin/demos/' . (int)$cur['id'] . '/activate')) ?>" class="inline" data-once><?= Ui::csrf() ?><button class="btn btn-sm" type="submit">Qayta joylashtirish</button></form></div>
      <?php endif; ?>
      <?php if ($rawUrl !== '' && $rawUrl !== $curUrl): ?>
        <p class="muted small">Mahsulot formasidagi «Brauzer manzili» (<code><?= e($rawUrl) ?></code>) hisobga olinmaydi: versiyalar yoqilgan, saytda joriy versiya ochiladi.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted small">Hali versiya yo'q. Hozir demo manzili mahsulot formasidagi «Brauzer manzili»dan olinadi: <?= $rawUrl !== '' ? '<code>' . e($rawUrl) . '</code>' : "<b>bo'sh</b> (saytda «Sinab ko'rish» chiqmaydi)" ?>.
        Birinchi versiya joriy qilingach, demo shu bo'limdan boshqariladi: yangi versiya qo'shish, eskisiga qaytarish.</p>
    <?php endif; ?>
    <?php if ($overUrl !== ''): ?>
      <p class="flash info">config.php dagi <code>tool_urls</code> bu mahsulot manzilini almashtiradi: saytda <code><?= e($overUrl) ?></code> ochiladi (sinov sozlamasi).</p>
    <?php endif; ?>
  </div>
  <?php if ($demos): ?>
  <div class="tbl-wrap"><table class="tbl demo-tbl"><thead><tr><th>Versiya</th><th>Turi</th><th>Qo'shilgan</th><th>Izoh</th><th>Holat</th><th></th></tr></thead><tbody>
  <?php foreach ($demos as $dm): $isCur = (int)$dm['id'] === $curId; ?>
    <tr<?= $isCur ? ' class="is-cur"' : '' ?>>
      <td class="nowrap"><b>v<?= e($dm['version']) ?></b></td>
      <td><?= e(Demos::KIND_UZ[$dm['kind']] ?? $dm['kind']) ?>
        <div class="muted small demo-url"><?= $dm['kind'] === 'url' ? e($dm['url']) : e(Util::bytes((int)$dm['size'])) . ' · <span title="SHA-256: ' . e($dm['sha256']) . '">' . e(substr((string)$dm['sha256'], 0, 10)) . '…</span>' ?></div></td>
      <td class="nowrap"><?= e(Util::dateTime((int)$dm['created_at'])) ?><?php if (!empty($dm['author'])): ?><div class="muted small"><?= e($dm['author']) ?></div><?php endif; ?></td>
      <td class="demo-notes small"><?= $dm['notes'] !== '' ? e($dm['notes']) : '<span class="faint">—</span>' ?></td>
      <td><?= $isCur ? '<span class="badge ok">Joriy</span>' : '' ?></td>
      <td class="right nowrap">
        <?php if ($isCur): ?>
          <?php if ($dm['kind'] === 'package'): ?>
          <form method="post" action="<?= e(Util::url('/admin/demos/' . (int)$dm['id'] . '/activate')) ?>" class="inline" data-once data-confirm="<?= e("Paket zipdan qaytadan ochiladi: demoda kiritilgan o'zgarishlar o'chib, boshlang'ich holatiga qaytadi. Davom etilsinmi?") ?>"><?= Ui::csrf() ?><button class="btn btn-sm" type="submit" title="Paketni zipdan qayta ochish">Qayta joylashtirish</button></form>
          <?php endif; ?>
        <?php else: $back = $cur !== null && $newer($cur, $dm); ?>
          <form method="post" action="<?= e(Util::url('/admin/demos/' . (int)$dm['id'] . '/activate')) ?>" class="inline" data-once<?= $back ? ' data-confirm="' . e("Saytdagi demo v{$dm['version']} ga qaytariladi. Davom etilsinmi?") . '"' : '' ?>><?= Ui::csrf() ?><button class="btn btn-sm <?= $back ? '' : 'btn-primary' ?>" type="submit"><?= $back ? 'Shu versiyaga qaytarish' : 'Joriy qilish' ?></button></form>
          <form method="post" action="<?= e(Util::url('/admin/demos/' . (int)$dm['id'] . '/delete')) ?>" class="inline" data-once data-confirm="<?= e("v{$dm['version']} o'chiriladi" . ($dm['kind'] === 'package' ? ' (zip fayli ham)' : '') . ". Qaytarib bo'lmaydi. Davom etilsinmi?") ?>"><?= Ui::csrf() ?><button class="btn btn-sm btn-ghost" type="submit">O'chirish</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <form class="card-b demo-form" method="post" enctype="multipart/form-data" action="<?= e(Util::url('/admin/tools/' . $tid . '/demos')) ?>" data-once>
    <?= Ui::csrf() ?>
    <h3>Yangi versiya qo'shish</h3>
    <div class="row3">
      <div class="field"><label class="f" for="dv-<?= $tid ?>">Versiya raqami</label>
        <input class="in" id="dv-<?= $tid ?>" name="version" required maxlength="20" pattern="[0-9A-Za-z][0-9A-Za-z._\-]{0,19}" value="<?= e($in['version'] ?? Demos::nextVersion($tid)) ?>">
        <div class="hint">Masalan 1.3. Har versiyaga yangi raqam.</div></div>
      <div class="field demo-kinds"><span class="f">Demo qayerda turadi</span>
        <label class="check"><input type="radio" name="kind" value="url"<?= $kind === 'url' ? ' checked' : '' ?>> Manzil (URL): demo alohida joyda ishlaydi</label>
        <label class="check"><input type="radio" name="kind" value="package"<?= $kind === 'package' ? ' checked' : '' ?><?= $zipOk ? '' : ' disabled' ?>> Paket (zip): portal o'zi joylashtiradi</label>
        <?php if (!$zipOk): ?><div class="hint" style="color:var(--warn)">Serverda PHP «zip» kengaytmasi yo'q: paket rejimi ishlamaydi.</div><?php endif; ?>
      </div>
    </div>
    <div class="field demo-f-url"><label class="f" for="du-<?= $tid ?>">Demo manzili</label>
      <input class="in" id="du-<?= $tid ?>" name="url" maxlength="300" inputmode="url" placeholder="https://<?= e($slug) ?>-demo.biwork.uz/" value="<?= e($in['url'] ?? '') ?>">
      <div class="hint">http:// yoki https:// bilan. Eng xavfsiz yo'l: demoni alohida subdomenda ishlatish.</div></div>
    <div class="field demo-f-pkg"><label class="f" for="dz-<?= $tid ?>">Zip fayl</label>
      <input class="in" id="dz-<?= $tid ?>" name="file" type="file" accept=".zip,application/zip" data-demo-max="<?= $upMax === PHP_INT_MAX ? 0 : $upMax ?>">
      <div class="hint demo-big" hidden style="color:var(--bad)">Fayl serverning yuklash chegarasidan katta (<?= $upMax === PHP_INT_MAX ? 'cheksiz' : e(Util::bytes($upMax)) ?>).</div>
      <div class="hint">Eng ko'pi <b><?= $upMax === PHP_INT_MAX ? 'chegara yo\'q' : e(Util::bytes($upMax)) ?></b> (server: upload_max_filesize = <?= e(ini_get('upload_max_filesize')) ?>, post_max_size = <?= e(ini_get('post_max_size')) ?>).
        Zip ochilganda eng yuqorida <code>index.php</code> yoki <code>index.html</code> tursin. Paket <code><?= e(Demos::packageUrl($slug)) ?></code> manzilida ochiladi va portal bilan bitta serverda ishlaydi: <b>faqat o'zingiz tayyorlagan, ishonchli paketni yuklang</b>.</div></div>
    <div class="field"><label class="f" for="dn-<?= $tid ?>">Izoh: nima o'zgardi</label>
      <textarea class="in" id="dn-<?= $tid ?>" name="notes" maxlength="500" rows="2" placeholder="Masalan: yangi hisobot oynasi, xatolar tuzatildi (ixtiyoriy)"><?= e($in['notes'] ?? '') ?></textarea></div>
    <div class="actions"><label class="check"><input type="checkbox" name="activate" value="1" checked> Darhol joriy qilish (saytda shu versiya ochiladi)</label><button class="btn btn-primary" type="submit">Saqlash</button></div>
  </form>
</details>
