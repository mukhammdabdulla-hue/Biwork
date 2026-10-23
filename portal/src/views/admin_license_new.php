<?php /** @var string $error @var array $customers @var array $tools @var array $plans @var array $in */ ?>
<div class="crumbs"><a href="<?= e(Util::url('/admin/licenses')) ?>">Litsenziyalar</a> / Yangi</div>
<div class="page-h"><div><h1>Litsenziya berish</h1><p>To'lov tasdiqlangach kalit yaratiladi. Mijoz kalitni va yuklab olish tugmasini "Mening mahsulotlarim" da ko'radi.</p></div></div>
<?php if ($error !== ''): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card" style="max-width:760px" data-once id="lic-form"><div class="card-b">
  <?= Ui::csrf() ?><input type="hidden" name="request" value="<?= e($in['request'] ?? '') ?>">
  <div class="row2">
    <div class="field"><label class="f" for="customer">Korxona</label>
      <select class="in" id="customer" name="customer" required><option value="">— tanlang —</option>
        <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === ($in['customer'] ?? '') ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="f" for="tool">Mahsulot</label>
      <select class="in" id="tool" name="tool" required><option value="">— tanlang —</option>
        <?php foreach ($tools as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (string)$t['id'] === ($in['tool'] ?? '') ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="field"><label class="f" for="plan">Tarif</label>
    <select class="in" id="plan" name="plan" required>
      <option value="">— tanlang —</option>
      <?php foreach ($plans as $p): ?><option data-tool="<?= (int)$p['tool_id'] ?>" value="<?= e($p['code']) ?>" <?= $p['code'] === ($in['plan'] ?? '') ? 'selected' : '' ?>><?= e($p['name']) ?> — <?= $p['days'] !== null ? (int)$p['days'] . ' kun' : 'muddatsiz' ?>, <?= (int)$p['seats'] ?> o'rindiq<?= $p['type'] === 'trial' ? ' (sinov)' : '' ?></option><?php endforeach; ?>
      <option data-tool="*" value="_paid">Maxsus pullik (tarifsiz)</option>
      <option data-tool="*" value="_internal">Ichki / sinov uchun bepul (pullik emas)</option>
    </select>
    <div class="hint">Tarif muddati va o'rindiqlari avtomatik qo'yiladi; pastda o'zgartirishingiz mumkin.</div></div>
  <div class="row3">
    <div class="field"><label class="f" for="seats">O'rindiqlar</label><input class="in" id="seats" name="seats" type="number" min="1" max="999" value="<?= e($in['seats'] ?? '') ?>" placeholder="tarifdagidek"></div>
    <div class="field"><label class="f" for="until">Tugash sanasi</label><input class="in" id="until" name="until" type="date" value="<?= e($in['until'] ?? '') ?>"><div class="hint">Bo'sh = tarif muddati (maxsus tarifda — muddatsiz)</div></div>
    <div class="field"><label class="f" for="instance_url">Brauzer nusxasi (ixtiyoriy)</label><input class="in" id="instance_url" name="instance_url" placeholder="https://reja.mijoz.uz/" value="<?= e($in['instance_url'] ?? '') ?>"><div class="hint">Mijozning o'z serveridagi nusxa — "Ochish" shunga yo'naltiradi</div></div>
  </div>
  <div class="field"><label class="f" for="note">Ichki izoh</label><textarea class="in" id="note" name="note" maxlength="500"><?= e($in['note'] ?? '') ?></textarea></div>
  <div class="actions"><button class="btn btn-primary" type="submit">Kalit yaratish</button><a class="btn btn-ghost" href="<?= e(Util::url('/admin/licenses')) ?>">Bekor qilish</a></div>
</div></form>
<script>
(function () {
  var tool = document.getElementById('tool'), plan = document.getElementById('plan');
  function sync() {
    var t = tool.value, keep = false;
    Array.prototype.forEach.call(plan.options, function (o) {
      var d = o.getAttribute('data-tool');
      var show = !d || d === '*' || d === t;
      o.hidden = !show; o.disabled = !show;
      if (o.selected && !show) keep = true;
    });
    if (keep) plan.value = '';
  }
  tool.addEventListener('change', sync); sync();
})();
</script>
