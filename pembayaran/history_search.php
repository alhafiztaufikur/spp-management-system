<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if(empty($_SESSION['admin_id'])){http_response_code(401);echo json_encode(['ok'=>false,'code'=>'session_expired']);exit;}
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
requireRole(['admin','kasir','bendahara']);
require_once __DIR__.'/../includes/history_search.php';
try{
    foreach(['context','q','source','unit'] as $key)if(isset($_GET[$key])&&!is_scalar($_GET[$key]))throw new InvalidArgumentException('Parameter tidak valid.');
    $context=(string)($_GET['context']??'');$source=(string)($_GET['source']??'all');
    if(!in_array($context,['payments','du'],true)||!in_array($source,['all','system','legacy'],true))throw new InvalidArgumentException('Konteks tidak valid.');
    if(isset($_GET['unit'])&&(string)$_GET['unit']!==(string)unit_active_id()){http_response_code(409);echo json_encode(['ok'=>false,'code'=>'unit_changed']);exit;}
    $q=mb_substr(trim((string)($_GET['q']??'')),0,100);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    echo json_encode(['ok'=>true,'rows'=>mb_strlen($q)<2?[]:history_search_rows($koneksi,$context,$q,$source)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['ok'=>false,'code'=>'invalid_request']);}
catch(Throwable $e){error_log('History identity search: '.$e->getMessage());http_response_code(503);echo json_encode(['ok'=>false,'code'=>'search_unavailable']);}
