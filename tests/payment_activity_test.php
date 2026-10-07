<?php
if (PHP_SAPI!=='cli' || getenv('SPP_TEST_ALLOW_MUTATION')!=='1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))) throw new RuntimeException('Clone uji wajib.');
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/transaction_authorization.php';
require_once __DIR__.'/../includes/pagination.php';
require_once __DIR__.'/../includes/payment_archive.php';
function activity_assert(bool $value,string $message):void { if(!$value)throw new RuntimeException($message); }
$journalBefore=(int)$koneksi->query('SELECT COUNT(*) FROM pembayaran_aktivitas_data')->fetch_row()[0];
foreach([1,2,3] as $unit) {
    unit_set_context($koneksi,$unit);
    $p=$koneksi->query('SELECT id FROM bayar ORDER BY id LIMIT 1')->fetch_assoc();
    $before=transaction_authorization_snapshot($koneksi,(int)$p['id'])['data'];
    $actor=$koneksi->query("SELECT id FROM admin WHERE unit_id=$unit AND role='kasir' LIMIT 1")->fetch_assoc()['id'];
    $koneksi->begin_transaction();
    try {
        $payment=$before['payment'];
        $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,total_jumlah,payment_link_version)
            VALUES(?,?,NOW(),'10','2026',?,'Tunai',0,1)");
        $nis=$payment['NO_INDUK'];$grade=$payment['KELAS'];$identity=(string)$actor;$s->bind_param('sss',$nis,$grade,$identity);$s->execute();$id=(int)$koneksi->insert_id;$s->close();
        $initial=transaction_authorization_snapshot($koneksi,$id)['data'];
        payment_activity_record($koneksi,$id,'created',$actor,null,$initial,'test-created:'.$id);
        payment_activity_record($koneksi,$id,'created',$actor,null,$initial,'test-created:'.$id);
        activity_assert(count(payment_activity_for_payments($koneksi,[$id])[$id])===1,'Permintaan ulang menggandakan jurnal');
        $after=$initial;$after['payment']['KETERANGAN']='Catatan diubah';
        payment_activity_record($koneksi,$id,'edited',$actor,$initial,$after,'test-edit:'.$id);
        activity_assert(payment_activity_changes($initial,$after)[0]['label']==='Keterangan','Detail perubahan tidak sesuai');
        $koneksi->query('DELETE FROM bayar WHERE id='.$id);
        payment_activity_record($koneksi,$id,'deleted',$actor,$after,null,'test-deleted:'.$id);
        $events=payment_activity_for_payments($koneksi,[$id])[$id];
        activity_assert(count($events)===3,'Jejak hilang sesudah penghapusan');
        $summary=payment_activity_summary($events);
        activity_assert((int)$summary['creator']['actor_id']===(int)$actor && $summary['last']['action']==='deleted','Ringkasan operator salah');
        $archive=payment_archive_page($koneksi,date('Y-m-d'),date('Y-m-d'),$nis,0,1,50);
        activity_assert(in_array($id,array_column($archive['rows'],'id'),true),'Transaksi tidak ditemukan pada arsip');
        foreach(['UPDATE pembayaran_aktivitas SET note="diganti" WHERE payment_id='.$id,'DELETE FROM pembayaran_aktivitas WHERE payment_id='.$id] as $sql) {
            $denied=false;try{$koneksi->query($sql);}catch(mysqli_sql_exception $e){$denied=true;}
            activity_assert($denied,'Jurnal dapat diubah/dihapus');
        }
        unit_set_context($koneksi,$unit===3?1:$unit+1);
        activity_assert(payment_activity_for_payments($koneksi,[$id])===[],'Jurnal bocor lintas unit');
        $denied=false;try{payment_activity_record($koneksi,$id,'edited',$actor,$initial,$after,'forged:'.$id);}catch(RuntimeException $e){$denied=true;}
        activity_assert($denied,'Pencatatan lintas unit diterima');
        unit_set_context($koneksi,0);
        activity_assert(count(payment_activity_for_payments($koneksi,[$id])[$id])===3,'Rekap Semua Unit tidak lengkap');
        unit_set_context($koneksi,$unit);
    } finally { $koneksi->rollback(); }
    activity_assert(payment_activity_for_payments($koneksi,[$id])===[],'Rollback meninggalkan jurnal sukses');
    echo 'OK: unit '.unit_label($unit)." creation/edit/delete/archive/replay/rollback/isolation/immutable.\n";
}
activity_assert((int)$koneksi->query('SELECT COUNT(*) FROM pembayaran_aktivitas_data')->fetch_row()[0]===$journalBefore,'Tes mengubah jumlah jurnal permanen');
activity_assert(payment_activity_actor($koneksi,'unknown-operator')['id']===null,'Operator lama tidak boleh ditebak');
echo "OK: bukti operator tidak dikenal ditampilkan tanpa tebakan.\n";
foreach([1,2,3] as $unit) {
    unit_set_context($koneksi,$unit);
    $cashier=(int)$koneksi->query("SELECT id FROM admin WHERE unit_id=$unit AND role='kasir' ORDER BY id LIMIT 1")->fetch_row()[0];
    $_SESSION=['admin_id'=>$cashier,'admin_role'=>'kasir','active_unit_id'=>$unit];
    $p=$koneksi->query("SELECT b.id FROM bayar b JOIN pembayaran_aktivitas e ON e.payment_id=b.id AND e.unit_id=b.unit_id AND e.action='created' AND e.actor_id=$cashier WHERE NOT EXISTS(SELECT 1 FROM transaksi_otorisasi r WHERE r.bayar_id=b.id AND r.status='pending') ORDER BY b.id LIMIT 1")->fetch_assoc();
    $id=(int)$p['id'];
    $approver=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' LIMIT 1")->fetch_row()[0];
    foreach(['rejected','cancelled','failed'] as $status) {
        $request=transaction_authorization_create($koneksi,$id,'edit',['aksi'=>'update','id'=>$id],'Uji operator dan rollback keputusan',$cashier);
        $initialCount=count(payment_activity_for_payments($koneksi,[$id])[$id]);
        if($status==='failed') transaction_authorization_mark_failed($koneksi,$request,$approver,'Snapshot berubah; transaksi tidak diterapkan.');
        else {
            $actor=$status==='cancelled'?$cashier:$approver;
            $koneksi->begin_transaction();transaction_authorization_decide($koneksi,$request,$status,$actor,'Catatan keputusan');$koneksi->rollback();
            activity_assert(count(payment_activity_for_payments($koneksi,[$id])[$id])===$initialCount,'Rollback keputusan meninggalkan jurnal');
            $koneksi->begin_transaction();transaction_authorization_decide($koneksi,$request,$status,$actor,'Catatan keputusan');$koneksi->commit();
        }
        $events=payment_activity_for_payments($koneksi,[$id])[$id];$last=$events[count($events)-1];
        activity_assert($last['action']===$status && (int)$last['authorization_id']===$request,'Keputusan tidak dicatat');
        if($status==='failed') {
            transaction_authorization_mark_failed($koneksi,$request,$approver,'Diulang');
            activity_assert(count(payment_activity_for_payments($koneksi,[$id])[$id])===count($events),'Kegagalan ulang menggandakan aktivitas');
        }
    }
    echo 'OK: unit '.unit_label($unit)." request/reject/cancel/failure/decision rollback.\n";
}
$fks=(int)$koneksi->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='pembayaran_aktivitas_data' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetch_row()[0];
activity_assert($fks===0,'Jurnal tidak boleh tergantung akun, siswa atau pembayaran hidup');
