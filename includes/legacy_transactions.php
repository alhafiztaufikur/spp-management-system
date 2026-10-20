<?php
/** Operational Legacy adapters. Source archives are never edited. */
require_once __DIR__.'/legacy_operational_corrections.php';
require_once __DIR__.'/transaction_authorization.php';
function lt_actor(mysqli $db,int $actor,int $unit): array {
    if(!in_array($unit,[1,2,3],true)||(int)$db->query('SELECT current_unit_id()')->fetch_row()[0]!==$unit)throw new DomainException('Pilih unit transaksi.',403);
    $a=lf_one($db,'SELECT * FROM admin WHERE id=? AND is_active=1',[$actor]);
    if(!$a||!in_array($a['role'],['super_admin','admin'],true)||($a['unit_id']!==null&&(int)$a['unit_id']!==$unit))throw new DomainException('Akses transaksi tidak tersedia.',403);
    return $a;
}
function lt_schema_ready(mysqli $db): bool {return transaction_authorization_column_exists($db,'transaksi_otorisasi','legacy_payment_id');}
function lt_snapshot(array $v,array $identity): array {return ['payment'=>array_replace($v,$identity,['TGL_BYR'=>$v['source_date']??null,'total_jumlah'=>$v['total']??0,'BULAN'=>$v['source_period']??'','KETERANGAN'=>$v['note']??'','sistem_pembayaran'=>$v['method']??'']),'details'=>['legacy_items'=>$v['items']??[]]];}
function lt_events(mysqli $db,int $id): array {
    $p=lop_payment_model($db,$id)['legacy'];$rows=[];
    $identity=['id'=>-1000000000-$id,'NAMA'=>$p['NAMA'],'NO_INDUK'=>$p['NO_INDUK'],'unit_id'=>$p['unit_id']];
    $rows[]=['id'=>0,'unit_id'=>$p['unit_id'],'action'=>'created','actor_name'=>$p['source_operator']?:'Tidak tercatat','actor_username'=>'','actor_role'=>'legacy_source','occurred_at'=>$p['source_date'],'before_snapshot'=>null,'after_snapshot'=>lf_json(['payment'=>$identity]),'note'=>'Operator sumber Legacy; bukan ID akun SistemSPP.','reconstructed'=>true];
    foreach(lf_rows($db,"SELECT a.*,u.nama actor_name,u.username actor_username,u.role actor_role FROM legacy_op_audit a LEFT JOIN admin u ON u.id=a.actor_id WHERE a.entity_type IN ('payment','payment_request') AND a.entity_id=? ORDER BY a.created_at,a.id",[$id]) as $a){$v=json_decode($a['after_data'],true);$beforeSnapshot=$a['entity_type']==='payment_request'?$a['before_data']:lf_json(lt_snapshot(json_decode($a['before_data'],true),$identity));$afterSnapshot=$a['entity_type']==='payment_request'?null:lf_json(lt_snapshot($v,$identity));$rows[]=['id'=>$a['id'],'unit_id'=>$a['unit_id'],'action'=>$v['_action']??($a['entity_type']==='payment'?(!empty($v['cancelled'])?'deleted':'edited'):'request_edit'),'actor_name'=>$a['actor_name'],'actor_username'=>$a['actor_username'],'actor_role'=>$a['actor_role'],'occurred_at'=>$a['created_at'],'before_snapshot'=>$beforeSnapshot,'after_snapshot'=>$afterSnapshot,'note'=>$a['reason'],'authorization_id'=>$v['_request_id']??null];}
    return $rows;
}
function lt_model(mysqli $db,int $id,bool $deleted=false): array {
    $r=lop_payment_model($db,$id)['legacy'];if((bool)$r['cancelled']!==$deleted)throw new OutOfBoundsException('Transaksi tidak tersedia pada daftar ini.');
    $events=lt_events($db,$id);$request=lt_schema_ready($db)?lf_one($db,'SELECT *,request_reason reason FROM transaksi_otorisasi WHERE legacy_payment_id=? ORDER BY id DESC LIMIT 1',[$id]):null;
    $writable=!$deleted&&!unit_all_readonly()&&hasRole(['admin','kasir'])&&(!$request||$request['status']!=='pending');
    $p=$r+['TGL_BYR'=>$r['source_date'],'total_jumlah'=>$r['total'],'kelas_transaksi'=>$r['class_snapshot'],'KELAS'=>$r['class_snapshot'],'KETERANGAN'=>$r['note'],'sistem_pembayaran'=>$r['method']?:'Tidak tercatat','created_at'=>null,'updated_at'=>null];$p['id']=-1000000000-$id;$p['BULAN']='';$p['TAHUN']='';
    if($deleted)$p['_deleted_event']=end($events);
    return ['payment'=>$p,'legacy_record'=>$r,'deleted'=>$deleted,'events'=>$events,'summary'=>['creator'=>$events[0],'last'=>end($events)],'request'=>$request,'components'=>array_map(fn($i)=>[$i['label'],$i['amount']],$r['items']),'batch_print'=>false,'capabilities'=>['can_edit'=>$writable,'can_delete'=>$writable,'can_print'=>!$deleted,'locked'=>false,'reason'=>($request&&$request['status']==='pending')?'Menunggu keputusan Super Admin.':'']];
}
function lt_request_event(mysqli $db,array $r,int $actor,string $action,string $note): void {
    lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'payment_request',?,?,?,?,?)",[$r['unit_id'],$actor,$r['legacy_payment_id'],hash('sha256','lt:'.$r['id'].':'.$action),$note?:'Keputusan pengajuan', $r['before_snapshot'],lf_json(['_action'=>$action,'_request_id'=>$r['id']])]);
}
function lt_submit(mysqli $db,int $actor,int $unit,int $id,int $version,array $input,string $key): array {
    $a=lt_actor($db,$actor,$unit);if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new DomainException('Kunci transaksi tidak valid.');
    $db->begin_transaction();try{
        $p=lop_record($db,'payment',$id,true);
        $stable=$input;unset($stable['preview_expires'],$stable['confirmation']);$inputDigest=hash('sha256',lf_json([$id,$version,$stable]));$input['_request_input_digest']=$inputDigest;
        if(lt_schema_ready($db)&&($retry=lf_one($db,"SELECT id,requested_by,proposed_payload FROM transaksi_otorisasi WHERE legacy_payment_id=? AND JSON_UNQUOTE(JSON_EXTRACT(proposed_payload,'$.legacy_key'))=?",[$id,$key]))){$payload=json_decode($retry['proposed_payload'],true);if((int)$retry['requested_by']!==$actor||($payload['legacy_input']['_request_input_digest']??'')!==$inputDigest)throw new DomainException('Kunci formulir sudah digunakan untuk usulan berbeda.');$db->commit();return ['request_id'=>(int)$retry['id'],'replayed'=>true];}
        $done=lf_one($db,'SELECT * FROM legacy_op_audit WHERE request_key=?',[$key]);if($done){$saved=json_decode($done['after_data'],true);if((int)$done['actor_id']!==$actor||$done['entity_type']!=='payment'||(int)$done['entity_id']!==$id||($saved['_request_input_digest']??'')!==$inputDigest)throw new DomainException('Kunci formulir sudah digunakan.');$db->commit();return ['replayed'=>true];}
        if((int)$p['version']!==$version||$p['cancelled'])throw new DomainException('Transaksi berubah. Muat ulang sebelum menyimpan.');
        $input['preview_expires']=time()+300;$input['confirmation']='SIMPAN KOREKSI';$preview=lop_correction_preview($db,$actor,$unit,'payment',$id,$version,$input);
        if(lt_schema_ready($db)&&lf_one($db,"SELECT id FROM transaksi_otorisasi WHERE legacy_payment_id=? AND status='pending' FOR UPDATE",[$id]))throw new DomainException('Sudah ada pengajuan yang menunggu keputusan.');
        if($a['role']==='super_admin'){$result=lop_correction_apply($db,$actor,$unit,'payment',$id,$version,$input,$preview['digest'],$key,false);}
        else {
            if(!lt_schema_ready($db))throw new DomainException('Metadata otorisasi Legacy belum dipasang melalui CLI.');
            $model=lt_model($db,$id);$snapshot=['payment'=>$model['payment'],'details'=>['legacy_items'=>$p['items']]];
            $payload=['id'=>-1000000000-$id,'legacy_version'=>$version,'legacy_input'=>$input,'legacy_key'=>$key];
            lf_query($db,"INSERT INTO transaksi_otorisasi(transaction_source,legacy_payment_id,bayar_id,transaction_reference,no_induk_snapshot,student_name_snapshot,action,status,before_snapshot,snapshot_hash,proposed_payload,request_reason,requested_by) VALUES('legacy_op',?,NULL,?,?,?,?, 'pending',?,?,?,?,?)",[$id,'TRX-L-'.$unit.'-'.$id,$model['payment']['NO_INDUK'],$model['payment']['NAMA'],!empty($input['cancelled'])?'hapus':'edit',lf_json($snapshot),hash('sha256',lf_json($p)),lf_json($payload),$input['reason'],$actor]);
            $request=(int)$db->insert_id;lt_request_event($db,['unit_id'=>$unit,'legacy_payment_id'=>$id,'id'=>$request,'before_snapshot'=>lf_json($snapshot)],$actor,!empty($input['cancelled'])?'request_delete':'request_edit',$input['reason']);$result=['request_id'=>$request];
        }$db->commit();return $result;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function lt_approve(mysqli $db,int $requestId,int $actor,string $note): void {
    transaction_authorization_assert_super($db,$actor);$db->begin_transaction();try{
        $r=transaction_authorization_find($db,$requestId,true);if(!$r||($r['transaction_source']??'')!=='legacy_op')throw new DomainException('Pengajuan tidak ditemukan.');
        if($r['status']==='approved'){$db->commit();return;}if($r['status']!=='pending')throw new DomainException('Pengajuan sudah diproses.');
        if((int)$r['requested_by']===$actor)throw new DomainException('Pemohon tidak boleh menyetujui pengajuannya sendiri.');
        lt_actor($db,(int)$r['requested_by'],(int)$r['unit_id']);$payload=transaction_authorization_decode_payload($r);$p=lop_record($db,'payment',(int)$r['legacy_payment_id'],true);
        if(!hash_equals($r['snapshot_hash'],hash('sha256',lf_json($p))))throw new DomainException('Versi transaksi berubah; pengajuan harus diperiksa ulang.');
        $input=$payload['legacy_input'];$input['preview_expires']=time()+300;$input['confirmation']='SIMPAN KOREKSI';$pr=lop_correction_preview($db,$actor,(int)$r['unit_id'],'payment',(int)$p['id'],(int)$p['version'],$input);
        lop_correction_apply($db,$actor,(int)$r['unit_id'],'payment',(int)$p['id'],(int)$p['version'],$input,$pr['digest'],$payload['legacy_key'],false);
        transaction_authorization_decide($db,$requestId,'approved',$actor,$note);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
