<?php
if(PHP_SAPI!=='cli')exit(1);
$app=getenv('SPP_SPECIAL_APP_ROOT')?:dirname(__DIR__);
require $app.'/koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require $app.'/includes/legacy_class_links.php';
function lc_assert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function lc_rows_hash(mysqli $db,array $tables):array{$out=[];foreach($tables as $t){$hashes=[];foreach($db->query("SELECT * FROM `$t`")->fetch_all(MYSQLI_ASSOC) as $r)$hashes[]=hash('sha256',json_encode($r));sort($hashes);$out[$t]=hash('sha256',implode('',$hashes));}return $out;}
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);
$batch=$koneksi->query("SELECT id FROM legacy_full_batches WHERE state='completed' LIMIT 1")->fetch_row()[0];
$financial=['legacy_op_payments','legacy_op_journals','legacy_op_accounts','legacy_op_tariffs','legacy_op_obligations','bayar','tabungan','transaksi_m','transaksi_k','siswa_tahun_ajaran','legacy_full_batches'];$money=lc_rows_hash($koneksi,$financial);
$beforeStudents=lc_rows_hash($koneksi,['siswa']);
$dry=legacy_class_repair($koneksi,$actor);lc_assert(count($dry)>=10,'Named account groups missing from repair preview');lc_assert($beforeStudents===lc_rows_hash($koneksi,['siswa']),'Dry run changed students');
// A class already created by a user is reused, including its inactive state.
$btq=special_class_save($koneksi,0,'btq',0);
$oldClass=$koneksi->query("SELECT id FROM master_kelas WHERE kode_rombel='Alumni2024'")->fetch_row()[0];$newClass=$koneksi->query("SELECT id FROM master_kelas WHERE kode_rombel='Alumni2025'")->fetch_row()[0];
$tag='LC'.bin2hex(random_bytes(4));$nis=(string)random_int(9000000000,9999999999);
special_class_query($koneksi,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active,SPP_PERBULAN,POMG) VALUES(?,?,'0',?,0,0,0)",[$nis,$tag,$oldClass]);$student=(int)$koneksi->insert_id;
special_class_query($koneksi,"INSERT INTO legacy_op_groups(unit_id,code,label,kind,aliases) VALUES(1,'B2025','B2025','alumni',?)",[json_encode(['B2025'])]);$g=(int)$koneksi->insert_id;
special_class_query($koneksi,"INSERT INTO legacy_op_owners(unit_id,batch_id,source_identity,name,kind,group_id,student_id,source_class,state) VALUES(1,?,?,?,'alumni',?,?,'B2025','ready')",[$batch,$nis,$tag,$g,$student]);
$tables=['master_kelas','legacy_op_groups','legacy_op_owners','siswa','legacy_op_audit'];$before=lc_rows_hash($koneksi,$tables);
$koneksi->begin_transaction();legacy_class_repair($koneksi,$actor,true);$koneksi->rollback();lc_assert($before===lc_rows_hash($koneksi,$tables),'Rollback changed class/owner/audit rows');
$koneksi->begin_transaction();$changes=legacy_class_repair($koneksi,$actor,true);$koneksi->commit();lc_assert(legacy_class_repair($koneksi,$actor)===[],'Retry still proposes duplicate classes/links');
$btqGroup=$koneksi->query("SELECT * FROM legacy_op_groups WHERE code='BTQ'")->fetch_assoc();lc_assert((int)$btqGroup['master_kelas_id']===$btq&&!class_find($koneksi,$btq)['is_active'],'Existing custom class was duplicated or reactivated');
lc_assert((int)special_class_query($koneksi,'SELECT master_kelas_id FROM siswa WHERE id=?',[$student])->fetch_row()[0]===(int)$newClass,'Incorrect alumni placement not repaired');
$wrong=(int)$koneksi->query("SELECT COUNT(*) FROM legacy_op_owners o JOIN legacy_op_groups g ON g.id=o.group_id JOIN siswa s ON s.id=o.student_id WHERE g.kind='alumni' AND NOT(s.master_kelas_id<=>g.master_kelas_id)")->fetch_row()[0];lc_assert($wrong===0,'Alumni memberships diverged');
lc_assert($koneksi->query("SELECT master_kelas_id FROM legacy_op_groups WHERE code='ARSIP TANPA SISWA'")->fetch_row()[0]===null,'Synthetic archive became class');
$members=special_class_members_sql($koneksi);$guru=$koneksi->query("SELECT master_kelas_id FROM legacy_op_groups WHERE code='GK'")->fetch_row()[0];$count=(int)special_class_query($koneksi,'SELECT COUNT(*) FROM ('.$members.') m WHERE class_id=?',[$guru])->fetch_row()[0];lc_assert($count===125,'Non-student membership count incorrect');
$out=$koneksi->query("SELECT master_kelas_id FROM legacy_op_groups WHERE code='OUT'")->fetch_row()[0];lc_assert(class_kind(class_find($koneksi,(int)$out))==='out','OUT became Alumni/custom');
lc_assert($money===lc_rows_hash($koneksi,$financial),'Repair changed financial/history/FIX/batch contents');
try{special_class_delete($koneksi,$btq,special_class_version(class_find($koneksi,$btq)));throw new RuntimeException('Legacy-used class deleted');}catch(DomainException $e){}
lc_assert(legacy_class_label($koneksi,$koneksi->query("SELECT * FROM legacy_op_groups WHERE code='A2025'")->fetch_assoc())==='Alumni2025','Canonical Alumni label lost');
// Real HTTP, no-JS navigation and class scope.
$base=getenv('SPP_TEST_BASE_URL');spp_test_assert_http_clone($base,DB_NAME);$sid='unified'.bin2hex(random_bytes(12));session_id($sid);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];session_write_close();
function lc_http(string $route):array{global $base,$sid;$c=curl_init($base.'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sid,CURLOPT_TIMEOUT=>60]);$body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$code,$body];}
[$code,$html]=lc_http('master_kelas.php');lc_assert($code===200&&!preg_match('/Fatal error|Warning:|Notice:/',$html),'Master page failed');lc_assert(str_contains($html,'Telusuri Kelompok Legacy')&&!str_contains($html,'name="lg_q"'),'Legacy directory eagerly loaded');
[$code,$html]=lc_http('master_kelas.php?special_kind=alumni');lc_assert($code===200&&str_contains($html,'Alumni2025'),'Alumni filter lost canonical class');
[$code,$html]=lc_http('master_kelas.php?special_members='.$guru);lc_assert($code===200&&str_contains($html,'125 identitas')&&str_contains($html,'Pemilik rekening non-siswa'),'Non-student member view failed');
[$code,$html]=lc_http('legacy_accounts.php?class_id='.$guru);lc_assert($code===200&&str_contains($html,'Guru &amp; Karyawan'),'Class directory filter failed');
[$code,$html]=lc_http('legacy_accounts.php?class_id='.$newClass);lc_assert($code===200&&str_contains($html,'Alumni2025'),'Combined source-group view failed');
$foreignOwner=$koneksi->query("SELECT id FROM legacy_op_owners WHERE group_id=(SELECT id FROM legacy_op_groups WHERE code='BTQ') LIMIT 1")->fetch_row()[0];lc_assert(lc_http('legacy_accounts.php?class_id='.$guru.'&owner='.$foreignOwner)[0]===404,'Owner escaped class filter');
[$code,$html]=lc_http('master_kelas.php?lg_open=1&lg_kind=alumni');lc_assert($code===200&&str_contains($html,'name="lg_q"')&&str_contains($html,'Alumni2025'),'Legacy directory explicit opening failed');
$q=getenv('SPP_SPECIAL_QA_DIR');file_put_contents($q.'/classes-fixture.json',json_encode(['base'=>$base,'session'=>$sid,'guru'=>(int)$guru,'alumni'=>(int)$newClass,'btq'=>$btq,'changes'=>$changes]));
echo "PASS: dry run, canonical Alumni, existing custom reuse, OUT/PSB separation, 125 non-student owners, atomic rollback, retry, archive exclusion, unchanged finance/FIX/history, HTTP lazy directory and class/owner scope.\n";
