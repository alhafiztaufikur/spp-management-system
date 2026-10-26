<?php
/** Explicit client decisions close review tasks; original cash and source evidence remain intact. */
function lec_item(array $item,array $closures): array {
    $key=$item['migration']['detail_key']??'';$decision=$closures['fees'][$key]??null;
    if(!$decision)return $item;
    $meta=$item['migration'];
    if(($meta['source_key']??'')!==($decision['source_key']??'')||($meta['source_row_key']??'')!==($decision['row_key']??'')
        ||($item['column']??'')!==($decision['column']??'')||$item['amount']!==$decision['amount']||$item['category']!==$decision['category'])throw new DomainException('Keputusan penutupan tidak cocok dengan rincian sumber.');
    if(($meta['status']??'')==='closed')return $item;
    if(($meta['status']??'')!=='unresolved')throw new DomainException('Hanya rincian biaya belum teridentifikasi yang dapat ditutup.');
    $item['migration']['original_resolution']=['status'=>$meta['status'],'rule'=>$meta['rule'],'label'=>$item['label']];
    $item['migration']['status']='closed';$item['migration']['rule']='client_accepted_history';
    $item['migration']['closure']=array_intersect_key($decision,array_flip(['decision_id','reason','actor_id','closed_at']));
    $item['label']='Biaya Legacy (histori ditutup)';return $item;
}

/** The database manifest, rather than posted or stale SQLite settings, owns accepted closures. */
function lec_saved(mysqli $db,string $batch): array {
    $row=lf_one($db,'SELECT manifest FROM legacy_full_batches_data WHERE id=?',[$batch]);
    return $row?(json_decode($row['manifest'],true,512,JSON_THROW_ON_ERROR)['exception_closures']??[]):[];
}

/** Also updates cached previews created before closure, without regenerating old warnings. */
function lec_preview(array $plan,array $closures): array {
    if(!$closures)return $plan;
    foreach($closures['owners']??[] as $identity=>$decision){
        unset($plan['owners'][$identity]);
        $plan['pending']=array_values(array_filter($plan['pending'],static fn($p)=>(string)$p['identity']!==(string)$identity));
        foreach($plan['groups']??[] as $key=>$group){
            if(isset($group['identities'])){$group['identities']=array_values(array_filter($group['identities'],static fn($v)=>(string)$v!==(string)$identity));if(!$group['identities']){unset($plan['groups'][$key]);continue;}$plan['groups'][$key]=$group;}
        }
    }
    $new=array_diff_key($closures['fees']??[],$plan['closed_fee_keys']??[]);
    foreach($new as $key=>$decision){
        foreach(['fee_unresolved','fee_unresolved_'.($decision['priority']??'historical')] as $counter)if(isset($plan['summary'][$counter]))$plan['summary'][$counter]=max(0,$plan['summary'][$counter]-1);
        if(isset($plan['summary']['fee_trace'])){$plan['summary']['fee_trace']['unresolved']=max(0,$plan['summary']['fee_trace']['unresolved']-1);$plan['summary']['fee_trace']['closed']=($plan['summary']['fee_trace']['closed']??0)+1;}
        $plan['summary']['fee_closed']=($plan['summary']['fee_closed']??0)+1;$plan['closed_fee_keys'][$key]=true;
    }
    $plan['summary']['pending']=count($plan['pending']);$plan['summary']['review_tasks']=count(lop_pending_groups($plan));return $plan;
}

