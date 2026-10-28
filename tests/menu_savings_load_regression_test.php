<?php
/** Exercise the current two-role savings flow only on an explicitly owned clone. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require_once __DIR__.'/../includes/savings_authorization.php';
require_once __DIR__.'/../includes/savings_book_print.php';
$db=$koneksi;unit_set_context($db,1);$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
$fixtureNis=(string)getenv('SPP_MENU_SAVINGS_NIS');if($fixtureNis==='')throw new RuntimeException('Private savings fixture identity required.');
$stmt=$db->prepare('SELECT id,NO_INDUK FROM siswa WHERE NO_INDUK=?');$stmt->bind_param('s',$fixtureNis);$stmt->execute();$student=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$student)throw new RuntimeException('Verified real savings regression fixture required.');
$nis=$student['NO_INDUK'];$before=(float)$db->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0];
session_save_path(getenv('SPP_QA_SESSION_PATH'));session_id('menubank'.bin2hex(random_bytes(12)));session_start();$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];$cookie=session_id();session_write_close();
$checks=0;$incoming=0;$outgoing=0;
function mlb_check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function mlb_http(string $path,?array $post=null):string {
    global $cookie;$c=curl_init(rtrim(getenv('SPP_TEST_BASE_URL'),'/').'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$cookie,CURLOPT_TIMEOUT=>60]);
    if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body=curl_exec($c);if($body===false)throw new RuntimeException(curl_error($c));$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    mlb_check(in_array($code,[200,302,303],true)&&!preg_match('/Fatal error|Warning:|Uncaught/',$body),'Savings HTTP request');return $body;
}
function mlb_form(string $kind,string $amount):array {
    global $student;$html=mlb_http('tabungan/'.$kind.'.php?student_id='.$student['id']);$dom=new DOMDocument();@$dom->loadHTML($html);$form=(new DOMXPath($dom))->query('//form[@data-savings-entry]')->item(0);if(!$form)throw new RuntimeException('Savings entry form unavailable.');
    $fields=[];foreach($form->getElementsByTagName('input') as $i)if($i->getAttribute('name')!=='')$fields[$i->getAttribute('name')]=$i->getAttribute('value');
    return array_replace($fields,['owner_type'=>'student','student_id'=>$student['id'],'nominal'=>$amount,'keterangan'=>'QA menu load savings lifecycle']);
}
function mlb_balance():float {global $db,$nis;return (float)$db->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0];}
try{
    $post=mlb_form('masuk','123.13');mlb_http('tabungan/proses.php',$post);
    $incoming=(int)$db->query("SELECT MAX(id) FROM transaksi_m WHERE NO_INDUK='$nis'")->fetch_row()[0];
    mlb_check(abs(mlb_balance()-$before-123.13)<.001,'Deposit preserves the Legacy opening and exact cents');
    mlb_http('tabungan/proses.php',$post);mlb_check(abs(mlb_balance()-$before-123.13)<.001,'Duplicate deposit is rejected');
    mlb_http('tabungan/proses.php',mlb_form('keluar','999999999.99'));mlb_check(abs(mlb_balance()-$before-123.13)<.001,'Excess withdrawal is rejected');
    mlb_http('tabungan/proses.php',mlb_form('keluar','23.13'));
    $outgoing=(int)$db->query("SELECT MAX(id) FROM transaksi_k WHERE NO_INDUK='$nis'")->fetch_row()[0];
    mlb_check(abs(mlb_balance()-$before-100)<.001,'Withdrawal uses the current balance');
    $row=$db->query('SELECT * FROM transaksi_m WHERE id='.$incoming)->fetch_assoc();
    savings_correct($db,'direct_edit','masuk',$incoming,['nominal'=>'150.13','tanggal'=>$row['TANGGAL'],'keterangan'=>'QA menu load corrected savings','reason'=>'Disposable performance regression correction'],1);
    mlb_check(abs(mlb_balance()-$before-127)<.001,'Correction changes only the real difference');
    savings_correct($db,'direct_hapus','keluar',$outgoing,['reason'=>'Disposable performance regression cancellation'],1);$outgoing=0;
    savings_correct($db,'direct_hapus','masuk',$incoming,['reason'=>'Disposable performance regression cancellation'],1);$incoming=0;
    mlb_check(abs(mlb_balance()-$before)<.001,'Cancellation restores the original balance');
    [, $book]=savings_book_load($db,$nis);mlb_check(abs($book['balance']/100-$before)<.001,'Savings book agrees with the account');
    $live=json_decode(mlb_http('tabungan/get_saldo.php?student_id='.$student['id']),true);mlb_check(abs((float)$live['raw_saldo']-$before)<.001,'GUI balance agrees after correction and cancellation');
    echo "PASS $checks current savings GUI/service, exact balance, duplicate/excess rejection, correction and cancellation checks.\n";
}finally{
    foreach([['keluar',$outgoing],['masuk',$incoming]] as [$kind,$id])if($id)try{savings_correct($db,'direct_hapus',$kind,$id,['reason'=>'Clean up owned performance regression fixture'],1);}catch(Throwable $ignored){}
}
