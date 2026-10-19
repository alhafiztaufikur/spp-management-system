<?php
if(PHP_SAPI!=='cli')exit(1);ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_finance_http.php';require_once __DIR__.'/legacy_finance_support.php';require_once __DIR__.'/../includes/biaya_lain.php';
$db=$koneksi;$base=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');spp_test_assert_http_clone($base,DB_NAME);
function lfht_session(int $actor,int $unit,string $role='super_admin'): string {
    $sid='lfqa'.bin2hex(random_bytes(16));session_id($sid);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>$role,'active_unit_id'=>$unit,'csrf_legacy_finance'=>str_repeat('a',64),'csrf_savings'=>str_repeat('b',64),'csrf_payment'=>str_repeat('c',64),'csrf_master_biaya_lain'=>str_repeat('d',64),'csrf_transaction_authorization'=>str_repeat('e',64)];session_write_close();return $sid;
}
function lfht_http(string $path,string $session='',?array $post=null): array {
    global $base;$headers=['Cookie: PHPSESSID='.$session];if($post!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
    $body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>120]]));
    preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$m);return [(int)($m[1]??0),(string)$body];
}
$actor=(int)$db->query("SELECT id FROM admin WHERE is_active=1 AND role='super_admin' LIMIT 1")->fetch_row()[0];$sid=lfht_session($actor,1);
lft_assert(lfht_http('legacy_finance_api.php')[0]===403,'Anonymous archive access');
foreach(['admin','kasir','bendahara'] as $role){$id=(int)$db->query("SELECT id FROM admin WHERE role='$role' AND is_active=1 LIMIT 1")->fetch_row()[0];lft_assert(lfht_http('legacy_finance_api.php',lfht_session($id,1,$role))[0]===403,'Non-SA archive access');lft_assert(lfht_http('legacy_finance_api.php',lfht_session($id,1,'super_admin'))[0]===403,'Forged role archive access');}
foreach([0,1,2,3] as $unit){$session=lfht_session($actor,$unit);lft_assert(lfht_http('legacy_finance.php',$session)[0]===200,'Archive UI scope');lft_assert(lfht_http('legacy_finance_api.php?action=archive&table=bayar&page=1',$session)[0]===200,'Archive API scope');}
lft_assert(lfht_http('legacy_finance_api.php?action=archive&q[]=x',$sid)[0]>=400,'Array filter');lft_assert(lfht_http('legacy_finance_api.php?action=archive&id=../../config',$sid)[0]>=400,'Path parameter');
$f=lft_seed($db,$actor,1);$post=['id'=>$f['id'],'version'=>1,'action'=>'save','csrf_token'=>str_repeat('a',64),'proposal'=>lf_json(['effective_date'=>date('Y-m-d'),'evidence'=>'Bukti yayasan HTTP terisolasi','attested'=>true,'savings'=>true,'debts'=>[['component'=>'HTTP QA '.$f['id'],'period'=>'2018-07','amount'=>'15000','kind'=>'other','source_row_key'=>$f['key'],'distinction'=>'Kewajiban terpisah yang telah diperiksa.']]])];
lft_assert(lfht_http('legacy_finance_api.php',$sid,array_merge($post,['csrf_token'=>'wrong']))[0]===403,'CSRF not enforced');
lft_assert(lfht_http('legacy_finance_api.php',lfht_session($actor,0),$post)[0]===409,'All-unit write');
[$status,$body]=lfht_http('legacy_finance_api.php',$sid,$post);$saved=json_decode($body,true);lft_assert($status===200&&!empty($saved['confirmation_token']),'Save and server confirmation');
$apply=['id'=>$f['id'],'version'=>2,'action'=>'apply','csrf_token'=>str_repeat('a',64),'confirmation'=>'TERAPKAN LEGACY','confirmation_token'=>$saved['confirmation_token']];
session_id($sid);session_start();$_SESSION['legacy_finance_confirmations'][$saved['confirmation_token']]['expires']=time()-1;session_write_close();lft_assert(lfht_http('legacy_finance_api.php',$sid,$apply)[0]===409,'Expired confirmation accepted');
[$status,$body]=lfht_http('legacy_finance_api.php',$sid,['action'=>'confirm','id'=>$f['id'],'version'=>2,'csrf_token'=>str_repeat('a',64)]);$confirmed=json_decode($body,true);$apply['confirmation_token']=$confirmed['confirmation_token'];
lft_assert(lfht_http('legacy_finance_api.php',$sid,$apply)[0]===200,'Confirmed apply');lft_assert(lfht_http('legacy_finance_api.php',$sid,$apply)[0]===200,'Lost response retry');
unit_set_context($db,1);$debt=lf_one($db,'SELECT * FROM legacy_debts WHERE review_id=?',[$f['id']]);
foreach(['masuk'=>20,'keluar'=>10] as $action=>$amount){lft_assert(lfht_http('tabungan/proses.php',$sid,['aksi'=>$action,'no_induk'=>$f['nis'],'nominal'=>(string)$amount,'request_key'=>bin2hex(random_bytes(16)),'csrf_token'=>str_repeat('b',64),'keterangan'=>'QA sesudah saldo awal'])[0]===302,'Savings HTTP');}
lft_assert((float)lf_one($db,'SELECT SALDO FROM tabungan WHERE NO_INDUK=?',[$f['nis']])['SALDO']===310.5,'Savings HTTP balance');
// Ordinary fee payments must use actual current receipts, including installments.
$paymentIds=[];
$cashier=(int)$db->query("SELECT id FROM admin WHERE role='kasir' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_row()[0];$cashierSession=lfht_session($cashier,1,'kasir');
foreach([5000,10000] as $amount){
    $request=['aksi'=>'input','no_induk'=>$f['nis'],'bulan_bayar'=>date('m'),'tahun_bayar'=>date('Y'),'sistem_pembayaran'=>'Tunai','payment_plan'=>'monthly','csrf_token'=>str_repeat('c',64),'request_key'=>bin2hex(random_bytes(16)),'biaya_lain_detail_id'=>[''],'biaya_lain_tagihan_id'=>[$debt['bill_id']],'biaya_lain_nominal'=>[(string)$amount],'biaya_lain_keterangan'=>['Cicilan Tunggakan Legacy QA']];
    [$status,$body]=lfht_http('pembayaran/proses.php',$cashierSession,$request);lft_assert($status===302,'Fee payment HTTP');$payment=lf_one($db,'SELECT * FROM bayar WHERE NO_INDUK=? ORDER BY id DESC LIMIT 1',[$f['nis']]);lft_assert($payment && (float)$payment['total_jumlah']===$amount*1.0,'Fee receipt not created');$paymentIds[]=(int)$payment['id'];
    $bill=other_fee_bill_find($db,(int)$debt['bill_id'],$f['nis']);lft_assert((float)$bill['terbayar']===(float)array_sum(array_slice([5000,10000],0,count($paymentIds))),'Installment amount');
}
lft_assert((float)other_fee_bill_find($db,(int)$debt['bill_id'],$f['nis'])['sisa']===0.0,'Legacy debt not settled');
lft_reject(fn()=>lf_cancel_debts($db,$actor,1,$f['id'],3,'Pembatalan tagihan yang sudah menerima pembayaran','BATALKAN TUNGGAKAN LEGACY'),'Paid debt cancelled');
[$status,$receipt]=lfht_http('laporan/cetak_struk.php?id='.$paymentIds[0],$sid);lft_assert($status===200&&str_contains($receipt,'Tunggakan Legacy'),'Receipt missing Legacy name');
// Editing and deleting a new Legacy-debt payment retain the existing authorization workflow.
$detail=lf_one($db,'SELECT id FROM bayar_biaya_lain WHERE bayar_id=?',[$paymentIds[0]]);
$edit=['aksi'=>'update','id'=>$paymentIds[0],'no_induk'=>$f['nis'],'bulan_bayar'=>date('m'),'tahun_bayar'=>date('Y'),'sistem_pembayaran'=>'Tunai','csrf_token'=>str_repeat('c',64),'biaya_lain_detail_id'=>[$detail['id']],'biaya_lain_tagihan_id'=>[$debt['bill_id']],'biaya_lain_nominal'=>['4000'],'biaya_lain_keterangan'=>['Koreksi QA'],'authorization_reason'=>'Bukti koreksi nominal cicilan Legacy QA.'];
lfht_http('pembayaran/proses.php',$cashierSession,$edit);$pendingEdit=lf_one($db,"SELECT id FROM transaksi_otorisasi WHERE bayar_id=? AND action='edit' AND status='pending' ORDER BY id DESC LIMIT 1",[$paymentIds[0]]);lft_assert((bool)$pendingEdit,'Edit authorization not created');
lfht_http('pembayaran/proses.php',$sid,['aksi'=>'otorisasi_setujui','request_id'=>$pendingEdit['id'],'csrf_token'=>str_repeat('e',64),'decision_note'=>'Disetujui berdasarkan bukti QA.']);lft_assert(other_fee_bill_find($db,(int)$debt['bill_id'],$f['nis'])['terbayar']===14000.0,'Authorized correction failed');
lfht_http('pembayaran/proses.php',$cashierSession,['aksi'=>'hapus','id'=>$paymentIds[0],'csrf_token'=>str_repeat('c',64),'authorization_reason'=>'Pembatalan cicilan salah pada pengujian QA.']);$pendingDelete=lf_one($db,"SELECT id FROM transaksi_otorisasi WHERE bayar_id=? AND action='hapus' AND status='pending' ORDER BY id DESC LIMIT 1",[$paymentIds[0]]);lft_assert((bool)$pendingDelete,'Delete authorization not created');
lfht_http('pembayaran/proses.php',$sid,['aksi'=>'otorisasi_setujui','request_id'=>$pendingDelete['id'],'csrf_token'=>str_repeat('e',64),'decision_note'=>'Pembatalan berdasarkan bukti QA.']);lft_assert(other_fee_bill_find($db,(int)$debt['bill_id'],$f['nis'])['terbayar']===10000.0,'Authorized cancellation failed');
[$status,$csv]=lfht_http('legacy_finance_api.php?action=csv&q='.$f['nis'],$sid);lft_assert($status===200&&str_contains($csv,'Hash Backup')&&str_contains($csv,$f['key']),'CSV source identity');
lft_assert(lf_csv_cell(' =1+1')==="' =1+1",'CSV formula injection');
// A role revoked after issuing confirmation cannot use that confirmation.
$user='lfqa'.bin2hex(random_bytes(6));$hash=password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT);lf_query($db,"INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,'QA revoked','super_admin',1,1)",[$user,$hash]);$owned=(int)$db->insert_id;$revoked=lfht_session($owned,1);lf_query($db,'UPDATE admin SET is_active=0 WHERE id=?',[$owned]);lft_assert(lfht_http('legacy_finance_api.php',$revoked,$apply)[0]===403,'Revoked actor access');
// Server-rendered review and confirmation work with no client scripting.
$pending=lft_seed($db,$actor,1);$server=['action'=>'save','id'=>$pending['id'],'version'=>1,'csrf_token'=>str_repeat('a',64),'effective_date'=>date('Y-m-d'),'evidence'=>'Bukti yayasan tanpa JavaScript','savings'=>'on','attested'=>'on'];[$status,$html]=lfht_http('legacy_finance.php',$sid,$server);lft_assert($status===200&&str_contains($html,'id="lf-confirmation"')&&str_contains($html,'name="confirmation_token"'),'Server confirmation missing');
preg_match('/name="confirmation_token" value="([a-f0-9]+)"/',$html,$token);lft_assert(lfht_http('legacy_finance.php',$sid,['action'=>'apply','id'=>$pending['id'],'version'=>2,'csrf_token'=>str_repeat('a',64),'confirmation'=>'TERAPKAN LEGACY','confirmation_token'=>$token[1]])[0]===302,'Server form apply without JavaScript');lft_assert(lf_review_load($db,$pending['id'])['status']==='applied','Server apply did not commit');
$pending=lft_seed($db,$actor,1);lf_save_review($db,$actor,1,$pending['id'],1,['effective_date'=>date('Y-m-d'),'evidence'=>'Bukti yayasan browser terisolasi','savings'=>true,'attested'=>true,'debts'=>[]]);
file_put_contents(dirname((string)getenv('SPP_TEST_ISOLATED_INSTANCE')).'/finance-browser.json',lf_json(['base'=>$base,'session'=>$sid,'review'=>$pending['id'],'applied_review'=>$f['id'],'source'=>$pending['source'],'unit'=>1]));
echo "PASS: HTTP roles/scopes, CSRF, stale/expired confirmation, retry, actual deposits/withdrawals, installments/settlement/receipt, CSV, revoked actor and server confirmation.\n";
