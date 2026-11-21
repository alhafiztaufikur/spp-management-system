<?php
ob_start();
/** Full official payment/approval lifecycle; explicitly disposable server only. */
define('WORKFLOW_HELPERS_ONLY',true);require __DIR__.'/workflow_update_http_test.php';
$db=$koneksi;require_once __DIR__.'/../includes/other_fee_billing.php';
$private=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$feeHttpChecks=[];
function fh_check(bool $ok,string $label):void{global $feeHttpChecks;if(!$ok)throw new RuntimeException($label);$feeHttpChecks[]=$label;echo "PASS $label\n";}
function fh_session(int $actor,string $role,int $unit):array{
    session_save_path(getenv('SPP_QA_SESSION_PATH'));session_id('feebill'.bin2hex(random_bytes(12)));session_start();$token=bin2hex(random_bytes(32));
    $_SESSION=['admin_id'=>$actor,'admin_role'=>$role,'active_unit_id'=>$unit,'csrf_payment'=>$token,'csrf_transaction_authorization'=>$token,'csrf_unit_switch'=>$token];$id=session_id();session_write_close();return ['id'=>$id,'csrf'=>$token,'unit'=>$unit];
}
function fh_post(array $session,string $path,array $data):array{return wf_http($session['id'],$path,array_replace(['_operational_unit'=>(string)$session['unit']],$data));}
function fh_fields(string $html,string $required):array{
    $dom=new DOMDocument();@$dom->loadHTML($html);
    foreach($dom->getElementsByTagName('form') as $form){$found=false;$out=[];
        foreach($form->getElementsByTagName('input') as $i){$name=$i->getAttribute('name');if($name===$required)$found=true;if($i->getAttribute('type')==='hidden'&&!str_contains($name,'['))$out[$name]=$i->getAttribute('value');}
        if($found)return $out;
    }throw new RuntimeException('Expected payment form field missing: '.$required);
}
function fh_input(array $session,string $nis,int $master,int|string $amount,array $extra=[]):array{
    global $db;[$code,$html]=wf_http($session['id'],'pembayaran/form.php');fh_check($code===200,'Payment form available');
    $data=array_replace(fh_fields($html,'request_key'),['aksi'=>'input','no_induk'=>$nis,'bulan_bayar'=>'10','tahun_bayar'=>'2026','sistem_pembayaran'=>'Tunai','payment_plan'=>'monthly','tanggal_bayar'=>'2026-10-27','uang_psb'=>'0','uang_spp'=>'0','uang_komite'=>'0','uang_du'=>'0','potongan_spp'=>'0','biaya_lain_detail_id'=>[''],'biaya_lain_tagihan_id'=>['master:'.$master],'biaya_lain_nominal'=>[(string)$amount],'biaya_lain_keterangan'=>['QA purchase billing']],$extra);
    fh_post($session,'pembayaran/proses.php',$data);$p=hp_one($db,'SELECT id FROM bayar WHERE NO_INDUK=? ORDER BY id DESC LIMIT 1',[$nis]);return [(int)($p['id']??0),$data];
}
function fh_edit(array $session,int $id,string $nis,int $master,int|string $amount,bool $includeNote=true):void{
    global $db;[$code,$html]=wf_http($session['id'],'pembayaran/edit.php?id='.$id);fh_check($code===200,'Purchase edit page available');$d=hp_one($db,'SELECT * FROM bayar_biaya_lain WHERE bayar_id=?',[$id]);
    $data=array_replace(fh_fields($html,'biaya_lain_detail_id[]'),['aksi'=>'update','id'=>$id,'no_induk'=>$nis,'tanggal_bayar'=>'2026-10-27','bulan_bayar'=>'10','tahun_bayar'=>'2026','sistem_pembayaran'=>'Tunai','uang_psb'=>'0','uang_spp'=>'0','uang_komite'=>'0','uang_du'=>'0','potongan_spp'=>'0','authorization_reason'=>'QA authorised purchase correction','biaya_lain_detail_id'=>[$d['id']],'biaya_lain_tagihan_id'=>['master:'.$master],'biaya_lain_nominal'=>[(string)$amount]]);
    if ($includeNote) $data['biaya_lain_keterangan']=['QA corrected purchase'];
    fh_post($session,'pembayaran/proses.php',$data);
}
function fh_create_manual(array $session, string $name): int {
    global $db;
    [$code,$html]=wf_http($session['id'],'master_biaya_lain.php');
    fh_check($code===200 && str_contains($html,'value="input_manual"'),'Input Manual master choice available');
    fh_post($session,'master_biaya_lain.php',array_replace(fh_fields($html,'nominal'),[
        'aksi'=>'tambah','nama'=>$name,'jenis_pembayaran'=>'input_manual','is_active'=>'1',
    ]));
    $master=hp_one($db, 'SELECT id,nominal,jenis_pembayaran,is_active FROM master_biaya_lain WHERE nama=?', [$name]);
    fh_check(($master['jenis_pembayaran']??'')==='input_manual'&&$master['nominal']==='0.00'&&(int)$master['is_active']===1,'Name-only master saved');
    return (int)$master['id'];
}

