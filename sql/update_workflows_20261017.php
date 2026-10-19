<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/readiness_migration_guard.php';
$target=(string)(getenv('SPP_DB_NAME')?:'db_spp');
readiness_migration_assert_apply_allowed($argv,$target);
$files=array_values(array_filter($argv,static fn($a)=>str_starts_with($a,'--credentials=')));
$preserve=in_array('--preserve-existing-accounts',$argv,true);
if($preserve&&$files)throw new RuntimeException('Pilih satu kebijakan akun: pertahankan akun existing atau buat akun awal.');
if(!$preserve&&count($files)!==1)throw new RuntimeException('Gunakan --credentials=ABSOLUTE_PRIVATE_FILE atau --preserve-existing-accounts');
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/workflow_update_schema.php';
workflow_schema_apply($koneksi);
if($preserve)workflow_accounts_preserve_existing($koneksi);
else workflow_accounts_apply($koneksi,substr($files[0],14));
require_once __DIR__.'/../includes/rombel_update_schema.php';
rombel_schema_apply($koneksi);
echo "OK: migrasi workflow dan akun selesai. Penerimaan dan saldo tidak diubah.\n";
