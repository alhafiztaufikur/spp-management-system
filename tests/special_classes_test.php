<?php
if(PHP_SAPI!=='cli')exit(1);
$app=getenv('SPP_SPECIAL_APP_ROOT')?:dirname(__DIR__);
require $app.'/koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require $app.'/includes/special_classes.php';require $app.'/includes/special_classes_schema.php';
function sc_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$actor=$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$_SESSION=['admin_id'=>(int)$actor,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);
$tag='QA'.bin2hex(random_bytes(4));$financial=['bayar','bayar_du','tabungan','transaksi_m','transaksi_k','tagihan_spp','tagihan_komite','tagihan_daftar_ulang'];$before=[];
foreach($financial as $t)$before[$t]=(int)$koneksi->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
special_classes_schema_apply($koneksi);special_classes_schema_apply($koneksi);sc_check(special_classes_ready($koneksi),'Idempotent schema not ready');
$id=special_class_save($koneksi,0,$tag.' BTQ',1);$row=class_find($koneksi,$id);sc_check($id>0&&class_kind($row)==='custom'&&$row['tingkat']===null&&class_label($row)===$tag.' BTQ','Special class representation');
foreach(['',str_repeat('x',101),'PSB','out','Alumni2025'] as $invalid){try{special_class_save($koneksi,0,$invalid,1);throw new RuntimeException('Invalid/reserved name accepted');}catch(DomainException $e){}}
$long=special_class_save($koneksi,0,str_repeat('x',100),0);special_class_delete($koneksi,$long,special_class_version(class_find($koneksi,$long)));
try{special_class_save($koneksi,0,strtolower($tag).' btq',1);throw new RuntimeException('Duplicate accepted');}catch(DomainException $e){}
unit_set_context($koneksi,2);$_SESSION['active_unit_id']=2;$other=special_class_save($koneksi,0,$tag.' BTQ',1);sc_check($other!==$id,'Cross-unit same name failed');
try{special_class_delete($koneksi,$id,special_class_version($row));throw new RuntimeException('Cross-unit deletion accepted');}catch(DomainException $e){}
unit_set_context($koneksi,1);$_SESSION['active_unit_id']=1;
special_class_save($koneksi,$id,$tag.' Tabungan Guru',0,special_class_version($row));
try{special_class_save($koneksi,$id,$tag.' stale',1,special_class_version($row));throw new RuntimeException('Stale change accepted');}catch(DomainException $e){}
sc_check(!class_find($koneksi,$id,true),'Inactive special class selectable for new member');
class_enable_all_rombel($koneksi);sc_check(!(int)class_find($koneksi,$id)['is_active'],'Bulk regular action activated special class');
$row=class_find($koneksi,$id);special_class_save($koneksi,$id,$tag.' Tabungan Guru',1,special_class_version($row));
$nis=(string)random_int(9000000000,9999999999);special_class_query($koneksi,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,'CUSTOM',?,0,0,1)",[$nis,$tag.' Member',$id]);$student=(int)$koneksi->insert_id;
$sync=null;$placement=class_sync_student_current_year($koneksi,$nis,$id,530000,15000,true,$sync);sc_check($placement===null,'Special class generated academic placement');
sc_check(class_label(['KELAS'=>'CUSTOM','master_kelas_id'=>$id])===$tag.' Tabungan Guru','Old-source label lookup lost special name');
sc_check((bool)special_class_usage($koneksi,$id),'Existing member not counted');
try{special_class_delete($koneksi,$id,special_class_version(class_find($koneksi,$id)));throw new RuntimeException('Used class deleted');}catch(DomainException $e){}
$row=class_find($koneksi,$id);special_class_save($koneksi,$id,$tag.' Tabungan Guru',0,special_class_version($row));sc_check((int)special_class_query($koneksi,'SELECT master_kelas_id FROM siswa WHERE id=?',[$student])->fetch_row()[0]===$id,'Deactivation moved existing member');
$empty=special_class_save($koneksi,0,$tag.' Empty',0);special_class_delete($koneksi,$empty,special_class_version(class_find($koneksi,$empty)));sc_check(class_find($koneksi,$empty)===null,'Unused class not deleted');
foreach($financial as $t)sc_check((int)$koneksi->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0]===$before[$t],'Master change affected '.$t);
$base=getenv('SPP_TEST_BASE_URL');spp_test_assert_http_clone($base,DB_NAME);$sid='special'.bin2hex(random_bytes(12));session_id($sid);session_start();$_SESSION=['admin_id'=>(int)$actor,'admin_role'=>'super_admin','active_unit_id'=>1,'csrf_master_kelas'=>str_repeat('a',64)];session_write_close();
function sc_http(string $route,array $post=[]):array{global $base,$sid;$c=curl_init($base.'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sid,CURLOPT_TIMEOUT=>60]);if($post)$post+=['_operational_unit'=>1];if($post)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,$body];}
[$status,$body]=sc_http('master_kelas.php?special_new=1');sc_check($status===200&&!preg_match('/Fatal error|Warning:|Notice:/',$body),'Master UI failed');
$request=bin2hex(random_bytes(16));$post=['csrf_token'=>str_repeat('a',64),'aksi'=>'tambah_custom','id'=>0,'nama_custom'=>$tag.' HTTP','is_active'=>1,'special_request'=>$request];
sc_check(sc_http('master_kelas.php',$post)[0]===302,'HTTP create did not redirect');sc_http('master_kelas.php',$post);
$count=(int)special_class_query($koneksi,'SELECT COUNT(*) FROM master_kelas WHERE nama_custom=?',[$tag.' HTTP'])->fetch_row()[0];sc_check($count===1,'Retry duplicated class');
sc_http('master_kelas.php',array_replace($post,['csrf_token'=>'wrong','nama_custom'=>$tag.' BAD']));sc_check(!special_class_query($koneksi,'SELECT id FROM master_kelas WHERE nama_custom=?',[$tag.' BAD'])->fetch_assoc(),'CSRF bypass');
sc_http('master_kelas.php',array_replace($post,['nama_custom'=>$tag.' invalid'.str_repeat('x',101),'special_request'=>bin2hex(random_bytes(16))]));
[, $invalidForm]=sc_http('master_kelas.php?special_new=1');sc_check(str_contains($invalidForm,$tag.' invalid'.str_repeat('x',101)),'Validation failure lost entered name');
$deleteId=special_class_save($koneksi,0,$tag.' HTTP delete',0);[, $deleteHtml]=sc_http('master_kelas.php?special_delete='.$deleteId);preg_match('/name="delete_token" value="([a-f0-9]{64})"/',$deleteHtml,$deleteToken);sc_check(!empty($deleteToken[1]),'Server delete preview missing');
$deletePost=['aksi'=>'hapus_custom','id'=>$deleteId,'csrf_token'=>str_repeat('a',64),'delete_token'=>$deleteToken[1]];sc_http('master_kelas.php',$deletePost);sc_http('master_kelas.php',$deletePost);sc_check(class_find($koneksi,$deleteId)===null,'Confirmed HTTP delete/retry failed');
$r=class_find($koneksi,$id);[$status,$body]=sc_http('master_kelas.php?special_delete='.$id);sc_check($status===200&&str_contains($body,'Kelas sudah pernah digunakan'),'Used-class resolution missing');
[$status,$body]=sc_http('siswa/daftar.php?edit='.$student);sc_check($status===200&&str_contains($body,$tag.' Tabungan Guru'),'Inactive member edit lost current class');
// Exercise the actual member form: no academic bill or savings account may appear.
[$status,$body]=sc_http('siswa/daftar.php');preg_match('/name="csrf_token" value="([a-f0-9]{64})"/',spp_test_form_scope($body,'no_induk'),$csrf);
$target=special_class_query($koneksi,'SELECT id FROM master_kelas WHERE nama_custom=?',[$tag.' HTTP'])->fetch_row()[0];
$newNis=(string)random_int(9000000000,9999999999);$memberPost=['aksi'=>'tambah','csrf_token'=>$csrf[1],'no_induk'=>$newNis,'nama'=>$tag.' HTTP Member','master_kelas_id'=>$target,'komite_mulai_bulan'=>'07','psb'=>'0','pomg'=>'0','daftar_ulang'=>'0','potongan_spp_nominal'=>'0','potong_du'=>'0'];
sc_check(sc_http('siswa/daftar.php',$memberPost)[0]===302,'Member create failed');
$newMember=special_class_query($koneksi,'SELECT * FROM siswa WHERE NO_INDUK=?',[$newNis])->fetch_assoc();sc_check($newMember&&$newMember['KELAS']==='CUSTOM'&&(float)$newMember['SPP_PERBULAN']===0.0,'Nonacademic member did not save correctly');
foreach($financial as $t)sc_check((int)$koneksi->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0]===$before[$t],'Member create affected '.$t);
[$status,$body]=sc_http('siswa/daftar.php?edit='.$student);preg_match('/name="csrf_token" value="([a-f0-9]{64})"/',spp_test_form_scope($body,'no_induk'),$csrf);
$editPost=array_replace($memberPost,['aksi'=>'update','id'=>$student,'no_induk'=>$nis,'nama'=>$tag.' Member Edited','master_kelas_id'=>$id,'csrf_token'=>$csrf[1]]);sc_http('siswa/daftar.php',$editPost);
if(special_class_query($koneksi,'SELECT NAMA FROM siswa WHERE id=?',[$student])->fetch_row()[0]!==$tag.' Member Edited'){[, $debug]=sc_http('siswa/daftar.php?edit='.$student);preg_match('/class="alert[^"]*"[^>]*>(.*?)<\/div>/s',$debug,$failure);throw new RuntimeException('Inactive edit: '.strip_tags($failure[1]??'No flash available'));}
$rolesColumn=$koneksi->query("SHOW COLUMNS FROM admin LIKE 'role'")->fetch_assoc()['Type'];
foreach(['bendahara','admin','kasir'] as $role){if(!str_contains($rolesColumn,"'".$role."'"))continue;
    $username=$tag.$role;special_class_query($koneksi,'INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,?,?,2,1)',[$username,password_hash('private-test-only',PASSWORD_DEFAULT),$username,$role]);$testActor=(int)$koneksi->insert_id;
    $originalSid=$sid;$sid='specialrole'.bin2hex(random_bytes(10));session_id($sid);session_start();$_SESSION=['admin_id'=>$testActor,'admin_role'=>$role,'active_unit_id'=>2,'csrf_master_kelas'=>str_repeat('a',64)];session_write_close();
    sc_http('master_kelas.php',['aksi'=>'update_custom','id'=>$id,'nama_custom'=>$tag.' FOREIGN','is_active'=>1,'_operational_unit'=>2,'csrf_token'=>str_repeat('a',64)]);
    sc_check(class_find($koneksi,$id)['nama_custom']===$tag.' Tabungan Guru','Other-role/unit edited class');
    $sid=$originalSid;
}
$_SESSION=['admin_id'=>(int)$actor,'admin_role'=>'super_admin','active_unit_id'=>1];
require_once $app.'/includes/reports.php';$filters=report_filters($koneksi,['template'=>'saldo-tabungan','siswa_status'=>'all']);$model=report_savings_balance_data($koneksi,$filters);$matched=array_values(array_filter($model['rows'],static fn($r)=>$r['nis']===$newNis));sc_check(count($matched)===1&&$matched[0]['kelas']===$tag.' HTTP','Savings report lost special class name');
[$bookStatus,$bookHtml]=sc_http('tabungan/cetak_buku.php?'.http_build_query(['nis'=>$newNis,'student_id'=>$newMember['id'],'output'=>'preview']));sc_check($bookStatus===200,'Savings book preview failed');require_once $app.'/includes/savings_book_print.php';[$bookStudent,$book]=savings_book_load($koneksi,$newNis,(int)$newMember['id'],1);sc_check(($bookStudent['kelas_label']??$bookStudent['KELAS'])===$tag.' HTTP','Savings book model lost special class name');
foreach(['tabungan/masuk.php','tabungan/riwayat.php','tabungan/cetak.php'] as $route){[$status,$html]=sc_http($route);sc_check($status===200&&str_contains($html,$tag.' HTTP')&&!preg_match('/Fatal error|Warning:|Notice:/',$html),'Savings screen lost label: '.$route);}
// A real workbook and PDF keep the class name in the reporting output.
$exportQuery=http_build_query(['template'=>'saldo-tabungan','siswa_status'=>'all','unit'=>'1']);
[$exportStatus,$excel]=sc_http('laporan/export_global.php?'.$exportQuery.'&format=excel&download=1');sc_check($exportStatus===200&&str_starts_with($excel,'PK'),'Special-class Excel export failed');
$output=getenv('SPP_SPECIAL_QA_DIR');file_put_contents($output.'/special-savings.xlsx',$excel);require_once dirname(__DIR__).'/vendor/autoload.php';$workbook=\PhpOffice\PhpSpreadsheet\IOFactory::load($output.'/special-savings.xlsx');$found=false;foreach($workbook->getAllSheets() as $sheet)foreach($sheet->toArray() as $cells)if(in_array($tag.' HTTP',$cells,true))$found=true;sc_check($found,'Export workbook lost class name');$workbook->disconnectWorksheets();
[$exportStatus,$pdf]=sc_http('laporan/export_global.php?'.$exportQuery.'&format=pdf&download=1');sc_check($exportStatus===200&&str_starts_with($pdf,'%PDF-'),'Special-class PDF export failed');file_put_contents($output.'/special-savings.pdf',$pdf);
$dialogName=$tag.' Dialog Empty';special_class_save($koneksi,0,$dialogName,1);
$fixture=['base'=>$base,'session'=>$sid,'class'=>$id,'member'=>$student,'name'=>$tag.' Tabungan Guru','http_name'=>$dialogName,'new_member'=>$newMember['id'],'database'=>DB_NAME];$output=getenv('SPP_SPECIAL_QA_DIR');if(!$output||!is_dir($output))throw new RuntimeException('Private QA directory required');file_put_contents($output.'/special-fixture.json',json_encode($fixture));
echo "PASS: idempotent schema, custom CRUD, name uniqueness/unit scope, stale changes, unused deletion, used-class protection, bulk isolation, no billing/savings side effects, HTTP retry/CSRF and inactive-member editing.\n";
