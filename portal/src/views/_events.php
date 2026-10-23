<?php /** @var array $rows @var bool $showCustomer */ ?>
<?php if (!$rows): ?><div class="empty">Hodisalar yo'q</div><?php else: ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Vaqt</th><th>Hodisa</th><?= !empty($showCustomer) ? '<th>Korxona</th>' : '' ?><th>Kim</th><th>Izoh</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr>
    <td class="nowrap muted"><?= e(Util::dateTime((int)$r['ts'])) ?></td>
    <td class="nowrap"><b><?= e(Ui::ACTION_UZ[$r['action']] ?? $r['action']) ?></b></td>
    <?php if (!empty($showCustomer)): ?><td><?= $r['customer_id'] ? '<a href="' . e(Util::url('/admin/customers/' . $r['customer_id'])) . '">' . e($r['customer_name'] ?? '#' . $r['customer_id']) . '</a>' : '—' ?></td><?php endif; ?>
    <td class="nowrap"><?= e(Ui::actor($r['actor'])) ?></td>
    <td class="muted"><?= e($r['detail']) ?><?= !empty($r['license_key']) ? ' <code class="small">' . e($r['license_key']) . '</code>' : '' ?></td>
  </tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
