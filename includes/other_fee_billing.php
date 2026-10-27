<?php
require_once __DIR__.'/legacy_finance.php';
require_once __DIR__.'/history_performance.php';
require_once __DIR__.'/other_fee_billing_schema.php';
const OFB_RULE_VERSION=1;

function ofb_cents(mixed $value): int {
    if(is_float($value))$value=number_format($value,2,'.','');
    if(!is_scalar($value)||!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D',(string)$value,$m))throw new DomainException('Nominal Biaya Lain tidak sah.');
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
function ofb_money(int $cents): string {return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);}
function ofb_write(mysqli $db,string $sql,array $args=[]): void { $s=$db->prepare($sql);try{if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();}finally{$s->close();}}
function ofb_invalidate(mysqli $db): void {$GLOBALS['ofb_generation'][$db->thread_id]=($GLOBALS['ofb_generation'][$db->thread_id]??0)+1;}

/** Stable receipt-slot identity; changing unrelated components does not invalidate a credit. */
function ofb_detail(array $r): array {
    $r['detail_key']=$r['detail_key']?:hash('sha256',json_encode([$r['unit_id'],$r['source_key'],$r['row_key'],$r['source_column']],JSON_THROW_ON_ERROR));
    $r['fingerprint']=hash('sha256',json_encode(array_map(static fn($key)=>(string)($r[$key]??''),['unit_id','student_id','master_id','detail_key','amount','source_period','kelas']),JSON_THROW_ON_ERROR));
    return $r;
}
/** No name-only matching. Every returned receipt already identifies its student and unit. */
function ofb_legacy_details(mysqli $db,bool $boundOnly=false): array {
    $opJoin=$boundOnly?' JOIN other_fee_legacy_credits cr ON cr.op_payment_id=p.id AND cr.unit_id=p.unit_id ':'';
    $fullJoin=$boundOnly?' JOIN other_fee_legacy_credits cr ON cr.full_item_id=i.id AND cr.unit_id=i.unit_id ':'';
    $queries=["SELECT DISTINCT p.unit_id,p.batch_id,p.id payment_id,NULL full_item_id,o.student_id,s.NO_INDUK nis,
        CAST(SUBSTRING(j.category,12) AS UNSIGNED) master_id,p.row_key,p.source_period,p.class_snapshot kelas,
        b.source_id,src.backup_hash source_key,j.source_column,j.detail_key,j.amount,j.obligation_id,
        'operational' source_kind,p.source_date
        FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id
        JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id
        JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id
        JOIN legacy_finance_sources src ON src.id=b.source_id AND src.unit_id=b.unit_id
        $opJoin
        JOIN JSON_TABLE(CAST(p.items AS JSON),'$[*]' COLUMNS(category VARCHAR(100) PATH '$.category',
            source_column VARCHAR(64) PATH '$.column',detail_key VARCHAR(64) PATH '$.migration.detail_key' NULL ON EMPTY,
            amount DECIMAL(15,2) PATH '$.amount',obligation_id BIGINT PATH '$.obligation_id' NULL ON EMPTY)) j
        WHERE b.state IN ('completed','verifying') AND p.cancelled=0 AND j.category REGEXP '^biaya_lain:[1-9][0-9]*$' AND j.amount>0",
        "SELECT p.unit_id,p.batch_id,p.id payment_id,i.id full_item_id,p.student_id,s.NO_INDUK nis,
        CAST(SUBSTRING(i.category,12) AS UNSIGNED) master_id,p.row_key,p.source_period,p.class_snapshot kelas,
        b.source_id,src.backup_hash source_key,i.source_column,NULL detail_key,i.amount,NULL obligation_id,
        'archive' source_kind,p.source_date
        FROM legacy_full_items i JOIN legacy_full_payments p ON p.id=i.payment_id AND p.unit_id=i.unit_id
        JOIN siswa s ON s.id=p.student_id AND s.unit_id=p.unit_id
        JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id
        JOIN legacy_finance_sources src ON src.id=b.source_id AND src.unit_id=b.unit_id $fullJoin
        WHERE b.state IN ('completed','verifying') AND i.category REGEXP '^biaya_lain:[1-9][0-9]*$' AND i.amount>0"];
    $out=[];foreach($queries as $sql)foreach(hp_rows($db,$sql) as $r){$r=ofb_detail($r);$out[$r['unit_id'].'|'.$r['detail_key']]=$r;}return $out;
}
/** Revalidate bindings against current, uncancelled evidence on every request. */
function ofb_credits(mysqli $db): array {
    static $cache=[];$key=hp_connection_key($db).':'.($GLOBALS['ofb_generation'][$db->thread_id]??0).':'.$db->query('SELECT current_unit_id()')->fetch_row()[0];
    if(PHP_SAPI!=='cli'&&isset($cache[$key]))return $cache[$key];
    $bindings=hp_rows($db,'SELECT c.*,t.no_induk,t.master_biaya_lain_id,t.status FROM other_fee_legacy_credits c JOIN tagihan_biaya_lain t ON t.id=c.bill_id AND t.unit_id=c.unit_id');
    if(!$bindings){if(PHP_SAPI!=='cli')$cache[$key]=[];return [];}$source=ofb_legacy_details($db,true);$out=[];
    foreach($bindings as $b){$r=$source[$b['unit_id'].'|'.$b['detail_key']]??null;
        if(!$r||$b['status']==='cancelled'||$r['nis']!==$b['no_induk']||(int)$r['master_id']!==(int)$b['master_biaya_lain_id']||!hash_equals($b['source_fingerprint'],$r['fingerprint'])||ofb_cents($b['amount'])!==ofb_cents($r['amount']))continue;
        $r['bill_id']=(int)$b['bill_id'];$out[$b['unit_id'].'|'.$b['detail_key']]=$r;
    }
    if(PHP_SAPI!=='cli')$cache[$key]=$out;return $out;
}
function ofb_legacy_paid(mysqli $db,int $billId): int {$sum=0;foreach(ofb_credits($db) as $r)if((int)$r['bill_id']===$billId)$sum+=ofb_cents($r['amount']);return $sum;}

/** Lock the bill first; all native writers use the same lock order. */
function ofb_paid(mysqli $db,int $billId,int $exclude=0,bool $lock=false): float {
    static $readCache=[];
    if(!$lock&&PHP_SAPI!=='cli'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $key=hp_connection_key($db).':'.$db->query('SELECT current_unit_id()')->fetch_row()[0].':'.$exclude.':'.($GLOBALS['ofb_generation'][$db->thread_id]??0);
        if(!isset($readCache[$key])){
            $totals=[];foreach(hp_rows($db,'SELECT tagihan_biaya_lain_id bill_id,SUM(nominal_snapshot) amount FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id IS NOT NULL AND bayar_id<>? GROUP BY tagihan_biaya_lain_id',[$exclude]) as $r)$totals[(int)$r['bill_id']]=ofb_cents($r['amount']);
            foreach(ofb_credits($db) as $r)$totals[$r['bill_id']]=($totals[$r['bill_id']]??0)+ofb_cents($r['amount']);$readCache[$key]=$totals;
        }return ($readCache[$key][$billId]??0)/100.0;
    }
    $sql='SELECT bayar_id,nominal_snapshot FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=?'.($lock?' FOR UPDATE':'');
    $sum=ofb_legacy_paid($db,$billId);foreach(hp_rows($db,$sql,[$billId]) as $r)if((int)$r['bayar_id']!==$exclude)$sum+=ofb_cents($r['nominal_snapshot']);return $sum/100;
}
function ofb_audit(mysqli $db,int $bill,string $action,array $before,array $after): void {
    ofb_write($db,'INSERT INTO other_fee_billing_audit(unit_id,bill_id,actor_id,action,before_data,after_data) SELECT unit_id,id,?,?,?,? FROM tagihan_biaya_lain WHERE id=?',[$_SESSION['admin_id']??0,$action,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),$bill]);
}
/** Purchases are an occurrence, never an instalment against an unrelated issued bill. */
function ofb_purchase_line(mysqli $db,int $paymentId,array $line): array {
    $p=hp_one($db,'SELECT * FROM bayar WHERE id=? FOR UPDATE',[$paymentId]);if(!$p)throw new DomainException('Transaksi pembelian tidak ditemukan.');
    $key='purchase:'.$paymentId.':'.$line['master_id'];
    $bill=hp_one($db,'SELECT * FROM tagihan_biaya_lain WHERE no_induk=? AND master_biaya_lain_id=? AND occurrence_key=? FOR UPDATE',[$p['NO_INDUK'],$line['master_id'],$key]);
    $amount=ofb_money(ofb_cents($line['nominal']));$period=trim($p['BULAN'].' '.$p['TAHUN']);
    $class=trim((string)($p['kelas_rombel_snapshot']??''));if($class==='')$class=trim((string)($p['KELAS']??''));if($class==='')$class='Tidak tercatat';
    if(!$bill){
        ofb_write($db,"INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,master_kelas_id,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot,created_by,occurrence_key,origin,obligation_period,provenance) VALUES(?,?,NULLIF(?,0),?,?,?,?,?,'purchase',?,?)",[$line['master_id'],$p['NO_INDUK'],$p['master_kelas_id']??0,$line['nama'],$amount,$class,$_SESSION['admin_id'],$key,$period,json_encode(['payment_id'=>$paymentId,'tariff'=>$line['tarif_referensi']??null,'rule'=>OFB_RULE_VERSION],JSON_THROW_ON_ERROR)]);
        $billId=(int)$db->insert_id;ofb_audit($db,$billId,'purchase_created',[],['nominal_tagihan'=>$amount,'payment_id'=>$paymentId]);
    }else{
        $billId=(int)$bill['id'];if(ofb_paid($db,$billId,$paymentId,true)>.001)throw new DomainException('Tagihan pembelian mempunyai kredit lain; koreksi dihentikan.');
        if(ofb_cents($bill['nominal_tagihan'])!==ofb_cents($amount)||$bill['status']!=='open'){
            ofb_write($db,"UPDATE tagihan_biaya_lain SET nominal_tagihan=?,status='open',cancel_reason=NULL WHERE id=?",[$amount,$billId]);ofb_audit($db,$billId,'purchase_edited',$bill,['nominal_tagihan'=>$amount,'payment_id'=>$paymentId]);
        }
    }
    $line['bill_id']=$billId;return $line;
}
function ofb_cancel_purchase(mysqli $db,int $billId,string $reason): void {
    $bill=hp_one($db,'SELECT * FROM tagihan_biaya_lain WHERE id=? FOR UPDATE',[$billId]);if(!$bill||$bill['origin']!=='purchase'||$bill['status']==='cancelled')return;
    ofb_write($db,"UPDATE tagihan_biaya_lain SET status='cancelled',cancel_reason=? WHERE id=?",[$reason,$billId]);ofb_audit($db,$billId,'purchase_cancelled',$bill,['nominal_tagihan'=>$bill['nominal_tagihan'],'reason'=>$reason]);
}
function ofb_native_changed(mysqli $db,?array $before,?array $after): void {
    $newIds=[];foreach($after['details']['bayar_biaya_lain']??[] as $d)if(!empty($d['tagihan_biaya_lain_id']))$newIds[(int)$d['tagihan_biaya_lain_id']]=true;
    foreach($before['details']['bayar_biaya_lain']??[] as $d){$id=(int)($d['tagihan_biaya_lain_id']??0);if($id&&!isset($newIds[$id]))ofb_cancel_purchase($db,$id,'Pembelian dihapus melalui otorisasi transaksi.');}ofb_invalidate($db);
}

/** Called only after an authorised Legacy edit, in that same transaction. */
function ofb_legacy_changed(mysqli $db,int $paymentId): void {
    $bindings=hp_rows($db,'SELECT c.*,t.no_induk,t.master_biaya_lain_id,t.nominal_tagihan,t.status FROM other_fee_legacy_credits c JOIN tagihan_biaya_lain t ON t.id=c.bill_id AND t.unit_id=c.unit_id WHERE c.op_payment_id=? FOR UPDATE',[$paymentId]);
    if(!$bindings)return;ofb_invalidate($db);$details=ofb_legacy_details($db,true);
    foreach($bindings as $b){$d=$details[$b['unit_id'].'|'.$b['detail_key']]??null;
        if(!$d||$b['status']==='cancelled'||$d['nis']!==$b['no_induk']||(int)$d['master_id']!==(int)$b['master_biaya_lain_id']||hash_equals($d['fingerprint'],$b['source_fingerprint']))continue;
        $e=json_decode($b['evidence'],true);$ob=hp_one($db,'SELECT * FROM legacy_op_obligations WHERE id=?',[$e['obligation_id']??0]);
        if(!$ob||(int)($d['obligation_id']??0)!==(int)$ob['id'])continue;
        $other=ofb_cents(ofb_paid($db,(int)$b['bill_id']));
        // The stale binding is excluded by ofb_credits(); only the replacement is added.
        if($other+ofb_cents($d['amount'])>ofb_cents($b['nominal_tagihan']))throw new DomainException('Koreksi melebihi pokok tagihan Biaya Lain.');
        ofb_write($db,'UPDATE other_fee_legacy_credits SET amount=?,source_fingerprint=? WHERE id=?',[$d['amount'],$d['fingerprint'],$b['id']]);ofb_audit($db,(int)$b['bill_id'],'legacy_credit_edited',['amount'=>$b['amount'],'source_fingerprint'=>$b['source_fingerprint']],['amount'=>$d['amount'],'source_fingerprint'=>$d['fingerprint']]);
    }ofb_invalidate($db);
}
