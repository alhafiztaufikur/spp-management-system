<?php
require_once __DIR__.'/legacy_full_http.php';
require_once __DIR__.'/legacy_ui.php';
/** Full uploads use the same automatic pipeline as Codex; archive is an explicit legacy opt-out. */
function legacy_http_operational_upload(string $category,array $input): bool {return $category==='full'&&($input['mode']??'operational')!=='archive';}
function legacy_http_actor(mysqli $db,string $category,bool $write=false): void {
    if($category!=='students'){lf_actor($db,(int)($_SESSION['admin_id']??0),unit_active_id(),$write);return;}
    if(!operational_role()||!lf_one($db,"SELECT id FROM admin WHERE id=? AND is_active=1 AND role IN ('super_admin','admin')",[(int)($_SESSION['admin_id']??0)]))throw new DomainException('Akun pengelola operasional aktif diperlukan.',403);
    if($write&&!in_array(unit_active_id(),[1,2,3],true))throw new DomainException('Pilih satu unit operasional.',409);
}
/** Shared by the native form and the compatible JSON endpoint. */
function legacy_http_upload(mysqli $db,array $input,array $files): array {
    $actor=(int)($_SESSION['admin_id']??0);$unit=unit_active_id();legacy_actor($db,$actor,$unit);
    if(!is_string($input['csrf_token']??null)||!hash_equals($_SESSION['csrf_legacy_import']??'',$input['csrf_token']))throw new DomainException('Token CSRF tidak valid.',403);
    $category=$input['category']??'students';
    if(!is_string($category)||!in_array($category,['students','finance','full'],true)||!is_string($input['unit']??null)||!preg_match('/^[123]$/D',$input['unit'])||(int)$input['unit']!==$unit)throw new DomainException('Pilih unit yang sama dengan unit operasional.',409);
    legacy_http_actor($db,$category,true);
    $file=$files['backup']??null;
    if(count($files)!==1||!is_array($file)||!is_string($file['name']??null)||($file['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))throw new DomainException('Pilih tepat satu file backup SQL Server.',400);
    if($invalid=legacy_upload_error($file['name'],(int)$file['size']))throw new DomainException($invalid[1],$invalid[0]);
    $key=$input['request_key']??null;
    if($key!==null&&(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key)))throw new DomainException('Formulir unggah tidak valid. Muat ulang halaman.',400);
    $fingerprint=hash('sha256',$unit.'|'.$category.'|'.hash_file('sha256',$file['tmp_name']));
    if($key!==null&&isset($_SESSION['legacy_upload_requests'][$key])){
        $previous=$_SESSION['legacy_upload_requests'][$key];
        if(!hash_equals($previous['fingerprint'],$fingerprint))throw new DomainException('File pada formulir ini berubah. Buka Unggah untuk memilih file baru.',409);
        return legacy_job($previous['job']);
    }
    $health=legacy_readiness($db);if(!$health['ready'])throw new DomainException('Layanan pemeriksaan belum siap. Periksa ringkasan kesiapan.',503);
    $operational=legacy_http_operational_upload($category,$input);if($operational){lop_require($db);if(!$health['operational_worker'])throw new DomainException('Mulai ulang worker untuk memakai migrasi operasional terbaru.',503);}
    if($category==='finance'&&(!legacy_finance_ready($db)||!$health['finance_worker']))throw new DomainException('Histori Keuangan belum siap. Pasang skema dan mulai ulang worker.',503);
    if($category==='full'){lful_require($db);lful_empty($db,$unit);if(!$health['full_worker'])throw new DomainException('Mulai ulang worker sebelum migrasi penuh.',503);}
    $job=legacy_enqueue($file['tmp_name'],$file['name'],$unit,$actor,$category);
    if($operational)file_put_contents(legacy_root().'/'.$job['id'].'/operational-request.json','{"mode":"operational"}',LOCK_EX);
    if($key!==null){if(count($_SESSION['legacy_upload_requests']??[])>=20)array_shift($_SESSION['legacy_upload_requests']);$_SESSION['legacy_upload_requests'][$key]=['fingerprint'=>$fingerprint,'job'=>$job['id']];}
    return $job;
}
function legacy_http_decide(mysqli $db,string $action,array $input): array {

    if(!is_string($input['csrf_token']??null)||!hash_equals($_SESSION['csrf_legacy_import']??'',$input['csrf_token']))throw new DomainException('Token CSRF tidak valid.',403);
    if(!is_string($input['id']??null))throw new DomainException('Pekerjaan tidak valid.',400);$job=legacy_job($input['id']);
    legacy_http_actor($db,$job['category']??'students',true);
    if((int)$job['unit']!==unit_active_id())throw new DomainException('Pilih unit pekerjaan di sidebar.',403);
    if($action==='confirm'){
        if(($job['category']??'students')==='full')throw new DomainException('Buka alur Migrasi Penuh untuk konfirmasi.',409);
        if(($input['confirmation']??'')!=='IMPOR LEGACY')throw new DomainException('Periksa hasil lalu centang persetujuan impor.',400);
        $stmt=legacy_queue()->prepare('UPDATE jobs SET state="apply_queued",updated=? WHERE id=? AND state="ready" AND cancel=0');$stmt->execute([time(),$job['id']]);
        if($stmt->rowCount()!==1)throw new DomainException('Pekerjaan berubah. Muat ulang status sebelum melanjutkan.',409);
    }elseif($action==='cancel'){
        $stmt=legacy_queue()->prepare('UPDATE jobs SET cancel=1,state=CASE WHEN state IN ("queued","ready","apply_queued","simulate_queued") THEN "cancelled" ELSE state END,updated=? WHERE id=? AND state NOT IN ("applying","imported","failed","cancelled")');$stmt->execute([time(),$job['id']]);
        if(!$stmt->rowCount())throw new DomainException('Penerapan sudah dimulai atau pekerjaan telah selesai.',409);
    }else throw new DomainException('Tindakan tidak tersedia.',400);
    return legacy_job($job['id']);
}
function legacy_http_rows(string $id,array $input): array {
    foreach(['q','filter','page'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new DomainException('Filter tidak valid.',400);
    $q=trim($input['q']??'');$filter=$input['filter']??'';$page=$input['page']??'1';
    if(mb_strlen($q)>100||!preg_match('/^[0-9]{1,6}$/D',$page)||(int)$page<1||!in_array($filter,['','accepted','held','non_student','already_imported'],true))throw new DomainException('Filter tidak valid.',400);
    $where='job=?';$args=[$id];if($q!==''){$where.=' AND (nis LIKE ? OR name LIKE ? OR class LIKE ?)';array_push($args,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}if($filter!==''){$where.=' AND result=?';$args[]=$filter;}
    $stmt=legacy_queue()->prepare('SELECT COUNT(*) FROM rows WHERE '.$where);$stmt->execute($args);$count=(int)$stmt->fetchColumn();
    $stmt=legacy_queue()->prepare('SELECT ordinal,nis,name,class,result,reason FROM rows WHERE '.$where.' ORDER BY ordinal LIMIT 30 OFFSET '.(((int)$page-1)*30));$stmt->execute($args);
    return ['rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'total'=>$count,'page'=>(int)$page];
}
