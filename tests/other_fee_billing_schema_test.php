<?php
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);require __DIR__.'/../includes/other_fee_billing.php';
$db=$koneksi;$passed=[];$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
$bill=hp_one($db,'SELECT * FROM tagihan_biaya_lain ORDER BY id LIMIT 1');if(!$bill)throw new RuntimeException('Run the disposable billing HTTP fixture first.');
try{ofb_write($db,'UPDATE tagihan_biaya_lain SET master_biaya_lain_id=2147483646 WHERE id=?',[$bill['id']]);throw new LogicException('Orphan accepted.');}catch(mysqli_sql_exception $e){if(!str_contains($e->getMessage(),'lintas unit'))throw $e;$passed[]='Live unit guard prevents orphan writes';}
try{ofb_write($db,"INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,occurrence_key) VALUES(?,?,'QA invalid',-1,?)",[$bill['master_biaya_lain_id'],$bill['no_induk'],'qa-negative-'.bin2hex(random_bytes(4))]);throw new LogicException('Negative amount was accepted.');}catch(mysqli_sql_exception $e){if($e->getCode()!==3819)throw $e;$passed[]='Negative principal rejected by enforced CHECK';}
if(ofb_schema_apply($db)!==0)throw new RuntimeException('Healthy schema changed.');$passed[]='Repeated installer returns zero changes';
$scratch='db_spp_test'.random_int(10000,99999);$db->query('CREATE DATABASE '.$scratch);$db->select_db($scratch);
try{
    $db->query('CREATE TABLE siswa_data(id INT PRIMARY KEY,unit_id TINYINT UNSIGNED,NO_INDUK VARCHAR(10))');
    $db->query('CREATE TABLE master_biaya_lain_data(id INT PRIMARY KEY,unit_id TINYINT UNSIGNED)');
    $db->query('CREATE TABLE tagihan_biaya_lain_data(id BIGINT PRIMARY KEY,unit_id TINYINT UNSIGNED,no_induk VARCHAR(10),master_biaya_lain_id INT,nominal_tagihan DECIMAL(15,2))');
    $db->query("INSERT INTO tagihan_biaya_lain_data VALUES(1,1,'orphan',999,1)");
    try{ofb_schema_plan($db,true);throw new LogicException('Orphan preflight accepted.');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'orphan'))throw $e;$passed[]='Imported orphan blocks preflight before DDL';}
    $db->query('ALTER TABLE tagihan_biaya_lain_data ADD occurrence_key INT NOT NULL DEFAULT 0');
    try{ofb_schema_plan($db);throw new LogicException('Conflicting column accepted.');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'occurrence_key:'))throw $e;$passed[]='Conflicting definition rejected without DDL';}
    if((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetch_row()[0]!==3)throw new RuntimeException('Preflight mutated schema.');
}finally{$db->select_db(DB_NAME);}
file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/billing-schema.json',json_encode(['passed'=>true,'checks'=>$passed],JSON_PRETTY_PRINT));echo 'PASS '.count($passed)." schema safety checks on owned instance.\n";
