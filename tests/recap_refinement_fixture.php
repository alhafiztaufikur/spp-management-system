<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
$qa=(string)getenv('SPP_RECAP_QA');$resolved=realpath($qa);if(!$resolved||str_starts_with(strtolower(str_replace('\\','/',$resolved)),strtolower(str_replace('\\','/',realpath(__DIR__.'/..')))))throw new RuntimeException('Private QA directory required.');
$fixture=['database'=>DB_NAME,'sessions'=>[],'samples'=>[]];
$super=$koneksi->query("SELECT id,nama,role,unit_id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();if(!$super)throw new RuntimeException('Existing audit actor required.');
session_save_path($qa.'/sessions');$paths=database_access_paths(DB_NAME,3306);$generation=is_file($paths['generation'])?trim(file_get_contents($paths['generation'])):'0';
foreach([0,1,2,3] as $unit){
    $actors=['super_admin'=>$super];
    if($unit){$name='QA Recap Administrator';$username='qa-recap-'.bin2hex(random_bytes(6));$password=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);$stmt=$koneksi->prepare("INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,?,'admin',?,1)");$stmt->bind_param('sssi',$username,$password,$name,$unit);$stmt->execute();$actors['admin']=['id'=>$koneksi->insert_id,'nama'=>$name,'role'=>'admin','unit_id'=>$unit];$stmt->close();}
    foreach($actors as $role=>$actor){session_id('recapqa'.bin2hex(random_bytes(16)));session_start();$_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>$role,'admin_unit_id'=>$actor['unit_id'],'active_unit_id'=>$unit,'database_generation'=>$generation];$fixture['sessions'][$role][$unit]=session_id();$fixture['actors'][$role][$unit]=(int)$actor['id'];session_write_close();}
    if(!$unit)continue;unit_set_context($koneksi,$unit);
    $fixture['samples'][$unit]=$koneksi->query("SELECT j.id,j.owner_id,o.student_id,j.version FROM legacy_op_journals j JOIN legacy_op_owners o ON o.id=j.owner_id AND o.unit_id=j.unit_id WHERE j.cancelled=0 AND j.origin='legacy' AND j.incoming>0 AND o.state='ready' ORDER BY j.id LIMIT 1")->fetch_assoc();
}
file_put_contents($qa.'/fixture.json',json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo "Private recap sessions ready.\n";
