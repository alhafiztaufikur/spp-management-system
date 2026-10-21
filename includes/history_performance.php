<?php
/** Read-only SQL projections. Scope comes from the connection, never request parameters. */
function hp_connection_key(mysqli $db): string {
    static $keys=[];$identity=spl_object_id($db).':'.$db->thread_id;
    // CLI migration/tests may switch databases on one connection; HTTP never does.
    if(PHP_SAPI==='cli')return $identity.':'.$db->query('SELECT DATABASE()')->fetch_row()[0];
    return $keys[$identity]??=($identity.':'.$db->query('SELECT DATABASE()')->fetch_row()[0]);
}
function hp_sql(mysqli $db, string $sql,bool $ordered=false): string {
    static $tables=[],$identityIndexes=[],$schoolIds=[];
    $key=hp_connection_key($db);
    if(!isset($tables[$key])){
        $tables[$key]=[];
        $r=$db->query("SELECT v.TABLE_NAME,v.VIEW_DEFINITION FROM information_schema.VIEWS v WHERE v.TABLE_SCHEMA=DATABASE()");
        while($row=$r->fetch_row()){
            if(!is_string($row[1]))continue;
            $definition=str_replace('`','',strtolower($row[1]));
            $pos=strrpos($definition,' where ');if($pos===false)continue;
            $predicate=preg_replace('/\b[a-zA-Z_][a-zA-Z0-9_]*\./','',substr($definition,$pos+7));
            $predicate=preg_replace('/[\s()]/','',$predicate);
            if($predicate!=='current_unit_id=0orunit_id=current_unit_id')continue;
            $base=$row[0].'_data';
            // Optimize only the ordinary unit projection, never a view with
            // additional restrictions, joins, aliases or computed columns.
            $source=preg_replace('/\b[a-zA-Z_][a-zA-Z0-9_]*\./','',substr($definition,0,$pos));
            if(!str_ends_with($source,' from '.$base)||str_contains($source,' join '))continue;
            $columns=substr($source,7,-strlen(' from '.$base));$plain=true;
            foreach(explode(',',$columns) as $column)if(!preg_match('/^\s*([a-zA-Z0-9_]+) as \1\s*$/D',$column)){$plain=false;break;}
            if(!$plain)continue;
            if(preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$base))$tables[$key][$row[0]]=$base;
        }
    }
    if(!$tables[$key])return $sql;
    // The original views use this same connection-owned scope. An explicit predicate
    // lets MySQL use composite indexes without evaluating the stored function per row.
    $unit=(int)$db->query('SELECT current_unit_id()')->fetch_row()[0];
    if($unit===0&&!isset($schoolIds[$key])){
        $schoolIds[$key]=array_map('intval',array_column($db->query('SELECT id FROM unit_sekolah ORDER BY id')->fetch_all(MYSQLI_ASSOC),'id'));
    }
    // All-unit reads still enumerate every school (including inactive schools).
    // An explicit IN lets date filters use the existing unit/date indexes.
    $scope=$unit===0?($schoolIds[$key]?' WHERE unit_id IN ('.implode(',',$schoolIds[$key]).')':' WHERE FALSE'):' WHERE unit_id='.$unit;
    if($ordered&&!isset($identityIndexes[$key])){
        $identityIndexes[$key]=[];
        $r=$db->query("SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND IS_VISIBLE='YES' GROUP BY TABLE_NAME,INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='unit_id,id'");
        while($row=$r->fetch_row())if(preg_match('/^[a-zA-Z0-9_]+$/D',$row[1]))$identityIndexes[$key][$row[0]]=$row[1];
    }
    return preg_replace_callback('/\b(FROM|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?(?:\s+(?:AS\s+)?((?!(?:WHERE|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|ON|GROUP|ORDER|HAVING|LIMIT|UNION|FOR|USE|FORCE|IGNORE)\b)[a-zA-Z_][a-zA-Z0-9_]*))?(?=\s|$|\))/i',static function($m)use($tables,$key,$unit,$ordered,$identityIndexes,$scope){
        $name=$m[2];if(!isset($tables[$key][$name]))return $m[0];
        $base=$tables[$key][$name];
        $hint=$ordered&&$unit>0&&isset($identityIndexes[$key][$base])?' FORCE INDEX (`'.$identityIndexes[$key][$base].'`)':'';
        return $m[1].' (SELECT * FROM `'.$base.'`'.$hint.$scope.') '.($m[3]??$name);
    },$sql);
}

function hp_rows(mysqli $db,string $sql,array $args=[]): array {return lf_rows($db,hp_sql($db,$sql),$args);}
function hp_one(mysqli $db,string $sql,array $args=[]): ?array {return lf_one($db,hp_sql($db,$sql),$args);}

/** Covering hints are optional and only touch the verified scoped base projections. */
function hp_operator_sql(mysqli $db,string $sql): string {
    static $available=[];$key=hp_connection_key($db);
    if(!isset($available[$key]))$available[$key]=array_column($db->query("SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME IN ('idx_menu_journal_operator','idx_menu_payment_operator') GROUP BY TABLE_NAME,INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) IN ('unit_id,batch_id,source_operator,origin','unit_id,batch_id,source_operator')")->fetch_all(MYSQLI_ASSOC),'INDEX_NAME','TABLE_NAME');
    $sql=hp_sql($db,$sql);
    foreach($available[$key] as $table=>$index)$sql=str_replace('FROM `'.$table.'` WHERE','FROM `'.$table.'` FORCE INDEX (`'.$index.'`) WHERE',$sql);
    return $sql;
}
