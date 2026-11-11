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
    $pattern='/\b(FROM|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?(?:\s+(?:AS\s+)?((?!(?:WHERE|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|ON|GROUP|ORDER|HAVING|LIMIT|UNION|FOR|USE|FORCE|IGNORE)\b)[a-zA-Z_][a-zA-Z0-9_]*))?(?=\s|$|\))/i';
    // Callers may already have projected a query. With no scoped view left,
    // the callback below is an identity operation; avoid another scope query.
    preg_match_all($pattern,$sql,$references);
    if(!array_intersect($references[2],array_keys($tables[$key])))return $sql;
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
$r=$db->query("SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND IS_VISIBLE='YES' GROUP BY TABLE_NAME,INDEX_NAME HAVING COUNT(*)=2 AND COUNT(COLUMN_NAME)=2 AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='unit_id,id'");
        while($row=$r->fetch_row())if(preg_match('/^[a-zA-Z0-9_]+$/D',$row[1]))$identityIndexes[$key][$row[0]]=$row[1];
    }
    return preg_replace_callback($pattern,static function($m)use($tables,$key,$unit,$ordered,$identityIndexes,$scope){
        $name=$m[2];if(!isset($tables[$key][$name]))return $m[0];
        $base=$tables[$key][$name];
        $hint=$ordered&&$unit>0&&isset($identityIndexes[$key][$base])?' FORCE INDEX (`'.$identityIndexes[$key][$base].'`)':'';
        return $m[1].' (SELECT * FROM `'.$base.'`'.$hint.$scope.') '.($m[3]??$name);
    },$sql);
}

function hp_rows(mysqli $db,string $sql,array $args=[]): array {return lf_rows($db,hp_sql($db,$sql),$args);}
function hp_one(mysqli $db,string $sql,array $args=[]): ?array {return lf_one($db,hp_sql($db,$sql),$args);}

/** Keep repeated JSON_TABLE documents out of prepared SQL text. These variables
 * belong to this connection only; distinct documents/scopes cannot overwrite one
 * another when a CLI reader switches units or retains an earlier SQL projection.
 */
function hp_json_document(mysqli $db,array $document): string {
    static $loaded=[];$json=json_encode($document,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $name='@spp_read_'.substr(hash('sha256',hp_connection_key($db).':'.$json),0,48);
    if(!isset($loaded[$name])){lf_query($db,'SET '.$name.'=?',[$json]);$loaded[$name]=true;}
    return $name;
}

function hp_json_category_candidate(string $json,string $needle): string {
    if(!preg_match('/^[a-z]+\.(items|before_data|after_data)$/D',$json)||!in_array($needle,['biaya_lain:','daftar_ulang'],true))throw new LogicException('Invalid category candidate.');
    $path=str_ends_with($json,'.items')?'$[*].category':'$.items[*].category';
    $cats="JSON_UNQUOTE(JSON_EXTRACT($json,'$path'))";
    return "REGEXP_LIKE($cats,CONCAT('$needle|[^ -~]|',CHAR(92),CHAR(92)),'i')";
}

/** Whole-scope scans avoid secondary-index row lookups for JSON/operator columns.
 * Bounded identity/date readers keep their ordinary index selection.
 */
function hp_scan_sql(mysqli $db,string $sql): string {
    static $ordered=[];$key=hp_connection_key($db);$sql=hp_sql($db,$sql);
    if(!isset($ordered[$key]))$ordered[$key]=array_column($db->query("SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND IS_VISIBLE='YES' GROUP BY TABLE_NAME,INDEX_NAME HAVING COUNT(*)=2 AND COUNT(COLUMN_NAME)=2 AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='unit_id,id'")->fetch_all(MYSQLI_ASSOC),'INDEX_NAME','TABLE_NAME');
    foreach(['legacy_op_payments_data','legacy_op_journals_data'] as $table){
        $scope=(int)($GLOBALS['app_unit_id']??unit_active_id());
        $index=$scope>0?($ordered[$key][$table]??'PRIMARY'):'PRIMARY';
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$index))throw new LogicException('Invalid scan index.');
        $sql=str_replace('FROM `'.$table.'` WHERE','FROM `'.$table.'` FORCE INDEX (`'.$index.'`) WHERE',$sql);
    }
    return $sql;
}

function hp_completed_batches(mysqli $db): array {
    static $cache=[];$key=hp_connection_key($db).':'.(int)($GLOBALS['app_unit_id']??unit_active_id());
    $read=PHP_SAPI!=='cli'&&($_SERVER['REQUEST_METHOD']??'')==='GET';
    if($read&&isset($cache[$key]))return $cache[$key];
    $rows=hp_rows($db,"SELECT id,unit_id FROM legacy_full_batches WHERE state='completed'");
    if($read)$cache[$key]=$rows;return $rows;
}

function hp_completed_batch_predicate(mysqli $db,string $alias): string {
    if(!preg_match('/^[a-z]+$/D',$alias))throw new LogicException('Invalid batch alias.');
    $units=[];foreach(hp_completed_batches($db) as $r)$units[(int)$r['unit_id']][]="'".$db->real_escape_string($r['id'])."'";
    $parts=[];foreach($units as $unit=>$ids)$parts[]="($alias.unit_id=$unit AND $alias.batch_id IN (".implode(',',$ids).'))';
    return '('.(implode(' OR ',$parts)?:'FALSE').')';
}

/** Covering hints are optional and only touch the verified scoped base projections. */
function hp_operator_sql(mysqli $db,string $sql): string {
    static $available=[];$key=hp_connection_key($db);
    if(!isset($available[$key]))$available[$key]=array_column($db->query("SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND IS_VISIBLE='YES' AND INDEX_NAME IN ('idx_menu_journal_operator','idx_menu_payment_operator','idx_history_journal_operator','idx_history_payment_operator') GROUP BY TABLE_NAME,INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) IN ('unit_id,batch_id,source_operator,origin','unit_id,batch_id,source_operator','unit_id,batch_id,source_operator,cancelled') ORDER BY INDEX_NAME DESC")->fetch_all(MYSQLI_ASSOC),'INDEX_NAME','TABLE_NAME');
    $sql=hp_sql($db,$sql);
    foreach($available[$key] as $table=>$index)$sql=str_replace('FROM `'.$table.'` WHERE','FROM `'.$table.'` FORCE INDEX (`'.$index.'`) WHERE',$sql);
    return $sql;
}
