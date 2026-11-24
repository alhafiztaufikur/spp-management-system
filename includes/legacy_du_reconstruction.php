<?php
require_once __DIR__.'/legacy_migration_automation.php';
require_once __DIR__.'/legacy_full_components.php';
require_once __DIR__.'/other_fee_usage.php';

/** Reclassify only direct source evidence; no cash, dates, owners or receipt amounts change. */
function ldu_reclassify_items(array $payment,array $source,array $context,bool $corrected=false): array {
    if($corrected)return $payment['items'];$out=$payment['items'];$year=lma_year((string)($source['raw']['th_ajaran']??''));
    if($year==='')return $out;
    foreach($out as &$item){
        if($item['category']==='daftar_ulang'||in_array($item['category'],['psb','spp','komite'],true)||!empty($item['obligation_id'])||!empty($item['migration']['correction_preserved']))continue;
        $column=$item['column'];$decision=$context['fees'][$source['row_key']][$column]??null;
        $activity=$column==='U_KEGIATAN'&&isset($context['du'][lma_du_key($source)]);
        if(!$activity&&($decision['category']??'')!=='daftar_ulang')continue;
        if(lf_cents($source['raw'][$column]??'0')!==lf_cents($item['amount']))continue;
        $item['category']='daftar_ulang';$item['label']='Daftar Ulang';
        $item['migration']=array_replace($item['migration']??[],['version'=>LMA_VERSION,'period'=>$year,'source_row_key'=>$source['row_key'],'source_record_id'=>lfet_source_id($source['raw']),'source_unit_id'=>$context['unit_id'],'source_key'=>$context['source_key'],'status'=>'direct','rule'=>$activity?'du_recap_reconciled':'du_source_fee','du_trace_version'=>LDU_VERSION,'du_grade'=>trim((string)($source['raw']['kelas_du']??''))]);
        if(lfet_is_detail($item))$item['migration']=array_replace($item['migration'],lfet_metadata($source,$context,$column,$decision,$item['category']));
        $item=lfet_resolve_item($item);
    }unset($item);return $out;
}
function ldu_reconstruct_payments(mysqli $db,int $actor,?array &$pendingBatches=null): array {
    require_once __DIR__.'/legacy_full.php'; // The GUI must load the same source helpers as the runner.
    $summary=['changes'=>0,'receipts'=>0,'items'=>0,'amount_cents'=>0,'corrections_preserved'=>0];
    foreach(hp_rows($db,"SELECT b.id,b.unit_id,b.source_id,s.backup_hash FROM legacy_full_batches b JOIN legacy_finance_sources s ON s.id=b.source_id AND s.unit_id=b.unit_id WHERE b.state IN ('completed','verifying')") as $b){
        $payments=[];$wanted=[];
        $query="SELECT p.id,p.version,p.row_key,p.items FROM legacy_op_payments p WHERE p.batch_id=? AND (JSON_SEARCH(p.items,'one','U_KEGIATAN',NULL,'$[*].column') IS NOT NULL OR JSON_SEARCH(p.items,'one','%Daftar%Ulang%',NULL,'$[*].label') IS NOT NULL OR JSON_SEARCH(p.items,'one','DU',NULL,'$[*].label') IS NOT NULL)";
        foreach(lf_stream_rows($db,hp_sql($db,$query,true),[$b['id']]) as $p){
            foreach(json_decode($p['items'],true) as $i)if($i['category']!=='daftar_ulang'&&($i['column']==='U_KEGIATAN'||preg_match('/daftar\s*ulang|^DU$/iu',$i['label']))){
                $payments[(int)$p['id']]=(int)$p['version'];$wanted[$p['row_key']]=true;break;
            }
        }
        if(!$payments)continue;
        $sources=[];
        $record=static fn(array $r)=>['table'=>$r['source_table'],'raw'=>json_decode($r['raw_data'],true),'row_key'=>$r['row_key'],
            'source_id'=>(int)$b['source_id'],'unit_id'=>(int)$b['unit_id']];
        $scope=[$b['unit_id'],$b['source_id']];
        foreach(array_chunk(array_keys($wanted),250) as $keys){
            $marks=implode(',',array_fill(0,count($keys),'?'));
            $sql="SELECT source_table,row_key,raw_data,amounts,reference_only FROM legacy_finance_rows
                WHERE unit_id=? AND source_id=? AND source_table='bayar' AND row_key IN ($marks)";
            foreach(lf_stream_rows($db,hp_sql($db,$sql,true),array_merge($scope,$keys)) as $r){
                $sources[$r['row_key']]=$record($r)+['money_columns'=>array_keys(json_decode($r['amounts'],true)??[]),'reference_only'=>(bool)$r['reference_only']];
            }
        }
        $read=static function()use($db,$scope,$record): Generator {
            $sql="SELECT source_table,row_key,raw_data FROM legacy_finance_rows
                WHERE unit_id=? AND source_id=? AND source_table IN ('siswa','lain','bayar','bayar_du') ORDER BY id";
            foreach(lf_stream_rows($db,hp_sql($db,$sql,true),$scope) as $r)yield $record($r);
        };
        $context=lma_context($read,(int)$b['unit_id'],$b['backup_hash'],$sources);
        $beforeChanges=$summary['changes'];
        foreach($payments as $id=>$version){
            $p=hp_one($db,'SELECT * FROM legacy_op_payments WHERE id=? FOR UPDATE',[$id]);
            if(!$p||(int)$p['version']!==$version)throw new DomainException('Transaksi DU berubah; ulangi simulasi.');
            $source=$sources[$p['row_key']]??null;if(!$source)continue;
            $corrected=(bool)hp_one($db,"SELECT id FROM legacy_op_audit WHERE entity_type='payment' AND entity_id=? LIMIT 1",[$id]);
            $p['items']=json_decode($p['items'],true);
            if($corrected){$summary['corrections_preserved']++;continue;}$next=ldu_reclassify_items($p,$source,$context);
            if($next===$p['items'])continue;lfet_validate_payment(array_replace($p,['items'=>$next]));other_fee_lock_items($db,(int)$b['unit_id'],array_merge($p['items'],$next));
            foreach($next as $index=>$item)if($item['category']!==$p['items'][$index]['category']){$summary['items']++;$summary['amount_cents']+=lf_cents($item['amount']);}
            lf_query($db,'UPDATE legacy_op_payments SET items=?,version=version+1 WHERE id=? AND version=?',[lf_json($next),$p['id'],$p['version']]);
            ldu_audit($db,$actor,'du_payment',(int)$p['id'],['items'=>$p['items']],['items'=>$next],['unit_id'=>$b['unit_id']]);$summary['changes']++;$summary['receipts']++;
            ldu_sync_owner_trace($db,(int)$p['owner_id'],$actor);
        }
        unset($sources,$context,$p,$source,$next);
        if($summary['changes']!==$beforeChanges){
            if($pendingBatches!==null)$pendingBatches[]=$b['id'];
            else lb_refresh_manifests($db,[$b['id']]);
        }
    }
    lb_cache($db,refresh:true,invalidateOnly:true);return $summary;
}
