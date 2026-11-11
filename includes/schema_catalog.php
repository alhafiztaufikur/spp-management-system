<?php
/** One metadata snapshot for HTTP reads; installers and financial writes inspect fresh metadata. */
function schema_catalog(mysqli $db): array {
    static $cache=[];
    $read=PHP_SAPI!=='cli'&&($_SERVER['REQUEST_METHOD']??'')==='GET';
    $key=spl_object_id($db).':'.$db->thread_id;
    if($read&&isset($cache[$key]))return $cache[$key];
    $out=['tables'=>[],'columns'=>[],'foreign_keys'=>[],'triggers'=>[],'routines'=>[]];
    foreach($db->query('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $r)$out['tables'][$r['TABLE_NAME']]=$r['TABLE_TYPE'];
    foreach($db->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()') as $r)$out['columns'][$r['TABLE_NAME']][$r['COLUMN_NAME']]=$r;
    foreach($db->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL') as $r)$out['foreign_keys'][$r['TABLE_NAME']][$r['COLUMN_NAME']][]=$r;
    foreach($db->query('SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()') as $r)$out['triggers'][$r['TRIGGER_NAME']]=$r['ACTION_STATEMENT'];
    foreach($db->query('SELECT ROUTINE_NAME,ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()') as $r)$out['routines'][$r['ROUTINE_NAME']]=$r['ROUTINE_DEFINITION'];
    if($read)$cache[$key]=$out;
    return $out;
}
