<?php
require_once __DIR__.'/legacy_full.php';
require_once __DIR__.'/legacy_operational_review_support.php';
require_once __DIR__.'/legacy_migration_verification.php';

/** Explicit settings only. This never derives an attestation from a source header. */
function lsr_reconcile(mysqli $db,int $actor,array $request): array {
    foreach(['unit_id','batch_id','identity','amount','evidence','pending_version','batch_version','source_hash'] as $key)if(!isset($request[$key])||!is_scalar($request[$key]))throw new DomainException('Pengesahan rekening tidak lengkap: '.$key);
    foreach(['pending_version','batch_version'] as $key)if(filter_var($request[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new DomainException('Versi pengesahan rekening tidak valid.');
    $unit=(int)$request['unit_id'];$identity=(string)$request['identity'];$batch=(string)$request['batch_id'];
    if($unit!==unit_active_id()||!preg_match('/^[a-f0-9]{32}$/D',$batch)||!preg_match('/^[a-f0-9]{64}$/D',(string)$request['source_hash'])||($request['confirmed']??false)!==true)throw new DomainException('Identitas/unit atau konfirmasi rekening tidak valid.');
    lop_actor($db,$actor,$unit,true);$amount=lf_money(lf_cents($request['amount']));
    $b=lf_one($db,'SELECT b.*,s.backup_hash,s.source_date FROM legacy_full_batches b JOIN legacy_finance_sources s ON s.id=b.source_id AND s.unit_id=b.unit_id WHERE b.id=? AND b.unit_id=? FOR UPDATE',[$batch,$unit]);
    if(!$b||$b['state']!=='completed'||!hash_equals($b['backup_hash'],(string)$request['source_hash']))throw new DomainException('Batch/sumber rekening berubah.');
    $o=lf_one($db,'SELECT o.*,s.NO_INDUK FROM legacy_op_owners o JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE o.batch_id=? AND o.source_identity=? AND o.state=\'ready\' FOR UPDATE',[$batch,$identity]);
    if(!$o)throw new DomainException('Pemilik rekening belum siap atau tidak cocok.');
    $digest=hash('sha256',lf_json($request));$manifest=json_decode($b['manifest'],true,512,JSON_THROW_ON_ERROR);$saved=$manifest['savings_fixes'][$identity]??null;
    $account=lf_one($db,'SELECT * FROM legacy_op_accounts WHERE owner_id=? FOR UPDATE',[$o['id']]);
    $native=lf_one($db,'SELECT * FROM tabungan WHERE NO_INDUK=? FOR UPDATE',[$o['NO_INDUK']]);
    if($saved){
        if(!hash_equals($saved['request_digest'],$digest)||!$account||!$native||lf_cents($account['opening'])!==lf_cents($amount))throw new DomainException('Keputusan rekening berbeda; gunakan koreksi existing.');
        $journal=lf_one($db,"SELECT COALESCE(SUM(incoming-outgoing),0) total FROM legacy_op_journals WHERE owner_id=? AND origin IN ('legacy','adjustment') AND cancelled=0",[$o['id']]);
        if(lf_cents($journal['total'],true)!==lf_cents($account['opening']))throw new DomainException('Jurnal rekening berubah; periksa koreksi existing.');
        return ['changes'=>0,'replayed'=>true,'opening'=>$amount,'balance'=>$native['SALDO'],'owner_id'=>(int)$o['id']];
    }
    if((int)$b['version']!==(int)$request['batch_version'])throw new DomainException('Versi batch berubah.');
    if($account||$native||lf_one($db,'SELECT id FROM legacy_op_journals WHERE owner_id=? LIMIT 1',[$o['id']])||lf_one($db,'SELECT id FROM transaksi_m WHERE NO_INDUK=? UNION ALL SELECT id FROM transaksi_k WHERE NO_INDUK=? LIMIT 1',[$o['NO_INDUK'],$o['NO_INDUK']]))throw new DomainException('Rekening/transaksi sudah tersedia; gunakan koreksi existing.');
    $pending=lf_one($db,"SELECT * FROM legacy_op_pending WHERE batch_id=? AND source_identity=? AND code='savings_review' AND resolved=0 FOR UPDATE",[$batch,$identity]);
    if(!$pending||(int)$pending['version']!==(int)$request['pending_version'])throw new DomainException('Versi peninjauan rekening berubah.');
    $rows=[];foreach(hp_rows($db,"SELECT * FROM legacy_finance_rows WHERE source_id=? AND source_identity=? AND source_table IN ('tabungan','transaksi_m','transaksi_k') ORDER BY id",[$b['source_id'],$identity]) as $r)$rows[]=['table'=>$r['source_table'],'raw'=>json_decode($r['raw_data'],true),'row_key'=>$r['row_key']];
    $cfg=$manifest['mapping']??[];$fix=['amount'=>$amount,'evidence'=>(string)$request['evidence'],'confirmed'=>true];
    $proposal=lop_savings_fix_proposal($rows,$cfg,$fix,$b['source_date'],$identity);
    if($proposal['journal_changes'])throw new DomainException('Koreksi presisi jurnal perlu pengesahan terpisah.');
    $effective=lop_effective_bank_rows($rows,$cfg);$journals=[];
    foreach($effective as $r)if(in_array($r['table'],['transaksi_m','transaksi_k'],true)){
        $incoming=lf_money(lf_cents($r['raw']['MASUK']??'0'));$outgoing=lf_money(lf_cents($r['raw']['KELUAR']??'0'));
        if($incoming==='0.00'&&$outgoing==='0.00')continue;
        $journals[]=['identity'=>$identity,'row_key'=>$r['row_key'],'operator'=>$r['raw']['user_id']??null,'date'=>lop_date($r['raw']['TANGGAL']??null),'incoming'=>$incoming,'outgoing'=>$outgoing];
    }
    $bank=$proposal['bank'];$delta=lf_cents($bank['adjustment'],true);
    if($delta)$journals[]=['identity'=>$identity,'row_key'=>hash('sha256','adjustment:'.$identity.':'.($cfg['source_fingerprint']??$manifest['source_fingerprint'])),'date'=>lop_date($b['cutoff']),'operator'=>null,'incoming'=>lf_money(max(0,$delta)),'outgoing'=>lf_money(max(0,-$delta)),'origin'=>'adjustment','note'=>mb_substr('Penyesuaian Saldo Migrasi - '.$fix['evidence'],0,1000)];
    $sim=['owners'=>[$identity=>['ready'=>true,'savings'=>$bank,'balance_fix'=>$fix]],'journals'=>$journals,'cutoff'=>$b['cutoff'],'config'=>$cfg];
    lop_store_savings($db,$unit,$actor,$batch,$sim,[$identity=>$o]);
    lf_query($db,'UPDATE legacy_op_pending SET resolved=1,version=version+1 WHERE id=? AND resolved=0',[$pending['id']]);
    $manifest['mapping']['op_savings'][$identity]=$fix;
    $manifest['savings_fixes'][$identity]=['request_digest'=>$digest,'actor'=>$actor,'rule'=>LOP_VERSION,'amount'=>$amount,'adjustment'=>$bank['adjustment'],'source_hash'=>$b['backup_hash'],'source_rows_digest'=>hash('sha256',lf_json($rows))];
    $manifest['pending']=(int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_pending WHERE batch_id=? AND resolved=0',[$batch])['n'];
    $manifest['summary']['pending']=max(0,(int)($manifest['summary']['pending']??0)-1);
    $manifest['summary']['journals']=(int)($manifest['summary']['journals']??0)+count($journals);
    $manifest['summary']['savings_total']=lf_money(lf_cents($manifest['summary']['savings_total']??'0.00')+lf_cents($amount));
    $manifest['summary']['adjustments']=(int)($manifest['summary']['adjustments']??0)+($delta?1:0);
    $manifest['working_digest']=lop_working_digest($db,$batch);
    lf_query($db,'UPDATE legacy_full_batches SET manifest=? WHERE id=?',[lf_json($manifest),$batch]);
    lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'savings_resume',?,?,?,?,?)",[$unit,$actor,$o['id'],hash('sha256','savings-resume|'.$batch.'|'.$identity.'|'.$digest),$fix['evidence'],lf_json(['pending_id'=>$pending['id'],'account'=>null]),lf_json($manifest['savings_fixes'][$identity])]);
    return ['changes'=>1,'replayed'=>false,'opening'=>$amount,'balance'=>$amount,'adjustment'=>$bank['adjustment'],'source_journals'=>count($journals)-($delta?1:0),'owner_id'=>(int)$o['id']];
}

/** Every unrelated row, including every other account, must stay byte-identical. */
function lsr_guard(mysqli $db,array $request): array {
    $owner=lf_one($db,'SELECT id,student_id FROM legacy_op_owners WHERE batch_id=? AND source_identity=?',[$request['batch_id'],$request['identity']]);
    $student=$owner?lf_one($db,'SELECT NO_INDUK FROM siswa WHERE id=?',[$owner['student_id']]):null;$unit=(int)$request['unit_id'];$oid=(int)($owner['id']??0);$nis=$db->real_escape_string($student['NO_INDUK']??'');$batch=$db->real_escape_string($request['batch_id']);$identity=$db->real_escape_string($request['identity']);
    $filters=['tabungan_data'=>"NOT(unit_id=$unit AND NO_INDUK='$nis')",'legacy_op_accounts_data'=>"NOT(unit_id=$unit AND owner_id=$oid)",'legacy_op_journals_data'=>"NOT(unit_id=$unit AND owner_id=$oid)",'legacy_op_audit_data'=>"NOT(unit_id=$unit AND ((entity_type='savings_resume' AND entity_id=$oid) OR (entity_type='balance_fix' AND entity_id IN(SELECT id FROM legacy_op_journals_data WHERE owner_id=$oid AND unit_id=$unit))))",'legacy_op_pending_data'=>"NOT(unit_id=$unit AND batch_id='$batch' AND source_identity='$identity' AND code='savings_review')",'legacy_full_batches_data'=>"NOT(unit_id=$unit AND id='$batch')"];
    $out=[];foreach($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $t){$name=$t['TABLE_NAME'];$sql="SELECT * FROM `$name`".(isset($filters[$name])?' WHERE '.$filters[$name]:'').' ORDER BY 1';$hash=hash_init('sha256');$count=0;foreach(lf_iter_rows($db,$sql) as $row){hash_update($hash,lf_json($row));$count++;}$out[$name]=[$count,hash_final($hash)];}return $out;
}
