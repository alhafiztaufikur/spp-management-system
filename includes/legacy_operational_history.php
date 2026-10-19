<?php
require_once __DIR__.'/legacy_operational_checks.php';
require_once __DIR__.'/legacy_operational_resolution.php';
/** Same classifier before/after publication; original rows stay in the finance archive. */
function lop_history_search(mysqli $db,array $job,array $input,bool $csv=false): array {
    foreach(['q','date_from','date_to','page','kind'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new DomainException('Filter histori tidak valid.');
    $kind=$input['kind']??'history';if(!in_array($kind,['history','unidentified','du_anomaly','zero_journal','all'],true))throw new DomainException('Jenis catatan tidak valid.');
    $q=trim($input['q']??'');$from=$input['date_from']??'';$to=$input['date_to']??'';$page=$input['page']??'1';
    if(mb_strlen($q)>100||!ctype_digit($page)||(int)$page<1||(int)$page>100000)throw new DomainException('Filter histori tidak valid.');
    foreach([$from,$to] as $date)if($date!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||legacy_source_record_date(['raw'=>['TANGGAL'=>$date]])===null))throw new DomainException('Tanggal filter tidak valid.');
    if($from!==''&&$to!==''&&$from>$to)throw new DomainException('Rentang tanggal terbalik.');
    $batch=lf_one($db,'SELECT source_id,manifest FROM legacy_full_batches WHERE id=? AND unit_id=?',[$job['id'],$job['unit']]);
    $cfg=$batch?(json_decode($batch['manifest'],true,512,JSON_THROW_ON_ERROR)['mapping']??[]):lful_draft($job['id'])['settings'];
    $unowned="(source_identity IS NULL OR TRIM(source_identity)='')";
    $negative="(source_table='bayar_du' AND JSON_UNQUOTE(JSON_EXTRACT(raw_data,'$.jumlah')) LIKE '-%')";
    $zero="(source_table IN ('transaksi_m','transaksi_k') AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_data,'$.MASUK')),'0') REGEXP '^(0([.]0+)?|null)$' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_data,'$.KELUAR')),'0') REGEXP '^(0([.]0+)?|null)$')";
    $gate=match($kind){'history','unidentified'=>$unowned,'du_anomaly'=>$negative,'zero_journal'=>$zero,default=>"($unowned OR $negative OR $zero)"};
    $source=$batch?lf_rows($db,'SELECT source_table,raw_data,row_key,amounts FROM legacy_finance_rows WHERE source_id=? AND unit_id=? AND '.$gate,[$batch['source_id'],$job['unit']]):null;
    $records=$source===null?lf_extract_stream($job):array_map(fn($r)=>['table'=>$r['source_table'],'raw'=>json_decode($r['raw_data'],true,512,JSON_THROW_ON_ERROR),'row_key'=>$r['row_key'],'money_columns'=>array_keys(json_decode($r['amounts'],true,512,JSON_THROW_ON_ERROR))],$source);
    $rows=[];foreach($records as $record){$history=lop_unowned_history($record);if(!$history&&$kind!=='history')$history=lop_separate_record($record,$cfg);if(!$history||($kind!=='all'&&$kind!=='history'&&$history['kind']!==$kind))continue;$date=$history['date']?substr($history['date'],0,10):'';
        if(($from!==''&&($date===''||$date<$from))||($to!==''&&($date===''||$date>$to))||($q!==''&&mb_stripos($record['table'].' '.lf_json($record['raw']),$q)===false))continue;
        $rows[]=$history+['raw'=>$record['raw']];
    }
    usort($rows,fn($a,$b)=>[$a['date'],$a['row_key']]<=>[$b['date'],$b['row_key']]);$total=count($rows);$page=min((int)$page,max(1,(int)ceil($total/25)));
    return ['rows'=>$csv?$rows:array_slice($rows,($page-1)*25,25),'total'=>$total,'page'=>$page];
}
