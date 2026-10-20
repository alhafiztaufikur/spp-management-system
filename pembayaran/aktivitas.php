<?php
session_start();
header('Content-Type: '.(($_GET['format']??'')==='html'?'text/html':'application/json').'; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['admin_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Sesi habis.']); exit; }
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/authorization_presentation.php';
if (!hasRole(['admin','kasir','bendahara'])) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Akses tidak diizinkan.']); exit; }
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'message'=>'Gunakan GET.']);exit;}
try {
    $rawId=$_GET['id']??'';if(!is_scalar($rawId)||!preg_match('/^-?[1-9][0-9]*$/D',(string)$rawId)) throw new InvalidArgumentException('Nomor transaksi tidak valid.');
    $id=(int)$rawId;
    authorization_read_unit($koneksi,$_GET['unit_id']??null);
    if($id<-1000000000){require_once __DIR__.'/../includes/legacy_transactions.php';$events=lt_events($koneksi,-$id-1000000000);}else $events=authorization_history_events($koneksi,$id);
    if (!$events) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Riwayat transaksi tidak ditemukan pada unit ini.']); exit; }
    $labels=payment_activity_labels(); $rows=[]; $identity=[];
    foreach($events as $event) {
        $before=json_decode($event['before_snapshot']??'null',true);
        $after=json_decode($event['after_snapshot']??'null',true);
        $identity=$after['payment']??$before['payment']??$identity;
        $rows[]=authorization_event_model($event);
    }
    if(($_GET['format']??'')==='html'){
        require_once __DIR__.'/../includes/payment_return.php';$back=payment_return_url($_GET['return_context']??'',$id);$e='authorization_escape';
        echo '<!doctype html><html lang="id" data-theme="light"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Riwayat Aktivitas</title><link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/payment_history.css"><main class="page-content"><section class="main-card"><h1>Riwayat Aktivitas</h1><p>'.$e($identity['NAMA']??'Tidak tercatat').'</p><ol>';
        foreach($rows as $r){echo '<li><h3>'.$e($r['label']).'</h3><p>'.$e($r['name']).' · '.$e($r['time']).'</p><p>'.$e($r['note']).'</p>';foreach($r['changes'] as $c)echo '<p>'.$e($c['label']).': '.$e($c['before']).' → '.$e($c['after']).'</p>';echo '</li>';}
        echo '</ol><a class="btn btn-ghost" href="'.$e($back).'">Kembali ke Riwayat</a></section></main></html>';exit;
    }
    echo json_encode(['ok'=>true,'reference'=>'TRX-'.str_pad((string)$id,6,'0',STR_PAD_LEFT),
        'student'=>$identity['NAMA']??$identity['NO_INDUK']??'', 'unit'=>unit_label((int)$events[0]['unit_id']), 'events'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    error_log('Riwayat aktivitas pembayaran: '.$e->getMessage());
    http_response_code($e instanceof InvalidArgumentException?400:($e instanceof RuntimeException&&!($e instanceof mysqli_sql_exception)?404:500)); echo json_encode(['ok'=>false,'message'=>'Riwayat aktivitas belum dapat dimuat. Silakan coba lagi.']);
}