$actors=[];foreach(hp_rows($db,"SELECT id,role FROM admin WHERE is_active=1 ORDER BY id") as $a)$actors[$a['role']]??=(int)$a['id'];
if(!isset($actors['admin'])){ofb_write($db,"INSERT INTO admin(username,password,nama,role,is_active) VALUES(?,?,?,'admin',1)",['feeqa'.bin2hex(random_bytes(4)),password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),'QA Fee Billing Admin']);$actors['admin']=(int)$db->insert_id;}
foreach([1,2,3] as $unit){$_SESSION=['admin_id'=>$actors['super_admin'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);
    $super=fh_session($actors['super_admin'],'super_admin',$unit);$admin=fh_session($actors['admin'],'admin',$unit);$_SESSION=['admin_id'=>$actors['super_admin'],'admin_role'=>'super_admin','active_unit_id'=>$unit];
    $class=hp_one($db,'SELECT * FROM master_kelas WHERE is_active=1 AND is_placeholder=0 AND tingkat>0 ORDER BY tingkat,id LIMIT 1');$nis='F'.bin2hex(random_bytes(4));
    ofb_write($db,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB,POMG,is_active) VALUES(?,?,?,?,0,0,1)',[$nis,'QA Fee Billing '.$unit,$class['tingkat'],$class['id']]);
    ofb_write($db,"INSERT INTO master_biaya_lain(nama,nominal,is_active,jenis_pembayaran) VALUES(?,100000,1,'berulang')",['QA Fee Billing '.bin2hex(random_bytes(4))]);$master=(int)$db->insert_id;
    [$first,$replay]=fh_input($admin,$nis,$master,100000);fh_check($first>0,'Unit '.$unit.' official purchase saved');$d=hp_one($db,'SELECT * FROM bayar_biaya_lain WHERE bayar_id=?',[$first]);$bill=(int)$d['tagihan_biaya_lain_id'];
    fh_check($bill>0&&other_fee_bill_find($db,$bill,$nis)['sisa']===0.0,'Unit '.$unit.' purchase creates paid student bill');
    fh_post($admin,'pembayaran/proses.php',$replay);fh_check((int)hp_one($db,'SELECT COUNT(*) n FROM bayar WHERE NO_INDUK=?',[$nis])['n']===1,'Unit '.$unit.' HTTP retry creates no duplicate cash');
    [$second]=fh_input($admin,$nis,$master,100000);$secondBill=(int)hp_one($db,'SELECT tagihan_biaya_lain_id FROM bayar_biaya_lain WHERE bayar_id=?',[$second])['tagihan_biaya_lain_id'];fh_check($second!==$first&&$secondBill!==$bill,'Unit '.$unit.' second purchase has another bill');
    fh_edit($super,$first,$nis,$master,100000);$d=hp_one($db,'SELECT * FROM bayar_biaya_lain WHERE bayar_id=?',[$first]);fh_check((int)$d['tagihan_biaya_lain_id']===$bill,'Unit '.$unit.' authorised edit keeps bill identity');
    ofb_write($db,'UPDATE master_biaya_lain SET nominal=120000 WHERE id=?',[$master]);
    $before=(int)hp_one($db,'SELECT COUNT(*) n FROM bayar WHERE NO_INDUK=?',[$nis])['n'];fh_input($super,$nis,$master,100000);fh_check((int)hp_one($db,'SELECT COUNT(*) n FROM bayar WHERE NO_INDUK=?',[$nis])['n']===$before,'Unit '.$unit.' changed tariff rejects stale purchase');
    [$third]=fh_input($super,$nis,$master,120000);fh_check($third!==$second,'Unit '.$unit.' new tariff purchase saved');
    fh_edit($admin,$first,$nis,$master,120000);$req=hp_one($db,'SELECT * FROM transaksi_otorisasi WHERE bayar_id=? ORDER BY id DESC LIMIT 1',[$first]);fh_check(($req['status']??'')==='pending','Unit '.$unit.' admin edit requires approval');
    fh_check(ofb_cents(hp_one($db,'SELECT nominal_tagihan FROM tagihan_biaya_lain WHERE id=?',[$bill])['nominal_tagihan'])===10000000,'Unit '.$unit.' proposal changes no bill');
    fh_post($super,'pembayaran/proses.php',['aksi'=>'otorisasi_setujui','request_id'=>$req['id'],'csrf_token'=>$super['csrf'],'_operational_unit'=>$unit,'decision_note'=>'QA approved purchase correction']);
    fh_check(ofb_cents(hp_one($db,'SELECT nominal_tagihan FROM tagihan_biaya_lain WHERE id=?',[$bill])['nominal_tagihan'])===12000000,'Unit '.$unit.' approved amount changes bill atomically');
    $delete=['aksi'=>'hapus','id'=>$second,'csrf_token'=>$admin['csrf'],'_operational_unit'=>$unit,'authorization_reason'=>'QA cancel purchase'];fh_post($admin,'pembayaran/proses.php',$delete);$req=hp_one($db,'SELECT * FROM transaksi_otorisasi WHERE bayar_id=? ORDER BY id DESC LIMIT 1',[$second]);
    fh_post($super,'otorisasi_transaksi.php',['action'=>'reject','request_id'=>$req['id'],'csrf_token'=>$super['csrf'],'_operational_unit'=>$unit,'decision_note'=>'QA keep original purchase']);fh_check(hp_one($db,'SELECT status FROM tagihan_biaya_lain WHERE id=?',[$secondBill])['status']==='open','Unit '.$unit.' rejected deletion changes no bill');
    fh_post($admin,'pembayaran/proses.php',$delete);$req=hp_one($db,'SELECT * FROM transaksi_otorisasi WHERE bayar_id=? ORDER BY id DESC LIMIT 1',[$second]);fh_post($super,'pembayaran/proses.php',['aksi'=>'otorisasi_setujui','request_id'=>$req['id'],'csrf_token'=>$super['csrf'],'_operational_unit'=>$unit,'decision_note'=>'QA approve cancelled purchase']);
    fh_check(hp_one($db,'SELECT status FROM tagihan_biaya_lain WHERE id=?',[$secondBill])['status']==='cancelled'&&!hp_one($db,'SELECT id FROM bayar WHERE id=?',[$second]),'Unit '.$unit.' approved cancellation preserves cancelled bill');
    fh_check((int)hp_one($db,'SELECT COUNT(*) n FROM other_fee_billing_audit WHERE bill_id=?',[$secondBill])['n']>=2,'Unit '.$unit.' bill audit retained');
    $manualName='QA Pendaftaran Manual '.bin2hex(random_bytes(4));
    $manual=fh_create_manual($super,$manualName);
    $modeQuote=['_other_fee_modes'=>['master:'.$manual=>'input_manual']];
    [$manualFirst,$manualRequest]=fh_input($admin,$nis,$manual,'125.000,50',$modeQuote);
    $detail=hp_one($db,'SELECT * FROM bayar_biaya_lain WHERE bayar_id=?',[$manualFirst]);
    $manualBill=(int)($detail['tagihan_biaya_lain_id']??0);
    $registered=other_fee_bill_find($db,$manualBill,$nis);
    fh_check($manualBill>0&&$detail['nominal_snapshot']==='125000.50'&&$detail['tarif_referensi_snapshot']===null&&$registered['sisa']===0.0&&other_fee_purchase_mode($registered)==='input_manual','Unit '.$unit.' records exact paid manual bill and evidence');
    fh_post($admin,'pembayaran/proses.php',$manualRequest);
    fh_check((int)hp_one($db,'SELECT COUNT(*) n FROM tagihan_biaya_lain WHERE master_biaya_lain_id=?',[$manual])['n']===1,'Manual HTTP replay creates no duplicate bill');

    [$manualSecond]=fh_input($admin,$nis,$manual,'175.700,25',$modeQuote);
    $secondManualBill=(int)hp_one($db,'SELECT tagihan_biaya_lain_id FROM bayar_biaya_lain WHERE bayar_id=?',[$manualSecond])['tagihan_biaya_lain_id'];
    fh_check($manualFirst!==$manualSecond&&$manualBill!==$secondManualBill,'Same student can pay the same manual master again');
    if($unit===1){
        foreach(['','0','-10','10,123','1.2.3','10000000000000'] as $bad)fh_input($admin,$nis,$manual,$bad,$modeQuote);
        fh_input($admin,$nis,$manual,'55.000',array_replace($modeQuote,[
            'biaya_lain_tagihan_id'=>['master:'.$manual,'master:'.$manual],
            'biaya_lain_nominal'=>['55.000','75.000'],
        ]));
        fh_check((int)hp_one($db,'SELECT COUNT(*) n FROM tagihan_biaya_lain WHERE master_biaya_lain_id=?',[$manual])['n']===2,'Invalid and duplicate manual entries create no bills');
    }
    if($unit===2){
        $outside=(int)$fixtures[1]['manual'];
        [$stillSame]=fh_input($admin,$nis,$outside,'55.000');
        fh_check($stillSame===$manualSecond,'Cross-unit manual choice creates no payment');
    }

    ofb_write($db,"UPDATE master_biaya_lain SET jenis_pembayaran='berulang',nominal=51000 WHERE id=?",[$manual]);
    fh_edit($admin,$manualFirst,$nis,$manual,'133.120,75',false);
    $req=hp_one($db,'SELECT * FROM transaksi_otorisasi WHERE bayar_id=? ORDER BY id DESC LIMIT 1',[$manualFirst]);
    fh_check(($req['status']??'')==='pending','Historic manual mode accepts authorised amount correction after master change');
    fh_post($super,'pembayaran/proses.php',['aksi'=>'otorisasi_setujui','request_id'=>$req['id'],'csrf_token'=>$super['csrf'],'decision_note'=>'QA approved manual correction']);
    $detail=hp_one($db,'SELECT * FROM bayar_biaya_lain WHERE bayar_id=?',[$manualFirst]);
    $registered=other_fee_bill_find($db,$manualBill,$nis);
    fh_check((int)$detail['tagihan_biaya_lain_id']===$manualBill&&$detail['nama_biaya_snapshot']===$manualName&&$detail['nominal_snapshot']==='133120.75'&&$registered['nominal_tagihan']===133120.75&&$registered['sisa']===0.0,'Manual correction retains bill identity and updates cash and principal');
    fh_check($detail['keterangan']==='QA purchase billing','Manual correction without the note field preserves the stored note');
    $delete=['aksi'=>'hapus','id'=>$manualSecond,'csrf_token'=>$admin['csrf'],'authorization_reason'=>'QA manual purchase cancellation'];
    fh_post($admin,'pembayaran/proses.php',$delete);
    $req=hp_one($db,'SELECT * FROM transaksi_otorisasi WHERE bayar_id=? ORDER BY id DESC LIMIT 1',[$manualSecond]);
    fh_post($super,'pembayaran/proses.php',['aksi'=>'otorisasi_setujui','request_id'=>$req['id'],'csrf_token'=>$super['csrf'],'decision_note'=>'QA approved manual cancellation']);
    fh_check(hp_one($db,'SELECT status FROM tagihan_biaya_lain WHERE id=?',[$secondManualBill])['status']==='cancelled'
        &&!hp_one($db,'SELECT id FROM bayar WHERE id=?',[$manualSecond]),'Cancelled manual purchase retains cancelled bill');
    require_once __DIR__.'/../includes/other_fee_usage.php';
    $usage=other_fee_master_usage($db,$manual)[0];
    fh_check($usage['jumlah_pemakaian']===1&&ofb_cents($usage['nominal_pemakaian'])===13312075&&!$usage['can_delete'],
        'Master counts manual receipts and protects the audit');
    ofb_write($db,"UPDATE master_biaya_lain SET jenis_pembayaran='input_manual',nominal=0 WHERE id=?",[$manual]);
    $fixtures[$unit]=compact('nis','master','first','second','third','bill','secondBill','super','admin','manual','manualName','manualFirst','manualBill');
}
file_put_contents($private.'/billing-http.json',json_encode(['passed'=>true,'checks'=>$feeHttpChecks,'fixtures'=>$fixtures],JSON_PRETTY_PRINT));echo 'PASS '.count($feeHttpChecks)." official HTTP lifecycle checks.\n";
