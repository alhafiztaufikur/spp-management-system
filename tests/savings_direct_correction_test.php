<?php
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/savings_correction_ui.php';
require_once __DIR__.'/../includes/migrated_savings.php';
require_once __DIR__.'/../includes/history_class_filter.php';
require_once __DIR__.'/../includes/legacy_operational_bank.php';

function direct_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException($message);
}
function direct_denied(callable $operation,string $message):void {
    try{$operation();}catch(DomainException|RuntimeException $e){return;}
    throw new RuntimeException($message);
}
function direct_row_hash(mysqli $db,string $kind,int $id): string {
    $row=savings_transaction($db,$kind,$id);
    direct_assert((bool)$row,'Transaction detail missing');
    ob_start();savings_correction_controls($db,$row);$html=ob_get_clean();
    direct_assert(str_contains($html,'value="direct_edit"')&&str_contains($html,'value="direct_hapus"'),'Direct controls missing');
    direct_assert(!str_contains($html,'name="keterangan"')&&!preg_match('/name="reason"[^>]*required/',$html),'Correction form still requires a reason or includes notes');
    preg_match('/name="expected_hash" value="([a-f0-9]{64})"/',$html,$matches);
    return $matches[1];
}
function direct_session(mysqli $db,int $actor,string $role,int $unit): void {
    $_SESSION=['admin_id'=>$actor,'admin_role'=>$role,'active_unit_id'=>$unit];
    unit_set_context($db,$unit);
}

