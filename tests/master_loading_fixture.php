<?php
/** Isolated account/session fixtures for the HTTP benchmark, never financial fixtures. */
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]===3306)throw new RuntimeException('Separate server required.');
$audit=realpath((string)getenv('SPP_PERF_AUDIT_ROOT'));if(!$audit||str_starts_with(strtolower(str_replace('\\','/',$audit)),'c:/laragon/www/'))throw new RuntimeException('Use a private audit directory.');
$sessionPath=$audit.'/sessions';if(!is_dir($sessionPath))mkdir($sessionPath);session_save_path($sessionPath);$out=[];
foreach(['super_admin','admin'] as $role){
    $actor=$koneksi->query("SELECT id FROM admin WHERE role='$role' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    if(!$actor&&$role==='admin'){
        $username='masterperf_'.bin2hex(random_bytes(6));$hash=password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT);
        $s=$koneksi->prepare("INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,'Performance fixture','admin',1,1)");$s->bind_param('ss',$username,$hash);$s->execute();$actor=['id'=>$koneksi->insert_id];$s->close();
    }
    if(!$actor)throw new RuntimeException('Active Super Admin required.');
    foreach([1,2,3,0] as $unit){session_id(bin2hex(random_bytes(20)));session_start();$_SESSION=['admin_id'=>(int)$actor['id'],'admin_role'=>$role,'active_unit_id'=>$unit];$out[$role.'-'.$unit]=session_id();session_write_close();}
}
file_put_contents($audit.'/sessions.json',json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'PASS eight isolated role/unit sessions',PHP_EOL;
