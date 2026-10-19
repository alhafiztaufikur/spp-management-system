<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_full_schema.php';
if(!in_array('--apply',$argv,true)){echo legacy_full_ready($koneksi)?"Migrasi penuh siap.\n":"Skema migrasi penuh belum terpasang.\n";exit;}
if(getenv('SPP_TEST_ALLOW_MUTATION')==='1'){require_once __DIR__.'/../tests/http_form_scope.php';spp_test_assert_database_target($koneksi);}
elseif(!in_array('--confirm-maintenance',$argv,true)||!is_file(__DIR__.'/../tmp/financial_migration.lock'))throw new RuntimeException('Backup, mode pemeliharaan, dan --confirm-maintenance diperlukan.');
if((int)$koneksi->query("SELECT GET_LOCK('spp_legacy_full_schema',0)")->fetch_row()[0]!==1)throw new RuntimeException('Pemasangan lain sedang berjalan.');
try{legacy_full_schema_apply($koneksi);echo "Skema migrasi penuh terpasang; tidak ada data sumber diterapkan.\n";}finally{$koneksi->query("SELECT RELEASE_LOCK('spp_legacy_full_schema')");}
