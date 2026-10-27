<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Document version history',$admin?'review':'track');
?>
<p><?= e($a['reference']) ?> · Previous uploads are retained. Internal reviewer notes are visible only to BPLO administrators.</p>
<?php foreach ($versions as $v): ?><article class="panel ops-panel"><h3><?= e(document_definitions()[$v['document_type']][0]??$v['document_type']) ?> — <?= $v['stored_name']===$v['current_file']?'Current':'Previous' ?></h3>
<p><a href="<?= e(url('document-version.php?id='.$v['id'])) ?>" target="_blank" rel="noopener"><?= e($v['original_name']) ?></a></p><p>Uploaded <?= e($v['uploaded_at']) ?> by <?= e($v['uploader']??'Unknown') ?><?= $v['archived_at']?' · Replaced '.e($v['archived_at']):'' ?></p>
<?php foreach($versionScans[$v['id']] as $s):$scan=json_decode($s['result_json'],true)?:[]; ?>
<details><summary>AI result · <?= e($s['scanned_at'].' · '.($scan['scan_status']??'')) ?></summary><p><?= e($scan['summary']??'No completed assessment.') ?></p><p>Type: <?= e($scan['detected_document_type']??'Unknown') ?> · Quality: <?= e($scan['quality_score']??'N/A') ?> · Confidence: <?= e($scan['confidence_score']??'N/A') ?></p><ul><?php foreach(document_scan_advisory_issues($scan['issues']??null) as $issue): ?><li><?= e($issue) ?></li><?php endforeach; ?></ul>
<?php if($admin): ?><pre><?= e(json_encode($scan,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?></details><?php endforeach; ?>
<?php if($admin): foreach($versionNotes[$v['id']] as $n): ?><p><?= e($n['created_at'].' · '.$n['name'].': '.$n['note']) ?></p><?php endforeach; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>"><label class="field">Internal reviewer note<textarea name="note" required maxlength="4000"></textarea></label><button class="button">Save note</button></form><?php endif; ?></article><?php endforeach; ?>
<?php render_app_footer(); ?>
