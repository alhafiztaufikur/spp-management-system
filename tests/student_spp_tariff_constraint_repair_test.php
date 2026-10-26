<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest();
require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/spp_student_tariff_schema.php';require_once __DIR__.'/../includes/spp_student_tariff.php';
$checks=[];$check=static function(bool $ok,string $name)use(&$checks){if(!$ok)throw new RuntimeException($name);$checks[]=$name;};
$private=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));
$preflight=sct_constraint_inspect($koneksi,true);$check(!$preflight['invalid']&&!$preflight['conflicts'],'Clone preflight clean');
$changes=sct_schema_apply($koneksi);$check(sct_ready($koneksi),'Clone full readiness');$check(sct_schema_apply($koneksi)===0,'Clone repeated apply changes zero');
$name='db_spp_audit_sct_constraints';
if($koneksi->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$name'")->num_rows)throw new RuntimeException('Fixture already exists');
$koneksi->query("CREATE DATABASE $name");$koneksi->select_db($name);
try{
    $koneksi->query('CREATE TABLE siswa_data(unit_id TINYINT UNSIGNED NOT NULL,id INT NOT NULL,PRIMARY KEY(unit_id,id))');
    $koneksi->query('CREATE TABLE siswa_tahun_ajaran_data(id BIGINT NOT NULL PRIMARY KEY)');
    $koneksi->query('CREATE TABLE tahun_ajaran_data(id INT NOT NULL PRIMARY KEY)');
    $koneksi->query('CREATE TABLE admin(id INT NOT NULL PRIMARY KEY)');
    $koneksi->query('CREATE TABLE spp_tarif_siswa_data(id BIGINT PRIMARY KEY,unit_id TINYINT UNSIGNED NOT NULL,student_id INT NOT NULL,penempatan_id BIGINT NOT NULL,tahun_ajaran_id INT NOT NULL,actor_id INT NOT NULL,nominal_akhir DECIMAL(15,2) NOT NULL)');
    $p=sct_constraint_inspect($koneksi,true);$check(count($p['missing'])===5&&!$p['conflicts']&&!$p['invalid'],'Existing fixture detects five missing constraints');
    $koneksi->query('INSERT INTO spp_tarif_siswa_data VALUES(1,1,1,1,1,1,-1)');
    $p=sct_constraint_inspect($koneksi,true);$check(count($p['invalid'])===5,'Preflight detects all four orphan kinds and negative nominal');
    $rejected=false;try{sct_schema_apply($koneksi);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'Preflight');}$check($rejected,'Official engine rejects invalid data before DDL');
    $check((int)$koneksi->query('SELECT COUNT(*) FROM spp_tarif_siswa_data')->fetch_row()[0]===1,'Rejected preflight preserves invalid fixture');
    $koneksi->query('DELETE FROM spp_tarif_siswa_data');
    $koneksi->query('ALTER TABLE spp_tarif_siswa_data MODIFY actor_id INT UNSIGNED NOT NULL');
    $check((bool)sct_constraint_inspect($koneksi,true)['conflicts'],'Type conflict rejected');
    $koneksi->query('ALTER TABLE spp_tarif_siswa_data MODIFY actor_id INT NOT NULL');
    $koneksi->query('ALTER TABLE spp_tarif_siswa_data ADD CONSTRAINT fk_sct_actor FOREIGN KEY(actor_id) REFERENCES tahun_ajaran_data(id)');
    $check((bool)sct_constraint_inspect($koneksi,true)['conflicts'],'Wrong referenced table rejected');
    $koneksi->query('ALTER TABLE spp_tarif_siswa_data DROP FOREIGN KEY fk_sct_actor');
    $p=sct_constraint_inspect($koneksi,true);foreach($p['missing'] as $constraint=>$sql){if($constraint==='ck_sct_nonnegative')continue;$koneksi->query(str_replace('`'.$constraint.'`','`equivalent_'.$constraint.'`',$sql));}
    $koneksi->query('ALTER TABLE spp_tarif_siswa_data ADD CONSTRAINT equivalent_nonnegative CHECK (0<=nominal_akhir)');
    $p=sct_constraint_inspect($koneksi,true);$check(!$p['missing']&&!$p['conflicts']&&!$p['invalid'],'Equivalent names and reversed CHECK accepted');
    $koneksi->query('ALTER TABLE spp_tarif_siswa_data ALTER CHECK equivalent_nonnegative NOT ENFORCED');
    $check((bool)sct_constraint_inspect($koneksi,true)['conflicts'],'Unenforced CHECK is not ready');
}finally{$koneksi->select_db('db_spp');$koneksi->query("DROP DATABASE $name");}
$result=['changes'=>$changes,'second_changes'=>0,'checks'=>$checks,'contract'=>sct_constraint_inspect($koneksi,true)];file_put_contents($private.'/schema-tests.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($result),PHP_EOL;