/** Called only by the official runner inside its backup-guarded per-unit transaction. */
function lec_reconcile(mysqli $db,int $actor,array $request): array {
    $out=['changes'=>0,'fees_closed'=>0,'owners_removed'=>0,'pending_resolved'=>0,'amount_cents'=>0];
    if(!$request)return $out;
    $decisionId=$request['decision_id']??'';$reason=trim((string)($request['reason']??''));
    if(!is_string($decisionId)||!preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$decisionId)||mb_strlen($reason)<10||mb_strlen($reason)>1000||!is_array($request['fees']??null)||!is_array($request['owners']??null))throw new DomainException('Keputusan klien dan daftar penutupan eksplisit wajib diisi.');
    $unit=unit_active_id();if(!$unit)throw new DomainException('Pilih satu unit untuk penutupan.');lop_actor($db,$actor,$unit,true);
    $batches=[];$seen=[];$paymentVersions=[];
    foreach(['fees','owners'] as $kind)foreach($request[$kind] as $entry){
        if(!is_array($entry)||!in_array($entry['unit_id']??null,[1,2,3],true)||!is_string($entry['batch_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$entry['batch_id']))throw new DomainException('Ruang lingkup penutupan tidak valid.');
        if($entry['unit_id']!==$unit)continue;
        $batchId=$entry['batch_id'];$batch=lf_one($db,"SELECT b.*,s.backup_hash FROM legacy_full_batches b JOIN legacy_finance_sources s ON s.id=b.source_id AND s.unit_id=b.unit_id WHERE b.id=? AND b.state='completed' FOR UPDATE",[$batchId]);
        if(!$batch||($entry['source_key']??'')!==$batch['backup_hash'])throw new DomainException('Batch atau hash sumber penutupan berbeda.');
        $batches[$batchId]??=['row'=>$batch,'manifest'=>json_decode($batch['manifest'],true,512,JSON_THROW_ON_ERROR),'dirty'=>false];$work=&$batches[$batchId];
        $saved=&$work['manifest']['exception_closures'];if(!is_array($saved))$saved=[];
        $key=$kind==='fees'?($entry['detail_key']??''):($entry['identity']??'');
        if(!is_string($key)||$key===''||isset($seen[$kind.'|'.$batchId.'|'.$key]))throw new DomainException('Daftar penutupan kosong atau ganda.');$seen[$kind.'|'.$batchId.'|'.$key]=true;
        $prior=$saved[$kind][$key]??null;
        if($prior){
            foreach($entry+['decision_id'=>$decisionId,'reason'=>$reason] as $field=>$value)if(($prior[$field]??null)!==$value)throw new DomainException('Keputusan penutupan sebelumnya berbeda; tidak ditimpa.');
            continue;
        }
        $decision=$entry+['decision_id'=>$decisionId,'reason'=>$reason,'actor_id'=>$actor,'closed_at'=>date(DATE_ATOM)];
        if($kind==='fees'){
            if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new DomainException('Identitas rincian biaya tidak valid.');
            $payment=lf_one($db,'SELECT * FROM legacy_op_payments WHERE id=? AND batch_id=? FOR UPDATE',[(int)($entry['payment_id']??0),$batchId]);
            if(!$payment||$payment['cancelled'])throw new DomainException('Pembayaran berubah sejak daftar penutupan dibuat.');
            $versions=$paymentVersions[$payment['id']]??['initial'=>$entry['version']??null,'next'=>$entry['version']??null];
            if(($entry['version']??null)!==$versions['initial']||(int)$payment['version']!==$versions['next'])throw new DomainException('Versi pembayaran berbeda dari keputusan penutupan.');
            $items=json_decode($payment['items'],true,512,JSON_THROW_ON_ERROR);$found=0;
            foreach($items as &$item)if(($item['migration']['detail_key']??'')===$key){
                if(!lfet_is_detail($item)||!str_starts_with($item['category'],'histori:')||!empty($item['obligation_id']))throw new DomainException('Penutupan hanya untuk histori biaya tanpa tagihan.');
                foreach(['row_key'=>$payment['row_key'],'column'=>$item['column'],'category'=>$item['category'],'amount'=>$item['amount']] as $field=>$value)if(($entry[$field]??null)!==$value)throw new DomainException('Nominal atau rincian penutupan berbeda.');
                $decision['priority']=$item['migration']['priority']??'historical';$item=lec_item($item,['fees'=>[$key=>$decision]]);$found++;
            }unset($item);
            if($found!==1)throw new DomainException('Rincian biaya tidak ditemukan atau tidak tunggal.');
            $next=array_replace($payment,['items'=>$items]);lfet_validate_payment($next);
            lf_query($db,'UPDATE legacy_op_payments SET items=?,version=version+1 WHERE id=?',[lf_json($items),$payment['id']]);
            $paymentVersions[$payment['id']]=['initial'=>$versions['initial'],'next'=>(int)$payment['version']+1];
            $entity=(int)$payment['id'];$before=['items'=>json_decode($payment['items'],true),'version'=>(int)$payment['version']];$after=['items'=>$items,'decision'=>$decision];
            $out['fees_closed']++;$out['amount_cents']+=lf_cents($entry['amount']);
        }else{
            $owner=lf_one($db,'SELECT * FROM legacy_op_owners WHERE id=? AND batch_id=? AND source_identity=? FOR UPDATE',[(int)($entry['owner_id']??0),$batchId,$key]);
            if(!$owner||$owner['student_id']!==null||$owner['state']!=='held'||!lop_testing_identity(['NO_INDUK'=>$owner['source_identity'],'NAMA'=>$owner['name']]))throw new DomainException('Hanya identitas uji tertahan tanpa siswa yang dapat dikeluarkan.');
            $source=lf_one($db,"SELECT row_key,raw_data FROM legacy_finance_rows WHERE source_id=? AND source_identity=? AND source_table='siswa'",[$batch['source_id'],$key]);
            if(!$source||$source['row_key']!==($entry['row_key']??''))throw new DomainException('Identitas uji berbeda dari arsip sumber.');
            foreach(['accounts','payments','journals','obligations','tariffs'] as $table)if(lf_one($db,"SELECT id FROM legacy_op_$table WHERE owner_id=? LIMIT 1",[$owner['id']]))throw new DomainException('Pemilik yang telah direferensikan tidak dapat dikeluarkan.');
            if(lf_one($db,"SELECT id FROM legacy_finance_rows WHERE source_id=? AND source_identity=? AND source_table<>'siswa' LIMIT 1",[$batch['source_id'],$key]))throw new DomainException('Identitas uji memiliki data lain pada sumber; penutupan dibatalkan.');
            $pending=lf_rows($db,'SELECT * FROM legacy_op_pending WHERE batch_id=? AND source_identity=? AND resolved=0 FOR UPDATE',[$batchId,$key]);
            foreach($pending as $p)if($p['code']!=='owner_review')throw new DomainException('Identitas mempunyai pengecualian lain; penutupan dibatalkan.');
            lf_query($db,"UPDATE legacy_op_pending SET resolved=1,version=version+1 WHERE batch_id=? AND source_identity=? AND code='owner_review' AND resolved=0",[$batchId,$key]);
            $out['pending_resolved']+=count($pending);$entity=(int)$owner['id'];$before=['owner'=>$owner,'source'=>$source,'pending'=>$pending];$after=['decision'=>$decision,'operational_owner_removed'=>true];
            lf_query($db,'DELETE FROM legacy_op_owners WHERE id=?',[$owner['id']]);$out['owners_removed']++;
        }
        lf_query($db,'INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,?,?,?,?,?,?)',[$unit,$actor,$kind==='fees'?'fee_closure':'owner_closure',$entity,hash('sha256','exception-closure|'.$batchId.'|'.$kind.'|'.$key.'|'.$decisionId),$reason,lf_json($before),lf_json($after)]);
        $saved[$kind][$key]=$decision;$work['dirty']=true;$out['changes']++;unset($work,$saved);
    }
    foreach($batches as $batchId=>$work)if($work['dirty']){
        $m=$work['manifest'];$m['working_digest']=lop_working_digest($db,$batchId);
        lf_query($db,'UPDATE legacy_full_batches SET manifest=? WHERE id=?',[lf_json($m),$batchId]);
    }
    return $out;
}

/** Existing authorized corrections may supersede a closed receipt, including cancellation. */
function lec_matches_correction(array $payment,array $after): bool {
    foreach(['id','unit_id','batch_id','owner_id','source_date','source_period','method','note','total','cancelled','version'] as $field)
        if(!array_key_exists($field,$after)||(string)($payment[$field]??'')!==(string)($after[$field]??''))return false;
    return isset($after['items'])&&lf_canonical(json_decode($payment['items'],true,512,JSON_THROW_ON_ERROR))===lf_canonical($after['items']);
}

/** An accepted exception must still match its audited result or an exact later authorized correction. */
function lec_verify(mysqli $db,string $batch,array $closures): void {
    foreach($closures['fees']??[] as $key=>$decision){
        $p=lf_one($db,'SELECT * FROM legacy_op_payments WHERE id=? AND batch_id=?',[$decision['payment_id'],$batch]);
        if(!$p||$p['row_key']!==$decision['row_key'])throw new DomainException('Histori biaya yang ditutup tidak ditemukan.');
        $closedAudit=lf_one($db,'SELECT id FROM legacy_op_audit WHERE request_key=?',[hash('sha256','exception-closure|'.$batch.'|fees|'.$key.'|'.$decision['decision_id'])]);
        $correction=lf_one($db,"SELECT id,after_data FROM legacy_op_audit WHERE entity_type='payment' AND entity_id=? ORDER BY id DESC LIMIT 1",[$p['id']]);
        if($closedAudit&&$correction&&(int)$correction['id']>(int)$closedAudit['id']&&lec_matches_correction($p,json_decode($correction['after_data'],true,512,JSON_THROW_ON_ERROR)))continue;
        $found=[];foreach(json_decode($p['items'],true,512,JSON_THROW_ON_ERROR) as $i)if(($i['migration']['detail_key']??'')===$key)$found[]=$i;
        if(count($found)!==1||($found[0]['migration']['status']??'')!=='closed'||($found[0]['migration']['closure']['decision_id']??'')!==$decision['decision_id']||($found[0]['migration']['closure']['reason']??'')!==$decision['reason'])throw new DomainException('Status penutupan histori biaya berbeda.');
        lec_item($found[0],['fees'=>[$key=>$decision]]);
    }
    foreach($closures['owners']??[] as $identity=>$decision)if(lf_one($db,'SELECT id FROM legacy_op_owners WHERE batch_id=? AND source_identity=?',[$batch,(string)$identity])||lf_one($db,'SELECT id FROM legacy_op_pending WHERE batch_id=? AND source_identity=? AND resolved=0',[$batch,(string)$identity]))throw new DomainException('Identitas uji yang ditutup muncul kembali pada data operasional.');
    foreach(['fees','owners'] as $kind)foreach($closures[$kind]??[] as $key=>$decision){
        $audit=lf_one($db,'SELECT after_data FROM legacy_op_audit WHERE request_key=?',[hash('sha256','exception-closure|'.$batch.'|'.$kind.'|'.$key.'|'.$decision['decision_id'])]);
        if(!$audit||lf_json_digest(json_decode($audit['after_data'],true)['decision'])!==lf_json_digest($decision))throw new DomainException('Audit keputusan penutupan tidak cocok.');
    }
}
