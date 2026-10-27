<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2).'/includes/bootstrap.php';require dirname(__DIR__, 2).'/includes/layout.php';require dirname(__DIR__, 2).'/includes/reports.php';
$user=require_role(['admin','treasurer']);$type=(string)($_GET['type']??(is_treasurer_role($user['role'])?'collections':'applications'));
if (is_treasurer_role($user['role']) && !in_array($type,['collections','outstanding','overdue'],true)) {http_response_code(403);exit('Treasurer access is limited to financial reports.');}
$start=(string)($_GET['start']??date('Y-m-01'));$end=(string)($_GET['end']??date('Y-m-d'));
try {$rows=report_rows(db(),$type,report_range($start,$end));}catch(InvalidArgumentException $e){http_response_code(400);exit(e($e->getMessage()));}
header('Cache-Control: private, no-store');
if (($_GET['format']??'')==='csv') {
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="permit-'.$type.'-'.$start.'-'.$end.'.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    if ($rows) {fputcsv($out,array_keys($rows[0]),',','"','');foreach($rows as $r)fputcsv($out,array_map('csv_safe_cell',$r),',','"','');}
    else fputcsv($out,['No records in this date range'],',','"','');
    fclose($out);exit;
}
$types=is_treasurer_role($user['role'])?['collections']:['applications','processing','collections','revisions','renewals'];
if (installment_workflow_ready(db())) $types=array_merge($types,['outstanding','overdue']);
$descriptions=['applications'=>'Applications submitted in this period, grouped by current status.','processing'=>'Time from submission to first approval/release, grouped by first approval date. Calendar days include waiting for applicant corrections.','collections'=>'Verified Paid payments grouped by paid date; pending and failed payments are excluded.','revisions'=>'Revision history events and distinct affected applications per day. Daily distinct counts cannot be summed into a distinct period total.','renewals'=>'Renewals submitted in this period, grouped by current status.'];
$descriptions['collections']='Verified collections in the selected payment-date range. Each verified installment is counted once; its annual assessment is not added again.';
$descriptions['outstanding']='Current outstanding balances for assessments created in the selected date range. This is a current-balance report, not a historical balance snapshot.';
$descriptions['overdue']='Currently unpaid overdue installments with due dates in the selected range. Permit status is shown separately and is not automatically changed.';

require dirname(__DIR__, 3) . '/frontend/views/admin/reports.php';
