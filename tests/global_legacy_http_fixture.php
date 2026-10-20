<?php
/** Private sessions/sample identities only on the owned QA database. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58470||!str_contains($koneksi->query('SELECT @@datadir')->fetch_row()[0],'global_audit_20261021'))throw new RuntimeException('Owned report clone required.');
require_once __DIR__.'/../includes/auth.php';
$qa=(string)getenv('SPP_GLOBAL_QA_DIR');if(!$qa||!is_dir($qa)||str_starts_with(strtolower(realpath($qa)),strtolower(realpath(__DIR__.'/..'))))throw new RuntimeException('Private directory outside the web root required.');
$super=$koneksi->query("SELECT id,nama,unit_id,role FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
$username='qa-global-'.bin2hex(random_bytes(5));$password=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);$name='QA Global Administrator';$s=$koneksi->prepare("INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,?,'admin',1,1)");$s->bind_param('sss',$username,$password,$name);$s->execute();$admin=['id'=>(int)$koneksi->insert_id,'nama'=>$name,'role'=>'admin','unit_id'=>1];$s->close();
$fixture=['base'=>'http://127.0.0.1:58471','database'=>DB_NAME,'port'=>58470,'sessions'=>[],'admin_sessions'=>[],'samples'=>[]];
session_save_path($qa.'/sessions');$paths=database_access_paths(DB_NAME,58470);$generation=is_file($paths['generation'])?trim(file_get_contents($paths['generation'])):'0';
foreach([0,1,2,3] as $unit){
    foreach(['sessions'=>$super,'admin_sessions'=>$admin] as $key=>$actor){session_id('globalqa'.bin2hex(random_bytes(16)));session_start();$_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>$actor['role'],'admin_unit_id'=>$actor['unit_id'],'active_unit_id'=>$unit,'database_generation'=>$generation];$fixture[$key][$unit]=session_id();session_write_close();}
    if(!$unit)continue;unit_set_context($koneksi,$unit);
    $sample=$koneksi->query("SELECT s.id,s.NO_INDUK FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE p.cancelled=0 AND p.source_period='11 2026' AND p.items LIKE '%spp%' AND s.is_active=1 LIMIT 1")->fetch_assoc();
    if(!$sample)$sample=$koneksi->query("SELECT id,NO_INDUK FROM siswa WHERE is_active=1 AND legacy_pending=0 LIMIT 1")->fetch_assoc();$fixture['samples'][$unit]=['nis'=>$sample['NO_INDUK'],'student_id'=>(int)$sample['id']];
}
file_put_contents($qa.'/fixture.json',json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo "Private report sessions ready.\n";
