<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/special_classes.php';
require_once __DIR__.'/../includes/special_classes_schema.php';
$apply=in_array('--apply',$argv,true);$target=null;$backup=null;
foreach($argv as $arg){if(str_starts_with($arg,'--confirm-target='))$target=substr($arg,17);if(str_starts_with($arg,'--backup-file='))$backup=substr($arg,14);}
if(!$apply){echo json_encode(['database'=>DB_NAME,'ready'=>special_classes_ready($koneksi),'mode'=>'check'],JSON_PRETTY_PRINT).PHP_EOL;exit;}
if($target!==DB_NAME)throw new RuntimeException('Konfirmasikan nama database tujuan secara persis dengan --confirm-target.');
if(getenv('SPP_TEST_ISOLATED_INSTANCE')){require_once __DIR__.'/../tests/http_form_scope.php';spp_test_assert_database_target($koneksi);}
else{
    $path=$backup?realpath($backup):false;$root=strtolower(str_replace('\\','/',realpath(__DIR__.'/..'))).'/';
    if(!$path||!is_file($path)||filesize($path)<1000||str_starts_with(strtolower(str_replace('\\','/',$path)),$root))throw new RuntimeException('Backup SQL di luar folder aplikasi wajib tersedia sebelum pemasangan.');
    $stream=fopen($path,'rb');$header=fread($stream,1024);fclose($stream);if(!str_contains($header,'MySQL dump'))throw new RuntimeException('Backup SQL MySQL tidak dikenali.');
    if(DB_NAME==='db_spp'&&getenv('SPP_ALLOW_MAIN_MIGRATION')!=='1')throw new RuntimeException('Database utama tidak diubah tanpa otorisasi pemasangan tersendiri.');
}
special_classes_schema_apply($koneksi);
echo 'OK: Kelas Khusus siap; akun dan data keuangan tidak dimigrasikan.'.PHP_EOL;
