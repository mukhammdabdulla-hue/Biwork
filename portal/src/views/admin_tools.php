<?php /** @var array $tools @var array $bundled paketda bor, lekin hali qo'shilmagan tayyor mahsulotlar (slug => manifest) */
$statusTone = ['active' => 'ok', 'soon' => 'warn', 'hidden' => ''];
?>
<div class="page-h">
  <div><h1>Mahsulotlar</h1><p>Saytda ko'rinadigan dasturlar. Har birining sahifasi shu yerda oddiy forma bilan yoziladi, saqlanganda sayt o'zi yangilanadi.</p></div>
  <a class="btn btn-primary" href="<?= e(Util::url('/admin/tools/new')) ?>"><?= Ui::icon('plus') ?> Yangi mahsulot</a>
</div>

<?php if ($bundled): ?>
<section class="card tool-adm" id="tayyor">
  <div class="card-h"><h2>Tayyor mahsulotlar: <?= count($bundled) ?> ta</h2><span class="muted small">paket ichida, hali qo'shilmagan</span></div>
  <div class="card-b">
    <p class="muted small" style="margin:0 0 12px">Bu mahsulotlarning matni, ekran rasmlari va savol-javoblari paketda tayyor. «Qo'shish» bosilsa, sahifasi darhol saytda ochiladi («Tez orada» belgisi bor mahsulot esa shu holatda: sinash o'rniga xabar olish shakli). Brauzerda sinab ko'rish manzili kiritilmaguncha «Sinab ko'rish» tugmasi chiqmaydi, faqat «So'rov qoldirish» bo'ladi. Narx tariflarda yozilmaguncha «so'rov bo'yicha» turadi.</p>
    <div class="bundled-list">
    <?php foreach ($bundled as $slug => $bm): ?>
      <form method="post" action="<?= e(Util::url('/admin/tools/bundled')) ?>" class="bundled-it" data-once>
        <?= Ui::csrf() ?><input type="hidden" name="slug" value="<?= e($slug) ?>">
        <span class="tool-ic green" style="width:34px;height:34px;border-radius:9px"><?= Ui::toolIcon((string)($bm['icon'] ?? 'tool')) ?></span>
        <span class="bundled-nm"><b><?= e($bm['name']) ?><?= ($bm['status'] ?? '') === 'soon' ? ' <em class="soon-tag">Tez orada</em>' : '' ?></b><small class="muted"><?= e((string)($bm['tagline'] ?? '')) ?></small></span>
        <button class="btn btn-sm" type="submit">Qo'shish</button>
      </form>
    <?php endforeach; ?>
    </div>
    <?php if (count($bundled) > 1): ?>
    <form method="post" action="<?= e(Util::url('/admin/tools/bundled')) ?>" style="margin-top:12px" data-once>
      <?= Ui::csrf() ?><input type="hidden" name="slug" value="all"><button class="btn btn-primary" type="submit">Hammasini qo'shish (<?= count($bundled) ?> ta)</button>
    </form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php $noUrl = array_values(array_filter($tools, static fn($t) => $t['status'] !== 'soon' && trim((string)$t['web_url']) === '')); // «tez orada» mahsulotning demosi bo'lmaydi ?>
<?php if (count($noUrl) > 1): ?>
<section class="card tool-adm" id="demo-manzil">
  <details class="tool-adm-more" style="border-top:0">
    <summary>Bitta demo manzilni bir nechta mahsulotga qo'yish (<?= count($noUrl) ?> ta mahsulotda manzil yo'q)</summary>
    <form class="card-b" method="post" action="<?= e(Util::url('/admin/tools/web-url')) ?>" data-once>
      <?= Ui::csrf() ?>
      <p class="muted small" style="margin:0 0 12px">Mahsulotlar bitta demo hostda ishlasa, manzilni har birining formasida alohida yozmasdan shu yerdan qo'ying. Manzil qo'yilgach, tanlangan mahsulot sahifalarida «Sinab ko'rish» tugmasi chiqadi: <b>demo host ishlayotganiga ishonch hosil qiling</b>.</p>
      <div class="field"><label class="f" for="wu">Brauzer (demo) manzili</label><input class="in" id="wu" name="url" maxlength="300" placeholder="https://demo.biwork.uz/" required></div>
      <div class="field"><span class="f">Qaysi mahsulotlarga</span>
        <?php foreach ($noUrl as $nt): ?><label class="check" style="margin:4px 0"><input type="checkbox" name="ids[]" value="<?= (int)$nt['id'] ?>" checked> <?= e($nt['name']) ?></label><?php endforeach; ?></div>
      <button class="btn btn-primary" type="submit">Manzilni qo'yish</button>
    </form>
  </details>
</section>
<?php endif; ?>

