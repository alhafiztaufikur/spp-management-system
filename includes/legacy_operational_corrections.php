<?php
require_once __DIR__.'/legacy_full.php';require_once __DIR__.'/legacy_operational_read.php';
/** Narrow operational permission; importing and payment corrections retain their own gates. */
function lop_correction_actor(mysqli $db,int $actor,int $unit,string $kind,?array $before=null,array $input=[],bool $lock=false):array {
    require_once __DIR__.'/legacy_transactions.php';
    $account=lt_actor($db,$actor,$unit);
    if($account['role']==='super_admin'){lop_actor($db,$actor,$unit,$lock);return $account;}
    if($kind!=='journal')throw new DomainException('Koreksi pembayaran Legacy memerlukan Super Admin.',403);
    if($lock){
        $locked=lf_one($db,'SELECT role,unit_id,is_active FROM admin WHERE id=? FOR UPDATE',[$actor]);
        if(!$locked||!$locked['is_active']||$locked['role']!=='admin'||($locked['unit_id']!==null&&(int)$locked['unit_id']!==$unit))throw new DomainException('Akses koreksi sudah berubah. Muat ulang.',403);
    }
    if($before){
        if((int)($input['owner_id']??$before['owner_id'])!==(int)$before['owner_id']||(bool)($input['cancelled']??$before['cancelled'])!==(bool)$before['cancelled'])throw new DomainException('Pemindahan pemilik dan pembatalan Legacy hanya tersedia untuk Super Admin.',403);
    }
    return $account;
}
/** Display fields are optional, so existing canonical callers remain compatible. */
function lop_correction_amount(string $raw):string {
    $raw=trim($raw);
    if(preg_match('/^[1-9][0-9]{0,2}(?:\.[0-9]{3})+(?:,[0-9]{1,2})?$/D',$raw))$raw=str_replace(['.',','],['','.'],$raw);
    elseif(preg_match('/^[0-9]+(?:,[0-9]{1,2})?$/D',$raw))$raw=str_replace(',','.',$raw);
    elseif(!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D',$raw))throw new DomainException('Gunakan nominal dengan maksimal dua desimal.');
    return lf_money(lf_cents($raw));
}
function lop_month(string $period): ?string {
    if(preg_match('/^([12][0-9]{3})-(0[1-9]|1[0-2])$/D',$period))return $period;
    $months=['JANUARI'=>'01','FEBRUARI'=>'02','MARET'=>'03','APRIL'=>'04','MEI'=>'05','JUNI'=>'06','JULI'=>'07','AGUSTUS'=>'08','SEPTEMBER'=>'09','OKTOBER'=>'10','NOVEMBER'=>'11','DESEMBER'=>'12'];
    if(preg_match('/^\s*([A-Za-z]+|[0-9]{1,2})\s+([12][0-9]{3})\s*$/D',$period,$m)){$month=$months[strtoupper($m[1])]??str_pad($m[1],2,'0',STR_PAD_LEFT);if(preg_match('/^(0[1-9]|1[0-2])$/D',$month))return $m[2].'-'.$month;}return null;
}
function lop_record(mysqli $db,string $kind,int $id,bool $lock=false): array {
    if(!in_array($kind,['payment','journal'],true))throw new DomainException('Jenis transaksi tidak valid.');$table=$kind==='payment'?'payments':'journals';
    $r=lf_one($db,"SELECT p.*,b.cutoff FROM legacy_op_$table p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE p.id=? AND b.state='completed'".($lock?' FOR UPDATE':''),[$id]);if(!$r)throw new DomainException('Transaksi migrasi tidak ditemukan pada unit ini.',404);if($kind==='payment')$r['items']=json_decode($r['items'],true,64,JSON_THROW_ON_ERROR);return $r;
}
function lop_current_balance(mysqli $db,array $owner,array $account,bool $lock=false): string {
    if(!$owner['student_id'])return (string)$account['balance'];$r=lf_one($db,'SELECT t.SALDO FROM tabungan t JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id WHERE s.id=?'.($lock?' FOR UPDATE':''),[$owner['student_id']]);if(!$r)throw new DomainException('Rekening siswa tidak ditemukan.');return lf_money((int)round((float)$r['SALDO']*100));
}
function lop_obligation_paid(mysqli $db,int $id,?array $replacement=null): int {
    $o=lf_one($db,'SELECT * FROM legacy_op_obligations WHERE id=?',[$id]);if(!$o)throw new DomainException('Kewajiban tidak ditemukan pada unit ini.');$sum=0;
    foreach(lf_rows($db,'SELECT id,owner_id,items,cancelled FROM legacy_op_payments WHERE owner_id=?',[$o['owner_id']]) as $p){if($replacement&&(int)$p['id']===(int)$replacement['id'])continue;if($p['cancelled'])continue;foreach(json_decode($p['items'],true) as $i)if((int)($i['obligation_id']??0)===$id)$sum+=lf_cents($i['amount']);}
    if($replacement&&!$replacement['cancelled'])foreach($replacement['items'] as $i)if((int)($i['obligation_id']??0)===$id)$sum+=lf_cents($i['amount']);
    if($o['bill_id']){$paid=lf_one($db,'SELECT COALESCE(SUM(nominal_snapshot),0) paid FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=?',[$o['bill_id']]);$sum+=lf_cents((string)$paid['paid']);}return $sum;
}
function lop_journal_timeline(mysqli $db,array $owner,array $replacement): array {
    $rows=lf_rows($db,'SELECT id,owner_id,source_date,incoming,outgoing,cancelled,origin FROM legacy_op_journals WHERE owner_id=? ORDER BY source_date,id',[$owner['id']]);$filtered=[];foreach($rows as $row)if((int)$row['id']!==(int)$replacement['id'])$filtered[]=$row;
    if((int)$replacement['owner_id']===(int)$owner['id'])$filtered[]=$replacement;
    if($owner['student_id'])foreach(['transaksi_m','transaksi_k'] as $table){$in=$table==='transaksi_m'?'CAST(t.MASUK AS DECIMAL(15,2))':'0';$out=$table==='transaksi_k'?'CAST(t.KELUAR AS DECIMAL(15,2))':'0';foreach(lf_rows($db,"SELECT t.id,t.TANGGAL source_date,$in incoming,$out outgoing,0 cancelled,'new' origin FROM $table t JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id WHERE s.id=?",[$owner['student_id']]) as $row)$filtered[]=$row;}
    usort($filtered,fn($a,$b)=>[$a['source_date'],(['legacy'=>0,'adjustment'=>1,'new'=>2][$a['origin']??'new']),lf_cents($a['outgoing'])>0,(int)$a['id']]<=>[$b['source_date'],(['legacy'=>0,'adjustment'=>1,'new'=>2][$b['origin']??'new']),lf_cents($b['outgoing'])>0,(int)$b['id']]);$balance=0;
    foreach($filtered as $row)if(!$row['cancelled']){$balance+=lf_cents((string)$row['incoming'])-lf_cents((string)$row['outgoing']);if($balance<0)throw new DomainException('Saldo berjalan menjadi negatif pada '.$row['source_date'].'. Periksa urutan dan nominal jurnal rekening ini.');}
    return $filtered;
}
/** Compute exact financial effects before asking for confirmation. */
function lop_correction_preview(mysqli $db,int $actor,int $unit,string $kind,int $id,int $version,array $input): array {
    lop_require($db);require_once __DIR__.'/legacy_transactions.php';lt_actor($db,$actor,$unit);$before=lop_record($db,$kind,$id);if((int)$before['version']!==$version)throw new DomainException('Transaksi berubah. Muat ulang.');
    // Payment proposals by Administrator are handled by the existing approval service.
    if($kind==='journal')lop_correction_actor($db,$actor,$unit,$kind,$before,$input);
    $reason=trim((string)($input['reason']??''));if(mb_strlen($reason)<10||mb_strlen($reason)>1000)throw new DomainException('Isi alasan koreksi minimal 10 karakter.');
    $expires=(int)($input['preview_expires']??0);if($expires<time()||$expires>time()+300)throw new DomainException('Pratinjau koreksi kedaluwarsa. Periksa kembali.');
    $after=$before;$after['source_date']=lop_date(array_key_exists('date',$input)?$input['date']:$before['source_date']);if($after['source_date']&&$after['source_date']>date('Y-m-d H:i:s'))throw new DomainException('Tanggal tidak boleh di masa depan.');
    if(array_key_exists('time_precision',$input)||$after['source_date']!==$before['source_date']){
        $precision=$input['time_precision']??($after['source_date']===null?'none':(preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)($input['date']??''))?'date':'datetime'));
        if(!in_array($precision,['none','date','datetime'],true)||(($precision==='none')!==($after['source_date']===null))||($precision==='date'&&substr($after['source_date'],11)!=='00:00:00'))throw new DomainException('Ketepatan waktu pembayaran tidak sesuai tanggal/jam.');
        $after['_time_precision']=$precision;
    }
    if($kind==='journal'&&($before['origin']??'')==='adjustment'&&(int)($input['owner_id']??$before['owner_id'])!==(int)$before['owner_id'])throw new DomainException('Penyesuaian saldo tidak dapat dipindahkan ke rekening lain.');
    $after['note']=trim((string)($input['note']??$before['note']));if(mb_strlen($after['note'])>1000)throw new DomainException('Catatan maksimal 1.000 karakter.');
    $after['cancelled']=($input['cancelled']??false)===true?1:0;$ownerId=(int)($input['owner_id']??$before['owner_id']);$owner=lf_one($db,"SELECT * FROM legacy_op_owners WHERE id=? AND state='ready'",[$ownerId]);if(!$owner||$owner['batch_id']!==$before['batch_id'])throw new DomainException('Pilih pemilik yang sudah disahkan pada batch/unit yang sama.');$after['owner_id']=$ownerId;
    $effects=[];$context=[];
    if($kind==='payment'){
        $after['method']=trim((string)($input['method']??$before['method']));if(mb_strlen($after['method'])>100)throw new DomainException('Metode maksimal 100 karakter.');
        $items=$input['items']??$before['items'];if(!is_array($items)||(!$items&&$before['items'])||count($items)>100)throw new DomainException('Rincian pembayaran tidak valid.');$after['items']=[];$total=0;$seen=[];$obligations=[];
        foreach($before['items'] as $i)if(!empty($i['obligation_id']))$obligations[(int)$i['obligation_id']]=true;
        foreach($items as $index=>$i){if(!is_array($i))throw new DomainException('Rincian tidak valid.');$column=(string)($i['column']??'');if(!preg_match('/^[A-Za-z0-9_:-]{1,64}$/D',$column)||isset($seen[$column]))throw new DomainException('Komponen ganda/tidak valid.');$seen[$column]=true;
            $amount=lf_money(lf_cents($i['amount']??null));$category=(string)($i['category']??'');$label=trim((string)($i['label']??''));if($label===''||mb_strlen($label)>150)throw new DomainException('Isi nama komponen.');
            if(!in_array($category,['spp','komite','daftar_ulang','psb'],true)&&!preg_match('/^(histori:[a-f0-9]{32}|biaya_lain:[1-9][0-9]*)$/D',$category))throw new DomainException('Komponen belum disahkan.');
            $old=null;foreach($before['items'] as $v)if($v['column']===$column)$old=$v;
            $changed=!$old||$old['amount']!==$amount||$old['category']!==$category||$ownerId!==(int)$before['owner_id']||$after['cancelled']!==(int)$before['cancelled'];
            if($old&&!str_starts_with($old['category'],'histori:')&&empty($old['obligation_id'])&&($old['category']!==$category||$ownerId!==(int)$before['owner_id']))throw new DomainException('Sahkan kewajiban asal sebelum mengubah komponen atau membatalkan pembayaran.');
            $binding=(int)($i['obligation_id']??0);if($binding){$o=lf_one($db,'SELECT * FROM legacy_op_obligations WHERE id=? AND owner_id=? AND category=?',[$binding,$ownerId,$category]);if(!$o)throw new DomainException('Hubungan tagihan tidak sesuai pemilik/komponen.');$period=trim((string)($input['period']??$before['source_period']));if(in_array($category,['spp','komite'],true)?lop_month($period)!==$o['period']:!str_contains($period,$o['period']))throw new DomainException('Periode pembayaran harus cocok dengan kewajiban tujuan.');$obligations[$binding]=true;}
            elseif($changed&&!str_starts_with($category,'histori:')&&(!$old||$old['category']!==$category||$ownerId!==(int)$before['owner_id']))throw new DomainException('Hubungkan komponen ke kewajiban yang disahkan pada peninjauan migrasi sebelum mengubah nominal atau pemilik.');
            $after['items'][]=compact('column','category','label','amount')+($binding?['obligation_id'=>$binding]:[]);$total+=lf_cents($amount);
        }
        foreach($before['items'] as $oldItem)if(!isset($seen[$oldItem['column']])&&!str_starts_with($oldItem['category'],'histori:')&&empty($oldItem['obligation_id']))throw new DomainException('Hubungkan kewajiban sebelum menghapus rincian pembayaran.');
        if($total>999999999999999)throw new DomainException('Total melampaui batas.');$after['total']=lf_money($total);$after['source_period']=trim((string)($input['period']??$before['source_period']));if(mb_strlen($after['source_period'])>100)throw new DomainException('Periode terlalu panjang.');
        foreach(array_keys($obligations) as $oid){$o=lf_one($db,'SELECT * FROM legacy_op_obligations WHERE id=?',[$oid]);$paid=lop_obligation_paid($db,$oid,$after);$principal=lf_cents($o['principal']);if($paid>$principal)throw new DomainException('Koreksi melebihi kewajiban. Periksa pembayaran di muka atau kewajiban tujuan.');$remaining=lf_money($principal-$paid);$effects[]=['obligation_id'=>$oid,'before'=>lf_money($principal-lop_obligation_paid($db,$oid)),'after'=>$remaining];$context[]=[$o,lop_obligation_paid($db,$oid)];}
    }else{
        if(($before['origin']??'')==='adjustment'&&$after['source_date']!==$before['cutoff'])throw new DomainException('Tanggal penyesuaian saldo tetap pada waktu peralihan.');
        if(!$after['source_date']||(in_array($before['origin'],['legacy','adjustment'],true)&&$after['source_date']>$before['cutoff'])||($before['origin']==='new'&&$after['source_date']<$before['cutoff']))throw new DomainException('Tanggal jurnal Legacy harus berada sebelum/saat peralihan.');
        $after['incoming']=lf_money(lf_cents($input['incoming']??$before['incoming']));$after['outgoing']=lf_money(lf_cents($input['outgoing']??$before['outgoing']));if((lf_cents($after['incoming'])>0)===(lf_cents($after['outgoing'])>0))throw new DomainException('Pilih satu arah transaksi dengan nominal positif.');
        $old=$before['cancelled']?0:lf_cents($before['incoming'])-lf_cents($before['outgoing']);$new=$after['cancelled']?0:lf_cents($after['incoming'])-lf_cents($after['outgoing']);$changes=[(int)$before['owner_id']=>-$old];$changes[$ownerId]=($changes[$ownerId]??0)+$new;ksort($changes);
        foreach($changes as $affected=>$delta){$affectedOwner=lf_one($db,"SELECT * FROM legacy_op_owners WHERE id=? AND state='ready'",[$affected]);$account=lf_one($db,'SELECT * FROM legacy_op_accounts WHERE owner_id=?',[$affected]);if(!$affectedOwner||!$account)throw new DomainException('Kedua rekening harus sudah direkonsiliasi.');$balance=lop_current_balance($db,$affectedOwner,$account);$opening=lf_cents($account['opening'])+(in_array($before['origin'],['legacy','adjustment'],true)?$delta:0);
            if($opening<0||lf_cents($balance)+$delta<0)throw new DomainException('Koreksi membuat saldo negatif sebesar '.legacy_ui_money(lf_money(abs(min($opening,lf_cents($balance)+$delta)))).'. Periksa transaksi lanjutan; rekening lain tidak terpengaruh.');
            $timeline=lop_journal_timeline($db,$affectedOwner,$after);
            $effects[]=['owner_id'=>$affected,'balance_before'=>$balance,'balance_after'=>lf_money(lf_cents($balance)+$delta),'opening_after'=>lf_money($opening)];$context[]=[$affectedOwner,$account,$balance,$timeline];}
    }
    $digest=hash('sha256',lf_json([$unit,$actor,$kind,$id,$version,$before,$after,$effects,$context,$reason,$expires]));return compact('kind','id','version','before','after','effects','reason','digest','expires');
}
function lop_correction_apply(mysqli $db,int $actor,int $unit,string $kind,int $id,int $version,array $input,string $digest,string $key,bool $manageTransaction=true): array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$key)||($input['confirmation']??'')!=='SIMPAN KOREKSI')throw new DomainException('Konfirmasi koreksi diperlukan.');
    if($manageTransaction){$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$db->begin_transaction();}try{lop_correction_actor($db,$actor,$unit,$kind,null,[],true);$done=lf_one($db,'SELECT * FROM legacy_op_audit WHERE request_key=? FOR UPDATE',[$key]);if($done){$saved=json_decode($done['after_data'],true);if((int)$done['actor_id']!==$actor||$done['entity_type']!==$kind||(int)$done['entity_id']!==$id||($saved['_preview_digest']??'')!==$digest)throw new DomainException('Kunci koreksi sudah digunakan.');if($manageTransaction)$db->commit();return ['saved'=>true,'replayed'=>true];}
        $old=lop_record($db,$kind,$id,true);$ids=[(int)$old['owner_id'],(int)($input['owner_id']??$old['owner_id'])];sort($ids);foreach(array_unique($ids) as $oid){lf_one($db,'SELECT id FROM legacy_op_owners WHERE id=? FOR UPDATE',[$oid]);$owner=lf_one($db,'SELECT * FROM legacy_op_owners WHERE id=?',[$oid]);if($owner&&$owner['student_id'])lf_one($db,'SELECT id FROM siswa WHERE id=? FOR UPDATE',[$owner['student_id']]);$account=lf_one($db,'SELECT * FROM legacy_op_accounts WHERE owner_id=? FOR UPDATE',[$oid]);if($account)lop_current_balance($db,$owner,$account,true);foreach(lf_rows($db,'SELECT id FROM legacy_op_obligations WHERE owner_id=? ORDER BY id FOR UPDATE',[$oid]) as $ignored){}}
        foreach(array_unique($ids) as $oid){$o=lf_one($db,'SELECT * FROM legacy_op_owners WHERE id=?',[$oid]);if($o&&$o['student_id'])lf_one($db,'SELECT id FROM siswa WHERE id=? FOR UPDATE',[$o['student_id']]);lf_rows($db,'SELECT id FROM legacy_op_payments WHERE owner_id=? ORDER BY id FOR UPDATE',[$oid]);lf_rows($db,'SELECT id FROM legacy_op_journals WHERE owner_id=? ORDER BY id FOR UPDATE',[$oid]);foreach(lf_rows($db,'SELECT bill_id FROM legacy_op_obligations WHERE owner_id=? ORDER BY id FOR UPDATE',[$oid]) as $ob)if($ob['bill_id']){lf_one($db,'SELECT id FROM tagihan_biaya_lain WHERE id=? FOR UPDATE',[$ob['bill_id']]);lf_rows($db,'SELECT id FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=? FOR UPDATE',[$ob['bill_id']]);}}
        $p=lop_correction_preview($db,$actor,$unit,$kind,$id,$version,$input);if(!hash_equals($p['digest'],$digest))throw new DomainException('Dampak koreksi berubah. Periksa ulang.');$a=$p['after'];
        if($kind==='payment'){lf_query($db,'UPDATE legacy_op_payments SET owner_id=?,source_date=?,source_period=?,method=?,note=?,items=?,total=?,cancelled=?,version=version+1 WHERE id=?',[$a['owner_id'],$a['source_date'],$a['source_period'],$a['method'],$a['note'],lf_json($a['items']),$a['total'],$a['cancelled'],$id]);
            foreach($p['effects'] as $e){$o=lf_one($db,'SELECT * FROM legacy_op_obligations WHERE id=?',[$e['obligation_id']]);if($o['bill_id']){$paid=lf_one($db,'SELECT COALESCE(SUM(nominal_snapshot),0) paid FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=?',[$o['bill_id']]);$nominal=lf_cents($e['after'])+lf_cents((string)$paid['paid']);lf_query($db,'UPDATE tagihan_biaya_lain SET nominal_tagihan=?,status=? WHERE id=?',[lf_money($nominal),$nominal===0?'waived':'open',$o['bill_id']]);}lf_query($db,'UPDATE legacy_op_obligations SET remaining=?,version=version+1 WHERE id=?',[$e['after'],$e['obligation_id']]);}
        }else{lf_query($db,'UPDATE legacy_op_journals SET owner_id=?,source_date=?,incoming=?,outgoing=?,note=?,cancelled=?,version=version+1 WHERE id=?',[$a['owner_id'],$a['source_date'],$a['incoming'],$a['outgoing'],$a['note'],$a['cancelled'],$id]);foreach($p['effects'] as $e){lf_query($db,'UPDATE legacy_op_accounts SET opening=?,balance=?,version=version+1 WHERE owner_id=?',[$e['opening_after'],$e['balance_after'],$e['owner_id']]);$o=lf_one($db,'SELECT student_id FROM legacy_op_owners WHERE id=?',[$e['owner_id']]);if($o['student_id'])lf_query($db,'UPDATE tabungan t JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id SET t.SALDO=? WHERE s.id=?',[$e['balance_after'],$o['student_id']]);}}
        lf_query($db,'INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,?,?,?,?,?,?)',[$unit,$actor,$kind,$id,$key,$p['reason'],lf_json($p['before']),lf_json(array_replace($a,['version'=>$version+1,'_preview_digest'=>$digest,'_request_input_digest'=>$input['_request_input_digest']??null]))]);require_once __DIR__.'/legacy_billing.php';if(function_exists('payment_time_invalidate'))payment_time_invalidate($db);if($kind==='payment')lb_sync_owners($db,array_unique($ids));$b=lf_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=? FOR UPDATE',[$old['batch_id']]);$m=json_decode($b['manifest'],true);$m['working_digest']=lop_working_digest($db,$old['batch_id']);lf_query($db,'UPDATE legacy_full_batches SET manifest=? WHERE id=?',[lf_json($m),$old['batch_id']]);if($manageTransaction)$db->commit();return ['saved'=>true,'replayed'=>false];
    }catch(Throwable $e){if($manageTransaction)$db->rollback();payment_time_invalidate($db);throw $e;}
}
