<?php
session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
if(empty($_SESSION['admin_id'])){http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Silakan masuk kembali.']);exit;}
require_once __DIR__.'/koneksi.php';require_once __DIR__.'/includes/auth.php';require_once __DIR__.'/includes/authorization_history.php';
if(!hasRole(['admin','bendahara','kasir'])){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Akses tidak diizinkan.']);exit;}
$id=max(0,(int)($_GET['id']??0));
try{
    $events=authorization_history_can_read($koneksi,$id)?authorization_history_events($koneksi,$id):[];
    if(!$events){http_response_code(404);echo json_encode(['ok'=>false,'message'=>'Riwayat tidak ditemukan pada cakupan akses Anda.']);exit;}
    $rows=[];$identity=[];
    foreach($events as $e){
        $before=json_decode($e['before_snapshot']??'null',true);$after=json_decode($e['after_snapshot']??'null',true);
        $identity=$after['payment']??$before['payment']??$identity;
        $changes=payment_activity_changes($before,$after);
        if($e['action']==='request_delete'){
            $changes=[['label'=>'Status transaksi','before'=>'Aktif','after'=>'Diusulkan dihapus']];
        }elseif(is_array($e['_proposal']??null)){
            if($before)$changes=payment_activity_changes($before,authorization_proposed_snapshot($before,$e['_proposal']));
        }
        foreach($changes as &$change)if($change['label']==='Biaya Lain')$change['label']='Total Daftar Ulang dan Biaya Lain';unset($change);
        $rows[]=['action'=>$e['action'],'label'=>payment_activity_labels()[$e['action']]??$e['action'],
            'name'=>$e['actor_name']?:'Tidak tercatat','username'=>$e['actor_username'],'role'=>$e['actor_role'],
            'time'=>spp_date_label($e['occurred_at'],true),'authorization_id'=>$e['authorization_id'],
            'note'=>$e['note']??'','reconstructed'=>(bool)$e['reconstructed'],'changes'=>$changes,'proposed'=>str_starts_with($e['action'],'request_')];
    }
    echo json_encode(['ok'=>true,'reference'=>'TRX-'.str_pad((string)$id,6,'0',STR_PAD_LEFT),'student'=>$identity['NAMA']??$identity['NO_INDUK']??'',
        'unit'=>unit_label((int)$events[0]['unit_id']),'events'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){error_log('Detail otorisasi: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Riwayat belum dapat dimuat.']);}
