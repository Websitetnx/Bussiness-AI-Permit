<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/installment_evidence.php';
require_once __DIR__ . '/payment_policy.php';
require_once __DIR__ . '/installment_reminders.php';
require_once __DIR__ . '/fsic_penalty.php';


function installment_workflow_ready(PDO $pdo): bool
{
    try { $pdo->query('SELECT id FROM permit_payment_policy LIMIT 1'); return true; }
    catch (PDOException) { return false; }
}

/** All schedule arithmetic is in integer centavos, never binary floats. */
function money_cents(mixed $amount): int
{
    $text = is_float($amount) ? number_format($amount, 2, '.', '') : trim((string) $amount);
    if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $text, $m)) throw new InvalidArgumentException('Enter a non-negative amount with at most two decimal places.');
    return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
}

function cents_amount(int $cents): string
{
    if ($cents < 0) throw new InvalidArgumentException('Amount cannot be negative.');
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function permit_date(string $value): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Enter a valid date (YYYY-MM-DD).');
    return $date;
}

function one_year_expiration(string $effective): string
{
    // Inclusive term. Feb 29 runs through Feb 28 of the following year.
    return permit_date($effective)->modify('+1 year')->modify('-1 day')->format('Y-m-d');
}

function permit_payment_policy(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM permit_payment_policy WHERE id=1')->fetch();
    if (!$row) throw new RuntimeException('Configure the LGU permit/payment policy.');
    $row['quarterly_fee_keys'] = json_decode((string) $row['quarterly_fee_keys'], true, 512, JSON_THROW_ON_ERROR);
    $row['installment_schedule'] = json_decode((string) $row['installment_schedule'], true, 512, JSON_THROW_ON_ERROR);
    return $row;
}

function installment_due_date(string $effective, array $entry, string $mode): string
{
    $start = permit_date($effective);
    if ($mode === 'calendar') {
        $year = (int) $start->format('Y') + (int) ($entry['year_offset'] ?? 0);
        return permit_date($year . '-' . (string) ($entry['month_day'] ?? ''))->format('Y-m-d');
    }
    if ($mode !== 'offsets') throw new InvalidArgumentException('Unknown installment deadline rule.');
    $month = $start->modify('first day of this month')->modify('+' . (int) ($entry['months'] ?? 0) . ' months');
    $day = (int) ($entry['day'] ?? 0) ?: (int) $start->format('j');
    return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min($day, (int) $month->format('t')))->format('Y-m-d');
}

