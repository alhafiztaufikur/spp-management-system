<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/readiness_migration_guard.php';require_once __DIR__.'/savings_authorization_schema.php';require_once __DIR__.'/../includes/savings_authorization.php';
if(!in_array('--apply',$argv,true)){echo savings_authorization_ready($koneksi)?"READY: otorisasi tabungan tersedia.\n":"AUDIT ONLY: migrasi otorisasi tabungan belum lengkap. Tidak ada data diubah.\n";exit;}
readiness_migration_assert_apply_allowed($argv,DB_NAME);
savings_authorization_schema_apply($koneksi);
if(!savings_authorization_ready($koneksi))throw new RuntimeException('Migrasi belum lengkap; otorisasi tabungan tetap ditutup.');
echo "OK: otorisasi dan audit tabungan siap. Jurnal, saldo, pembayaran, serta akun existing tidak diubah.\n";
