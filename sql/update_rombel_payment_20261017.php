<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/readiness_migration_guard.php';
$target=(string)(getenv('SPP_DB_NAME')?:'db_spp');
if (getenv('SPP_TEST_ISOLATED_INSTANCE')) {
    require_once __DIR__.'/../tests/http_form_scope.php';
    spp_test_isolated_manifest();
} else readiness_migration_assert_apply_allowed($argv,$target);
require_once __DIR__.'/../koneksi.php';
if(getenv('SPP_TEST_ISOLATED_INSTANCE'))spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/rombel_update_schema.php';
rombel_schema_apply($koneksi);
echo "OK: rombel and recurring tariff schema ready.\n";
