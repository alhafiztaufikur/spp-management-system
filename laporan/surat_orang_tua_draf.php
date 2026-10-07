<?php
session_start();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
if(empty($_SESSION['admin_id'])){http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Silakan masuk kembali.']);exit;}
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/parent_letter_drafts.php';
// This endpoint saves only a session draft, including read-only combined reports.
// Database identity/unit bootstrap, role, CSRF, and draft ownership checks still apply.
if(!hasRole(['admin','bendahara','kasir'])){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Akses tidak diizinkan.']);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['ok'=>false,'message'=>'Gunakan POST.']);exit;}
try{
    if(empty($_SESSION['csrf_parent_letter'])||!hash_equals($_SESSION['csrf_parent_letter'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))){http_response_code(403);throw new RuntimeException('Sesi penyusunan tidak valid. Muat ulang halaman.');}
    $raw=file_get_contents('php://input',false,null,0,8000001);
    if(strlen($raw)>8000000)throw new InvalidArgumentException('Draf terlalu besar. Simpan pesan dalam beberapa tahap.');
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(($data['action']??'save')==='apply_message'){
        if(!is_string($data['draft']??null)||!is_string($data['source_key']??null)||!is_array($data['targets']??null)||!is_bool($data['overwrite']??false))throw new InvalidArgumentException('Pilihan penerima tidak valid.');
        $result=parent_letter_draft_apply($data['draft'],$data['source_key'],$data['message']??null,$data['targets'],$data['overwrite']??false);
        echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE);exit;
    }
    if(($data['action']??'save')!=='save'||!is_string($data['draft']??null)||!is_array($data['messages']??null))throw new InvalidArgumentException('Pesan surat tidak valid.');
    $draft=parent_letter_draft_update((string)($data['draft']??''),$data['messages']);
    echo json_encode(['ok'=>true,'saved'=>count($data['messages']),'messages'=>array_intersect_key($draft['messages'],$data['messages'])],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(http_response_code()===200)http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