function build_installment_plan(array $assessment, string $frequency, string $effective, array $policy): array
{
    permit_date($effective);
    if (!in_array($frequency, ['Annual', 'Quarterly'], true)) throw new InvalidArgumentException('Choose Annual or Quarterly payment.');
    $total = money_cents($assessment['total']);
    if ($total <= 0) throw new InvalidArgumentException('The assessed amount must be greater than zero.');
    $eligible = 0; $sum = 0; $fees = [];
    foreach ($assessment['components'] as $component) {
        $cents = money_cents($component['amount']);
        $isEligible = in_array($component['key'], (array) $policy['quarterly_fee_keys'], true);
        $sum += $cents;
        if ($isEligible) $eligible += $cents;
        $fees[] = $component + ['quarterly_eligible' => $isEligible];
    }
    if ($sum !== $total) throw new InvalidArgumentException('Assessment components do not match the total.');
    $rows = [];
    if ($frequency === 'Annual') {
        $due = permit_date($effective)->modify(sprintf('%+d days', (int) $policy['annual_due_days']))->format('Y-m-d');
        $rows[] = ['sequence_no'=>1,'label'=>'Annual payment','amount'=>cents_amount($total),'due_date'=>$due,'required_before_release'=>1];
    } else {
        if (empty($policy['quarterly_enabled']) || trim((string) $policy['policy_reference']) === '') throw new InvalidArgumentException('Quarterly payments are not enabled by the LGU.');
        if ($eligible < 4 || count($policy['installment_schedule']) !== 4) throw new InvalidArgumentException('Quarterly payment needs eligible fees and four configured deadlines.');
        $upfront = $total - $eligible;
        if ($upfront > 0) $rows[] = ['sequence_no'=>0,'label'=>'Fees payable upfront','amount'=>cents_amount($upfront),'due_date'=>permit_date($effective)->modify(sprintf('%+d days', (int) $policy['upfront_due_days']))->format('Y-m-d'),'required_before_release'=>1];
        $previous = null;
        foreach ($policy['installment_schedule'] as $index => $entry) {
            $due = installment_due_date($effective, $entry, $policy['schedule_mode']);
            if ($previous !== null && $due <= $previous) throw new InvalidArgumentException('Quarterly deadlines must be strictly increasing.');
            $previous = $due;
            $amount = $index === 3 ? $eligible - intdiv($eligible, 4) * 3 : intdiv($eligible, 4);
            $rows[] = ['sequence_no'=>$index+1,'label'=>'Quarter '.($index+1),'amount'=>cents_amount($amount),'due_date'=>$due,'required_before_release'=>empty($policy['allow_installment_release']) || $index < (int)$policy['release_installments'] ? 1 : 0];
        }
    }
    $dueNow = 0;
    foreach ($rows as $row) if ($row['due_date'] <= date('Y-m-d') || $row['required_before_release']) $dueNow += money_cents($row['amount']);
    return ['frequency'=>$frequency,'annual_assessment'=>cents_amount($total),'eligible_amount'=>cents_amount($frequency==='Quarterly'?$eligible:0),'upfront_amount'=>cents_amount($frequency==='Quarterly'?$total-$eligible:0),'amount_due_now'=>cents_amount($dueNow),'remaining_after_due_now'=>cents_amount($total-$dueNow),'effective_date'=>$effective,'fees'=>$fees,'installments'=>$rows,'policy'=>$policy];
}

function payment_installments(PDO $pdo, int $paymentId, bool $lock = false): array
{
    $q=$pdo->prepare('SELECT * FROM payment_installments WHERE payment_id=? ORDER BY sequence_no' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$paymentId]); return $q->fetchAll();
}

function installment_balance(array $payment, array $rows, ?string $today = null): array
{
    $today ??= date('Y-m-d'); $paid=0; $overdue=0; $due=0; $required=0; $sum=0;
    foreach ($rows as $row) {
        $amount=money_cents($row['amount']); $sum+=$amount;
        if ($row['status']==='Paid') { $paid+=$amount; continue; }
        if ($row['due_date'] < $today) $overdue+=$amount;
        if ($row['due_date'] <= $today || $row['required_before_release']) $due+=$amount;
        if ($row['required_before_release'] || $row['due_date'] <= $today) $required+=$amount;
    }
    $total=money_cents($payment['amount']);
    $valid = count($rows)>0 && $sum===$total;
    $snapshot=json_decode((string)($payment['plan_snapshot']??''),true)?:[];
    $quarterly=($payment['payment_frequency']??'Annual')==='Quarterly';
    $full=$valid && $paid===$total;
    $canRelease=$full || ($valid && $quarterly && !empty($snapshot['policy']['allow_installment_release']) && $required===0);
    return ['total'=>cents_amount($total),'paid'=>cents_amount($paid),'remaining'=>cents_amount(max(0,$total-$paid)),'due_now'=>cents_amount($due),'overdue'=>cents_amount($overdue),'fully_paid'=>$full,'can_release'=>$canRelease,'status'=>$full?'Paid':($paid>0?'Partially Paid':'Pending'),'valid_schedule'=>$valid];
}

