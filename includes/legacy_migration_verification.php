<?php
/** Immutable migration/cash checks shared by the CLI and isolated restore tests. */
function lmr_snapshot(mysqli $db,int $unit=0): array {
    $queries=[
        'identity'=>'SELECT id,unit_id,NO_INDUK,NAMA,KELAS,master_kelas_id,is_active FROM siswa_data',
        'archive'=>'SELECT id,unit_id,source_id,source_table,row_key,row_hash,occurrence,source_nis,source_date,source_operator,amounts,reference_only,source_identity,SHA2(CAST(raw_data AS CHAR),256) raw_digest FROM legacy_finance_rows_data',
        'native_payments'=>'SELECT * FROM bayar_data',
        'native_du'=>'SELECT * FROM bayar_du_data',
        'savings'=>'SELECT * FROM tabungan_data',
        'incoming'=>'SELECT * FROM transaksi_m_data',
        'outgoing'=>'SELECT * FROM transaksi_k_data',
        'accounts'=>'SELECT * FROM legacy_op_accounts_data',
        'journals'=>'SELECT * FROM legacy_op_journals_data',
        'legacy_cash'=>'SELECT id,unit_id,batch_id,owner_id,row_key,source_table,source_operator,source_date,source_period,class_snapshot,method,note,total,cancelled,items FROM legacy_op_payments_data',
    ];$out=[];
    foreach($queries as $name=>$sql){
        $ctx=hash_init('sha256');$count=0;$total=0;
        $rows=$db->query($sql.' FORCE INDEX(PRIMARY)'.($unit?' WHERE unit_id='.(int)$unit:'').' ORDER BY id',MYSQLI_USE_RESULT);
        while($r=$rows->fetch_assoc()){
            if($name==='legacy_cash'){$r['items']=array_map(static fn($i)=>['column'=>$i['column'],'amount'=>$i['amount']],json_decode($r['items'],true));$total+=(int)round((float)$r['total']*100);}
            hash_update($ctx,json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");$count++;
        }$rows->free();$out[$name]=['count'=>$count,'sha256'=>hash_final($ctx)];if($name==='legacy_cash')$out[$name]['total_cents']=$total;
    }return $out;
}

function lmr_target(mysqli $db): array {return $db->query('SELECT DATABASE() database_name,@@port port,@@server_uuid server_uuid')->fetch_assoc();}

/** Same principal/native credit/Legacy credit used by the cashier, grouped by period. */
function lmr_monthly_totals(mysqli $db): array {
    $data=lb_cache($db);$out=[];
    foreach($data['students'] as $s)foreach($data['native_monthly'][$s['unit_id'].'|'.$s['NO_INDUK']]??[] as $key=>$bill){
        [$category,$period]=explode('|',$key);$cell=&$out[$category][$period];$cell??=['count'=>0,'principal_cents'=>0,'native_paid_cents'=>0,'legacy_paid_cents'=>0,'remaining_cents'=>0];
        $principal=$bill['status']==='cancelled'?0:lf_cents($bill['nominal_tagihan']);$native=lf_cents($bill['paid']);$legacy=$data['paid'][$s['id']][$category][$period]??0;
        $cell['count']++;$cell['principal_cents']+=$principal;$cell['native_paid_cents']+=$native;$cell['legacy_paid_cents']+=$legacy;$cell['remaining_cents']+=max(0,$principal-$native-$legacy);unset($cell);
    }
    foreach($out as &$periods)ksort($periods);unset($periods);return $out;
}

/** Reconciled obligations and original receipt amounts are separate measures. */
function lmr_component_totals(mysqli $db): array {
    $out=['obligations'=>[],'receipts'=>[]];
    foreach(hp_rows($db,'SELECT category,period,COUNT(*) positions,SUM(principal) principal,SUM(remaining) remaining FROM legacy_op_obligations GROUP BY category,period ORDER BY category,period') as $r)
        $out['obligations'][$r['category']][$r['period']]=['positions'=>(int)$r['positions'],'principal_cents'=>lf_cents($r['principal']),'remaining_cents'=>lf_cents($r['remaining'])];
    $sql="SELECT j.category,COALESCE(j.period,p.source_period) period,COUNT(DISTINCT p.id) receipts,COUNT(*) items,SUM(j.amount) amount FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id JOIN JSON_TABLE(CAST(p.items AS JSON),'$[*]' COLUMNS(category VARCHAR(100) PATH '$.category',period VARCHAR(40) PATH '$.migration.period' NULL ON EMPTY,amount DECIMAL(20,2) PATH '$.amount')) j WHERE b.state='completed' AND p.cancelled=0 GROUP BY j.category,COALESCE(j.period,p.source_period) ORDER BY j.category,period";
    foreach(hp_rows($db,$sql) as $r)$out['receipts'][$r['category']][$r['period']]=['receipts'=>(int)$r['receipts'],'items'=>(int)$r['items'],'amount_cents'=>lf_cents($r['amount'])];
    return $out;
}

/** Read the same per-slot metadata used by receipts; cancelled cash is reported separately. */
function lmr_fee_trace_totals(mysqli $db): array {
    $out=lfet_summary([]);$out['cancelled_receipts']=0;$out['active_amount_cents']=0;$masters=[];
    $expected=[];
    foreach(hp_rows($db,'SELECT unit_id,id FROM master_biaya_lain') as $m)$masters[$m['unit_id'].'|'.$m['id']]=true;
    foreach(lf_stream_rows($db,hp_sql($db,"SELECT p.*,b.state batch_state FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state IN ('completed','verifying')",true)) as $p){
        $p['items']=json_decode($p['items'],true,512,JSON_THROW_ON_ERROR);lfet_validate_payment($p);$part=lfet_summary([$p]);
        foreach($part as $key=>$value)if($key!=='rule')$out[$key]+=$value;
        if($p['cancelled'])$out['cancelled_receipts']++;else $out['active_amount_cents']+=$part['amount_cents'];
        if(!$p['cancelled']&&$p['batch_state']==='completed')foreach($p['items'] as $i)if(preg_match('/^biaya_lain:([1-9][0-9]*)$/D',$i['category'],$match))lmr_fee_master_expected($expected,(int)$p['unit_id'],(int)$match[1],-1000000000-(int)$p['id'],$i['amount']);
        foreach($p['items'] as $i)if(lfet_is_detail($i)&&str_starts_with($i['category'],'biaya_lain:')&&!isset($masters[$p['unit_id'].'|'.(int)substr($i['category'],11)]))throw new DomainException('Master biaya lain tidak sesuai unit transaksi migrasi.');
    }
    require_once __DIR__.'/other_fee_usage.php';
    foreach(hp_rows($db,'SELECT d.unit_id,COALESCE(d.master_biaya_lain_id,t.master_biaya_lain_id) master_id,d.bayar_id id,d.nominal_snapshot amount FROM bayar_biaya_lain d JOIN bayar p ON p.id=d.bayar_id AND p.unit_id=d.unit_id LEFT JOIN tagihan_biaya_lain t ON t.id=d.tagihan_biaya_lain_id AND t.unit_id=d.unit_id') as $r)if($r['master_id'])lmr_fee_master_expected($expected,(int)$r['unit_id'],(int)$r['master_id'],(int)$r['id'],$r['amount']);
    foreach(hp_rows($db,"SELECT i.* FROM legacy_full_items i JOIN legacy_full_payments p ON p.id=i.payment_id AND p.unit_id=i.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed'") as $r)if(preg_match('/^biaya_lain:([1-9][0-9]*)$/D',$r['category'],$match))lmr_fee_master_expected($expected,(int)$r['unit_id'],(int)$match[1],-(int)$r['payment_id'],$r['amount']);
    $out['master_usage']=[];
    foreach(other_fee_master_usage($db) as $m){$key=$m['unit_id'].'|'.$m['id'];$e=$expected[$key]??['receipts'=>[],'details'=>0,'cents'=>0];
        if($m['jumlah_pemakaian']!==count($e['receipts'])||$m['jumlah_rincian']!==$e['details']||lf_cents($m['nominal_pemakaian'])!==$e['cents']||($e['details']&&$m['can_delete']))throw new DomainException('Pemakaian Master Biaya Lain tidak cocok dengan transaksi pada unit ini.');
        $out['master_usage'][]=['unit_id'=>(int)$m['unit_id'],'master_id'=>(int)$m['id'],'name'=>$m['nama'],'receipts'=>$m['jumlah_pemakaian'],'details'=>$m['jumlah_rincian'],'amount_cents'=>lf_cents($m['nominal_pemakaian']),'can_delete'=>$m['can_delete']];
        unset($expected[$key]);
    }
    if($expected)throw new DomainException('Transaksi biaya merujuk master yang tidak tersedia.');
    $out['status']=$out['trace_complete']===$out['details']?'traced':'partial';return $out;
}

function lmr_fee_master_expected(array &$out,int $unit,int $master,int $payment,mixed $amount): void {
    $entry=&$out[$unit.'|'.$master];$entry??=['receipts'=>[],'details'=>0,'cents'=>0];
    $entry['receipts'][$payment]=true;$entry['details']++;$entry['cents']+=lf_cents($amount);
}

function lmr_database_checksums(mysqli $db): array {
    $tables=[];foreach($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'") as $r){$name=array_values($r)[0];if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Nama tabel tidak didukung.');$tables[]='`'.$name.'`';}
    $out=[];foreach($db->query('CHECKSUM TABLE '.implode(',',$tables).' EXTENDED') as $r){if($r['Checksum']===null)throw new RuntimeException('Checksum backup tidak tersedia.');$out[substr($r['Table'],strrpos($r['Table'],'.')+1)]=$r['Checksum'];}ksort($out);return $out;
}

function lmr_backup_check(mysqli $db,string $proofFile): array {
    if(!is_file($proofFile))throw new RuntimeException('Bukti pemulihan backup wajib tersedia untuk apply.');
    $p=json_decode(file_get_contents($proofFile),true,512,JSON_THROW_ON_ERROR);
    if(empty($p['verified_restore'])||empty($p['backup_file'])||!is_file($p['backup_file'])||!hash_equals($p['sha256']??'',hash_file('sha256',$p['backup_file']))||($p['target']??null)!==lmr_target($db)||empty($p['restored_server_uuid'])||$p['restored_server_uuid']===$p['target']['server_uuid'])throw new RuntimeException('Backup, target, atau bukti restore berbeda.');
    if(($p['snapshot']??[])!==lmr_snapshot($db)||($p['database_checksums']??[])!==lmr_database_checksums($db))throw new RuntimeException('Data berubah sejak backup diverifikasi. Buat dan verifikasi backup baru sebelum apply.');
    return $p;
}
