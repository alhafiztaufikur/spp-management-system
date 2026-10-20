<?php
/** No DDL and no migration replay. Preview by default; transaction per unit. */
if(PHP_SAPI!=='cli')throw new LogicException('Gunakan CLI.');
$options=getopt('',['database:','port:','unit:','actor:','apply','backup:','output:']);
$name=$options['database']??'';$port=(int)($options['port']??3306);
if($name!=='db_spp_test2'||!in_array($port,[3306,58246],true))throw new InvalidArgumentException('Rekonsiliasi ini hanya untuk db_spp_test2 pada server lokal yang ditentukan.');
putenv('SPP_DB_HOST=127.0.0.1');putenv('SPP_DB_NAME='.$name);putenv('SPP_DB_PORT='.$port);
$_SESSION=['admin_id'=>(int)($options['actor']??1),'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_billing.php';
$identity=$koneksi->query('SELECT @@port port,DATABASE() name')->fetch_assoc();if((int)$identity['port']!==$port||$identity['name']!==$name)throw new RuntimeException('Konfigurasi aplikasi berbeda dari target CLI; tidak ada perubahan diterapkan.');
$actor=lf_one($koneksi,"SELECT id FROM admin WHERE id=? AND role='super_admin' AND is_active=1",[$_SESSION['admin_id']]);if(!$actor)throw new DomainException('Super Admin aktif diperlukan.');
$apply=array_key_exists('apply',$options);$backup=$options['backup']??'';
if($apply&&$port===3306&&(!is_file($backup)||filesize($backup)<1024))throw new RuntimeException('Backup terverifikasi diperlukan sebelum menerapkan pada lab.');
$units=isset($options['unit'])?[(int)$options['unit']]:[1,2,3];foreach($units as $u)if(!in_array($u,[1,2,3],true))throw new InvalidArgumentException('Unit tidak valid.');
$report=['rule'=>LB_RULE_VERSION,'database'=>$name,'port'=>$port,'applied'=>$apply,'backup_sha256'=>$backup!==''?hash_file('sha256',$backup):null,'units'=>[]];
foreach($units as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$koneksi->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$koneksi->begin_transaction();try{
    if($apply){lf_rows($koneksi,'SELECT id FROM siswa ORDER BY id FOR UPDATE');lf_rows($koneksi,'SELECT id FROM legacy_full_batches ORDER BY id FOR UPDATE');}
    $report['units'][$unit]=lb_reconcile($koneksi,(int)$actor['id'],$apply);
    if($apply)$koneksi->commit();else $koneksi->rollback();
}catch(Throwable $e){$koneksi->rollback();throw $e;}}
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(isset($options['output']))file_put_contents($options['output'],$json.PHP_EOL);echo $json.PHP_EOL;
