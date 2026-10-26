<?php
require_once __DIR__.'/legacy_fee_trace.php';
require_once __DIR__.'/history_performance.php';

/** Bounded, scoped archive lookup for old and new receipts. Never writes on read. */
function lfet_hydrate_receipts(mysqli $db,array $payments): array {
    if(!$payments)return [];$groups=[];$raw=[];
    foreach($payments as $p)if(!empty($p['source_id'])&&!empty($p['row_key']))$groups[(int)$p['unit_id'].'|'.(int)$p['source_id']][$p['row_key']]=true;
    foreach($groups as $group=>$keys){[$unit,$source]=explode('|',$group);
        foreach(array_chunk(array_keys($keys),500) as $chunk){$marks=implode(',',array_fill(0,count($chunk),'?'));
            $projection="JSON_OBJECT('id',COALESCE(JSON_EXTRACT(r.raw_data,'$.id'),JSON_EXTRACT(r.raw_data,'$.ID')),'LAIN_LAIN1',JSON_EXTRACT(r.raw_data,'$.LAIN_LAIN1'),'LAIN_LAIN2',JSON_EXTRACT(r.raw_data,'$.LAIN_LAIN2'),'LAIN_LAIN3',JSON_EXTRACT(r.raw_data,'$.LAIN_LAIN3'),'LAIN_LAIN4',JSON_EXTRACT(r.raw_data,'$.LAIN_LAIN4'))";
            foreach(hp_rows($db,"SELECT r.unit_id,r.source_id,r.row_key,s.backup_hash,$projection raw_trace FROM legacy_finance_rows r JOIN legacy_finance_sources s ON s.id=r.source_id AND s.unit_id=r.unit_id WHERE r.unit_id=? AND r.source_id=? AND r.row_key IN ($marks)",array_merge([$unit,$source],$chunk)) as $r)$raw[$group.'|'.$r['row_key']]=['raw'=>json_decode($r['raw_trace'],true,512,JSON_THROW_ON_ERROR),'hash'=>$r['backup_hash']];
        }
    }
    foreach($payments as &$p){$source=$raw[$p['unit_id'].'|'.($p['source_id']??0).'|'.($p['row_key']??'')]??[];$p['source_hash']=$source['hash']??$p['source_hash']??$p['backup_hash']??'';$p['items']=lfet_receipt_items($p,$source['raw']??[]);}unset($p);
    return $payments;
}
