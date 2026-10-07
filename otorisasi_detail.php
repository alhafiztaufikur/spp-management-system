<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if(empty($_SESSION['admin_id'])){http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Silakan masuk kembali.']);exit;}
require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/authorization_presentation.php';
if(!hasRole(['admin','bendahara','kasir'])){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Akses tidak diizinkan.']);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'message'=>'Gunakan GET untuk membaca detail.']);exit;}
try {
    authorization_read_unit($koneksi,$_GET['unit_id']??null);
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)throw new InvalidArgumentException('ID tidak valid.');
    $view=$_GET['view']??'history';
    if(!in_array($view,['history','queue'],true))throw new InvalidArgumentException('Tampilan tidak valid.');
    $model=authorization_detail_model($koneksi,$id,$view==='queue');
    ob_start();authorization_render_detail($model);$html=ob_get_clean();
    echo json_encode(['ok'=>true,'html'=>$html],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
catch(RuntimeException $e){http_response_code(404);echo json_encode(['ok'=>false,'message'=>'Detail tidak ditemukan pada cakupan akses Anda.']);}
catch(Throwable $e){error_log('Detail otorisasi: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Detail belum dapat dimuat. Silakan coba kembali.']);}
