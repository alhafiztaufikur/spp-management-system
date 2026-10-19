<?php
require_once __DIR__.'/history_operator_filter.php';

/** The same evidence precedence as savings_enrich_rows; conflicting proof stays unknown. */
function savings_operator_owner_sql(string $unit,string $kind,string $id,string $user):string {
    $match="r.unit_id=$unit AND CONVERT(r.aksi USING utf8mb4) COLLATE utf8mb4_general_ci=CONVERT(CONCAT('tabungan_',LOWER($kind)) USING utf8mb4) COLLATE utf8mb4_general_ci AND r.referensi_id=$id";
    return "CASE WHEN EXISTS(SELECT 1 FROM keuangan_request r WHERE $match)
        THEN COALESCE((SELECT CASE WHEN COUNT(DISTINCT r.operator_id)=1 THEN MIN(r.operator_id) ELSE 0 END FROM keuangan_request r WHERE $match),0)
        WHEN CAST($user AS CHAR) REGEXP '^[1-9][0-9]*$' THEN CAST($user AS UNSIGNED) ELSE 0 END";
}
function savings_operator_where(array $operators,string $owner):string {
    if(filter_is_all($operators))return '';
    $ids=[];foreach($operators as $id){
        if(is_string($id)&&preg_match('/^legacy:[123]:[a-f0-9]*$/D',$id))continue;
        if($id!=='unknown'&&!preg_match('/^[1-9][0-9]*$/D',(string)$id))throw new InvalidArgumentException('Operator tidak valid.');
        $ids[]=$id==='unknown'?0:(int)$id;
    }
    if(!$ids)return ' AND 0=1';
    return ' AND ('.$owner.') IN ('.implode(',',$ids).')';
}
function savings_operator_options(mysqli $db,bool $showUsername=true):array {
    $options=[];$scope=(int)($GLOBALS['app_unit_id']??unit_active_id());
    static $names=[];$cacheKey=$db->thread_id.':'.$scope;
    if(!$showUsername&&isset($names[$cacheKey]))return $names[$cacheKey];
    $where=$scope===0?'':" WHERE unit_id=$scope OR role IN ('super_admin','admin','kasir')";
    foreach($db->query('SELECT id,nama,username FROM admin'.$where)->fetch_all(MYSQLI_ASSOC) as $a)$options[(string)$a['id']]=$a['nama'].($showUsername&&$a['username']?' (@'.$a['username'].')':'');
    foreach(['transaksi_m'=>'masuk','transaksi_k'=>'keluar'] as $table=>$kind){
        $owner=savings_operator_owner_sql('t.unit_id',"'$kind'",'t.id','t.user_id');
        foreach($db->query("SELECT DISTINCT ($owner) owner_id FROM $table t")->fetch_all(MYSQLI_ASSOC) as $r)if((int)$r['owner_id']>0)$options[(string)$r['owner_id']]??='Operator #'.$r['owner_id'];
    }
    if((int)$db->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tabungan_aktivitas'")->fetch_row()[0]===1){
        foreach($db->query("SELECT actor_id,actor_name,actor_username FROM tabungan_aktivitas WHERE action IN ('created','baseline') AND actor_id>0 ORDER BY occurred_at,id")->fetch_all(MYSQLI_ASSOC) as $r){
            $key=(string)$r['actor_id'];if(!isset($options[$key])||str_starts_with($options[$key],'Operator #'))$options[$key]=($r['actor_name']?:'Operator #'.$key).($showUsername&&$r['actor_username']?' (@'.$r['actor_username'].')':'');
        }
        $user="JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.transaction.user_id'))";
        $owner=savings_operator_owner_sql('a.unit_id','a.jenis','a.transaction_id',$user);
        foreach($db->query("SELECT DISTINCT ($owner) owner_id FROM tabungan_aktivitas a WHERE a.action='deleted'")->fetch_all(MYSQLI_ASSOC) as $r)if((int)$r['owner_id']>0)$options[(string)$r['owner_id']]??='Operator #'.$r['owner_id'];
    }
    if(function_exists('lful_operator_options'))$options+=lful_operator_options($db);
    natcasesort($options);$options['unknown']='Tidak tercatat';if(!$showUsername)$names[$cacheKey]=$options;return $options;
}
