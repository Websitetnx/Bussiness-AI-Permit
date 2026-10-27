<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Permit verification</title><link rel="stylesheet" href="<?= e(url('frontend/assets/styles.css')) ?>"></head>
<body><main class="verification-page panel ops-panel"><h1>PERMIT verification</h1>
<?php if($permit): ?><dl><?php foreach(['permit_number'=>'Permit number','status'=>'Current status','issued_at'=>'Issued','effective_date'=>'Effective date','valid_until'=>'Expiration date (inclusive)'] as $key=>$label): ?><dt><?= e($label) ?></dt><dd><?= e($permit[$key]) ?></dd><?php endforeach; ?></dl><p>Installment payment dates do not change permit validity.</p>
<p>Checked <?= e(date('Y-m-d H:i:s').' '.app_config('timezone')) ?>.</p><p>This verifies the issued permit record in this system. It does not authenticate uploaded supporting documents. Confirm that this is your LGU’s official website.</p>
<?php else: ?><h2>Permit record not found</h2><p>Check the QR code or contact the issuing BPLO.</p><?php endif; ?>
</main></body></html>