<?php if (!$tools): ?>
<section class="card"><div class="empty">Hali mahsulot yo'q.<br><a class="btn btn-primary" style="margin-top:14px" href="<?= e(Util::url('/admin/tools/new')) ?>">Birinchi mahsulotni qo'shish</a></div></section>
<?php endif; ?>

<?php foreach ($tools as $t):
    $total = (int)$t['ready_total'];
    $done = count($t['ready']);
    $pct = $total > 0 ? (int)round($done * 100 / $total) : 0;
    $editUrl = Util::url('/admin/tools/' . (int)$t['id'] . '/edit');
    $isActive = $t['status'] === 'active';
?>
<section class="card tool-adm" id="mahsulot-<?= (int)$t['id'] ?>">
  <div class="card-h">
    <div class="actions" style="min-width:0">
      <div class="tool-ic green" style="width:38px;height:38px"><?= Ui::toolIcon($t['icon']) ?></div>
      <div style="min-width:0">
        <h2><a href="<?= e($editUrl) ?>"><?= e($t['name']) ?></a></h2>
        <div class="muted small">/<?= e($t['slug']) ?> · kod <code><?= e($t['code']) ?></code> · <?= (int)$t['lic_count'] ?> ta litsenziya</div>
      </div>
    </div>
    <span class="badge <?= $statusTone[$t['status']] ?? '' ?>"><?= e(Catalog::STATUS_UZ[$t['status']] ?? $t['status']) ?></span>
  </div>
  <div class="card-b">
    <div class="tool-adm-row">
      <div>
        <div class="muted small" style="margin-bottom:6px">Sahifa to'ldirilganligi: <b><?= $done ?> / <?= $total ?></b></div>
        <div class="bar <?= $pct < 60 ? 'warn' : '' ?>" aria-hidden="true"><i style="width:<?= $pct ?>%"></i></div>
        <?php if ($t['missing']): ?>
          <p class="small" style="margin:8px 0 0;color:var(--warn)"><b>E'lon qilishdan oldin to'ldiring:</b> <?= e(implode(', ', $t['missing'])) ?>.</p>
        <?php elseif ($done < $total): ?>
          <p class="muted small" style="margin:8px 0 0">Yana to'ldirish mumkin: <?= e(implode(', ', array_column(array_filter(ToolEditor::readiness($t, $t['plans']), static fn($r) => !$r['ok']), 'label'))) ?>.</p>
        <?php else: ?>
          <p class="small" style="margin:8px 0 0;color:var(--ok)">Hammasi to'ldirilgan.</p>
        <?php endif; ?>
      </div>
      <div class="actions tool-adm-btns">
        <a class="btn btn-primary" href="<?= e($editUrl) ?>">Tahrirlash</a>
        <a class="btn" href="<?= e(Util::url('/' . $t['slug'])) ?>" target="_blank" rel="noopener"><?= $isActive ? 'Saytda ochish' : "Sahifani ko'rish" ?></a>
        <?php foreach ($isActive ? ['hide' => 'Yashirish'] : ($t['status'] === 'soon' ? ['publish' => "E'lon qilish", 'hide' => 'Yashirish'] : ['publish' => "E'lon qilish", 'soon' => 'Tez orada']) as $do => $label): ?>
          <form method="post" action="<?= e(Util::url('/admin/tools/' . (int)$t['id'] . '/status')) ?>" class="inline" data-once<?= $do === 'hide' ? ' data-confirm="' . e("Mahsulot saytdan yashiriladi (mijozlar uni ko'rmaydi). Davom etilsinmi?") . '"' : '' ?>>
            <?= Ui::csrf() ?><input type="hidden" name="do" value="<?= e($do) ?>"><button class="btn <?= $do === 'hide' ? 'btn-ghost' : '' ?>" type="submit"><?= e($label) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?= View::render('_admin_demos', ['t' => $t]) ?>

  <details class="tool-adm-more" id="rel-<?= (int)$t['id'] ?>"<?= (int)($_GET['rel'] ?? 0) === (int)$t['id'] ? ' open' : '' ?>>
    <summary>Yuklab olish paketlari (relizlar): <?= count($t['releases']) ?> ta</summary>
    <?php if (!$t['releases']): ?><div class="empty" style="padding:18px">Reliz yo'q</div><?php else: ?>
    <p class="muted small" style="margin:0;padding:12px 20px 0">Mijozning yuklab olish ro'yxatida «Oxirgi» belgili reliz birinchi turadi. Yangi relizda xato chiqsa, avvalgisida «Oxirgi qilish»ni bosing (kerak bo'lsa yangisini yashiring).</p>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Versiya</th><th>Turi</th><th>Fayl</th><th>Hajm</th><th>SHA-256</th><th></th></tr></thead><tbody>
    <?php foreach ($t['releases'] as $r): ?>
      <tr>
        <td><b>v<?= e($r['version']) ?></b> <?= (int)$r['is_latest'] ? '<span class="badge ok">Oxirgi</span>' : '' ?> <?= !(int)$r['active'] ? '<span class="badge">Yashirin</span>' : '' ?><div class="muted small"><?= e(Util::date((int)$r['published_at'])) ?></div>
          <details class="small" style="margin-top:4px"><summary class="muted" style="cursor:pointer"><?= $r['notes'] !== '' ? e($r['notes']) : 'Izoh yo\'q' ?> · o'zgartirish</summary>
            <form method="post" action="<?= e(Util::url('/admin/releases/' . $r['id'] . '/notes')) ?>" class="actions" style="margin-top:6px" data-once><?= Ui::csrf() ?><input class="in" name="notes" maxlength="1000" value="<?= e($r['notes']) ?>" placeholder="Nima o'zgardi" style="height:32px;min-width:220px;flex:1"><button class="btn btn-sm" type="submit">Saqlash</button></form></details></td>
        <td><?= e(Catalog::KIND_UZ[$r['kind']] ?? $r['kind']) ?></td><td class="small"><?= e($r['filename']) ?></td><td class="nowrap"><?= e(Util::bytes((int)$r['size'])) ?></td>
        <td><code class="small" title="<?= e($r['sha256']) ?>"><?= e(substr($r['sha256'], 0, 16)) ?>…</code></td>
        <td class="right nowrap"><?php if (!(int)$r['is_latest']): ?><form method="post" action="<?= e(Util::url('/admin/releases/' . $r['id'] . '/latest')) ?>" class="inline" data-once><?= Ui::csrf() ?><button class="btn btn-sm" type="submit">Oxirgi qilish</button></form> <?php endif; ?><form method="post" action="<?= e(Util::url('/admin/releases/' . $r['id'] . '/toggle')) ?>" class="inline"><?= Ui::csrf() ?><button class="btn btn-sm" type="submit"><?= (int)$r['active'] ? 'Yashirish' : 'Yoqish' ?></button></form></td>
      </tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <form class="card-b" method="post" enctype="multipart/form-data" action="<?= e(Util::url('/admin/tools/' . $t['id'] . '/releases')) ?>" style="border-top:1px solid var(--border)" data-once>
      <?= Ui::csrf() ?>
      <h3 style="margin-bottom:12px">Yangi reliz yuklash</h3>
      <div class="row3">
        <div class="field"><label class="f">Versiya</label><input class="in" name="version" required placeholder="1.8" maxlength="30"></div>
        <div class="field"><label class="f">Turi</label><select class="in" name="kind"><?php foreach (Catalog::KIND_UZ as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="f">Fayl</label><input class="in" name="file" type="file" required style="padding-top:7px"></div>
      </div>
      <div class="field"><label class="f">Izoh</label><input class="in" name="notes" maxlength="1000" placeholder="Nima o'zgardi (ixtiyoriy)"></div>
      <div class="actions"><label class="check"><input type="checkbox" name="latest" value="1" checked> Oxirgi reliz deb belgilash</label><button class="btn btn-primary" type="submit">Yuklash</button></div>
      <div class="hint">Katta fayl (&gt; <?= e(ini_get('upload_max_filesize')) ?>) hosting chegarasiga urilishi mumkin; unda <code>tools/cli.php release:add</code> ishlating.</div>
    </form>
  </details>
</section>
<?php endforeach; ?>

<section class="card tool-adm" style="margin-top:20px">
  <details class="tool-adm-more" style="border-top:0">
    <summary>Dasturchilar uchun: manifest.json orqali qo'shish</summary>
    <form class="card-b" method="post" enctype="multipart/form-data" action="<?= e(Util::url('/admin/tools/register')) ?>" data-once>
      <?= Ui::csrf() ?>
      <p class="muted small" style="margin:0 0 12px">Oddiy holatda mahsulot yuqoridagi «Yangi mahsulot» formasi bilan qo'shiladi. Mahsulot dasturchilari <code>manifest.json</code> tayyorlagan bo'lsa, shu yerdan yuklash mumkin (shartnoma: <code>docs/TOOL_CONTRACT.md</code>). Mavjud mahsulot qayta yuklansa, tariflar (narx) o'zgarmaydi, lekin <b>sahifa matnlari, rasm nomlari va brauzer manzili manifestdagilar bilan almashadi</b>: formada yozganingiz yo'qolishi mumkin.</p>
      <div class="actions"><input class="in" name="manifest" type="file" accept=".json,application/json" required style="padding-top:7px;max-width:380px"><button class="btn btn-primary" type="submit">Yuklash</button></div>
      <label class="check" style="margin-top:12px"><input type="checkbox" name="overwrite" value="1"> Mavjud mahsulotni almashtirish (shu manzilli mahsulot bo'lsa, matnlari manifestdagisi bilan almashadi)</label>
    </form>
  </details>
</section>
