<?php
session_start();
require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/database_backup.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
function backup_api_fail(int $status,string $message): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE);exit;
}
if(empty($_SESSION['admin_id'])||($_SESSION['admin_role']??'')!=='super_admin')backup_api_fail(403,'Hanya Super Admin aktif yang dapat mengelola cadangan.');
requireRole(['super_admin']);
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');backup_api_fail(405,'Metode tidak diizinkan.');}
$action=($method==='POST'?$_POST:$_GET)['action']??'status';
if(!is_string($action))backup_api_fail(400,'Tindakan tidak valid.');
if(!in_array($action,['status','download','create'],true))backup_api_fail(400,'Tindakan tidak valid.');
if(($action==='create')!==($method==='POST'))backup_api_fail(405,'Metode tidak sesuai tindakan.');
$return=$_POST['return']??'';
if(!is_string($return)||!in_array($return,['','page'],true))backup_api_fail(400,'Tujuan tidak valid.');
if($method==='POST'&&(empty($_SESSION['csrf_backup'])||!is_string($_POST['csrf_token']??null)||!hash_equals($_SESSION['csrf_backup'],$_POST['csrf_token'])))backup_api_fail(403,'Token CSRF tidak valid. Muat ulang halaman.');
try{
    if($action==='status'){header('Content-Type: application/json; charset=utf-8');echo json_encode(db_backup_summary(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
    if($action==='create'){
        if(!is_string($_POST['request_key']??null))backup_api_fail(400,'Kunci permintaan tidak valid.');
        $job=db_backup_enqueue((int)$_SESSION['admin_id'],$_POST['request_key']);$_SESSION['backup_request_key']=bin2hex(random_bytes(16));
        if($return==='page'){$_SESSION['backup_flash']=['type'=>'success','message'=>'Cadangan masuk antrean. Progres akan diperbarui otomatis.'];header('Location: backup_restore.php',true,303);exit;}
        http_response_code(202);header('Content-Type: application/json; charset=utf-8');echo json_encode(['job'=>$job,'request_key'=>$_SESSION['backup_request_key']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    if(!is_string($_GET['id']??null))backup_api_fail(400,'ID cadangan tidak valid.');
    db_backup_actor((int)$_SESSION['admin_id']);$db=db_backup_store();$id=$_GET['id'];db_backup_find($db,$id);
    $archive=db_backup_download_archive($db,$id);session_write_close();
    header('Content-Type: application/sql; charset=utf-8');header('Content-Disposition: attachment; filename="'.$archive['name'].'"');header('Content-Length: '.$archive['bytes']);
    $stream=fopen($archive['file'],'rb');if(!$stream)throw new RuntimeException('Backup stream unavailable.');fpassthru($stream);fclose($stream);exit;
}catch(InvalidArgumentException $e){backup_api_fail(str_contains($e->getMessage(),'tidak ditemukan')?404:400,$e->getMessage());}
catch(Throwable $e){
    error_log('Database backup request failed ('.get_class($e).').');
    $message=$action==='download'?'Cadangan tidak dapat diunduh karena pemeriksaan berkas gagal.':'Layanan pencadangan belum tersedia. Tidak ada perubahan pada data operasional.';
    if($return==='page'){$_SESSION['backup_flash']=['type'=>'error','message'=>$message];header('Location: backup_restore.php',true,303);exit;}
    backup_api_fail(503,$message);
}
