<?php
/** Read-only compatibility gate: never execute new forms on a partially migrated database. */
function workflow_schema_ready(mysqli $db): bool {
    $n=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (
      (TABLE_NAME IN ('siswa','siswa_data') AND COLUMN_NAME='potongan_psb_nominal') OR
      (TABLE_NAME IN ('master_biaya_lain','master_biaya_lain_data') AND COLUMN_NAME='jenis_pembayaran') OR
      (TABLE_NAME IN ('bayar','bayar_data') AND COLUMN_NAME IN ('riwayat_tahun_ajaran','riwayat_kelas_snapshot')) OR
      (TABLE_NAME='admin' AND COLUMN_NAME='active_super_guard'))")->fetch_row()[0];
    if($n!==9)return false;
    if(!$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workflow_migration_log'")->num_rows)return false;
    return (int)$db->query("SELECT COUNT(*) FROM workflow_migration_log WHERE migration_key IN ('two_roles_20261017','rombel_payment_20261017')")->fetch_row()[0]===2 && workflow_legacy_schema_ready($db);
}
/** A completed import must never silently disappear behind optional-reader readiness gates. */
function workflow_legacy_schema_ready(mysqli $db): bool {
    if(!$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_full_batches_data' AND TABLE_TYPE='BASE TABLE'")->num_rows)return true;
    if(!$db->query("SELECT 1 FROM legacy_full_batches_data WHERE state='completed' LIMIT 1")->num_rows)return true;
    require_once __DIR__.'/legacy_full_schema.php';
    require_once __DIR__.'/legacy_operational_schema.php';
    require_once __DIR__.'/financial_components_schema.php';
    return legacy_full_ready($db) && lop_ready($db) && financial_components_ready($db);
}
function workflow_require_ready(mysqli $db): void {
    if(PHP_SAPI==='cli'||workflow_schema_ready($db))return;
    http_response_code(503);header('Retry-After: 300');header('Cache-Control: no-store');
    $message='Pembaruan database belum diterapkan. Hubungi Super Admin untuk menyelesaikan pembaruan sistem.';
    if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')||basename($_SERVER['SCRIPT_NAME']??'')==='health.php'){
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'code'=>'schema_update_required','message'=>$message],JSON_UNESCAPED_UNICODE);
    }else{
        header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sistem sedang diperbarui</title><style>body{margin:0;background:#eef6f1;color:#18382c;font:16px/1.7 Arial,sans-serif}main{max-width:620px;margin:12vh auto;padding:30px;background:#fff;border:1px solid #cde3d5;border-radius:18px}h1{font-size:24px;margin:0 0 12px}</style></head><body><main><h1>Sistem sedang diperbarui</h1><p>'.$message.'</p></main></body></html>';
    }exit;
}
