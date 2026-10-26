<?php
require_once __DIR__.'/legacy_migration_automation.php';
require_once __DIR__.'/other_fee_usage.php';

function lfer_needs_enrichment(array $payment,string $sourceKey): bool {
    foreach($payment['items'] as $item)if(lfet_is_detail($item)){
        $meta=$item['migration']??[];
        if(($meta['trace_version']??0)!==LEGACY_FEE_TRACE_VERSION||(!lma_metadata_supported($meta['version']??0)||($meta['version']??0)<3)||($meta['source_key']??null)!==$sourceKey)return true;
        try{lfet_validate_payment($payment);}catch(DomainException $e){return true;}
    }
    return false;
}

/** Pure reconciliation: preserve cash, cancelled state and all user corrections. */
function lfer_enrich_payment(array $payment,array $source,array $cfg,array $names,array $context,bool $corrected=false): array {
    $candidate=lma_payment($source,$cfg,$names,$context);$byColumn=[];
    foreach($candidate['items']??[] as $item)$byColumn[$item['column']]=$item;
    $next=$payment['items'];
    foreach($next as &$item){
        if(!lfet_is_detail($item))continue;$column=$item['column'];$old=$item;
        $key=lful_component_key('bayar',$column,$source['raw']);
        $manual=$corrected||!empty($old['obligation_id'])||lma_fee_manual_mapping($cfg['components'][$key]??[]);
        if(!$manual&&isset($byColumn[$column])){$item['category']=$byColumn[$column]['category'];$item['label']=$byColumn[$column]['label'];}
        $decision=$context['fees'][$source['row_key']][$column]??['status'=>'unresolved','rule'=>'unidentified_fee','evidence'=>[]];
        if($manual){$decision['status']='manual';$decision['rule']='preserved_correction';}
        $meta=$old['migration']??[];
        // Trusted metadata is derived from the archived source, never a posted form.
        $item['migration']=array_replace($meta,['version'=>LMA_VERSION,'period'=>$payment['source_period']??$candidate['period']??'',
            'status'=>$decision['status'],'rule'=>$decision['rule'],'priority'=>$decision['priority']??'historical'],
            lfet_metadata($source,$context,$column,$decision,$item['category']));
        if($manual)$item['migration']['correction_preserved']=true;
        $item=lfet_resolve_item($item);
    }unset($item);
    return $next;
}

