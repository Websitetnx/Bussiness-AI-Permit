<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Reviewer assignments','review');
?>
<p>Deadlines are internal targets in <?= e(app_config('timezone')) ?>. Overdue alerts apply while an application is For Review; applicant corrections pause alerts.</p>
<?php if ($error): ?><p class="form-alert form-alert-error"><?= e($error) ?></p><?php endif; ?>
<form method="get"><label>Show <select name="filter"><?php foreach (['all','mine','overdue','unassigned'] as $v): ?><option <?= $filter===$v?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></label><button class="button">Filter</button></form>
<form method="post" class="panel ops-panel"><?= csrf_field() ?>
<label class="field">Application<select name="application_id" required><?php foreach ($rows as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)($_GET['application_id']??0)===(int)$r['id']?'selected':'' ?>><?= e($r['reference']) ?></option><?php endforeach; ?></select></label>
<label class="field">Reviewer<select name="reviewer_id" required><?php foreach ($reviewers as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
<label class="field">Review deadline<input type="datetime-local" name="due_at" required></label><button class="button">Assign / reassign</button></form>
<div class="panel ops-panel table-responsive"><table><thead><tr><th>Application</th><th>Status</th><th>Reviewer</th><th>Deadline</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><a href="<?= e(url('admin/review.php?id='.$r['id'])) ?>"><?= e($r['reference']) ?></a></td><td><?= e($r['status']) ?></td><td><?= e($r['name']??'Unassigned') ?><?= $r['name']&&!$r['is_active']?' (inactive — reassign)':'' ?></td><td><?= e($r['due_at']??'No deadline') ?> <?= $r['due_at'] && $r['status']==='For Review' && strtotime($r['due_at'])<time()?' — Overdue':'' ?></td></tr><?php endforeach; ?>
</tbody></table><p>Showing up to 200 applications. Use filters to narrow the queue.</p></div>
<details class="panel ops-panel"><summary>Recent assignment history</summary><?php foreach ($history as $h): ?><p><?= e($h['created_at'].' · '.$h['reference'].' · '.$h['name'].' · Due '.$h['due_at']) ?></p><?php endforeach; ?></details>
<?php render_app_footer(); ?>
