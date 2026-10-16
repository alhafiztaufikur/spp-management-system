<?php
session_start();require_once __DIR__.'/koneksi.php';require_once __DIR__.'/includes/legacy_import.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
function legacy_api_error(int $code,string $message):never{http_response_code($code);echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE);exit;}
if(!isset($_SESSION['admin_id'])||!operational_role())legacy_api_error(403,'Akun pengelola operasional diperlukan untuk mengakses importer.');
$write=($_SERVER['REQUEST_METHOD']??'GET')==='POST';$action=(string)($write?($_POST['action']??''):($_GET['action']??'health'));
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))legacy_api_error(405,'Metode tidak diizinkan.');
if($write){
    if(!in_array(unit_active_id(),[1,2,3],true))legacy_api_error(409,'Pilih SD, SMP, atau SMA di sidebar terlebih dahulu.');
    if(empty($_SESSION['csrf_legacy_import'])||!isset($_POST['csrf_token'])||!is_string($_POST['csrf_token'])||!hash_equals($_SESSION['csrf_legacy_import'],$_POST['csrf_token']))legacy_api_error(403,'Token CSRF tidak valid.');
}
try {
    if($action==='health'&&!$write){echo json_encode(legacy_readiness($koneksi),JSON_UNESCAPED_UNICODE);exit;}
    if($action==='upload'&&$write){
        legacy_actor($koneksi,(int)$_SESSION['admin_id'],unit_active_id());
        if(!legacy_readiness($koneksi)['ready'])legacy_api_error(503,'Worker atau prasyarat importer belum siap.');
        if((int)($_POST['unit']??0)!==unit_active_id()||($_POST['category']??'')!=='students')legacy_api_error(409,'Unit sumber harus sesuai sidebar. Hanya identitas siswa yang tersedia.');
        $f=$_FILES['backup']??null;if(count($_FILES)!==1||!$f||!is_string($f['name']??null)||$f['error']!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']))legacy_api_error(400,'Unggah tepat satu file .dat.');
        if($invalid=legacy_upload_error($f['name'],(int)$f['size']))legacy_api_error($invalid[0],$invalid[1]);
        echo json_encode(legacy_public(legacy_enqueue($f['tmp_name'],$f['name'],unit_active_id(),(int)$_SESSION['admin_id'])),JSON_UNESCAPED_UNICODE);exit;
    }
    $id=(string)($write?($_POST['id']??''):($_GET['id']??''));$job=legacy_job($id);
    if($write&&(int)$job['unit']!==unit_active_id())legacy_api_error(403,'Unit pekerjaan tidak sesuai pilihan aktif.');
    if(!$write&&$action==='status'){echo json_encode(['job'=>legacy_public($job),'preview'=>in_array($job['state'],['ready','imported'],true)?legacy_preview($id):null],JSON_UNESCAPED_UNICODE);exit;}
    if(!$write&&$action==='issues'){
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="legacy-issues-'.$id.'.csv"');
        $out=fopen('php://output','w');fputcsv($out,['Baris','NIS','Nama','Kelas sumber','Hasil','Alasan']);$s=legacy_queue()->prepare('SELECT ordinal,nis,name,class,result,reason FROM rows WHERE job=? ORDER BY ordinal');$s->execute([$id]);while($r=$s->fetch(PDO::FETCH_NUM))fputcsv($out,array_map(static fn($v)=>preg_match('/^[=+@-]/',(string)$v)?"'".$v:$v,$r));exit;
    }
    if($write&&$action==='confirm'){
        if(($job['state']??'')!=='ready')legacy_api_error(409,'Pekerjaan belum siap ditinjau atau telah diproses.');
        if(($_POST['confirmation']??'')!=='IMPOR LEGACY')legacy_api_error(400,'Konfirmasi impor tidak sesuai.');
        $s=legacy_queue()->prepare('UPDATE jobs SET state="apply_queued",updated=? WHERE id=? AND state="ready" AND cancel=0');$s->execute([time(),$id]);if($s->rowCount()!==1)legacy_api_error(409,'Pekerjaan berubah; muat ulang status.');
    }elseif($write&&$action==='cancel'){
        $s=legacy_queue()->prepare('UPDATE jobs SET cancel=1,state=CASE WHEN state IN ("queued","ready","apply_queued") THEN "cancelled" ELSE state END,updated=? WHERE id=? AND state NOT IN ("applying","imported","failed","cancelled")');$s->execute([time(),$id]);if(!$s->rowCount())legacy_api_error(409,'Penerapan sudah dimulai atau pekerjaan telah selesai.');
    }else legacy_api_error(405,'Tindakan tidak tersedia.');
    echo json_encode(legacy_public(legacy_job($id)),JSON_UNESCAPED_UNICODE);
}catch(InvalidArgumentException $e){legacy_api_error(404,$e->getMessage());}
catch(Throwable $e){error_log('Legacy importer request: '.$e->getMessage());legacy_api_error(500,'Permintaan importer gagal. Tidak ada hasil sukses yang dinyatakan.');}
