<?php
require_once __DIR__.'/legacy_operational_checks.php';
require_once __DIR__.'/legacy_operational_resolution.php';
require_once __DIR__.'/history_performance.php';
/** Completed publications already record every separated reference by immutable row key. */
function lop_history_reference_keys(array $manifest): ?array {
    $checks=$manifest['automatic_checks']??[];$summary=$manifest['summary']??[];
    if(($manifest['mode']??'')!=='operational'||!in_array($checks['rules_version']??null,[7,9,10,11],true))return null;
    $keys=[];
    foreach(['references'=>'separate_references','accepted_history'=>'accepted_history'] as $list=>$count){
        if(!isset($checks[$list],$summary[$count])||!is_array($checks[$list])||count($checks[$list])!==(int)$summary[$count])return null;
        foreach($checks[$list] as $key=>$row){
            if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||($row['row_key']??null)!==$key||isset($keys[$key]))return null;
            $keys[$key]=true;
        }
    }
    return array_keys($keys);
}
/** Published SQL archives remain readable after restoring onto a different computer. */
function lop_history_job(mysqli $db,string $id): array {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Pekerjaan tidak valid.',400);
    $batch=hp_one($db,"SELECT id,unit_id FROM legacy_full_batches WHERE id=? AND state='completed'",[$id]);
    if($batch)return ['id'=>$batch['id'],'unit'=>(int)$batch['unit_id'],'category'=>'full','state'=>'completed','published'=>true];
    return legacy_job($id);
}
/** Same classifier before/after publication; original rows stay in the finance archive. */
function lop_history_search(mysqli $db,array $job,array $input,bool $csv=false): array {
    foreach(['q','date_from','date_to','page','kind'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new DomainException('Filter histori tidak valid.');
    $kind=$input['kind']??'history';if(!in_array($kind,['history','unidentified','du_anomaly','zero_journal','du_reconciled','du_unresolved','all'],true))throw new DomainException('Jenis catatan tidak valid.');
    $q=trim($input['q']??'');$from=$input['date_from']??'';$to=$input['date_to']??'';$page=$input['page']??'1';
    if(mb_strlen($q)>100||!ctype_digit($page)||(int)$page<1||(int)$page>100000)throw new DomainException('Filter histori tidak valid.');
    foreach([$from,$to] as $date)if($date!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||legacy_source_record_date(['raw'=>['TANGGAL'=>$date]])===null))throw new DomainException('Tanggal filter tidak valid.');
    if($from!==''&&$to!==''&&$from>$to)throw new DomainException('Rentang tanggal terbalik.');
    $batch=hp_one($db,'SELECT source_id,manifest,state FROM legacy_full_batches WHERE id=? AND unit_id=?',[$job['id'],$job['unit']]);
    $manifest=$batch?json_decode($batch['manifest'],true,512,JSON_THROW_ON_ERROR):[];
    $cfg=$batch?($manifest['mapping']??[]):lful_draft($job['id'])['settings'];
    $classifiedRefs=lma_enabled($cfg)?($manifest['automatic_checks']['references']??[]):[];
    if(!$batch&&lma_enabled($cfg)){$path=legacy_root().'/'.$job['id'].'/operational-preview.json';if(is_file($path)){$preview=json_decode(file_get_contents($path),true);$draft=lful_draft($job['id']);if(($preview['rules']??0)===LOP_VERSION&&($preview['version']??0)===(int)$draft['version'])$classifiedRefs=$preview['preview']['references']??[];}}
    $unowned="(source_identity IS NULL OR TRIM(source_identity)='')";
    // Select candidates from the small archived amount metadata. Loading every
    // full raw payload here causes unnecessary disk reads on large SQL restores.
    // The original raw record remains authoritative in the classifier below.
    $negative="(source_table='bayar_du' AND (JSON_UNQUOTE(JSON_EXTRACT(amounts,'$.jumlah')) LIKE '-%' OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(amounts,'$.jumlah')),'') IN ('','null')))";
    $zero="(source_table IN ('transaksi_m','transaksi_k') AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(amounts,'$.MASUK')),'0') REGEXP '^(0([.]0+)?|null)$' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(amounts,'$.KELUAR')),'0') REGEXP '^(0([.]0+)?|null)$')";
    $unowned="(source_table IN ('bayar','bayar_du','tabungan','transaksi_m','transaksi_k','tb_total','tb_masuk','tb_keluar','tbg') AND $unowned)";
    $gates=match($kind){'history','unidentified'=>[$unowned],'du_anomaly'=>[$negative],'zero_journal'=>[$zero],default=>[$unowned,$negative,$zero]};
    if(lma_enabled($cfg)&&in_array($kind,['du_reconciled','du_unresolved','all'],true))$gates[]="source_table='bayar_du'";
    $source=null;
    if($batch){
        $keys=$batch['state']==='completed'?lop_history_reference_keys($manifest):null;
        if($keys!==null){
            $source=$keys?hp_rows($db,'SELECT source_table,raw_data,row_key,amounts FROM legacy_finance_rows WHERE source_id=? AND unit_id=? AND row_key IN ('.implode(',',array_fill(0,count($keys),'?')).')',array_merge([$batch['source_id'],$job['unit']],$keys)):[];
            if(count($source)!==count($keys))$source=null;
        }
    }
    if($batch&&$source===null){
        // Independent branches use the existing identity/table indexes. Union
        // their IDs before reading large raw payloads; overlapping references
        // still appear exactly once.
        $parts=[];$args=[];
        foreach($gates as $gate){$parts[]='SELECT id FROM legacy_finance_rows WHERE source_id=? AND unit_id=? AND '.$gate;array_push($args,$batch['source_id'],$job['unit']);}
        $source=hp_rows($db,'SELECT r.source_table,r.raw_data,r.row_key,r.amounts FROM ('.implode(' UNION ',$parts).') candidate JOIN legacy_finance_rows r ON r.id=candidate.id',$args);
    }
    $records=$source===null?lf_extract_stream($job):array_map(fn($r)=>['table'=>$r['source_table'],'raw'=>json_decode($r['raw_data'],true,512,JSON_THROW_ON_ERROR),'row_key'=>$r['row_key'],'money_columns'=>array_keys(json_decode($r['amounts'],true,512,JSON_THROW_ON_ERROR))],$source);
    $rows=[];foreach($records as $record){$history=lop_unowned_history($record);if(!$history&&$kind!=='history')$history=$classifiedRefs[$record['row_key']]??lop_separate_record($record,$cfg);if(!$history||($kind!=='all'&&$kind!=='history'&&$history['kind']!==$kind))continue;$date=$history['date']?substr($history['date'],0,10):'';
        if(($from!==''&&($date===''||$date<$from))||($to!==''&&($date===''||$date>$to))||($q!==''&&mb_stripos($record['table'].' '.lf_json($record['raw']),$q)===false))continue;
        $rows[]=$history+['raw'=>$record['raw']];
    }
    usort($rows,fn($a,$b)=>[$a['date'],$a['row_key']]<=>[$b['date'],$b['row_key']]);$total=count($rows);$page=min((int)$page,max(1,(int)ceil($total/25)));
    return ['rows'=>$csv?$rows:array_slice($rows,($page-1)*25,25),'total'=>$total,'page'=>$page];
}
