<?php
require_once __DIR__.'/legacy_operational_corrections.php';
/** Reviewed remaining amounts are evidence; source cash alone never proves a debt. */
function lop_create_payable(mysqli $db,int $unit,int $actor,array $owner,array $obligation,string $remaining): ?int {
    if(lf_cents($remaining)<=0)return $obligation['bill_id']?(int)$obligation['bill_id']:null;
    if(!$owner['student_id'])throw new DomainException('Kewajiban siswa harus terhubung ke siswa yang sah.');
    $student=lf_one($db,'SELECT * FROM siswa WHERE id=? FOR UPDATE',[$owner['student_id']]);$batch=lf_one($db,'SELECT source_id,cutoff FROM legacy_full_batches WHERE id=?',[$owner['batch_id']]);$review=lf_one($db,'SELECT review_id FROM legacy_full_students WHERE student_id=? AND batch_id=?',[$student['id'],$owner['batch_id']]);
    $source=lf_one($db,"SELECT row_key FROM legacy_finance_rows WHERE source_id=? AND TRIM(source_nis)=? AND source_table='siswa' LIMIT 1",[$batch['source_id'],$owner['source_identity']]);
    $category=$obligation['category'];$kind=['spp'=>'spp','komite'=>'komite','daftar_ulang'=>'du'][$category]??'other';$label=['spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang','psb'=>'Uang PSB'][$category]??null;if(!$label){$m=lf_one($db,'SELECT nama FROM master_biaya_lain WHERE id=?',[(int)substr($category,11)]);if(!$m)throw new DomainException('Master kewajiban tidak tersedia.');$label=$m['nama'];}
    $key=hash('sha256',($kind==='other'?'source:'.mb_strtolower(preg_replace('/\s+/u',' ',$label)):'kind:'.$kind).'|'.$obligation['period']);
    if(!$review||!$source)throw new DomainException('Bukti kewajiban belum terhubung.');
    lf_create_debt($db,$unit,$actor,['source_id'=>$batch['source_id'],'source_nis'=>$owner['source_identity'],'id'=>$review['review_id']],$student,['effective_date'=>substr($batch['cutoff'],0,10),'evidence'=>$obligation['evidence']],['component'=>$label,'period'=>$obligation['period'],'amount'=>$remaining,'kind'=>$kind,'obligation_key'=>$key,'source_row_key'=>$source['row_key'],'distinction'=>$obligation['evidence']]);
    return (int)lf_one($db,'SELECT bill_id FROM legacy_debts WHERE student_id=? AND obligation_key=?',[$student['id'],$key])['bill_id'];
}
function lop_apply_obligations(mysqli $db,int $unit,int $actor,string $batch,int $sourceId,array $sim,array $targets): void {
    $feeLocks=[];foreach($sim['owners'] as $person)foreach($person['decision']['coverage']??[] as $coverage)$feeLocks[]=$coverage;
    other_fee_lock_items($db,$unit,$feeLocks);
    foreach($sim['owners'] as $identity=>$person){if(!$person['ready']||!$person['student'])continue;$ownerId=(int)$targets[$identity]['id'];$owner=lf_one($db,'SELECT * FROM legacy_op_owners WHERE id=? FOR UPDATE',[$ownerId]);
        foreach($person['decision']['coverage']??[] as $coverage){
            $category=(string)($coverage['category']??'');$period=(string)($coverage['period']??'');$evidence=trim((string)($coverage['evidence']??''));
            if(!lful_period($period)||mb_strlen($evidence)<10||!in_array($coverage['disposition']??'',['settled','legacy_debt','not_applicable'],true)||(!in_array($category,['spp','komite','daftar_ulang','psb'],true)&&!preg_match('/^biaya_lain:[1-9][0-9]*$/D',$category)))throw new DomainException('Lengkapi kategori, periode, status dan bukti kewajiban.');
            if(in_array($category,['spp','komite'],true)&&(strlen($period)!==7||($period>substr($sim['cutoff'],0,7)&&$coverage['disposition']!=='settled')))throw new DomainException('Periode kewajiban awal harus bulanan dan tidak melewati peralihan.');
            $existing=lf_one($db,'SELECT * FROM legacy_op_obligations WHERE owner_id=? AND category=? AND period=? FOR UPDATE',[$ownerId,$category,$period]);if($existing)continue;
            $paid=0;$payments=lf_rows($db,'SELECT * FROM legacy_op_payments WHERE owner_id=? AND cancelled=0 ORDER BY id FOR UPDATE',[$ownerId]);$updates=[];
            foreach($payments as $payment){$items=json_decode($payment['items'],true);foreach($items as $n=>$item){$itemPeriod=lma_item_period($item,$payment['source_period']);$matches=in_array($category,['spp','komite'],true)?lop_month($itemPeriod)===$period:str_contains($itemPeriod,$period);if($item['category']!==$category||!$matches)continue;if(!empty($item['obligation_id']))throw new DomainException('Pembayaran sudah terhubung ke kewajiban lain.');$paid+=lf_cents($item['amount']);$updates[$payment['id']]=$items;}}
            $remaining=$coverage['disposition']==='legacy_debt'?lf_cents($coverage['amount']??null):0;$principal=isset($coverage['principal'])?lf_cents($coverage['principal']):$remaining+$paid;
            if($principal!==$remaining+$paid||($coverage['disposition']==='not_applicable'&&$paid>0))throw new DomainException('Nominal kewajiban tidak cocok dengan pembayaran dan sisa yang disahkan.');
            lf_query($db,'INSERT INTO legacy_op_obligations(unit_id,owner_id,category,period,principal,remaining,evidence) VALUES(?,?,?,?,?,?,?)',[$unit,$ownerId,$category,$period,lf_money($principal),lf_money($remaining),$evidence]);$obligation=(int)$db->insert_id;
            foreach($updates as $paymentId=>$items){$before=lf_one($db,'SELECT * FROM legacy_op_payments WHERE id=?',[$paymentId]);foreach($items as &$item)if($item['category']===$category)$item['obligation_id']=$obligation;unset($item);lf_query($db,'UPDATE legacy_op_payments SET items=?,version=version+1 WHERE id=?',[lf_json($items),$paymentId]);lf_query($db,'INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,?,?,?,?,?,?)',[$unit,$actor,'obligation_binding',$paymentId,hash('sha256',$batch.'|bind|'.$obligation.'|'.$paymentId),$evidence,lf_json($before),lf_json(['items'=>$items,'version'=>(int)$before['version']+1])]);}
            $ob=lf_one($db,'SELECT * FROM legacy_op_obligations WHERE id=?',[$obligation]);$bill=str_starts_with($category,'biaya_lain:')?null:lop_create_payable($db,$unit,$actor,$owner,$ob,lf_money($remaining));if($bill)lf_query($db,'UPDATE legacy_op_obligations SET bill_id=? WHERE id=?',[$bill,$obligation]);
            lf_query($db,'INSERT IGNORE INTO legacy_full_coverage(unit_id,batch_id,student_id,category,period,disposition,evidence) VALUES(?,?,?,?,?,?,?)',[$unit,$batch,$person['student']?$targets[$identity]['student_id']:null,$category,$period,$coverage['disposition'],$evidence]);
        }
    }
}
