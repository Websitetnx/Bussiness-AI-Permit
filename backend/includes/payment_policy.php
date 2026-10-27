<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) { http_response_code(404); exit; }

function quarterly_fee_options(): array
{
    return ['local_business_tax'=>'Local business tax','mayors_permit_fee'=>"Mayor's permit fee",'sanitary_fee'=>'Sanitary inspection','zoning_fee'=>'Zoning','general_inspection_fee'=>'General inspection','building_inspection_fee'=>'Building inspection','electrical_inspection_fee'=>'Electrical inspection','plumbing_inspection_fee'=>'Plumbing inspection','fire_safety_inspection_fee'=>'BFP fire safety inspection','barangay_clearance_fee'=>'Barangay clearance','community_tax_fee'=>'Community tax certificate'];
}

function validate_payment_policy(array $input): array
{
    $reference=trim((string)($input['policy_reference']??''));
    if (strlen($reference)<3 || strlen($reference)>255) throw new InvalidArgumentException('Enter the approved LGU policy or ordinance reference.');
    $mode=(string)($input['validity_mode']??'');$scheduleMode=(string)($input['schedule_mode']??'');
    if (!in_array($mode,['one_year','bplo_dates'],true) || !in_array($scheduleMode,['offsets','calendar'],true)) throw new InvalidArgumentException('Choose valid permit-term and deadline rules.');
    $integer=static function(string $key,int $min,int $max)use($input):int {
        $value=filter_var($input[$key]??null,FILTER_VALIDATE_INT);
        if ($value===false || $value===null || $value<$min || $value>$max) throw new InvalidArgumentException('Invalid value for '.str_replace('_',' ',$key).'.');
        return $value;
    };
    $keys=array_values(array_unique((array)($input['quarterly_fee_keys']??[])));
    if (array_diff($keys,array_keys(quarterly_fee_options()))) throw new InvalidArgumentException('An unknown fee was selected.');
    $enabled=isset($input['quarterly_enabled']);$schedule=[];
    if ($enabled) {
        if (!$keys) throw new InvalidArgumentException('Select at least one quarterly-eligible fee.');
        for($i=0;$i<4;$i++) {
            if ($scheduleMode==='offsets') $schedule[]=['months'=>$integer('months_'.$i,0,24),'day'=>$integer('day_'.$i,0,31)];
            else {
                $monthDay=(string)($input['month_day_'.$i]??''); permit_date('2024-'.$monthDay);
                if ($monthDay==='02-29') throw new InvalidArgumentException('Use February 28 for a recurring calendar deadline.');
                $schedule[]=['year_offset'=>$integer('year_offset_'.$i,0,2),'month_day'=>$monthDay];
            }
        }
        $previous='';
        foreach($schedule as $entry) { $due=installment_due_date('2025-01-01',$entry,$scheduleMode);if($due<=$previous)throw new InvalidArgumentException('Enter four deadlines in increasing order.');$previous=$due; }
    }
    return ['policy_reference'=>$reference,'validity_mode'=>$mode,'quarterly_enabled'=>$enabled?1:0,'quarterly_fee_keys'=>$keys,'schedule_mode'=>$scheduleMode,'installment_schedule'=>$schedule,'annual_due_days'=>$integer('annual_due_days',-366,366),'upfront_due_days'=>$integer('upfront_due_days',-366,366),'allow_installment_release'=>$enabled&&isset($input['allow_installment_release'])?1:0,'release_installments'=>$integer('release_installments',1,4),'payment_reminder_days'=>$integer('payment_reminder_days',0,365),'expiration_reminder_days'=>$integer('expiration_reminder_days',0,365)];
}

function renewal_payment_quote(PDO $pdo, array $record, array $input): array
{
    $gross=money_cents($input['gross_sales']??'');
    $effective=(string)($input['requested_effective_date']??'');permit_date($effective);
    $frequency=(string)($input['payment_frequency']??'');
    $application=['application_type'=>'Renewal','business_type'=>$record['business_type'],'gross_sales'=>cents_amount($gross)];
    foreach(['building','electrical','plumbing'] as $kind) $application['requires_'.$kind.'_inspection']=isset($input['requires_'.$kind.'_inspection'])?1:0;
    $assessment=permit_fee_assessment($pdo,$application);
    if (!$assessment) throw new RuntimeException('BPLO must configure the fee schedule before a renewal can be quoted.');
    $sourceAppId = (int) ($record['source_application_id'] ?? 0);
    $fsicInfo = $sourceAppId > 0 ? get_source_fsic_info($pdo, $sourceAppId) : null;
    $fsicExpiry = $fsicInfo['fsic_expiry_date'] ?? null;
    $penaltyDetails = calculate_fsic_penalty_details($assessment['total'], $fsicExpiry);
    if ($penaltyDetails['penalty_applied']) {
        $assessment = inject_fsic_penalty_to_assessment($assessment, $penaltyDetails);
    }
    $policy=permit_payment_policy($pdo);
    $plan=build_installment_plan($assessment,$frequency,$effective,$policy);
    unset($assessment['calculated_at']);
    return ['source_application_id'=>$sourceAppId,'assessment'=>$assessment,'plan'=>$plan,'fsic_penalty'=>$penaltyDetails];
}


function renewal_quote_signature(array $quote): string
{
    return hash('sha256',json_encode($quote,JSON_THROW_ON_ERROR));
}
