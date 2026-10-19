<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/legacy_finance_schema.php';
if (!in_array('--apply', $argv, true)) {
    echo legacy_finance_ready($koneksi) ? "Skema keuangan Legacy siap.\n" : "Skema belum terpasang. Gunakan --apply setelah backup dan menutup akses aplikasi.\n";
    exit;
}
if (getenv('SPP_TEST_ALLOW_MUTATION')==='1') {
    require_once __DIR__.'/../tests/http_form_scope.php'; spp_test_assert_database_target($koneksi);
} elseif (!in_array('--confirm-maintenance', $argv, true) || !is_file(__DIR__.'/../tmp/financial_migration.lock')) {
    throw new RuntimeException('Pemasangan memerlukan backup, mode pemeliharaan, dan --confirm-maintenance.');
}
$lock=$koneksi->query("SELECT GET_LOCK('spp_legacy_finance_schema',0)")->fetch_row()[0];
if ((int)$lock!==1) throw new RuntimeException('Migrasi lain sedang berjalan.');
try { legacy_finance_schema_apply($koneksi); echo "Skema keuangan Legacy terpasang; data operasional tidak diimpor.\n"; }
finally { $koneksi->query("SELECT RELEASE_LOCK('spp_legacy_finance_schema')"); }
