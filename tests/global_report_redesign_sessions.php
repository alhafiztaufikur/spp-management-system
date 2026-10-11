<?php
/** Prepare private QA sessions without changing account records or passwords. */
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/http_form_scope.php';
spp_test_assert_http_clone((string)getenv('SPP_HTTP_BASE'),DB_NAME);
$dir=(string)getenv('SPP_QA_DIR');
if(!is_dir($dir)||!is_dir($dir.'/sessions')||str_replace('\\','/',session_save_path())!==str_replace('\\','/',$dir.'/sessions'))throw new RuntimeException('Use an existing private QA directory and its sessions subdirectory.');
unit_set_context($koneksi,0);$sessions=[];
foreach(['super_admin','admin','kasir','bendahara'] as $role){
    $statement=$koneksi->prepare("SELECT id,nama,username FROM admin WHERE role=? AND is_active=1 AND (?='super_admin' OR unit_id=1) ORDER BY id LIMIT 1");
    $statement->bind_param('ss',$role,$role);$statement->execute();$actor=$statement->get_result()->fetch_assoc();$statement->close();
    if(!$actor)throw new RuntimeException('Demo account role missing: '.$role);
    $id='redesign'.bin2hex(random_bytes(12));session_id($id);session_start();
    $_SESSION=['admin_id'=>(int)$actor['id'],'admin_role'=>$role,'admin_nama'=>$actor['nama'],'admin_username'=>$actor['username'],'active_unit_id'=>1];session_write_close();$sessions[$role]=$id;
}
file_put_contents($dir.'/sessions.json',json_encode($sessions,JSON_THROW_ON_ERROR));
echo "PASS: four private audit-clone QA sessions prepared; database records unchanged\n";
