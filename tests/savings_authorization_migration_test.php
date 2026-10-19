<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);
$name='db_spp_audit_savings_install_'.bin2hex(random_bytes(5));$root=getenv('SPP_SAVINGS_QA');$koneksi->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
function sm_run(string $script,array $args=[]):string{$pipes=[];$p=proc_open(array_merge([PHP_BINARY,__DIR__.'/../'.$script],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code!==0)throw new RuntimeException('Migration CLI: '.$error.' '.$out);return $out;}
function sm_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try{
    putenv('SPP_DB_NAME='.$name);putenv('SPP_BOOTSTRAP_TARGET='.$name);putenv('SPP_BOOTSTRAP_ADMIN_USER=qa_install');putenv('SPP_BOOTSTRAP_ADMIN_PASSWORD='.bin2hex(random_bytes(18)));
    sm_run('sql/bootstrap_production.php',['--execute']);putenv('SPP_BOOTSTRAP_ADMIN_PASSWORD');sm_run('sql/migrate_units.php',['--apply']);
    $db=new mysqli(DB_HOST,DB_USER,DB_PASS,$name,(int)getenv('SPP_DB_PORT'));$db->set_charset('utf8mb4');require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/savings_authorization.php';sm_assert(savings_authorization_ready($db),'New installation missing schema');
    $before=[];foreach(['bayar_data','transaksi_m_data','transaksi_k_data','tabungan_data','admin'] as $table)$before[$table]=$db->query("CHECKSUM TABLE `$table` EXTENDED")->fetch_assoc()['Checksum'];
    $db->query('DROP VIEW tabungan_aktivitas');sm_assert(!savings_authorization_ready($db),'Partial readiness accepted');sm_run('sql/add_savings_authorization.php',['--apply']);sm_assert(savings_authorization_ready($db),'Partial application not recovered');
    sm_run('sql/add_savings_authorization.php',['--apply']);sm_assert(str_contains(sm_run('sql/add_savings_authorization.php'),'READY'),'CLI readiness');
    foreach($before as $table=>$checksum)sm_assert($db->query("CHECKSUM TABLE `$table` EXTENDED")->fetch_assoc()['Checksum']===$checksum,'Migration changed '.$table);
    echo "PASS fresh installation, additive migration, partial schema recovery, replay, readiness and financial invariants.\n";
}finally{putenv('SPP_DB_NAME='.DB_NAME);putenv('SPP_BOOTSTRAP_TARGET');putenv('SPP_BOOTSTRAP_ADMIN_USER');putenv('SPP_BOOTSTRAP_ADMIN_PASSWORD');if(isset($db))$db->close();$koneksi->query("DROP DATABASE `$name`");}
