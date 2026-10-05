<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['admin_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Sesi habis.']); exit; }
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/payment_activity.php';
if (!hasRole(['admin','kasir'])) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Akses tidak diizinkan.']); exit; }
$id=max(0,(int)($_GET['id']??0));
try {
    $events=payment_activity_for_payments($koneksi,[$id])[$id]??[];
    if (!$events) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Riwayat transaksi tidak ditemukan pada unit ini.']); exit; }
    $labels=payment_activity_labels(); $rows=[]; $identity=[];
    foreach($events as $event) {
        $before=json_decode($event['before_snapshot']??'null',true);
        $after=json_decode($event['after_snapshot']??'null',true);
        $identity=$after['payment']??$before['payment']??$identity;
        $rows[]=['action'=>$event['action'],'label'=>$labels[$event['action']]??$event['action'],
            'name'=>$event['actor_name']?:'Tidak tercatat','username'=>$event['actor_username'],'role'=>$event['actor_role'],
            'time'=>spp_date_label($event['occurred_at'],true),'authorization_id'=>$event['authorization_id'],
            'note'=>$event['note']??'','reconstructed'=>(bool)$event['reconstructed'],
            'changes'=>payment_activity_changes($before,$after)];
    }
    echo json_encode(['ok'=>true,'reference'=>'TRX-'.str_pad((string)$id,6,'0',STR_PAD_LEFT),
        'student'=>$identity['NAMA']??$identity['NO_INDUK']??'', 'unit'=>unit_label((int)$events[0]['unit_id']), 'events'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    error_log('Riwayat aktivitas pembayaran: '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'message'=>'Riwayat aktivitas belum dapat dimuat. Silakan coba lagi.']);
}
