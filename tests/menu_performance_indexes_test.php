<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/menu_performance.php';
require_once __DIR__.'/../includes/database_backup.php';
$db=$koneksi;$checks=0;
function mpi_check(bool $ok,string $why): void {global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
$expected=json_decode(file_get_contents(getenv('SPP_QA_DIR').'/source-schema.json'),true,64,JSON_THROW_ON_ERROR);
$actual=db_backup_schema($db);
mpi_check(menu_performance_schema_compatible($actual,$expected),'Optional indexes retain compatibility with the pre-index backup.');
$changed=$expected;$changed['objects']['tables']['legacy_op_payments_data']['ddl'].=' extra';
mpi_check(!menu_performance_schema_compatible($actual,$changed),'Unrelated schema change must be rejected.');
$legacyMetadata=$actual;$ddl=&$legacyMetadata['objects']['tables']['legacy_op_payments_data']['ddl'];
$position=strpos($ddl,', CONSTRAINT');if($position===false)throw new RuntimeException('Restored payment constraints missing');
$ddl=substr_replace($ddl,menu_performance_metadata_ddl(),$position,0);unset($ddl);
mpi_check(menu_performance_schema_compatible($actual,$legacyMetadata),'A verified backup with the optional old metadata key stays readable.');
$changed=$legacyMetadata;$changed['objects']['tables']['legacy_op_payments_data']['ddl']=str_replace("_utf8mb4'$[0].migration'","_utf8mb4'$[1].migration'",$changed['objects']['tables']['legacy_op_payments_data']['ddl']);
mpi_check(!menu_performance_schema_compatible($actual,$changed),'A different functional expression must never be ignored.');
$literal=str_replace("'","''",menu_performance_metadata_ddl());
$a=['objects'=>['tables'=>['legacy_op_payments_data'=>['ddl'=>"CREATE TABLE t (`note` text DEFAULT 'safe')"]]]];$b=$a;
$b['objects']['tables']['legacy_op_payments_data']['ddl']=str_replace("'safe'","'safe$literal'",$a['objects']['tables']['legacy_op_payments_data']['ddl']);
mpi_check(!menu_performance_schema_compatible($a,$b),'The complete functional index inside a quoted value is not DDL.');
foreach(menu_performance_indexes() as [$table,$name,$columns]){
    $literal=", KEY `$name` (`".str_replace(',','`,`',$columns).'`)';
    $a=['objects'=>['tables'=>[$table=>['ddl'=>"CREATE TABLE `$table` (`note` varchar(200) DEFAULT 'safe')"]]]];
    $b=$a;$b['objects']['tables'][$table]['ddl']=str_replace("'safe'","'safe$literal'",$a['objects']['tables'][$table]['ddl']);
    mpi_check(!menu_performance_schema_compatible($a,$b),'Do not erase index-like text inside a SQL literal.');
}
mpi_check(menu_performance_apply($db)===[],'Repeated installer makes zero changes.');
foreach(menu_performance_plan($db) as $index)mpi_check($index['state']==='ready','Expected read index is available.');
echo "PASS $checks index, replay and backup-compatibility checks.\n";
