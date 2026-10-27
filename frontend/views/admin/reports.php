<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Reports and exports', 'reports');

// ---------- quick aggregate stats for the active report ----------
$rowCount     = count($rows);
$numericTotals = [];
if ($rowCount > 0) {
    foreach (array_keys($rows[0]) as $column) {
        $isNumeric = true;
        $sum = 0.0;
        foreach ($rows as $r) {
            $v = $r[$column];
            if ($v === null || $v === '') { continue; }
            if (!is_numeric($v)) { $isNumeric = false; break; }
            $sum += (float) $v;
        }
        if ($isNumeric && $sum > 0) {
            $numericTotals[$column] = $sum;
        }
    }
}
// Pick the most useful stats to display
$statCards = [];
foreach ($numericTotals as $col => $total) {
    $label = ucwords(str_replace('_', ' ', $col));
    $formatted = $total == (int) $total ? number_format((int) $total) : number_format($total, 2);
    if (stripos($col, 'php') !== false || stripos($col, 'amount') !== false || stripos($col, 'assessment') !== false || stripos($col, 'balance') !== false || stripos($col, 'overdue') !== false) {
        $formatted = '₱' . $formatted;
    }
    $statCards[] = ['label' => $label, 'value' => $formatted];
    if (count($statCards) >= 4) break;
}

// Icons for report types
$typeIcons = [
    'applications' => '▤',
    'processing'   => '◷',
    'collections'  => '₱',
    'revisions'    => '↻',
    'renewals'     => '↺',
    'outstanding'  => '◇',
    'overdue'      => '!',
];
$typeIcon = $typeIcons[$type] ?? '▤';
?>

<div class="section-heading">
  <div>
    <p class="eyebrow">Administrative reporting</p>
    <h2>Reports and exports</h2>
    <p class="muted">Generate, review, and export operational reports for the selected date range.</p>
  </div>
</div>

<!-- ──────────── Filter Toolbar ──────────── -->
<article class="panel reports-filter-panel">
  <div class="panel-header">
    <div><p class="eyebrow">Report parameters</p><h3>Select report and date range</h3></div>
  </div>
  <form method="get" class="reports-filter-form">
    <div class="reports-filter-grid">
      <label class="field">
        Report type
        <select name="type" id="report-type-select">
          <?php foreach ($types as $t): ?>
            <option value="<?= e($t) ?>" <?= $t === $type ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        From
        <input type="date" name="start" id="report-start-date" value="<?= e($start) ?>" required>
      </label>
      <label class="field">
        Through
        <input type="date" name="end" id="report-end-date" value="<?= e($end) ?>" required>
      </label>
      <div class="reports-filter-actions">
        <button class="button" id="report-view-btn" type="submit"><span>⌕</span> View report</button>
      </div>
    </div>
  </form>
</article>

<!-- ──────────── Report Description ──────────── -->
<div class="reports-description-bar">
  <span class="reports-description-icon"><?= $typeIcon ?></span>
  <p><?= e($descriptions[$type]) ?> Date filters include both selected days.</p>
</div>

<!-- ──────────── Quick Stats ──────────── -->
<?php if ($statCards): ?>
<div class="stat-grid reports-stat-grid">
  <?php foreach ($statCards as $index => $card): ?>
  <article class="stat-card">
    <span><?= ['↗', '◷', '▤', '◇'][$index % 4] ?></span>
    <strong><?= e($card['value']) ?></strong>
    <small><?= e($card['label']) ?></small>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ──────────── Export Toolbar ──────────── -->
<div class="reports-export-bar">
  <div class="reports-export-info">
    <strong><?= e(ucfirst($type)) ?> report</strong>
    <small class="muted"><?= e($start) ?> to <?= e($end) ?> · Generated <?= e(date('M j, Y g:i A')) ?> <?= e(app_config('timezone')) ?></small>
  </div>
  <div class="reports-export-actions">
    <a class="button" id="report-export-csv-btn" href="?<?= e(http_build_query(['type' => $type, 'start' => $start, 'end' => $end, 'format' => 'csv'])) ?>"><span>⤓</span> Export CSV</a>
    <button class="button button-secondary" id="report-print-btn" onclick="window.print()"><span>⎙</span> Print / Save PDF</button>
  </div>
</div>

<!-- ──────────── Data Table ──────────── -->
<article class="panel table-panel reports-table-panel">
  <div class="panel-header">
    <div>
      <p class="eyebrow"><?= e(ucfirst($type)) ?> data</p>
      <h3><?= e(ucfirst($type) . ' report: ' . $start . ' to ' . $end) ?></h3>
    </div>
    <span class="document-count"><?= $rowCount ?> <?= $rowCount === 1 ? 'record' : 'records' ?></span>
  </div>
  <?php if (!$rows): ?>
    <div class="empty-state reports-empty-state">
      <div class="reports-empty-icon">⌕</div>
      <p><strong>No matching records</strong></p>
      <p>Try adjusting the date range or selecting a different report type.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <?php foreach (array_keys($rows[0]) as $k): ?>
              <th><?= e(ucwords(str_replace('_', ' ', $k))) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $rowIndex => $r): ?>
            <tr>
              <?php foreach ($r as $colKey => $v):
                $cellValue = $v ?? 'Not specified';
                // Format currency-like columns
                if (is_numeric($v) && (stripos($colKey, 'php') !== false || stripos($colKey, 'amount') !== false || stripos($colKey, 'assessment') !== false || stripos($colKey, 'balance') !== false || stripos($colKey, 'total') !== false)) {
                    $cellValue = '₱' . number_format((float)$v, 2);
                }
              ?>
                <td><?= e($cellValue) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if (count($numericTotals) > 0): ?>
        <tfoot>
          <tr>
            <?php
            $firstCol = true;
            foreach (array_keys($rows[0]) as $colKey):
              if ($firstCol) {
                  echo '<td><strong>Totals</strong></td>';
                  $firstCol = false;
                  continue;
              }
              if (isset($numericTotals[$colKey])) {
                  $val = $numericTotals[$colKey];
                  $display = $val == (int)$val ? number_format((int)$val) : number_format($val, 2);
                  if (stripos($colKey, 'php') !== false || stripos($colKey, 'amount') !== false || stripos($colKey, 'assessment') !== false || stripos($colKey, 'balance') !== false || stripos($colKey, 'total') !== false) {
                      $display = '₱' . $display;
                  }
                  echo '<td><strong>' . e($display) . '</strong></td>';
              } else {
                  echo '<td></td>';
              }
            endforeach;
            ?>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  <?php endif; ?>
</article>

<div class="form-actions" style="margin-top:18px">
  <a class="button button-secondary" href="index.php">← Back to overview</a>
</div>

<?php render_app_footer(); ?>
