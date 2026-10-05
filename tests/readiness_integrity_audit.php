<?php
/** Read-only, all-unit financial and relationship audit. Never creates fixtures. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require_once __DIR__ . '/../koneksi.php';
unit_set_context($koneksi, 0);

function readiness_scalar(mysqli $db, string $sql): float {
    return (float)$db->query($sql)->fetch_row()[0];
}

$metrics = [
    'siswa' => 'SELECT COUNT(*) FROM siswa',
    'penempatan' => 'SELECT COUNT(*) FROM siswa_tahun_ajaran',
    'tagihan_spp' => 'SELECT COUNT(*) FROM tagihan_spp',
    'tagihan_komite' => 'SELECT COUNT(*) FROM tagihan_komite',
    'tagihan_du' => 'SELECT COUNT(*) FROM tagihan_daftar_ulang',
    'tagihan_biaya_lain' => 'SELECT COUNT(*) FROM tagihan_biaya_lain',
    'pembayaran' => 'SELECT COUNT(*) FROM bayar',
    'total_kas_pembayaran' => 'SELECT COALESCE(SUM(total_jumlah),0) FROM bayar',
    'alokasi_spp' => 'SELECT COUNT(*) FROM spp_alokasi',
    'pembayaran_komite' => 'SELECT COUNT(*) FROM bayar_komite',
    'pembayaran_du' => 'SELECT COUNT(*) FROM bayar_du',
    'tabungan' => 'SELECT COUNT(*) FROM tabungan',
    'saldo_tabungan' => 'SELECT COALESCE(SUM(SALDO),0) FROM tabungan',
    'jurnal_tabungan_masuk' => 'SELECT COUNT(*) FROM transaksi_m',
    'jurnal_tabungan_keluar' => 'SELECT COUNT(*) FROM transaksi_k',
];

$checks = [
    'duplikat_nis_diknas_per_unit' => "SELECT COUNT(*) FROM (
        SELECT unit_id,NO_induk_diknas FROM siswa
        WHERE NO_induk_diknas IS NOT NULL AND TRIM(NO_induk_diknas)<>''
        GROUP BY unit_id,NO_induk_diknas HAVING COUNT(*)>1
    ) x",
    'duplikat_penempatan' => "SELECT COUNT(*) FROM (
        SELECT unit_id,tahun_ajaran_id,no_induk FROM siswa_tahun_ajaran
        GROUP BY unit_id,tahun_ajaran_id,no_induk HAVING COUNT(*)>1
    ) x",
    'penempatan_tanpa_siswa_atau_tahun' => "SELECT COUNT(*) FROM siswa_tahun_ajaran p
        LEFT JOIN siswa s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id
        LEFT JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id
        WHERE s.id IS NULL OR y.id IS NULL OR s.unit_id<>p.unit_id OR y.unit_id<>p.unit_id",
    'pembayaran_tanpa_siswa' => "SELECT COUNT(*) FROM bayar b
        LEFT JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id
        WHERE b.NO_INDUK IS NOT NULL AND (s.id IS NULL OR s.unit_id<>b.unit_id)",
    'total_header_tidak_cocok' => "SELECT COUNT(*) FROM bayar b
        LEFT JOIN (SELECT bayar_id,SUM(jumlah) total_du FROM bayar_du GROUP BY bayar_id) d ON d.bayar_id=b.id
        WHERE ABS(COALESCE(b.total_jumlah,0)-(
            COALESCE(b.U_PSB,0)+COALESCE(b.U_SPP,0)
            +COALESCE(b.U_KOMITE,0)
            +COALESCE(b.U_LAIN,0)+COALESCE(d.total_du,0)
            -COALESCE(b.potong_spp,0)
        ))>0.01",
    'tagihan_spp_salah_relasi' => "SELECT COUNT(*) FROM tagihan_spp t
        LEFT JOIN siswa_tahun_ajaran p ON p.id=t.penempatan_id
        LEFT JOIN tahun_ajaran y ON y.id=t.tahun_ajaran_id
        WHERE p.id IS NULL OR y.id IS NULL OR p.no_induk<>t.no_induk
          OR p.tahun_ajaran_id<>t.tahun_ajaran_id OR p.unit_id<>t.unit_id OR y.unit_id<>t.unit_id",
    'tagihan_komite_salah_relasi' => "SELECT COUNT(*) FROM tagihan_komite t
        LEFT JOIN siswa_tahun_ajaran p ON p.id=t.penempatan_id
        WHERE p.id IS NULL OR p.no_induk<>t.no_induk
          OR p.tahun_ajaran_id<>t.tahun_ajaran_id OR p.unit_id<>t.unit_id",
    'tagihan_du_salah_relasi' => "SELECT COUNT(*) FROM tagihan_daftar_ulang t
        LEFT JOIN siswa_tahun_ajaran p ON p.id=t.penempatan_id
        WHERE p.id IS NULL OR p.no_induk<>t.no_induk
          OR p.tahun_ajaran_id<>t.tahun_ajaran_id OR p.unit_id<>t.unit_id",
    'alokasi_spp_salah_relasi' => "SELECT COUNT(*) FROM spp_alokasi a
        LEFT JOIN spp_alokasi_batch b ON b.id=a.batch_id
        LEFT JOIN tagihan_spp t ON t.id=a.tagihan_spp_id
        WHERE b.id IS NULL OR t.id IS NULL OR b.no_induk<>t.no_induk
          OR a.unit_id<>b.unit_id OR a.unit_id<>t.unit_id",
    'spp_melebihi_tagihan' => "SELECT COUNT(*) FROM (
        SELECT t.id FROM tagihan_spp t
        LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=t.id
        LEFT JOIN spp_alokasi_batch b ON b.id=a.batch_id
        GROUP BY t.id,t.nominal_tagihan
        HAVING COALESCE(SUM(CASE WHEN b.status='active'
            THEN a.nominal_dari_bayar ELSE 0 END),0)>t.nominal_tagihan+0.01
    ) x",
    'komite_melebihi_tagihan' => "SELECT COUNT(*) FROM (
        SELECT t.id FROM tagihan_komite t LEFT JOIN bayar_komite p ON p.tagihan_komite_id=t.id
        GROUP BY t.id,t.nominal_tagihan HAVING COALESCE(SUM(p.nominal),0)>t.nominal_tagihan+0.01
    ) x",
    'du_melebihi_tagihan' => "SELECT COUNT(*) FROM (
        SELECT t.id FROM tagihan_daftar_ulang t LEFT JOIN bayar_du p ON p.tagihan_daftar_ulang_id=t.id
        GROUP BY t.id,t.nominal_tagihan HAVING COALESCE(SUM(p.jumlah),0)>t.nominal_tagihan+0.01
    ) x",
    'biaya_lain_melebihi_tagihan' => "SELECT COUNT(*) FROM (
        SELECT t.id FROM tagihan_biaya_lain t LEFT JOIN bayar_biaya_lain p ON p.tagihan_biaya_lain_id=t.id
        GROUP BY t.id,t.nominal_tagihan HAVING COALESCE(SUM(p.nominal_snapshot),0)>t.nominal_tagihan+0.01
    ) x",
    'saldo_tabungan_tidak_cocok_jurnal' => "SELECT COUNT(*) FROM tabungan t
        LEFT JOIN (SELECT unit_id,NO_INDUK,SUM(MASUK) total FROM transaksi_m GROUP BY unit_id,NO_INDUK) m ON m.NO_INDUK=t.NO_INDUK AND m.unit_id=t.unit_id
        LEFT JOIN (SELECT unit_id,NO_INDUK,SUM(KELUAR) total FROM transaksi_k GROUP BY unit_id,NO_INDUK) k ON k.NO_INDUK=t.NO_INDUK AND k.unit_id=t.unit_id
        WHERE ABS(COALESCE(t.SALDO,0)-COALESCE(m.total,0)+COALESCE(k.total,0))>0.01
           OR COALESCE(t.SALDO,0)<-0.01",
];

if((int)$koneksi->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND COLUMN_NAME='legacy_pending'")->fetch_row()[0]){
    $checks['duplikat_nis_per_unit']="SELECT COUNT(*) FROM (SELECT unit_id,NO_INDUK FROM siswa GROUP BY unit_id,NO_INDUK HAVING COUNT(*)>1) x";
    $checks['legacy_status_tidak_valid']="SELECT COUNT(*) FROM siswa WHERE legacy_pending=1 AND (is_active<>0 OR KELAS<>'LEGACY' OR master_kelas_id IS NOT NULL)";
    $checks['legacy_metadata_tidak_cocok']="SELECT COUNT(*) FROM legacy_student_import m LEFT JOIN siswa s ON s.id=m.student_id AND s.unit_id=m.unit_id WHERE s.id IS NULL";
    $checks['legacy_tanpa_manifest']="SELECT COUNT(*) FROM siswa s LEFT JOIN legacy_student_import m ON m.student_id=s.id AND m.unit_id=s.unit_id WHERE s.legacy_pending=1 AND m.id IS NULL";
    foreach(['siswa_tahun_ajaran'=>'no_induk','bayar'=>'NO_INDUK','tabungan'=>'NO_INDUK','tagihan_spp'=>'no_induk','tagihan_komite'=>'no_induk','tagihan_daftar_ulang'=>'no_induk','tagihan_biaya_lain'=>'no_induk','tagihan_tahunan_siswa'=>'no_induk'] as $table=>$nis){$checks['legacy_memiliki_'.$table]="SELECT COUNT(*) FROM $table c JOIN siswa s ON s.unit_id=c.unit_id AND s.NO_INDUK=c.$nis WHERE s.legacy_pending=1";}
}

if((int)$koneksi->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND COLUMN_NAME='potongan_spp_nominal'")->fetch_row()[0]){
    $checks['psb_melebihi_master']="SELECT COUNT(*) FROM (SELECT b.unit_id,b.NO_INDUK,SUM(b.U_PSB) paid,s.PSB FROM bayar b JOIN siswa s ON s.unit_id=b.unit_id AND s.NO_INDUK=b.NO_INDUK GROUP BY b.unit_id,b.NO_INDUK,s.PSB HAVING SUM(b.U_PSB)>s.PSB+0.01) x";
    $checks['potongan_nominal_negatif']="SELECT COUNT(*) FROM siswa WHERE potongan_spp_nominal<0";
    $checks['snapshot_potongan_nominal_tidak_valid']="SELECT COUNT(*) FROM tagihan_spp WHERE potongan_nominal_ditetapkan_snapshot<0 OR potongan_nominal_snapshot<0 OR potongan_nominal_snapshot>tarif_dasar_snapshot+0.01 OR potongan_nominal_snapshot>potongan_nominal_ditetapkan_snapshot+0.01";
    $checks['migrasi_komponen_belum_selesai']="SELECT COUNT(*) FROM financial_component_migration WHERE stage<>'complete'";
}

echo 'database=' . DB_NAME . PHP_EOL;
foreach ($metrics as $name => $sql) {
    echo 'metric.' . $name . '=' . number_format(readiness_scalar($koneksi, $sql), 2, '.', '') . PHP_EOL;
}
$failed = false;
foreach ($checks as $name => $sql) {
    $count = (int)readiness_scalar($koneksi, $sql);
    echo ($count === 0 ? 'OK' : 'FAIL') . '.' . $name . '=' . $count . PHP_EOL;
    $failed = $failed || $count !== 0;
}
exit($failed ? 1 : 0);
