<?php
session_start();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
if(empty($_SESSION['admin_id'])){http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Silakan masuk kembali.']);exit;}
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/parent_letter_principals.php';
if(!hasRole(['admin'])){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Akses tidak diizinkan.']);exit;}
try {
    if(!plp_schema_ready($koneksi))throw new RuntimeException('Pengaturan Kepala Sekolah belum dipasang. Hubungi administrator.');
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
        $unit=unit_active_id();$states=[];foreach($unit===0?[1,2,3]:[$unit] as $u)$states[]=plp_state($koneksi,$u);
        echo json_encode(['ok'=>true,'states'=>$states],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: GET, POST');throw new InvalidArgumentException('Gunakan POST untuk menyimpan.');}
    if(empty($_SESSION['csrf_parent_principal'])||!hash_equals($_SESSION['csrf_parent_principal'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))){http_response_code(403);throw new RuntimeException('Sesi tidak valid. Muat ulang halaman.');}
    $raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new InvalidArgumentException('Permintaan terlalu besar.');
    $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($input)||!is_int($input['unit_id']??null))throw new InvalidArgumentException('Unit formulir tidak valid.');
    if(unit_active_id()===0||$input['unit_id']!==unit_active_id())throw new ParentLetterPrincipalConflict('Pilih kembali unit asal lalu muat ulang halaman sebelum menyimpan.');
    $state=plp_mutate($koneksi,$input['unit_id'],$input);
    echo json_encode(['ok'=>true,'state'=>$state],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(ParentLetterPrincipalConflict $e){http_response_code(409);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(InvalidArgumentException|JsonException $e){if(http_response_code()===200)http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){if(http_response_code()===200)http_response_code(503);error_log('Kepala Sekolah: '.$e->getMessage());echo json_encode(['ok'=>false,'message'=>'Pengaturan Kepala Sekolah belum dapat disimpan. Silakan coba kembali.'],JSON_UNESCAPED_UNICODE);}
