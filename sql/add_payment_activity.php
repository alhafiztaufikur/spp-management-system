<?php
/** Audit by default. Existing financial rows are never changed by this migration. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/readiness_migration_guard.php';
require_once __DIR__.'/payment_activity_schema.php';
require_once __DIR__.'/../includes/payment_activity.php';
require_once __DIR__.'/../includes/transaction_authorization.php';

$apply = in_array('--apply',$argv,true);
echo 'Aktivitas pembayaran: '.(payment_activity_ready($koneksi)?'ready':'missing').' on '.DB_NAME.PHP_EOL;
if (!$apply) { echo "AUDIT ONLY: database tidak diubah.\n"; exit; }
readiness_migration_assert_apply_allowed($argv,DB_NAME);
payment_activity_schema_apply($koneksi);

$oldContext=(int)$koneksi->query('SELECT current_unit_id()')->fetch_row()[0];
try {
    foreach ([1,2,3] as $unit) {
        unit_set_context($koneksi,$unit);
        $koneksi->begin_transaction();
        try {
            $payments=$koneksi->query('SELECT * FROM bayar ORDER BY id')->fetch_all(MYSQLI_ASSOC);
            $requests=$koneksi->query('SELECT * FROM transaksi_otorisasi ORDER BY requested_at,id')->fetch_all(MYSQLI_ASSOC);
            $byId=[]; foreach($payments as $p) $byId[(int)$p['id']]=$p;
            $financial=[];
            foreach($koneksi->query("SELECT referensi_id,operator_id FROM keuangan_request WHERE unit_id=$unit AND aksi='pembayaran' AND referensi_id IS NOT NULL ORDER BY dibuat_pada,request_key") as $r)
                if(!isset($financial[(int)$r['referensi_id']])) $financial[(int)$r['referensi_id']]=$r['operator_id'];
            $creatorFromSnapshot=[];
            foreach($requests as $r) {
                $snap=json_decode($r['before_snapshot'],true); $p=$snap['payment']??[]; $id=(int)($p['id']??0);
                if($id && !isset($creatorFromSnapshot[$id]) && !empty($p['created_at'])
                    && empty($p['updated_at']))
                    $creatorFromSnapshot[$id]=['actor'=>$p['user_id']??'', 'time'=>$p['created_at']];
            }
            foreach($payments as $p) {
                $id=(int)$p['id'];
                // Rerunning after deployment must not add baselines to new transactions.
                if(payment_activity_for_payments($koneksi,[$id])) continue;
                $snapshot=transaction_authorization_snapshot($koneksi,$id)['data'];
                $identity=$financial[$id]??null;
                if($identity===null && (int)($p['payment_batch_count']??1)===12 && !empty($p['payment_batch_token'])) {
                    foreach($payments as $first) if($first['payment_batch_token']===$p['payment_batch_token'] && isset($financial[(int)$first['id']])) {
                        $identity=$financial[(int)$first['id']]; break;
                    }
                }
                $initial=$creatorFromSnapshot[$id]??null;
                $unedited=empty($p['updated_at']);
                if($identity===null) $identity=$initial['actor']??($unedited?($p['user_id']??null):null);
                if($identity!==null && !empty(payment_activity_actor($koneksi,$identity)['id'])) {
                    $creation=['payment'=>array_intersect_key($p,array_flip(['id','unit_id','NO_INDUK','NAMA','created_at']))];
                    payment_activity_record($koneksi,$id,'created',$identity,null,$creation,'legacy-created:'.$id,null,
                        'Identitas pembuat direkonstruksi dari bukti lama; rincian saat pembuatan tidak tersedia.',
                        $initial['time']??$p['created_at'],true);
                }
                payment_activity_record($koneksi,$id,'baseline',null,null,$snapshot,'legacy-baseline:'.$id,null,
                    'Kondisi transaksi saat jurnal diaktifkan. Aktivitas lama yang tidak tercatat tidak dapat dipastikan.',date('Y-m-d H:i:s'),true);
                if(!$unedited && !empty($p['updated_at'])) payment_activity_record($koneksi,$id,'edited',$p['user_id']??null,null,$snapshot,
                    'legacy-last-edit:'.$id,null,'Operator dan waktu perubahan terakhir yang tersimpan; rincian sebelum perubahan tidak tersedia.',$p['updated_at'],true);
            }
            foreach($requests as $r) {
                $snapshot=json_decode($r['before_snapshot'],true);
                $p=$snapshot['payment']??[]; $id=(int)($p['id']??0);
                if(!$id || (int)($p['unit_id']??0)!==$unit) continue;
                $rid=(int)$r['id']; $action=$r['action']==='hapus'?'request_delete':'request_edit';
                payment_activity_record($koneksi,$id,$action,$r['requested_by'],$snapshot,null,'request:'.$rid,$rid,
                    $r['request_reason'],$r['requested_at'],true);
                if($r['status']==='pending' || empty($r['decided_at'])) continue;
                payment_activity_record($koneksi,$id,$r['status'],$r['decided_by'],$snapshot,null,'decision:'.$rid,$rid,
                    $r['decision_note']??'',$r['decided_at'],true);
                if($r['status']==='approved' && $r['action']==='hapus' && !isset($byId[$id])) {
                    payment_activity_record($koneksi,$id,'deleted',$r['decided_by'],$snapshot,null,'applied:'.$rid,$rid,
                        'Arsip direkonstruksi dari pengajuan hapus yang telah disetujui.',$r['applied_at']?:$r['decided_at'],true);
                    $initial=$creatorFromSnapshot[$id]??null;
                    if($initial && !empty(payment_activity_actor($koneksi,$initial['actor'])['id'])) {
                        $creation=['payment'=>array_intersect_key($p,array_flip(['id','unit_id','NO_INDUK','NAMA','created_at']))];
                        payment_activity_record($koneksi,$id,'created',$initial['actor'],null,$creation,'legacy-created:'.$id,null,
                            'Identitas pembuat direkonstruksi dari snapshot lama.',$initial['time'],true);
                    }
                }
            }
            $koneksi->commit();
            echo 'Unit '.unit_label($unit).': '.$koneksi->query('SELECT COUNT(*) FROM pembayaran_aktivitas')->fetch_row()[0]." aktivitas.\n";
        } catch(Throwable $e) { $koneksi->rollback(); throw $e; }
    }
} finally { unit_set_context($koneksi,$oldContext); }
echo "OK: jurnal siap; siswa, tagihan, saldo dan transaksi pembayaran tidak diubah.\n";
