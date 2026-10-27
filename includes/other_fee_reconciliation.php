<?php
require_once __DIR__.'/other_fee_billing.php';
require_once __DIR__.'/legacy_billing.php';

function ofb_period(string $raw): ?string {
    $raw=trim($raw);if(preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D',$raw))return $raw;
    if(preg_match('/^(0?[1-9]|1[0-2])\s+(20\d{2})$/D',$raw,$m))return $m[2].'-'.str_pad($m[1],2,'0',STR_PAD_LEFT);
    return null;
}
/** Same official stage for source publication and existing batches. Caller owns transaction. */
function ofb_reconcile(mysqli $db,int $actor): array {
    if(!ofb_schema_ready($db))throw new DomainException('Pasang installer tagihan Biaya Lain melalui CLI.');
    $out=['rule'=>OFB_RULE_VERSION,'changes'=>0,'bills_created'=>0,'credits_bound'=>0,'native_bound'=>0,'unproven'=>0,'exceptions'=>[]];$batches=[];
    $details=ofb_legacy_details($db);$already=[];foreach(hp_rows($db,'SELECT * FROM other_fee_legacy_credits') as $c)$already[$c['unit_id'].'|'.$c['detail_key']]=$c;
    $obligations=[];foreach(hp_rows($db,"SELECT ob.*,o.student_id FROM legacy_op_obligations ob JOIN legacy_op_owners o ON o.id=ob.owner_id AND o.unit_id=ob.unit_id WHERE ob.category REGEXP '^biaya_lain:[1-9][0-9]*$'") as $ob)$obligations[$ob['id']]=$ob;
    $groups=[];
    foreach($details as $key=>$d){
        if(isset($already[$key]))continue;
        $ob=$obligations[$d['obligation_id']??0]??null;
        if(!$ob||(int)$ob['student_id']!==(int)$d['student_id']||$ob['category']!=='biaya_lain:'.$d['master_id']){$out['unproven']++;continue;}
        $groups[$ob['id']]['obligation']=$ob;$groups[$ob['id']]['details'][]=$d;
    }
    foreach($groups as $g){$ob=$g['obligation'];$ds=$g['details'];$sum=array_sum(array_map(static fn($d)=>ofb_cents($d['amount']),$ds));
        $classes=array_values(array_unique(array_column($ds,'kelas')));$principal=ofb_cents($ob['principal']);
        if(count($classes)!==1||$principal<=0||$principal<$sum){$out['exceptions'][]=['obligation_id'=>$ob['id'],'reason'=>'Kelas/nominal kewajiban belum konsisten.'];continue;}
        if($ob['bill_id']){
            // Old debt bills represent the remaining amount, not the gross historical principal.
            $bill=hp_one($db,'SELECT * FROM tagihan_biaya_lain WHERE id=? FOR UPDATE',[$ob['bill_id']]);
            if(!$bill||$bill['status']==='cancelled'||$bill['no_induk']!==$ds[0]['nis']||(int)$bill['master_biaya_lain_id']!==(int)$ds[0]['master_id']
                ||(!empty($bill['obligation_period'])&&$bill['obligation_period']!==$ob['period'])
                ||(!empty($bill['kelas_rombel_snapshot'])&&$bill['kelas_rombel_snapshot']!==$classes[0])
                ||ofb_cents($bill['nominal_tagihan'])!==$principal||hp_one($db,'SELECT id FROM legacy_debts WHERE bill_id=?',[$bill['id']])){$out['exceptions'][]=['obligation_id'=>$ob['id'],'reason'=>'Tagihan pembukaan/koreksi atau identitas/periode berbeda dipertahankan; kredit tidak dialokasikan ulang.'];continue;}
        }else{
            if(ofb_cents($ob['remaining'])!==$principal-$sum){$out['exceptions'][]=['obligation_id'=>$ob['id'],'reason'=>'Total kredit dan sisa belum membuktikan kewajiban.'];continue;}
            $master=hp_one($db,'SELECT * FROM master_biaya_lain WHERE id=? FOR UPDATE',[$ds[0]['master_id']]);if(!$master)continue;
            $occurrence='legacy:obligation:'.$ob['id'];
            $bill=hp_one($db,'SELECT * FROM tagihan_biaya_lain WHERE master_biaya_lain_id=? AND no_induk=? AND occurrence_key=? FOR UPDATE',[$master['id'],$ds[0]['nis'],$occurrence]);
            if(!$bill){ofb_write($db,"INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot,created_by,occurrence_key,origin,obligation_period,provenance) VALUES(?,?,?,?,?,?,?,'legacy',?,?)",[$master['id'],$ds[0]['nis'],$master['nama'],ofb_money($principal),$classes[0],$actor,$occurrence,$ob['period'],json_encode(['rule'=>OFB_RULE_VERSION,'obligation_id'=>$ob['id'],'evidence'=>$ob['evidence']],JSON_THROW_ON_ERROR)]);$bill=hp_one($db,'SELECT * FROM tagihan_biaya_lain WHERE id=?',[$db->insert_id]);ofb_audit($db,(int)$bill['id'],'legacy_created',[],['nominal_tagihan'=>$bill['nominal_tagihan'],'obligation_id'=>$ob['id']]);$out['bills_created']++;$out['changes']++;}
        }
        if(ofb_cents(ofb_paid($db,(int)$bill['id'],0,true))+$sum>$principal){$out['exceptions'][]=['obligation_id'=>$ob['id'],'reason'=>'Kredit baru melebihi kewajiban.'];continue;}
        if(!$ob['bill_id']){ofb_write($db,'UPDATE legacy_op_obligations SET bill_id=?,version=version+1 WHERE id=? AND bill_id IS NULL',[$bill['id'],$ob['id']]);ofb_audit($db,(int)$bill['id'],'obligation_binding',$ob,array_replace($ob,['bill_id'=>$bill['id'],'version'=>(int)$ob['version']+1]));$out['changes']++;}
        foreach($ds as $d){ofb_write($db,'INSERT INTO other_fee_legacy_credits(unit_id,bill_id,op_payment_id,full_item_id,detail_key,amount,source_fingerprint,evidence,actor_id) VALUES(?,?,NULLIF(?,0),NULLIF(?,0),?,?,?,?,?)',[$d['unit_id'],$bill['id'],$d['source_kind']==='operational'?$d['payment_id']:0,$d['full_item_id']??0,$d['detail_key'],$d['amount'],$d['fingerprint'],json_encode(['obligation_id'=>$ob['id'],'period'=>$ob['period'],'source_key'=>$d['source_key'],'row_key'=>$d['row_key'],'column'=>$d['source_column']],JSON_THROW_ON_ERROR),$actor]);ofb_audit($db,(int)$bill['id'],'legacy_credit',[],['detail_key'=>$d['detail_key'],'amount'=>$d['amount']]);$out['credits_bound']++;$out['changes']++;$batches[$d['batch_id']]=true;}
    }
    // A native purchase's frozen tariff is independent proof, unlike a Legacy cash amount.
    foreach(hp_rows($db,'SELECT d.*,b.NO_INDUK FROM bayar_biaya_lain d JOIN bayar b ON b.id=d.bayar_id AND b.unit_id=d.unit_id WHERE d.tagihan_biaya_lain_id IS NULL AND d.master_biaya_lain_id IS NOT NULL AND d.tarif_referensi_snapshot IS NOT NULL') as $d){
        if(ofb_cents($d['tarif_referensi_snapshot'])<=0||ofb_cents($d['tarif_referensi_snapshot'])!==ofb_cents($d['nominal_snapshot']))continue;
        $line=ofb_purchase_line($db,(int)$d['bayar_id'],['master_id'=>$d['master_biaya_lain_id'],'nama'=>$d['nama_biaya_snapshot'],'nominal'=>$d['nominal_snapshot'],'tarif_referensi'=>$d['tarif_referensi_snapshot']]);
        ofb_write($db,'UPDATE bayar_biaya_lain SET tagihan_biaya_lain_id=? WHERE id=? AND tagihan_biaya_lain_id IS NULL',[$line['bill_id'],$d['id']]);ofb_audit($db,(int)$line['bill_id'],'native_binding',['detail_id'=>$d['id'],'bill_id'=>null],['detail_id'=>$d['id'],'bill_id'=>$line['bill_id']]);$out['native_bound']++;$out['changes']++;
    }
    if($batches)lb_refresh_manifests($db,array_keys($batches));ofb_invalidate($db);return $out;
}
