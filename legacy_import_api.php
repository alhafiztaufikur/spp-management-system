<?php
session_start();require_once __DIR__.'/koneksi.php';require_once __DIR__.'/includes/legacy_import.php';
require_once __DIR__.'/includes/legacy_finance.php';
require_once __DIR__.'/includes/legacy_import_http.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
function legacy_api_error(int $code,string $message):never{http_response_code($code);echo json_encode(['error'=>$message,'code'=>'request_failed','action'=>legacy_ui_problem('request_failed')],JSON_UNESCAPED_UNICODE);exit;}
if(!isset($_SESSION['admin_id'])||!operational_role())legacy_api_error(403,'Akun pengelola operasional diperlukan untuk mengakses importer.');
$write=($_SERVER['REQUEST_METHOD']??'GET')==='POST';$action=$write?($_POST['action']??''):($_GET['action']??'health');
if(!is_string($action))legacy_api_error(400,'Tindakan tidak valid.');
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))legacy_api_error(405,'Metode tidak diizinkan.');
if($write){
    if(!in_array(unit_active_id(),[1,2,3],true))legacy_api_error(409,'Pilih SD, SMP, atau SMA di sidebar terlebih dahulu.');
    if(empty($_SESSION['csrf_legacy_import'])||!isset($_POST['csrf_token'])||!is_string($_POST['csrf_token'])||!hash_equals($_SESSION['csrf_legacy_import'],$_POST['csrf_token']))legacy_api_error(403,'Token CSRF tidak valid.');
}
try {
    legacy_http_actor($koneksi,'students');
    if(str_starts_with($action,'full_')){echo lf_json(lful_http($koneksi,$action,$write?$_POST:$_GET,$write));exit;}
    if($action==='health'&&!$write){$health=legacy_readiness($koneksi);echo json_encode($health+['finance_ready'=>legacy_finance_ready($koneksi)&&$health['finance_worker'],'full_ready'=>legacy_full_ready($koneksi)&&$health['full_worker']],JSON_UNESCAPED_UNICODE);exit;}
    if($action==='upload'&&$write){
        echo json_encode(legacy_public(legacy_http_upload($koneksi,$_POST,$_FILES)),JSON_UNESCAPED_UNICODE);exit;
    }
    $id=$write?($_POST['id']??''):($_GET['id']??'');if(!is_string($id))legacy_api_error(400,'ID tidak valid.');$job=legacy_job($id);
    legacy_http_actor($koneksi,$job['category']??'students',$write);
    if(!$write&&($job['category']??'')==='full'&&unit_active_id()!==0&&(int)$job['unit']!==unit_active_id())legacy_api_error(403,'Unit pekerjaan tidak sesuai.');
    if($write&&(int)$job['unit']!==unit_active_id())legacy_api_error(403,'Unit pekerjaan tidak sesuai pilihan aktif.');
    if(!$write&&$action==='status'){echo json_encode(['job'=>legacy_public($job),'preview'=>in_array($job['state'],['ready','imported'],true)?legacy_preview($id):null],JSON_UNESCAPED_UNICODE);exit;}
    if(!$write&&$action==='issues'){
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="legacy-issues-'.$id.'.csv"');
        $out=fopen('php://output','w');fputcsv($out,['Baris','NIS','Nama','Kelas sumber','Hasil','Alasan']);$s=legacy_queue()->prepare('SELECT ordinal,nis,name,class,result,reason FROM rows WHERE job=? ORDER BY ordinal');$s->execute([$id]);while($r=$s->fetch(PDO::FETCH_NUM))fputcsv($out,array_map(static fn($v)=>preg_match('/^[=+@-]/',(string)$v)?"'".$v:$v,$r));exit;
    }
    if($write&&in_array($action,['confirm','cancel'],true)){echo json_encode(legacy_public(legacy_http_decide($koneksi,$action,$_POST)),JSON_UNESCAPED_UNICODE);exit;}
    legacy_api_error(405,'Tindakan tidak tersedia.');
}catch(DomainException $e){legacy_api_error(in_array($e->getCode(),[400,403,404,503],true)?$e->getCode():409,$e->getMessage());}
catch(InvalidArgumentException $e){legacy_api_error(404,$e->getMessage());}
catch(Throwable $e){error_log('Legacy importer request: '.$e->getMessage());legacy_api_error(500,'Permintaan importer gagal. Tidak ada hasil sukses yang dinyatakan.');}
