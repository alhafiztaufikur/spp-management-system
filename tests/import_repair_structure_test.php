<?php
/** Disposable schema regression: interrupted imports must recover keys before relations. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../includes/import_repair.php';
require_once __DIR__.'/../includes/import_repair_structure.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(getenv('SPP_DB_HOST')?:'127.0.0.1',getenv('SPP_DB_USER')?:'root',getenv('SPP_DB_PASS')?:'','',(int)(getenv('SPP_DB_PORT')?:3306));
$name='db_spp_audit_import_structure_'.bin2hex(random_bytes(6));
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$contract=['indexes'=>[
    ['table'=>'parent','name'=>'PRIMARY','unique'=>true,'columns'=>['id']],
    ['table'=>'parent','name'=>'unique_code','unique'=>true,'columns'=>['code']],
    ['table'=>'child','name'=>'PRIMARY','unique'=>true,'columns'=>['id']],
    ['table'=>'child','name'=>'parent_lookup','unique'=>false,'columns'=>['parent_id']],
],'auto_increment'=>[['table'=>'parent','column'=>'id','type'=>'int'],['table'=>'child','column'=>'id','type'=>'int']]];
try{
    $db->query('CREATE DATABASE '.ir_identifier($name));$db->select_db($name);
    $db->query('CREATE TABLE parent(id INT NOT NULL,code VARCHAR(20) NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE child(id INT NOT NULL,parent_id INT NOT NULL) ENGINE=InnoDB');
    $db->query("INSERT INTO parent VALUES(7,'existing'),(8,NULL),(9,NULL)");$db->query('INSERT INTO child VALUES(3,7)');
    $before=$db->query('SELECT * FROM parent ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $db->query("INSERT INTO parent VALUES(7,'duplicate')");
    $bad=ir_structure_plan($db,$contract);$assert((bool)$bad['violations'],'Duplicate primary key was not detected');
    $refused=false;try{ir_apply_structure($db,$bad,static function(){});}catch(RuntimeException $e){$refused=true;}$assert($refused,'Duplicate repair allowed');
    $db->query("DELETE FROM parent WHERE code='duplicate'");
    $db->query('CREATE INDEX unique_code ON parent(code)');
    $assert((bool)ir_structure_plan($db,$contract)['mismatches'],'Conflicting index definition was accepted');
    $db->query('DROP INDEX unique_code ON parent');
    $badContract=$contract;$badContract['auto_increment'][0]['type']='bigint';
    $assert((bool)ir_structure_plan($db,$badContract)['mismatches'],'Conflicting identity type was accepted');
    $plan=ir_structure_plan($db,$contract,['parent'=>'100']);
    $assert(!$plan['violations']&&!$plan['mismatches']&&count($plan['missing_indexes'])===4&&count($plan['missing_identity'])===2,'Wrong partial import plan');
    ir_apply_structure($db,$plan,static function(){});
    $assert($before===$db->query('SELECT * FROM parent ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Repair changed existing rows');
    ir_apply_schema($db,['missing'=>[['kind'=>'foreign_key','table_name'=>'child','name'=>'child_parent','columns'=>['parent_id'],'parent'=>'parent','parent_columns'=>['id'],'delete'=>'RESTRICT','update'=>'RESTRICT']],'violations'=>[],'mismatches'=>[]],static function(){});
    $db->begin_transaction();$db->query("INSERT INTO parent(code) VALUES('new')");$assert($db->insert_id===100,'Proven source identity counter was not retained');
    $db->query('INSERT INTO child(parent_id) VALUES(7)');$assert($db->insert_id===4,'Missing source counter did not resume after MAX(id)');
    $refused=false;try{$db->query('INSERT INTO child(parent_id) VALUES(999)');}catch(mysqli_sql_exception $e){$refused=$e->getCode()===1452;}$assert($refused,'Restored foreign key did not reject an orphan');$db->rollback();
    $again=ir_structure_plan($db,$contract,['parent'=>'1000']);$changes=[];
    ir_apply_structure($db,$again,static function($p)use(&$changes){$changes[]=$p;});
    $assert(!$again['missing_indexes']&&!$again['missing_identity']&&!$changes,'Rerun changed schema or existing counters');
    echo "PASS: duplicate and incompatible schemas rejected; nullable unique keys, parent indexes, identity counters, row preservation and zero-change rerun verified.\n";
}finally{$db->query('DROP DATABASE '.ir_identifier($name));$db->close();}
