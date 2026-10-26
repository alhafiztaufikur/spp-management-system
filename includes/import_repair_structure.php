<?php
/** Recover the index/identity stages of an interrupted phpMyAdmin import before FKs. */
function ir_structure_contract(): array {
    $contract=json_decode(file_get_contents(__DIR__.'/../sql/import_repair_structure.json'),true,64,JSON_THROW_ON_ERROR);
    if(($contract['version']??0)!==1||count($contract['indexes']??[])<200||count($contract['auto_increment']??[])<50)throw new RuntimeException('Incomplete import structure contract.');
    return $contract;
}
function ir_index_signature(array $index): string {
    return json_encode([$index['table'],$index['name']==='PRIMARY',$index['unique'],$index['columns']],JSON_THROW_ON_ERROR);
}
function ir_structure_plan(mysqli $db,?array $contract=null,array $counterFloors=[]): array {
    $contract??=ir_structure_contract();$indexes=[];$columns=[];$missingIndexes=[];$missingIdentity=[];$violations=[];$mismatches=[];
    foreach($db->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()") as $r)$columns[$r['TABLE_NAME']][$r['COLUMN_NAME']]=$r;
    foreach($db->query("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,INDEX_TYPE,COLUMN_NAME,SUB_PART,COLLATION,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX") as $r){
        $key=$r['TABLE_NAME'].'.'.$r['INDEX_NAME'];
        $indexes[$key]??=['table'=>$r['TABLE_NAME'],'name'=>$r['INDEX_NAME'],'unique'=>!(bool)$r['NON_UNIQUE'],'columns'=>[],'supported'=>true];
        $indexes[$key]['columns'][]=$r['COLUMN_NAME'];
        $indexes[$key]['supported']=$indexes[$key]['supported']&&$r['INDEX_TYPE']==='BTREE'&&$r['SUB_PART']===null&&$r['COLLATION']==='A'&&$r['EXPRESSION']===null;
    }
    $signatures=[];foreach($indexes as $index)if($index['supported'])$signatures[ir_index_signature($index)]=true;
    foreach($contract['indexes'] as $index){
        $table=$index['table'];$key=$table.'.'.$index['name'];ir_identifier($table);ir_identifier($index['name']);
        if(!isset($columns[$table])&&in_array($table,['tabungan_otorisasi_data','tabungan_aktivitas_data'],true))continue;
        foreach($index['columns'] as $column){ir_identifier($column);if(!isset($columns[$table][$column])){$mismatches[]=$key.': missing column '.$column;continue 2;}}
        if(isset($indexes[$key])&&(!$indexes[$key]['supported']||ir_index_signature($indexes[$key])!==ir_index_signature($index))){$mismatches[]=$key;continue;}
        if(isset($signatures[ir_index_signature($index)]))continue;
        $missingIndexes[]=$index;$signatures[ir_index_signature($index)]=true;
        if(!$index['unique'])continue;
        $quoted=array_map('ir_identifier',$index['columns']);$nonnull=implode(' AND ',array_map(static fn($c)=>$c.' IS NOT NULL',$quoted));
        if($index['name']==='PRIMARY'&&($n=(int)$db->query('SELECT COUNT(*) FROM '.ir_identifier($table).' WHERE NOT ('.$nonnull.')')->fetch_row()[0]))$violations[]=['constraint'=>$key,'null_rows'=>$n];
        $n=(int)$db->query('SELECT COUNT(*) FROM (SELECT 1 FROM '.ir_identifier($table).' WHERE '.$nonnull.' GROUP BY '.implode(',',$quoted).' HAVING COUNT(*)>1) duplicates')->fetch_row()[0];
        if($n)$violations[]=['constraint'=>$key,'duplicate_groups'=>$n];
    }
    foreach($contract['auto_increment'] as $identity){
        $table=$identity['table'];$column=$identity['column'];$key=$table.'.'.$column;
        if(!isset($columns[$table])&&in_array($table,['tabungan_otorisasi_data','tabungan_aktivitas_data'],true))continue;
        if(!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?: unsigned)?$/D',$identity['type']))throw new RuntimeException('Unsupported identity type.');
        $actual=$columns[$table][$column]??null;
        if(!$actual||$actual['COLUMN_TYPE']!==$identity['type']||$actual['IS_NULLABLE']!=='NO'||$actual['COLUMN_DEFAULT']!==null||!in_array($actual['EXTRA'],['','auto_increment'],true)){$mismatches[]=$key.': identity definition';continue;}
        if($actual['EXTRA']==='auto_increment')continue;
        $maximum=$db->query('SELECT COALESCE(MAX('.ir_identifier($column).'),0) FROM '.ir_identifier($table))->fetch_row()[0];
        $floor=(string)($counterFloors[$table]??'1');
        if(!ctype_digit($floor)||strlen($floor)>18||(float)$maximum>=PHP_INT_MAX)throw new RuntimeException('Unsupported identity range.');
        $identity['next']=max(1,(int)$maximum+1,(int)$floor);$missingIdentity[]=$identity;
    }
    return ['missing_indexes'=>$missingIndexes,'missing_identity'=>$missingIdentity,'violations'=>$violations,'mismatches'=>$mismatches];
}
function ir_apply_structure(mysqli $db,array $plan,callable $progress): void {
    if(PHP_SAPI!=='cli'||$plan['violations']||$plan['mismatches'])throw new RuntimeException('Structure preflight failed.');
    $tables=[];
    foreach($plan['missing_indexes'] as $index)$tables[$index['table']][]=($index['name']==='PRIMARY'?'ADD PRIMARY KEY':'ADD '.($index['unique']?'UNIQUE ':'').'KEY '.ir_identifier($index['name'])).' ('.implode(',',array_map('ir_identifier',$index['columns'])).')';
    // One rebuild per table, with its key and identity restored together.
    foreach($plan['missing_identity'] as $identity){
        $tables[$identity['table']][]='MODIFY '.ir_identifier($identity['column']).' '.$identity['type'].' NOT NULL AUTO_INCREMENT';
        $tables[$identity['table']][]='AUTO_INCREMENT='.$identity['next'];
    }
    // Finish all parent keys before the caller restores foreign keys.
    foreach($tables as $table=>$definitions){$db->query('ALTER TABLE '.ir_identifier($table).' '.implode(', ',$definitions));$progress(['table'=>$table,'structure_changes'=>count($definitions)]);}
}