$db=$koneksi;
$actors=[];
foreach(['admin','super_admin'] as $role){
    $actors[$role]=(int)(lf_one($db,'SELECT id FROM admin WHERE is_active=1 AND role=? AND unit_id IS NULL ORDER BY id LIMIT 1',[$role])['id']??0);
    direct_assert($actors[$role]>0,'Missing active '.$role);
}
$date=date('Y-m-d H:i:s',time()-120);
$reason='Uji perubahan langsung pada basis data salinan';
foreach([1,2,3] as $unit){
    direct_session($db,$actors['super_admin'],'super_admin',$unit);
    $nis='9'.random_int(100000000,999999999);
    $class=[1=>'1',2=>'7',3=>'10'][$unit];
    lf_query($db,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS) VALUES(?,?,?)',[$nis,'QA Riwayat Tabungan',$class]);
    lf_query($db,'INSERT INTO tabungan(NO_INDUK,SALDO) VALUES(?,?)',[$nis,'200.00']);
    lf_query($db,'INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES(?,?,250,0,?,?)',[$nis,$date,(string)$actors['super_admin'],'Catatan asli siswa']);
    $in=(int)$db->insert_id;
    lf_query($db,'INSERT INTO transaksi_k(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id) VALUES(?,?,0,50,?)',[$nis,$date,(string)$actors['admin']]);
    $out=(int)$db->insert_id;
    [$row,$ledger]=savings_target_locked($db,'masuk',$in);$snapshot=savings_snapshot($row,$ledger['student']);
    lf_query($db,"INSERT INTO tabungan_otorisasi(jenis,transaction_id,transaction_reference,no_induk_snapshot,student_name_snapshot,
        action,before_snapshot,snapshot_hash,proposed_payload,request_reason,requested_by,requested_at)
        VALUES('masuk',?,?,?,?,'edit',?,?,?,?,?,NOW())",
        [$in,'QA-'.$in,$nis,'QA Riwayat Tabungan',$snapshot['json'],$snapshot['hash'],'{}',$reason,$actors['admin']]);
    $pending=(int)$db->insert_id;
    direct_session($db,$actors['admin'],'admin',$unit);
    $edit=['expected_hash'=>direct_row_hash($db,'masuk',$in),'nominal'=>'300,25','tanggal'=>$date];
    direct_denied(fn()=>savings_correct($db,'direct_edit','masuk',$in,$edit+['reason'=>str_repeat('a',501)],$actors['admin']),'Overlong reason accepted');
    direct_denied(fn()=>savings_correct($db,'direct_hapus','masuk',$in,$edit,$actors['admin']),'Negative history accepted');
    direct_assert(savings_request_find($db,$pending)['status']==='pending','Failed correction closed the request');
    savings_correct($db,'direct_edit','masuk',$in,$edit,$actors['admin']);
    direct_assert(savings_transaction($db,'masuk',$in)['keterangan']==='Catatan asli siswa','Native edit changed existing notes');
    direct_assert(lf_one($db,"SELECT note FROM tabungan_aktivitas WHERE jenis='masuk' AND transaction_id=? AND action='edited'",[$in])['note']==='','Optional reason was not recorded as empty');
    direct_assert(savings_request_find($db,$pending)['status']==='cancelled','Pending request not closed by direct edit');
    direct_assert((float)lf_one($db,'SELECT SALDO FROM tabungan WHERE NO_INDUK=?',[$nis])['SALDO']===250.25,'Incorrect student balance');
    direct_denied(fn()=>savings_correct($db,'direct_edit','masuk',$in,$edit,$actors['admin']),'Stale edit accepted');
    direct_assert(lf_one($db,"SELECT COUNT(*) n FROM tabungan_aktivitas WHERE action='cancelled' AND authorization_id=?",[$pending])['n']===1,'Missing audit for closed request');
    direct_session($db,$actors['super_admin'],'super_admin',$unit);
    $edit['expected_hash']=direct_row_hash($db,'keluar',$out);$edit['nominal']='60,25';
    savings_correct($db,'direct_edit','keluar',$out,$edit+['reason'=>'OK'],$actors['super_admin']);
    direct_session($db,$actors['admin'],'admin',$unit);
    savings_correct($db,'direct_hapus','keluar',$out,['expected_hash'=>direct_row_hash($db,'keluar',$out),'reason'=>$reason],$actors['admin']);
    direct_session($db,$actors['super_admin'],'super_admin',$unit);
    savings_correct($db,'direct_hapus','masuk',$in,['expected_hash'=>direct_row_hash($db,'masuk',$in),'reason'=>''],$actors['super_admin']);
    direct_assert(!empty(savings_transaction($db,'keluar',$out)['deleted']),'Missing native archive');
    direct_assert((float)lf_one($db,'SELECT SALDO FROM tabungan WHERE NO_INDUK=?',[$nis])['SALDO']===0.0,'Native archive balance differs');

    $owner=lf_one($db,"SELECT o.id,a.balance FROM legacy_op_owners o JOIN legacy_op_accounts a ON a.owner_id=o.id
        JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id
        WHERE o.student_id IS NULL AND o.state='ready' AND o.kind<>'out' AND a.balance=0 AND b.state='completed'
          AND NOT EXISTS(SELECT 1 FROM legacy_op_journals j WHERE j.owner_id=o.id) ORDER BY o.id LIMIT 1");
    if(!$owner)$owner=lf_one($db,"SELECT o.id,a.balance FROM legacy_op_owners o JOIN legacy_op_accounts a ON a.owner_id=o.id
        JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id
        WHERE o.student_id IS NULL AND o.state='ready' AND o.kind<>'out' AND a.balance>=0 AND b.state='completed'
          AND NOT EXISTS(SELECT 1 FROM legacy_op_journals j WHERE j.owner_id=o.id AND j.outgoing>0 AND j.cancelled=0)
        ORDER BY a.balance DESC,o.id LIMIT 1");
    direct_assert((bool)$owner,'Missing reconciled non-student fixture');
    $accountId=(int)$owner['id'];
    $bank=lop_bank_new($db,$actors['super_admin'],$unit,$accountId,['amount'=>'250.00','direction'=>'in','date'=>$date,'note'=>'Uji setoran rekening'],bin2hex(random_bytes(32)));
    $bankId=-1000000000-$bank['id'];
    direct_session($db,$actors['admin'],'admin',$unit);
    $edit['expected_hash']=direct_row_hash($db,'masuk',$bankId);$edit['nominal']='300,25';
    savings_correct($db,'direct_edit','masuk',$bankId,$edit,$actors['admin']);
    direct_assert(savings_transaction($db,'masuk',$bankId)['keterangan']==='Uji setoran rekening','Account edit changed existing notes');
    direct_denied(fn()=>savings_correct($db,'direct_edit','masuk',$bankId,$edit,$actors['admin']),'Stale account edit accepted');
    $outBank=lop_bank_new($db,$actors['admin'],$unit,$accountId,['amount'=>'50.00','direction'=>'out','date'=>$date,'note'=>'Uji penarikan rekening'],bin2hex(random_bytes(32)));
    $outBankId=-1000000000-$outBank['id'];
    direct_session($db,$actors['super_admin'],'super_admin',$unit);
    $edit['expected_hash']=direct_row_hash($db,'keluar',$outBankId);$edit['nominal']='60,25';
    savings_correct($db,'direct_edit','keluar',$outBankId,$edit+['reason'=>'OK'],$actors['super_admin']);
    savings_correct($db,'direct_hapus','keluar',$outBankId,['expected_hash'=>direct_row_hash($db,'keluar',$outBankId),'reason'=>''],$actors['super_admin']);
    direct_session($db,$actors['admin'],'admin',$unit);
    savings_correct($db,'direct_hapus','masuk',$bankId,['expected_hash'=>direct_row_hash($db,'masuk',$bankId),'reason'=>$reason],$actors['admin']);
    direct_assert(!empty(savings_transaction($db,'masuk',$bankId)['deleted']),'Missing account archive detail');
    $filtered=mh_savings_archived_rows($db,['owner_id'=>$accountId,'all_periods'=>'1'],['values'=>['*']],['*'],'','');
    direct_assert(in_array($bankId,array_map('intval',array_column($filtered,'id')),true),'Missing account archive row');
    direct_assert(str_contains(savings_activity_html($db,savings_transaction($db,'masuk',$bankId)),$reason),'Missing account audit');
    direct_assert(lf_one($db,'SELECT balance FROM legacy_op_accounts WHERE owner_id=?',[$accountId])['balance']===$owner['balance'],'Account archive balance differs');
    $legacy=lf_one($db,"SELECT id,IF(incoming>0,'masuk','keluar') jenis FROM legacy_op_journals WHERE origin='legacy' AND cancelled=0 LIMIT 1");
    if($legacy){
        $legacyId=-1000000000-(int)$legacy['id'];
        direct_denied(fn()=>savings_correct($db,'direct_hapus',$legacy['jenis'],$legacyId,['expected_hash'=>str_repeat('0',64),'reason'=>$reason],$actors['admin']),'Imported journal accepted');
        $detail=savings_detail_html(savings_transaction($db,$legacy['jenis'],$legacyId));
        direct_assert(!str_contains($detail,'Koreksi Jurnal Legacy')&&!str_contains($detail,'data-savings-open'),'Imported journal exposes write controls');
    }
    echo "PASS unit $unit direct actions, balances, stale forms, pending closure, archive, and imported journal controls\n";
}
