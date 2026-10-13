<?php
/** Pure DDL comparison tests; optional captured inventories are read-only. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once __DIR__.'/../includes/database_backup.php';
$checks=0;
function schema_test(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function schema_equal(string $a,string $b,bool $table=false):bool{return db_backup_schema_ddl($a,$table)===db_backup_schema_ddl($b,$table);}
$table="CREATE TABLE `sample` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `note` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `positive` CHECK ((`id` > 0))\n) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$restored=str_replace('varchar(255) COLLATE','varchar(255) CHARACTER SET utf8mb4 COLLATE',str_replace("\n","\r\n",$table));
schema_test(schema_equal($table,$restored,true),'Matching redundant charset and CRLF must normalize.');
schema_test(schema_equal($table,str_replace('AUTO_INCREMENT=19','AUTO_INCREMENT=501',$table),true),'Table sequence changes must normalize.');
schema_test(schema_equal($table,str_replace("\n  ","\n\t    ",$table),true),'Outside-token indentation must normalize.');
schema_test(schema_equal('x text COLLATE latin1_swedish_ci','x text CHARACTER SET latin1 COLLATE latin1_swedish_ci'),'Matching latin1 collation must imply its charset.');
schema_test(schema_equal('x text COLLATE utf8mb3_general_ci','x text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci'),'Matching utf8mb3 collation must imply its charset.');
schema_test(!schema_equal('x text CHARACTER SET latin1 COLLATE utf8mb4_general_ci','x text COLLATE utf8mb4_general_ci'),'Nonmatching charset declarations must remain distinct.');
foreach([
    ['varchar(255)','varchar(254)','Column size'],
    ['utf8mb4_general_ci','utf8mb4_bin','Collation'],
    ['DEFAULT CHARSET=utf8mb4','DEFAULT CHARSET=latin1','Table charset'],
    ['DEFAULT NULL',"DEFAULT 'new'",'Default literal'],
    ['bigint NOT NULL','bigint NULL','Nullability'],
    ['`id` > 0','`id` >= 0','CHECK expression'],
    ['CONSTRAINT `positive`','CONSTRAINT `different`','Constraint name'],
    ['NOT NULL AUTO_INCREMENT','NOT NULL','Column AUTO_INCREMENT attribute'],
    ['PRIMARY KEY (`id`)','KEY (`id`)','Primary key'],
] as [$old,$new,$label])schema_test(!schema_equal($table,str_replace($old,$new,$table),true),$label.' change must remain detectable.');
schema_test(!schema_equal('x text CHARACTER SET utf8mb4','x text CHARACTER SET latin1'),'Standalone charset changes must remain detectable.');
$trigger="CREATE DEFINER=`root`@`localhost` TRIGGER `sample_bi` BEFORE INSERT ON `sample` FOR EACH ROW BEGIN\r\n  SET NEW.note = 'unchanged';\r\nEND";
schema_test(schema_equal($trigger,str_replace("\r\n","\n",$trigger)),'Trigger body line endings outside literals must normalize.');
foreach([
    ['BEFORE INSERT','BEFORE UPDATE','Trigger event'],
    ['`root`@`localhost`','`other`@`localhost`','Definer'],
    ["'unchanged'","'changed'",'Trigger literal'],
] as [$old,$new,$label])schema_test(!schema_equal($trigger,str_replace($old,$new,$trigger)),$label.' change must remain detectable.');
schema_test(!schema_equal("SELECT 'a\r\nb'","SELECT 'a\nb'"),'CRLF inside string literal is data and must remain distinct.');
schema_test(!schema_equal("SELECT 'a  b'","SELECT 'a b'"),'Whitespace inside string literal must remain distinct.');
schema_test(!schema_equal('SELECT "a  b"','SELECT "a b"'),'Double-quoted token content must remain distinct.');
schema_test(!schema_equal('SELECT `a  b`','SELECT `a b`'),'Quoted identifier content must remain distinct.');
foreach(["SELECT 'a\\'  b'","SELECT 'a''  b'",'SELECT "a\\"  b"','SELECT `a``  b`'] as $quoted){
    schema_test(db_backup_schema_ddl($quoted)===$quoted,'Escaped quoted content must be byte-exact.');
}
$literal="CREATE TABLE `x` (`v` text DEFAULT 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci AUTO_INCREMENT=19') ENGINE=InnoDB";
schema_test(db_backup_schema_ddl($literal,true)===$literal,'Charset/counter-looking literals must not be rewritten.');
schema_test(!schema_equal('SET AUTO_INCREMENT=19','SET AUTO_INCREMENT=20'),'Routine counter-looking assignments must not be removed.');
$comment="SELECT 1 -- ' quoted comment\r\n+ 2";
schema_test(str_contains(db_backup_schema_ddl($comment),"-- ' quoted comment\r\n"),'Line comment terminator must remain intact.');
$comment="SELECT 1 /* CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci AUTO_INCREMENT=19 */";
schema_test(db_backup_schema_ddl($comment,true)===$comment,'Comment text must not be rewritten.');
$comment="SELECT /*!80000 'a\r\nb' */";
schema_test(db_backup_schema_ddl($comment)===$comment,'Executable comment literal content must remain intact.');
schema_test(!schema_equal('FOREIGN KEY (`a`) REFERENCES `parent` (`id`)','FOREIGN KEY (`a`) REFERENCES `other` (`id`)'),'FK target changes must remain detectable.');
schema_test(!schema_equal('CREATE FUNCTION `f`() RETURNS int SQL SECURITY DEFINER RETURN 1','CREATE FUNCTION `f`() RETURNS int SQL SECURITY INVOKER RETURN 1'),'Routine security changes must remain detectable.');
$options=[];foreach($argv as $arg)if(preg_match('/^--(source-schema|restored-schema)=(.+)$/D',$arg,$m))$options[$m[1]]=$m[2];
if($options){
    foreach(['source-schema','restored-schema'] as $key)if(empty($options[$key]))throw new RuntimeException('Both captured schema paths are required.');
    $normalize=static function(string $file):array{
        $inventory=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR)['objects'];
        foreach($inventory as $kind=>&$objects)foreach($objects as &$object){if($kind==='tables')$object['ddl']=db_backup_schema_ddl($object['ddl'],true);else $object=db_backup_schema_ddl($object);}unset($object,$objects);
        return $inventory;
    };
    schema_test($normalize($options['source-schema'])===$normalize($options['restored-schema']),'Captured source/restored inventories must match after semantic canonicalization.');
}
echo 'PASS: '.$checks.' schema canonicalization checks; no database operations.'.PHP_EOL;
