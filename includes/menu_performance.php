<?php
/** Additive read indexes shared by the CLI installer and verified restore runner. */
function menu_performance_indexes(): array {
    return [
        ['legacy_op_journals_data','idx_menu_journal_date','unit_id,source_date,owner_id'],
        ['legacy_op_journals_data','idx_menu_journal_operator','unit_id,batch_id,source_operator,origin'],
        ['legacy_op_payments_data','idx_menu_payment_operator','unit_id,batch_id,source_operator'],
        ['legacy_op_payments_data','idx_menu_payment_period','unit_id,cancelled,source_period,batch_id'],
    ];
}
function menu_performance_plan(mysqli $db): array {
    $indexes=[];
    $result=$db->query("SELECT TABLE_NAME,INDEX_NAME,IS_VISIBLE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols,
        SUM(SUB_PART IS NOT NULL) prefixes FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('legacy_op_journals_data','legacy_op_payments_data')
        GROUP BY TABLE_NAME,INDEX_NAME,IS_VISIBLE");
    foreach($result as $index)$indexes[$index['TABLE_NAME']][]=$index;
    $plan=[];
    foreach(menu_performance_indexes() as [$table,$name,$columns]){
        if(!isset($indexes[$table])){$plan[]=['table'=>$table,'index'=>$name,'state'=>'unavailable','columns'=>$columns];continue;}
        $match=null;
        foreach($indexes[$table] as $index){
            if($index['IS_VISIBLE']==='YES' && !(int)$index['prefixes']
                && ($index['cols']===$columns||str_starts_with($index['cols'],$columns.','))){$match=$index['INDEX_NAME'];break;}
        }
        if($match){$plan[]=['table'=>$table,'index'=>$match,'state'=>'ready','columns'=>$columns];continue;}
        foreach($indexes[$table] as $index)if($index['INDEX_NAME']===$name)throw new RuntimeException('Indeks performa bertentangan: '.$table.'.'.$name);
        $plan[]=['table'=>$table,'index'=>$name,'state'=>'missing','columns'=>$columns];
    }
    return $plan;
}
/** Caller must verify backup/identity and own the CLI maintenance operation first. */
function menu_performance_apply(mysqli $db): array {
    if(PHP_SAPI!=='cli')throw new LogicException('Pemasangan indeks hanya melalui CLI.');
    $applied=[];
    foreach(menu_performance_plan($db) as $index){
        if($index['state']!=='missing')continue;
        $db->query('ALTER TABLE `'.$index['table'].'` ADD INDEX `'.$index['index'].'` ('.$index['columns'].'), ALGORITHM=INPLACE, LOCK=NONE');
        $applied[]=$index;
    }
    return $applied;
}
/** Optional read indexes must not make an otherwise exact older backup unusable. */
function menu_performance_schema_compatible(array $actual,array $expected): bool {
    $normalize=static function(array $schema): array {
        $objects=$schema['objects'];
        if(isset($objects['tables']['legacy_op_payments_data']['ddl']))$objects['tables']['legacy_op_payments_data']['ddl']=menu_performance_strip_metadata_ddl($objects['tables']['legacy_op_payments_data']['ddl']);
        foreach(menu_performance_indexes() as [$table,$name,$columns]){
            if(!isset($objects['tables'][$table]['ddl']))continue;
            $fragment=', KEY `'.$name.'` (`'.str_replace(',','`,`',$columns).'`)';
            // SQL literals/comments remain opaque, including a default value
            // that happens to contain text resembling an index declaration.
            $tokens=preg_split('~(\'(?:\\\\.|\'\'|[^\'\\\\])*\'|"(?:\\\\.|""|[^"\\\\])*"|/\*[\s\S]*?\*/)~',$objects['tables'][$table]['ddl'],-1,PREG_SPLIT_DELIM_CAPTURE);
            if($tokens===false)throw new RuntimeException('Schema inventory could not be parsed.');
            foreach($tokens as $i=>$token)if($i%2===0)$tokens[$i]=str_replace($fragment,'',$token);
            $objects['tables'][$table]['ddl']=implode('',$tokens);
        }
        return $objects;
    };
    return $normalize($actual)===$normalize($expected);
}

/** Conservative DU candidate predicate; escaped/non-ASCII categories retain the full reader. */
function menu_performance_metadata_expression(string $alias=''):string {
    if($alias!==''&&!preg_match('/^[a-z]+$/D',$alias))throw new LogicException('Invalid metadata alias');
    $field=$alias===''?'items':$alias.'.items';
    $cats="JSON_UNQUOTE(JSON_EXTRACT($field,'$[*].category'))";
    return "JSON_CONTAINS_PATH($field,'one','$[0].migration') AND (LOCATE('daftar_ulang',LOWER($cats))>0 OR LOCATE(CHAR(92),$cats)>0 OR OCTET_LENGTH($cats)<>CHAR_LENGTH($cats))";
}
/** Same ordered, visible-column algorithm as backup_verify_audit.php. Functional
 * indexes add hidden virtual fields to MySQL's physical CHECKSUM TABLE result.
 */
function menu_performance_payment_fingerprint(mysqli $db):array {
    $hash=hash_init('sha256');$count=0;
    $rows=$db->query('SELECT * FROM legacy_op_payments_data ORDER BY id',MYSQLI_USE_RESULT);
    try{while($row=$rows->fetch_assoc()){hash_update($hash,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");$count++;}}
    finally{$rows->free();}
    return ['count'=>$count,'sha256'=>hash_final($hash)];
}
/** Compatibility with verified backups made while the optional metadata key existed. */
function menu_performance_metadata_ddl():string {
    return <<<'DDL'
, KEY `idx_menu_payment_du_metadata` (`unit_id`,`cancelled`,((json_contains_path(`items`,_utf8mb4'one',_utf8mb4'$[0].migration') and ((locate(_utf8mb4'daftar_ulang',lower(json_unquote(json_extract(`items`,_utf8mb4'$[*].category')))) > 0) or (locate(char(92),json_unquote(json_extract(`items`,_utf8mb4'$[*].category'))) > 0) or (length(json_unquote(json_extract(`items`,_utf8mb4'$[*].category'))) <> char_length(json_unquote(json_extract(`items`,_utf8mb4'$[*].category'))))))),`batch_id`)
DDL;
}
/** Match only a full, trusted index fragment beginning outside SQL literals. */
function menu_performance_strip_metadata_ddl(string $ddl):string {
    $fragment=menu_performance_metadata_ddl();$needle=', KEY `idx_menu_payment_du_metadata` ';
    $tokens=preg_split('~(\'(?:\\\\.|\'\'|[^\'\\\\])*\'|"(?:\\\\.|""|[^"\\\\])*"|/\*[\s\S]*?\*/)~',$ddl,-1,PREG_SPLIT_DELIM_CAPTURE);
    if($tokens===false)throw new RuntimeException('Schema inventory could not be parsed');
    $offset=0;
    foreach($tokens as $i=>$token){
        if($i%2===0&&($pos=strpos($token,$needle))!==false&&substr($ddl,$offset+$pos,strlen($fragment))===$fragment)return substr_replace($ddl,'',$offset+$pos,strlen($fragment));
        $offset+=strlen($token);
    }
    return $ddl;
}