/** Caller owns an application lock and transaction; existing plans are immutable. */
function create_payment_plan(PDO $pdo, int $applicationId, array $assessment, array $plan, int $staffId): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Assessment creation requires a transaction.');
    $q=$pdo->prepare('SELECT * FROM payments WHERE application_id=? FOR UPDATE'); $q->execute([$applicationId]); $existing=$q->fetch();
    if ($existing) {
        if (money_cents($existing['amount'])!==money_cents($assessment['total']) || $existing['payment_frequency']!==$plan['frequency']) throw new RuntimeException('An assessment already exists. Existing installments and receipts cannot be replaced.');
        return (int)$existing['id'];
    }
    $q=$pdo->prepare("INSERT INTO payments(application_id,amount,assessment_breakdown,assessed_by,assessed_at,status,payment_frequency,plan_snapshot) VALUES(?,?,?,?,NOW(),'Pending',?,?)");
    $q->execute([$applicationId,$plan['annual_assessment'],json_encode($assessment,JSON_THROW_ON_ERROR),$staffId,$plan['frequency'],json_encode($plan,JSON_THROW_ON_ERROR)]);
    $id=(int)$pdo->lastInsertId();
    $q=$pdo->prepare('INSERT INTO payment_installments(payment_id,sequence_no,label,amount,due_date,required_before_release) VALUES(?,?,?,?,?,?)');
    foreach($plan['installments'] as $row) $q->execute([$id,$row['sequence_no'],$row['label'],$row['amount'],$row['due_date'],$row['required_before_release']]);
    return $id;
}

function synchronize_payment_balance(PDO $pdo, int $paymentId): array
{
    $q=$pdo->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');$q->execute([$paymentId]);$payment=$q->fetch();
    $balance=installment_balance($payment,payment_installments($pdo,$paymentId,true));
    $q=$pdo->prepare("UPDATE payments SET status=?,paid_at=CASE WHEN ?=1 THEN COALESCE(paid_at,NOW()) ELSE NULL END WHERE id=?");
    $q->execute([$balance['status'],$balance['fully_paid']?1:0,$paymentId]);
    return $balance;
}

function confirm_permit_term(PDO $pdo, array $application, string $effective, string $expiration, int $staffId, array $policy): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Permit date confirmation requires a transaction and application lock.');
    $start=permit_date($effective); $end=permit_date($expiration);
    if ($end<$start) throw new InvalidArgumentException('Expiration cannot precede the effective date.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE application_id=?');$q->execute([$application['id']]);
    $assessed=(int)$q->fetchColumn()>0;
    if (($application['status']==='Released' || $assessed) && !empty($application['permit_effective_date'])) {
        if ($effective!==$application['permit_effective_date'] || $expiration!==$application['permit_expiration_date']) throw new RuntimeException('Dates are locked once an assessment exists or a permit is released. Payments cannot extend the term.');
        // Preserve the original confirmation and its policy even if settings later change.
        if (!empty($application['validity_confirmed_at'])) return;
    }
    if ($policy['validity_mode']==='one_year' && $expiration!==one_year_expiration($effective)) throw new InvalidArgumentException('This policy requires a one-year inclusive term; expected expiration: '.one_year_expiration($effective).'.');
    if (trim((string)$policy['policy_reference'])==='') throw new InvalidArgumentException('Configure the LGU approved policy reference before confirming dates.');
    $q=$pdo->prepare("UPDATE applications SET permit_effective_date=?,permit_expiration_date=?,validity_source='bplo',validity_confirmed_by=?,validity_confirmed_at=NOW(),validity_policy_reference=? WHERE id=?");
    $q->execute([$effective,$expiration,$staffId,$policy['policy_reference'],$application['id']]);
}

function assert_permit_release(PDO $pdo, array $application): void
{
    if (empty($application['permit_effective_date']) || empty($application['permit_expiration_date']) || empty($application['validity_confirmed_at'])) throw new RuntimeException('BPLO must confirm the effective and expiration dates before release.');
    if ($application['permit_expiration_date']<date('Y-m-d')) throw new RuntimeException('The confirmed term has already expired; review the permit dates.');
    $q=$pdo->prepare('SELECT * FROM payments WHERE application_id=? FOR UPDATE');$q->execute([$application['id']]);$payment=$q->fetch();
    if (!$payment || !installment_balance($payment,payment_installments($pdo,(int)$payment['id'],true))['can_release']) throw new RuntimeException('Release is locked: verify all amounts required by the payment plan, including amounts already due.');
}
