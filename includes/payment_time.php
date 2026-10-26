<?php
/** Evidence-backed read times. The immutable archive and transaction rows are never rewritten. */
require_once __DIR__.'/date_format.php';
require_once __DIR__.'/history_performance.php';

function payment_time_archive(array $raw): array {
    $value=$raw['TGL_BYR']??null;
    // SQL Server dates were extracted as ISO with seven fractional digits.
    // Its midnight date padding is separate from the original `jam` column.
    $normalized=is_string($value)?preg_replace('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?$/D','$1 $2',$value):$value;
    $date=spp_date_value($normalized);
    if(!$date)return ['original'=>null,'value'=>null,'precision'=>'none','conflict'=>false];
    $original=$date->format('Y-m-d H:i:s');$embedded=is_string($value)&&preg_match('/[ T]\d{2}:\d{2}:\d{2}/D',$value)&&(!array_key_exists('jam',$raw)||$date->format('H:i:s')!=='00:00:00');
    $clock=$raw['jam']??null;$parsed=null;
    if(is_string($clock)){
        $clock=preg_replace('/\s+/',' ',strtoupper(trim($clock)));
        if(preg_match('/^(?:0?[1-9]|1[0-2]):[0-5][0-9]:[0-5][0-9] [AP]M$/D',$clock))$parsed=DateTimeImmutable::createFromFormat('!g:i:s A',$clock,new DateTimeZone('Asia/Jakarta'))?:null;
        elseif(preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D',$clock))$parsed=DateTimeImmutable::createFromFormat('!H:i:s',$clock,new DateTimeZone('Asia/Jakarta'))?:null;
    }
    $conflict=$embedded&&$parsed&&$date->format('H:i:s')!==$parsed->format('H:i:s');
    return ['original'=>$original,'value'=>$embedded?$original:($parsed?$date->format('Y-m-d').' '.$parsed->format('H:i:s'):$original),'precision'=>$embedded||$parsed?'datetime':'date','conflict'=>(bool)$conflict];
}
function &payment_time_cache(): array {static $cache=[];return $cache;}
function payment_time_invalidate(mysqli $db): void {$cache=&payment_time_cache();unset($cache[$db->thread_id],$GLOBALS['journal_time_cache'][$db->thread_id]);}

/** SQL Server savings archives contain dates without a separate clock column. */
function journal_time_overrides(mysqli $db):array {
    if(!function_exists('lop_ready')||!lop_ready($db))return [];
    $scope=(int)($GLOBALS['app_unit_id']??unit_active_id());$thread=$db->thread_id;
    if(PHP_SAPI==='cli'||!isset($GLOBALS['journal_time_cache'][$thread][$scope])){
        $overrides=[];
        foreach(hp_rows($db,"SELECT entity_id,before_data,after_data FROM legacy_op_audit WHERE entity_type='journal' ORDER BY id") as $event){
            $before=json_decode($event['before_data'],true);$after=json_decode($event['after_data'],true);
            if(isset($after['_time_precision']))$overrides[(int)$event['entity_id']]=$after['_time_precision'];
            elseif(($before['source_date']??null)!==($after['source_date']??null))$overrides[(int)$event['entity_id']]=empty($after['source_date'])?'none':'datetime';
        }
        $GLOBALS['journal_time_cache'][$thread][$scope]=$overrides;
    }
    return $GLOBALS['journal_time_cache'][$thread][$scope];
}
function journal_time_precision(mysqli $db,int $id,string $origin,?string $stamp,?array $overrides=null):string {
    if(!$stamp)return 'none';
    if($id>0||in_array($origin,['native','new','adjustment'],true))return 'datetime';
    if($id<-1000000000){$override=($overrides??journal_time_overrides($db))[-$id-1000000000]??null;if($override!==null)return $override;}
    return substr($stamp,11,8)==='00:00:00'?'date':'datetime';
}

function payment_time_archive_cache(mysqli $db,int $scope,array $batches,?array $write=null): ?array {
    require_once __DIR__.'/report_artifact.php';$root=report_artifact_root();if(!$root)return null;
    $database=$db->query('SELECT @@server_uuid,DATABASE(),@@port')->fetch_row();
    $path=$root.'/payment-clocks-'.hash('sha256',json_encode([$database,$scope])).'.json';
    $revision=hash('sha256','archive-clocks-v2:'.json_encode($batches,JSON_THROW_ON_ERROR));
    if(is_link($path))return null;
    if($write===null){
        if(!is_file($path)||filesize($path)>2097152)return null;
        $entry=json_decode((string)@file_get_contents($path),true);
        if(!is_array($entry)||($entry['revision']??'')!==$revision||($entry['created']??0)<time()-900||!is_array($entry['index']??null))return null;
        if(!hash_equals((string)($entry['digest']??''),hash('sha256',json_encode($entry['index'],JSON_THROW_ON_ERROR))))return null;
        return $entry['index'];
    }
    $bytes=json_encode(['revision'=>$revision,'created'=>time(),'digest'=>hash('sha256',json_encode($write,JSON_THROW_ON_ERROR)),'index'=>$write],JSON_THROW_ON_ERROR);
    $temp=@tempnam($root,'.clock-');if(!$temp)return null;
    try{if(@file_put_contents($temp,$bytes)!==false)@rename($temp,$path);}finally{if(is_file($temp))@unlink($temp);}
    return $write;
}

/** A small immutable clock index, shared by all projections in this request. */
function payment_time_index(mysqli $db): array {
    $cache=&payment_time_cache();$scope=(int)$db->query('SELECT current_unit_id()')->fetch_row()[0];$thread=$db->thread_id;
    if(isset($cache[$thread][$scope]))return $cache[$thread][$scope];
    if($scope>0&&isset($cache[$thread][0]))return $cache[$thread][$scope]=array_filter($cache[$thread][0],static fn($r)=>(int)$r['unit_id']===$scope);
    $index=[];$batches=[];
    foreach(hp_rows($db,"SELECT b.id,b.unit_id,b.source_id,b.completed_at,s.backup_hash,s.fingerprint,s.row_count FROM legacy_full_batches b JOIN legacy_finance_sources s ON s.id=b.source_id AND s.unit_id=b.unit_id WHERE b.state='completed' ORDER BY b.unit_id,b.id") as $b)$batches[$b['id']]=$b;
    if(!$batches)return $cache[$thread][$scope]=[];
    $clauses=[];foreach($batches as $b)$clauses[]='(r.unit_id='.(int)$b['unit_id'].' AND r.source_id='.(int)$b['source_id'].')';
    $archive=payment_time_archive_cache($db,$scope,$batches);
    if($archive!==null)$index=$archive;
    else {foreach(hp_rows($db,"SELECT r.unit_id,r.source_id,r.row_key,r.raw_data FROM legacy_finance_rows r WHERE (".implode(' OR ',$clauses).") AND r.source_table='bayar' AND (JSON_TYPE(JSON_EXTRACT(r.raw_data,'$.jam'))='STRING' OR (JSON_UNQUOTE(JSON_EXTRACT(r.raw_data,'$.TGL_BYR')) REGEXP '[ T][0-9]{2}:[0-9]{2}:[0-9]{2}' AND (JSON_CONTAINS_PATH(r.raw_data,'one','$.jam')=0 OR SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(r.raw_data,'$.TGL_BYR')),12,8)<>'00:00:00')))") as $r){
        $e=payment_time_archive(json_decode($r['raw_data'],true,512,JSON_THROW_ON_ERROR));
        if($e['precision']!=='datetime'&&!$e['conflict'])continue;
        $key=$r['unit_id'].'|'.$r['source_id'].'|'.$r['row_key'];
        $index[$key]=['unit_id'=>(int)$r['unit_id'],'source_id'=>(int)$r['source_id'],'row_key'=>$r['row_key'],'original'=>$e['original'],'archive_at'=>$e['value'],'override_precision'=>null,'updated_at'=>null,'conflict'=>$e['conflict']];
    }payment_time_archive_cache($db,$scope,$batches,$index);
    if($scope===0)foreach(array_unique(array_column($batches,'unit_id')) as $unit){
        $unit=(int)$unit;payment_time_archive_cache($db,$unit,array_filter($batches,static fn($b)=>(int)$b['unit_id']===$unit),array_filter($index,static fn($r)=>(int)$r['unit_id']===$unit));
    }}
    // Only applied corrections can override the original clock or supply an updated time.
    foreach(lop_ready($db)?hp_rows($db,"SELECT a.id,a.unit_id,a.before_data,a.after_data,a.created_at,p.row_key,b.source_id FROM legacy_op_audit a JOIN legacy_op_payments p ON p.id=a.entity_id AND p.unit_id=a.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE a.entity_type='payment' AND b.state='completed' ORDER BY a.id"):[] as $a){
        $before=json_decode($a['before_data'],true,512,JSON_THROW_ON_ERROR);$after=json_decode($a['after_data'],true,512,JSON_THROW_ON_ERROR);
        $key=$a['unit_id'].'|'.$a['source_id'].'|'.$a['row_key'];
        $index[$key]??=['unit_id'=>(int)$a['unit_id'],'source_id'=>(int)$a['source_id'],'row_key'=>$a['row_key'],'original'=>null,'archive_at'=>null,'override_precision'=>null,'updated_at'=>null,'conflict'=>false];
        $index[$key]['updated_at']=$a['created_at'];
        if(array_key_exists('_time_precision',$after))$index[$key]['override_precision']=$after['_time_precision'];
        elseif(($before['source_date']??null)!==($after['source_date']??null))$index[$key]['override_precision']=empty($after['source_date'])?'none':'datetime';
    }
    return $cache[$thread][$scope]=$index;
}
function payment_time_projection(mysqli $db,string $p='p',string $b='b',string $alias='pt'): array {
    foreach([$p,$b,$alias] as $name)if(!preg_match('/^[a-z_][a-z0-9_]*$/D',$name))throw new LogicException('Invalid time projection alias.');
    $json=json_encode(array_values(payment_time_index($db)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$literal="CONVERT(UNHEX('".bin2hex($json)."') USING utf8mb4)";
    $table="JSON_TABLE($literal,'$[*]' COLUMNS(unit_id INT PATH '$.unit_id',source_id BIGINT PATH '$.source_id',row_key CHAR(64) PATH '$.row_key',original DATETIME PATH '$.original' NULL ON EMPTY NULL ON ERROR,archive_at DATETIME PATH '$.archive_at' NULL ON EMPTY NULL ON ERROR,override_precision VARCHAR(8) PATH '$.override_precision' NULL ON EMPTY,updated_at DATETIME PATH '$.updated_at' NULL ON EMPTY))";
    $use="$alias.override_precision IS NULL AND $alias.archive_at IS NOT NULL AND $p.source_date=$alias.original";
    return ['join'=>" LEFT JOIN (SELECT DISTINCT * FROM $table clock_values) $alias ON $alias.unit_id=$p.unit_id AND $alias.source_id=$b.source_id AND $alias.row_key=$p.row_key ",
        'value'=>"CASE WHEN $use THEN $alias.archive_at ELSE $p.source_date END",
        'precision'=>"CASE WHEN $p.source_date IS NULL THEN 'none' WHEN $alias.override_precision IS NOT NULL THEN $alias.override_precision WHEN $use OR TIME($p.source_date)<>'00:00:00' THEN 'datetime' ELSE 'date' END",
        'updated'=>"$alias.updated_at"];
}
function payment_time_resolve(?string $stored,array $evidence=[]): array {
    if(!$stored)return ['payment_datetime'=>null,'time_precision'=>'none'];
    if(isset($evidence['override_precision']))return ['payment_datetime'=>$stored,'time_precision'=>$evidence['override_precision']];
    if(!empty($evidence['archive_at'])&&$stored===($evidence['original']??null))return ['payment_datetime'=>$evidence['archive_at'],'time_precision'=>'datetime'];
    return ['payment_datetime'=>$stored,'time_precision'=>substr($stored,11)!=='00:00:00'?'datetime':'date'];
}
function payment_time_record(mysqli $db,array $p): array {
    $key=$p['unit_id'].'|'.$p['source_id'].'|'.$p['row_key'];$e=payment_time_index($db)[$key]??[];
    return payment_time_resolve($p['source_date'],$e)+['updated_at'=>$e['updated_at']??null];
}

/** Preserve real midnight: precision is explicit, never guessed from a formatted zero. */
function payment_time_parts(array $row,string $column='TGL_BYR'): array {
    $value=$row[$column]??$row['payment_datetime']??null;$parts=spp_date_parts($value);
    $precision=$row['time_precision']??($value?'datetime':'none');
    if($precision==='none'||!spp_date_value($value))return ['date'=>'Tanggal tidak tercatat','time'=>'Jam tidak tercatat'];
    if($precision==='date')$parts['time']='Jam tidak tercatat';
    return $parts;
}
function payment_time_label(array $row,string $column='TGL_BYR'): string {$parts=payment_time_parts($row,$column);return $parts['date'].' · '.$parts['time'];}
function payment_time_native_update(string $input,string $stored): string {
    $input=trim($input);if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$input))$input.=' '.substr($stored,11,8);
    $date=spp_date_value($input);if(!$date)throw new DomainException('Tanggal pembayaran tidak valid.');return $date->format('Y-m-d H:i:s');
}
/** The displayed archive clock is not an implicit correction when other fields change. */
function payment_time_edit_input(array $input,array $stored,array $display): array {
    if(!array_key_exists('date_day',$input))return ['date'=>$input['date']??$stored['source_date']];
    if(!is_string($input['date_day'])||!is_string($input['date_clock']??''))throw new DomainException('Tanggal atau jam tidak valid.');
    $day=trim($input['date_day']);$clock=trim($input['date_clock']??'');
    if(preg_match('/^\d{2}:\d{2}$/D',$clock))$clock.=':00';
    $beforeDay=substr((string)($display['TGL_BYR']??''),0,10);$beforeClock=($display['time_precision']??'none')==='datetime'?substr($display['TGL_BYR'],11,8):'';
    if($day===$beforeDay&&$clock===$beforeClock)return ['date'=>$stored['source_date']];
    if($day===''){if($clock!=='')throw new DomainException('Pilih tanggal sebelum mengisi jam.');return ['date'=>null,'time_precision'=>'none'];}
    $date=spp_date_value($day);if(!$date||$date->format('Y-m-d')!==$day)throw new DomainException('Tanggal pembayaran tidak valid.');
    if($clock==='')return ['date'=>$day,'time_precision'=>'date'];
    if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D',$clock))throw new DomainException('Jam pembayaran tidak valid.');
    return ['date'=>$day.' '.$clock,'time_precision'=>'datetime'];
}
