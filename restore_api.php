<?php
session_start();
require_once __DIR__.'/includes/database_restore.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
function restore_response(int $status,array $data): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
if(empty($_SESSION['admin_id'])||!operational_role())restore_response(403,['error'=>'Hanya Super Admin, Administrator, atau Kasir aktif yang dapat memulihkan database.']);
$method=$_SERVER['REQUEST_METHOD']??'GET';$action=($method==='POST'?$_POST:$_GET)['action']??'';
if($method==='POST'&&empty($_POST)&&(int)($_SERVER['CONTENT_LENGTH']??0)>0)restore_response(413,['error'=>'Unggahan melebihi batas PHP atau tidak lengkap. Periksa post_max_size dan upload_max_filesize pada server.']);
foreach(['id','token','confirmation','understood'] as $field)if(isset(($method==='POST'?$_POST:$_GET)[$field])&&!is_string(($method==='POST'?$_POST:$_GET)[$field]))restore_response(400,['error'=>'Parameter pemulihan tidak valid.']);
if(!is_string($action)||!in_array($action,['upload','status','apply','cancel','resume'],true))restore_response(400,['error'=>'Tindakan pemulihan tidak valid.']);
if(($action==='status')!==($method==='GET')||!in_array($method,['GET','POST'],true))restore_response(405,['error'=>'Metode pemulihan tidak sesuai.']);
if($method==='POST'&&(!is_string($_POST['csrf_token']??null)||empty($_SESSION['csrf_backup'])||!hash_equals($_SESSION['csrf_backup'],$_POST['csrf_token'])))restore_response(403,['error'=>'Token CSRF tidak valid. Muat ulang halaman.']);
try{
    $db=db_restore_store();$actor=(int)$_SESSION['admin_id'];
    if($action==='status'){
        // A job capability remains readable during maintenance even if restored accounts differ.
        $row=db_restore_authorize($db,(string)($_GET['id']??''),$actor,(string)($_SERVER['HTTP_X_RESTORE_TOKEN']??''));
        session_write_close();if($row['state']!=='recovery_required')db_restore_dispatch($row['id']);
        $latest=db_restore_find($db,$row['id']);$public=db_restore_public($latest);
        $public['finalized']=!db_restore_needs_finalization(db_backup_config(),$latest);
        restore_response(200,['job'=>$public]);
    }
    if($action==='resume'){
        $row=db_restore_authorize($db,(string)($_POST['id']??''),$actor,(string)($_POST['token']??''));
        if(!in_array($row['state'],['recovery_required','rolling_back'],true))throw new DatabaseRestoreConflict('Tidak ada pemulihan pengaman yang perlu dilanjutkan.');
        db_restore_update($db,$row['id'],'rolling_back','Melanjutkan pemulihan backup pengaman.');
        session_write_close();db_restore_dispatch($row['id']);restore_response(202,['job'=>db_restore_public(db_restore_find($db,$row['id']))]);
    }
    require_once __DIR__.'/koneksi.php';require_once __DIR__.'/includes/auth.php';requireRole(['super_admin','admin','kasir']);db_backup_actor($actor);
    if($action==='upload'){
        if(!is_array($_FILES['sql']??null))throw new InvalidArgumentException('Pilih satu file SQL untuk diperiksa.');
        $result=db_restore_upload($actor,$_FILES['sql']);$_SESSION['database_restore_job']=['id'=>$result['job']['id'],'token'=>$result['token']];session_write_close();
        try{db_restore_dispatch($result['job']['id']);}catch(Throwable $dispatch){$result['warning']='Pemicu belum merespons. Progres akan mencoba kembali.';}
        restore_response(202,$result);
    }
    $row=db_restore_authorize($db,(string)($_POST['id']??''),$actor,(string)($_POST['token']??''));
    if($action==='cancel'){
        if(!in_array($row['state'],['ready','failed','cancelled'],true))throw new DatabaseRestoreConflict('Proses yang sedang berjalan tidak dapat dibatalkan.');
        $cancel=$db->prepare("UPDATE restores SET state='cancelled',message='Pemulihan dibatalkan. Database tidak diubah.',updated_at=?,finished_at=? WHERE id=? AND state IN ('ready','failed','cancelled')");
        $now=db_backup_now()->format(DATE_ATOM);$cancel->execute([$now,$now,$row['id']]);
        if($cancel->rowCount()!==1)throw new DatabaseRestoreConflict('Proses sudah berjalan dan tidak dapat dibatalkan.');
        restore_response(200,['job'=>db_restore_public(db_restore_find($db,$row['id']))]);
    }
    if(!is_string($_POST['confirmation']??null))throw new InvalidArgumentException('Konfirmasi tidak valid.');
    db_restore_confirm($db,$row,$actor,$_POST['confirmation'],($_POST['understood']??'')==='1');
    session_write_close();
    // The next stage must launch immediately, even if validation finished within ten seconds.
    $receipt=db_backup_job_dir($row['id']).'/restore-dispatch.json';if(is_file($receipt)){db_backup_private_path($receipt);unlink($receipt);}
    db_restore_dispatch($row['id']);restore_response(202,['job'=>db_restore_public(db_restore_find($db,$row['id']))]);
}catch(InvalidArgumentException $e){restore_response(400,['error'=>$e->getMessage()]);}
catch(DatabaseRestoreConflict $e){restore_response(409,['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Database restore request failed ('.get_class($e).').');restore_response(503,['error'=>'Pemulihan belum dapat diproses. Periksa layanan dan riwayat; jangan mengunggah ulang selama proses berlangsung.']);}
