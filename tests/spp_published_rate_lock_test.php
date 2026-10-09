<?php
/** Mutating regression, restricted to a disposable database; all fixtures roll back. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/spp_billing.php';
require_once __DIR__.'/../includes/kelas.php';
function rate_lock_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function rate_lock_snapshot(mysqli $db): array {
    $result=[];
    foreach (['master_spp_tahun','master_spp_tarif','tagihan_spp','siswa','siswa_tahun_ajaran','spp_audit_log'] as $table)
        $result[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    return $result;
}
function rate_lock_reject(mysqli $db, int $id, array $rates): void {
    $before=rate_lock_snapshot($db);$rejected=false;
    try { spp_master_save_rates($db,$id,$rates); }
    catch (RuntimeException $error) { $rejected=str_contains($error->getMessage(),'Tarif terkunci'); }
    rate_lock_assert($rejected,'Published base rates were editable');
    rate_lock_assert($before===rate_lock_snapshot($db),'Rejected save changed a rate, bill, student, placement or audit');
}
foreach ([1,2,3] as $unit) {
    $_SESSION=['admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
    $koneksi->begin_transaction();
    try {
        [$first,$last]=unit_level_bounds();$year='2171/2172';
        $master=spp_master_ensure_year($koneksi,$year,true);$id=(int)$master['id'];$yearId=(int)$master['tahun_ajaran_id'];
        rate_lock_assert(spp_master_rates_editable($master),'New draft is locked');
        $rates=array_fill_keys(range($first,$last),240000.0);
        spp_master_save_rates($koneksi,$id,$rates);
        rate_lock_assert(spp_publish_students($koneksi,$id,['NOT_A_STUDENT'])['created']===0,'Invalid student unexpectedly published');
        $rates=array_fill_keys(range($first,$last),250000.0);
        spp_master_save_rates($koneksi,$id,$rates);
        $class=$koneksi->query('SELECT id,tingkat,kode_rombel FROM master_kelas WHERE tingkat='.$first.' AND is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 1')->fetch_assoc();
        rate_lock_assert((bool)$class,'First grade fixture missing');$classId=(int)$class['id'];$level=(string)$first;$label=class_label($class);
        $students=[];
        foreach ([1,2] as $index) {
            $nis=(string)random_int(9700000000,9799999999);$students[]=$nis;$name='TEST LOCK '.$index;
            $s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,is_active) VALUES(?,?,?,?,250000,1)');
            $s->bind_param('sssi',$nis,$name,$level,$classId);$s->execute();$s->close();
            $s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,status) VALUES(?,?,?,?,?,250000,'aktif')");
            $s->bind_param('issis',$yearId,$nis,$level,$classId,$label);$s->execute();$s->close();
        }
        rate_lock_assert(spp_publish_students($koneksi,$id,[$students[0]])['created']===12,'Initial publication failed');
        rate_lock_reject($koneksi,$id,array_fill_keys(range($first,$last),300000.0));
        rate_lock_reject($koneksi,$id,$rates); // Even an unchanged stale save is rejected.
        rate_lock_assert(spp_publish_students($koneksi,$id,[$students[1]])['created']===12,'Additional publication was blocked');
        rate_lock_assert((float)$koneksi->query('SELECT MIN(tarif_dasar_snapshot) FROM tagihan_spp WHERE master_spp_tahun_id='.$id)->fetch_row()[0]===250000.0,'Additional bills used another base');
        $koneksi->query("UPDATE master_spp_tahun SET status='closed',closed_at=NOW() WHERE id=$id");rate_lock_reject($koneksi,$id,$rates);
        $koneksi->query("UPDATE master_spp_tahun SET status='published',closed_at=NULL WHERE id=$id");rate_lock_reject($koneksi,$id,$rates);
        $koneksi->query("UPDATE tagihan_spp SET status='cancelled',cancel_reason='test lock' WHERE master_spp_tahun_id=$id");
        $koneksi->query("UPDATE master_spp_tahun SET status='draft',published_at=NULL WHERE id=$id");rate_lock_reject($koneksi,$id,$rates);
        $other=spp_master_ensure_year($koneksi,'2172/2173',true);$otherId=(int)$other['id'];
        spp_master_save_rates($koneksi,$otherId,array_fill_keys(range($first,$last),280000.0));
        rate_lock_assert(spp_master_rates($koneksi,$id)===$rates,'Saving another year changed published rates');
        $koneksi->query("UPDATE master_spp_tahun SET published_at=NOW() WHERE id=$otherId");rate_lock_reject($koneksi,$otherId,$rates);
        $koneksi->query("UPDATE master_spp_tahun SET status='published',published_at=NULL WHERE id=$otherId");rate_lock_reject($koneksi,$otherId,$rates);
        echo "PASS: unit $unit draft/save, publication lock, stale save, extra students, reopen, cancelled bills, timestamp and separate year\n";
    } finally { $koneksi->rollback(); }
}
