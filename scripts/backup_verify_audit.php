<?php
/** Runs only against a freshly owned isolated MySQL instance. Never modifies the source. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once __DIR__.'/../includes/database_backup.php';
/** Merge private, sorted SHA-256 runs with a fixed number of open streams. */
function db_backup_merge_fingerprint_runs(array $files,?string $target=null): ?string {
    $streams=[];$heads=[];$output=$target?fopen($target,'xb'):null;$hash=$target?null:hash_init('sha256');
    if($target&&!$output)throw new RuntimeException('Fingerprint workspace unavailable.');
    try{
        foreach($files as $key=>$file){$streams[$key]=fopen($file,'rb');if(!$streams[$key])throw new RuntimeException('Fingerprint run unavailable.');$heads[$key]=fgets($streams[$key]);}
        while(true){$next=null;foreach($heads as $key=>$head)if($head!==false&&($next===null||strcmp($head,$heads[$next])<0))$next=$key;
            if($next===null)break;$line=$heads[$next];
            if($output){if(fwrite($output,$line)!==strlen($line))throw new RuntimeException('Fingerprint workspace full.');}else hash_update($hash,$line);
            $heads[$next]=fgets($streams[$next]);
        }
    }finally{foreach($streams as $stream)if(is_resource($stream))fclose($stream);if(is_resource($output))fclose($output);}
    return $hash?hash_final($hash):null;
}
/** Future tables without a primary key use bounded external sorting of row hashes. */
function db_backup_unkeyed_fingerprint(mysqli_result $rows,string $job): array {
    $work=$job.'/fingerprint-'.bin2hex(random_bytes(8));if(!mkdir($work,0700))throw new RuntimeException('Fingerprint workspace unavailable.');
    $owned=[];$runs=[];$chunk=[];$count=0;
    $flush=static function()use(&$chunk,&$owned,&$runs,$work):void{
        if(!$chunk)return;sort($chunk,SORT_STRING);$file=$work.'/'.bin2hex(random_bytes(8)).'.run';$owned[]=$file;
        $bytes=implode("\n",$chunk)."\n";if(file_put_contents($file,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Fingerprint workspace full.');$runs[]=$file;$chunk=[];
    };
    try{
        while($row=$rows->fetch_assoc()){$chunk[]=hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$count++;if(count($chunk)>=4096)$flush();}$flush();
        while(count($runs)>32){$next=[];foreach(array_chunk($runs,32) as $batch){$file=$work.'/'.bin2hex(random_bytes(8)).'.run';$owned[]=$file;db_backup_merge_fingerprint_runs($batch,$file);$next[]=$file;foreach($batch as $old)unlink($old);}$runs=$next;}
        return ['count'=>$count,'fingerprint'=>db_backup_merge_fingerprint_runs($runs),'algorithm'=>'sha256-sorted-row-sha256-lines-v1'];
    }finally{$rows->free();foreach($owned as $file)if(is_file($file)&&!is_link($file))unlink($file);rmdir($work);}
}
$options=[];foreach($argv as $arg)if(preg_match('/^--(config|instance|job)=(.+)$/D',$arg,$m))$options[$m[1]]=$m[2];
try{
    foreach(['config','instance','job'] as $key)if(empty($options[$key]))throw new RuntimeException('Explicit isolated audit arguments required.');
    putenv('SPP_BACKUP_CONFIG='.$options['config']);$c=db_backup_config();$job=realpath($options['job']);
    if(!$job||!preg_match('~[/\\\\]jobs[/\\\\][a-f0-9]{32}$~D',$job)||db_backup_normal_path(dirname($job))!==db_backup_normal_path($c['storage_root'].'/jobs')||db_backup_normal_path(realpath($options['instance'])?:'')!==db_backup_normal_path($job.'/verifier-instance.json'))throw new RuntimeException('Isolated audit storage differs.');
    $i=json_decode(file_get_contents($options['instance']),true,32,JSON_THROW_ON_ERROR);
    if(($i['host']??'')!=='127.0.0.1'||$i['database']!==$c['database']||empty($i['password'])||$i['server_uuid']===$c['server_uuid']||db_backup_normal_path($i['datadir'])===db_backup_normal_path($c['datadir']))throw new RuntimeException('Isolated server identity refused.');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=new mysqli($i['host'],$i['username'],$i['password'],$i['database'],(int)$i['port']);$db->set_charset('utf8mb4');
    $identity=$db->query('SELECT @@server_uuid uuid,@@datadir datadir,@@global.event_scheduler scheduler')->fetch_assoc();
    if($identity['uuid']!==$i['server_uuid']||db_backup_normal_path($identity['datadir'])!==db_backup_normal_path($i['datadir'])||$identity['scheduler']!=='OFF')throw new RuntimeException('Isolated server verification refused.');
    $expected=json_decode(file_get_contents($job.'/schema.json'),true,64,JSON_THROW_ON_ERROR);$actual=db_backup_schema($db);
    if(!hash_equals((string)($expected['signature']??''),hash('sha256',json_encode($expected['objects'],JSON_THROW_ON_ERROR))))throw new RuntimeException('Source schema inventory checksum differs.');
    // Older inventories retain renderer formatting; compare their semantics
    // with current inventories without changing the saved source evidence.
    foreach($expected['objects'] as $kind=>&$objects)foreach($objects as &$object){if($kind==='tables')$object['ddl']=db_backup_schema_ddl($object['ddl'],true);else $object=db_backup_schema_ddl($object);}unset($object,$objects);
    $expected['signature']=hash('sha256',json_encode($expected['objects'],JSON_THROW_ON_ERROR));
    if(!hash_equals($expected['signature'],$actual['signature'])){
        db_backup_atomic_json($job.'/schema-restored.json',$actual);throw new RuntimeException('Restored schema differs from source inventory.');
    }
    // Re-enabling FOREIGN_KEY_CHECKS after import does not validate restored rows.
    $quote=static fn(string $s):string=>'`'.str_replace('`','``',$s).'`';$foreignKeys=[];
    foreach($db->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC) as $fk){
        if($fk['REFERENCED_TABLE_SCHEMA']!==$i['database'])throw new RuntimeException('Restored foreign key refers outside the backup database.');
        $foreignKeys[$fk['TABLE_NAME'].'/'.$fk['CONSTRAINT_NAME']][]=$fk;
    }
    foreach($foreignKeys as $columns){$first=$columns[0];$nonnull=[];$join=[];foreach($columns as $column){$nonnull[]='c.'.$quote($column['COLUMN_NAME']).' IS NOT NULL';$join[]='p.'.$quote($column['REFERENCED_COLUMN_NAME']).'=c.'.$quote($column['COLUMN_NAME']);}
        if((int)$db->query('SELECT COUNT(*) FROM '.$quote($first['TABLE_NAME']).' c WHERE '.implode(' AND ',$nonnull).' AND NOT EXISTS (SELECT 1 FROM '.$quote($first['REFERENCED_TABLE_NAME']).' p WHERE '.implode(' AND ',$join).')')->fetch_row()[0])throw new RuntimeException('Restored foreign-key orphan detected.');
    }
    $checkCount=0;
    foreach($db->query("SELECT t.TABLE_NAME,c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.CONSTRAINT_TYPE='CHECK' AND t.ENFORCED='YES'")->fetch_all(MYSQLI_ASSOC) as $check){
        // MySQL exposes CHECK_CLAUSE with one metadata escape layer (e.g. _utf8mb4\'LEGACY\').
        $expression=stripslashes($check['CHECK_CLAUSE']);
        if((int)$db->query('SELECT COUNT(*) FROM '.$quote($check['TABLE_NAME']).' WHERE NOT ('.$expression.')')->fetch_row()[0])throw new RuntimeException('Restored CHECK violation detected.');$checkCount++;
    }
    $metrics=[];$fingerprints=[];$fingerprintAlgorithms=[];$primaryKeys=[];
    foreach($db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_NAME='PRIMARY' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC) as $key)$primaryKeys[$key['TABLE_NAME']][]=$key['COLUMN_NAME'];
    foreach($actual['objects']['tables'] as $name=>$table){
        $order=isset($primaryKeys[$name])?' ORDER BY '.implode(',',array_map($quote,$primaryKeys[$name])):'';
        $rows=$db->query('SELECT * FROM '.$quote($name).$order,MYSQLI_USE_RESULT);
        if(!$order){$result=db_backup_unkeyed_fingerprint($rows,$job);$metrics[$name]=$result['count'];$fingerprints[$name]=$result['fingerprint'];$fingerprintAlgorithms[$name]=$result['algorithm'];continue;}
        $hash=hash_init('sha256');$count=0;
        try{while($row=$rows->fetch_assoc()){hash_update($hash,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");$count++;}}finally{$rows->free();}
        $metrics[$name]=$count;$fingerprints[$name]=hash_final($hash);$fingerprintAlgorithms[$name]='sha256-json-lines-primary-key-v1';
    }
    $unitCounts=[];foreach([1,2,3] as $unit){$db->query('SET @app_unit_id='.$unit);$unitCounts[$unit]=['students'=>(int)$db->query('SELECT COUNT(*) FROM siswa')->fetch_row()[0],'payments'=>(int)$db->query('SELECT COUNT(*) FROM bayar')->fetch_row()[0]];}
    $db->query('SET @app_unit_id=0');$totals=['students'=>(int)$db->query('SELECT COUNT(*) FROM siswa')->fetch_row()[0],'payments'=>(int)$db->query('SELECT COUNT(*) FROM bayar')->fetch_row()[0],'receipts'=>(string)$db->query('SELECT COALESCE(SUM(total_jumlah),0) FROM bayar')->fetch_row()[0],'savings'=>(string)$db->query('SELECT COALESCE(SUM(SALDO),0) FROM tabungan')->fetch_row()[0]];
    if(array_sum(array_column($unitCounts,'students'))!==$totals['students']||array_sum(array_column($unitCounts,'payments'))!==$totals['payments'])throw new RuntimeException('Restored unit scopes differ.');
    $db->close();
    // All existing audits receive only the isolated connection, never operational defaults.
    foreach(['SPP_DB_HOST'=>$i['host'],'SPP_DB_PORT'=>(string)$i['port'],'SPP_DB_USER'=>$i['username'],'SPP_DB_PASS'=>$i['password'],'SPP_DB_NAME'=>$i['database']] as $key=>$value)putenv($key.'='.$value);
    foreach(['tests/readiness_integrity_audit.php','sql/audit_foreign_keys.php','health.php'] as $script)db_backup_process([$c['php_executable'],$c['app_root'].'/'.$script],$job.'/audit-'.pathinfo($script,PATHINFO_FILENAME),300);
    $fkReport=json_decode(file_get_contents($job.'/audit-audit_foreign_keys.out.log'),true,64,JSON_THROW_ON_ERROR);
    foreach(['missing','mismatched','unresolved'] as $problem)if(!array_key_exists($problem,$fkReport)||$fkReport[$problem])throw new RuntimeException('Restored foreign-key contract failed.');
    if(trim(file_get_contents($job.'/audit-health.out.log'))!=='ok')throw new RuntimeException('Restored health check failed.');
    db_backup_atomic_json($job.'/verification.json',['state'=>'verified','verified_at'=>db_backup_now()->format(DATE_ATOM),'sql_sha256'=>hash_file('sha256',$job.'/dump.sql'),'schema_signature'=>$actual['signature'],'object_counts'=>array_map('count',$actual['objects']),'table_counts'=>$metrics,'fingerprints'=>$fingerprints,'fingerprint_algorithms'=>$fingerprintAlgorithms,'units'=>$unitCounts,'totals'=>$totals,'checks'=>['health'=>'ok','financial_integrity'=>'passed','foreign_keys'=>'passed','foreign_key_count'=>count($foreignKeys),'check_constraint_count'=>$checkCount]]);
    echo 'Isolated database audit passed.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Isolated audit failed: '.$e->getMessage().PHP_EOL);exit(1);}
