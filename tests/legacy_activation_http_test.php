<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_activation.php';require_once __DIR__.'/http_form_scope.php';
$base=rtrim(getenv('SPP_TEST_BASE_URL'),'/');spp_test_assert_http_clone($base,DB_NAME);
function lh_assert($ok,$why){if(!$ok)throw new RuntimeException($why);}
function lh_request($path,$sid,$post=null){global $base;$opts=['method'=>$post===null?'GET':'POST','header'=>'Cookie: PHPSESSID='.$sid,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>30];if($post!==null){$opts['header'].="\r\nContent-Type: application/x-www-form-urlencoded";$opts['content']=http_build_query($post);}$body=file_get_contents($base.$path,false,stream_context_create(['http'=>$opts]));preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$m);return [(int)$m[1],$body];}
function lh_session($account,$unit){$sid='legacyhttp'.bin2hex(random_bytes(10));session_id($sid);session_start();$_SESSION=['admin_id'=>$account['id'],'admin_role'=>$account['role'],'active_unit_id'=>$unit];session_write_close();return $sid;}
$nis='00'.(string)random_int(80000000,89999999);$students=[];$actors=[];$sessions=[];
foreach([1=>1,2=>7,3=>10] as $unit=>$level){
 unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;
 $old=$koneksi->query("SELECT id FROM siswa WHERE NO_INDUK='$nis'")->fetch_row();
 if(!$old){$koneksi->query("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active,legacy_pending) VALUES('$nis','HTTP Legacy unit $unit','LEGACY',0,1)");$id=(int)$koneksi->insert_id;$hash=hash('sha256','http-fixture-'.$nis.'-'.$unit);$koneksi->query("INSERT INTO legacy_student_import(student_id,unit_id,source_hash,source_row,source_name,source_class,raw_data,imported_by) VALUES($id,$unit,'$hash',1,'HTTP.fixture.dat','unknown',JSON_OBJECT('NO_INDUK','$nis'),1)");}else $id=(int)$old[0];
 $students[$unit]=$id;$role=$unit===2?'kasir':'admin';$account=$koneksi->query("SELECT id,role FROM admin WHERE unit_id=$unit AND role='$role' AND is_active=1 LIMIT 1")->fetch_assoc();lh_assert((bool)$account,'Missing unit actor');$actors[$unit]=$account;$sessions[$unit]=lh_session($account,$unit);
}
$counts=[];unit_set_context($koneksi,0);foreach(['bayar','tabungan','transaksi_m','transaksi_k','tagihan_spp','tagihan_komite','tagihan_daftar_ulang','tagihan_tahunan_siswa'] as $table)$counts[$table]=(int)$koneksi->query('SELECT COUNT(*) FROM '.$table)->fetch_row()[0];
lh_assert(lh_request('/siswa/aktivasi_legacy.php?id='.$students[1],'')[0]===302,'Anonymous activation access');
lh_assert(lh_request('/siswa/aktivasi_legacy.php?id='.$students[2],$sessions[1])[0]===404,'Crossunit activation lookup');
foreach([1=>1,2=>7,3=>10] as $unit=>$level){
 unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;$id=$students[$unit];$sid=$sessions[$unit];
 [$status,$page]=lh_request('/siswa/aktivasi_legacy.php?id='.$id,$sid);lh_assert($status===200,'Activation page');$form=spp_test_form_scope($page,'confirmed');preg_match('/name="csrf_token" value="([a-f0-9]{64})"/',$form,$csrf);
 $class=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=$level AND kode_rombel='A' AND is_active=1 LIMIT 1")->fetch_row()[0];$year=$koneksi->query("SELECT id,label FROM tahun_ajaran WHERE label='2026/2027'")->fetch_assoc();$rate=spp_current_effective_rate($koneksi,(string)$level,0,$year['label']);
 $post=['id'=>$id,'csrf_token'=>$csrf[1],'class_id'=>$class,'year_id'=>$year['id'],'spp'=>$rate['net'],'psb'=>0,'komite'=>$unit*10000,'du'=>0,'confirmed'=>'1'];
 lh_assert(lh_request('/siswa/aktivasi_legacy.php',$sid,array_replace($post,['csrf_token'=>'wrong']))[0]===403,'Activation CSRF');
 lh_assert(lh_request('/siswa/aktivasi_legacy.php',$sid,array_replace($post,['spp'=>999999]))[0]===409,'Unconfirmed/mismatched master rate');
 lh_assert(lh_request('/siswa/aktivasi_legacy.php',$sid,array_replace($post,['pangkal'=>0]))[0]===409,'Retired activation component');
 lh_assert(lh_request('/siswa/aktivasi_legacy.php',$sid,$post)[0]===302,'Admin/cashier explicit activation');
 lh_assert(lh_request('/siswa/aktivasi_legacy.php',$sid,$post)[0]===409,'Stale activation replay');
 lh_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$nis'")->fetch_row()[0]===1,'Placement duplicated');
 echo "PASS: unit $unit explicit HTTP activation by {$actors[$unit]['role']}, CSRF, master rate and replay\n";
}
unit_set_context($koneksi,0);foreach($counts as $table=>$count)lh_assert((int)$koneksi->query('SELECT COUNT(*) FROM '.$table)->fetch_row()[0]===$count,'HTTP activation created '.$table);
$super=$koneksi->query("SELECT id,role FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();$all=lh_session($super,0);lh_assert(lh_request('/siswa/aktivasi_legacy.php?id='.$students[1],$all)[0]===409,'All units activation permitted');
echo "PASS: anonymous/crossunit/All-unit guards and no automatic bills, payments or savings\n";