/** Future apply only; caller owns the unit transaction and verified backup gate. */
function lfer_reconcile(mysqli $db,int $actor): array {
    $summary=['changes'=>0,'payments_enriched'=>0,'masters_created'=>0,'held_transactions'=>0,'exceptions'=>[]];
    foreach(hp_rows($db,"SELECT b.*,s.backup_hash FROM legacy_full_batches b JOIN legacy_finance_sources s ON s.id=b.source_id AND s.unit_id=b.unit_id WHERE b.state IN ('completed','verifying')") as $batch){
        lf_rows($db,'SELECT id FROM master_biaya_lain WHERE unit_id=? ORDER BY id FOR UPDATE',[$batch['unit_id']]);
        $payments=hp_rows($db,'SELECT * FROM legacy_op_payments WHERE batch_id=? ORDER BY id',[$batch['id']]);$stale=[];
        foreach($payments as $p){$p['items']=json_decode($p['items'],true,512,JSON_THROW_ON_ERROR);if(lfer_needs_enrichment($p,$batch['backup_hash']))$stale[]=$p;}unset($payments);
        if(!$stale)continue;
        $records=[];$sourceByRow=[];
        foreach(lf_stream_rows($db,hp_sql($db,"SELECT row_key,source_table,raw_data,amounts,reference_only FROM legacy_finance_rows WHERE source_id=? AND (source_table IN ('siswa','lain') OR source_table LIKE 'bayar%') ORDER BY id",true),[$batch['source_id']]) as $r){
            $raw=json_decode($r['raw_data'],true,512,JSON_THROW_ON_ERROR);
            $record=['row_key'=>$r['row_key'],'table'=>$r['source_table'],'raw'=>$raw,'unit_id'=>(int)$batch['unit_id'],'source_id'=>$batch['source_id'],
                'reference_only'=>(bool)$r['reference_only'],'money_columns'=>array_keys(json_decode($r['amounts'],true,512,JSON_THROW_ON_ERROR))];
            $records[]=$record;if($r['source_table']==='bayar')$sourceByRow[$r['row_key']]=$record;
        }
        $manifest=json_decode($batch['manifest'],true,512,JSON_THROW_ON_ERROR);$cfg=$manifest['mapping']??[];
        $cfg['_fee_source_key']=$batch['backup_hash'];$names=[];$masters=hp_rows($db,'SELECT id,unit_id,nama FROM master_biaya_lain');foreach($masters as $m)$names[$m['id']]=$m['nama'];
        [$cfg,$context]=lma_configure($records,$cfg,(int)$batch['unit_id'],$masters);unset($records);
        $corrected=array_fill_keys(array_map('intval',array_column(hp_rows($db,"SELECT entity_id FROM legacy_op_audit WHERE entity_type='payment'"),'entity_id')),true);
        $updates=[];$needed=[];
        foreach($stale as $p){
            $source=$sourceByRow[$p['row_key']]??null;
            try{if(!$source)throw new DomainException('Arsip transaksi asal tidak ditemukan.');$items=lfer_enrich_payment($p,$source,$cfg,$names,$context,isset($corrected[(int)$p['id']]));}
            catch(DomainException $e){$summary['held_transactions']++;$summary['exceptions'][]=['payment_id'=>$p['id'],'reason'=>$e->getMessage()];continue;}
            foreach($items as $item)if(str_starts_with($item['category'],'biaya_baru:'))$needed[substr($item['category'],11)]=true;
            $updates[]=['payment'=>$p,'items'=>$items];
        }
        $proposalConfig=['components'=>[],'new_masters'=>array_intersect_key($cfg['new_masters']??[],$needed)];
        foreach(array_keys($needed) as $key)$proposalConfig['components'][$key]=['category'=>lful_new_category($key)];
        $proposals=lful_proposals($db,$proposalConfig);$resolved=lful_create_proposals($db,(int)$batch['unit_id'],$proposals);
        $summary['masters_created']+=count($resolved);$summary['changes']+=count($resolved);
        foreach(array_chunk($updates,100) as $chunk){$cases=[];$caseParams=[];$conditions=[];$conditionParams=[];$audits=[];$auditParams=[];
            foreach($chunk as $update){$p=$update['payment'];$items=array_map(static fn($i)=>lfet_resolve_item($i,$resolved),$update['items']);
                if($items===$p['items'])continue;lfet_validate_payment(array_replace($p,['items'=>$items]));$after=lf_json($items);
                $cases[]='WHEN ? THEN ?';array_push($caseParams,$p['id'],$after);$conditions[]='(?,?)';array_push($conditionParams,$p['id'],$p['version']);
                $audits[]='(?,?,?,?,?,?,?,?)';array_push($auditParams,$batch['unit_id'],$actor,'fee_trace',$p['id'],hash('sha256','fee-trace|'.$p['id'].'|'.$after),'Pelacakan biaya lain otomatis; nominal kas dan koreksi pengguna dipertahankan',lf_json(['items'=>$p['items']]),lf_json(['items'=>$items]));
            }
            if(!$cases)continue;
            $parameters=array_merge($caseParams,$conditionParams);
            $statement=$db->prepare('UPDATE legacy_op_payments SET items=CASE id '.implode(' ',$cases).' END,version=version+1 WHERE (id,version) IN ('.implode(',',$conditions).')');
            try {
                $statement->bind_param(str_repeat('s',count($parameters)),...$parameters);
                $statement->execute();
                // Read the write count before get_result()/close reset connection state.
                $affected=$statement->affected_rows;
            } finally { $statement->close(); }
            if($affected!==count($cases))throw new DomainException('Transaksi berubah saat pengayaan jejak biaya lain.');
            lf_query($db,'INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES'.implode(',',$audits),$auditParams);
            $summary['payments_enriched']+=count($cases);$summary['changes']+=count($cases);
        }
        if($updates){$manifest['resolved_masters']=array_merge($manifest['resolved_masters']??[],$resolved);$manifest['fee_trace_rule']=LEGACY_FEE_TRACE_VERSION;$manifest['working_digest']=lop_working_digest($db,$batch['id']);
            lf_query($db,'UPDATE legacy_full_batches SET manifest=? WHERE id=?',[lf_json($manifest),$batch['id']]);}
    }
    return $summary;
}
