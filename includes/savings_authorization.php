<?php
require_once __DIR__.'/savings_workspace.php';

function savings_authorization_ready(mysqli $db): bool {
    return (int)$db->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('tabungan_otorisasi','tabungan_aktivitas')")->fetch_row()[0]===2
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('tabungan_otorisasi_data_bi','tabungan_otorisasi_data_bu','tabungan_otorisasi_data_bd','tabungan_aktivitas_data_bi','tabungan_aktivitas_data_bu','tabungan_aktivitas_data_bd')")->fetch_row()[0]===6;
}
function savings_actor(mysqli $db,int $id,bool $super=false):array {
    $s=$db->prepare('SELECT id,nama,username,role,is_active FROM admin WHERE id=?');$s->bind_param('i',$id);$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();
    if(!$a||!(int)$a['is_active']||!operational_role($a['role'])||($super&&$a['role']!=='super_admin')||!in_array(unit_active_id(),[1,2,3],true))throw new RuntimeException('Akun atau unit tidak diizinkan untuk koreksi tabungan.');return $a;
}
function savings_reason(string $reason,int $max=500):string {
    $reason=trim($reason);if(mb_strlen($reason)<5||mb_strlen($reason)>$max)throw new RuntimeException('Alasan wajib diisi 5 sampai '.$max.' karakter.');return $reason;
}
function savings_locked_ledger(mysqli $db,string $nis):array {
    require_once __DIR__.'/legacy_full_read.php';lop_bank_assert_ready($db,$nis);
    $s=$db->prepare('SELECT id,unit_id,NO_INDUK,NAMA,NO_induk_diknas,KELAS FROM siswa WHERE NO_INDUK=? FOR UPDATE');$s->bind_param('s',$nis);$s->execute();$student=$s->get_result()->fetch_assoc();$s->close();if(!$student)throw new RuntimeException('Siswa tidak ditemukan dalam unit ini.');
    $s=$db->prepare('SELECT SALDO FROM tabungan WHERE NO_INDUK=? FOR UPDATE');$s->bind_param('s',$nis);$s->execute();$stored=$s->get_result()->fetch_assoc();$s->close();
    $rows=[];foreach(['masuk'=>'transaksi_m','keluar'=>'transaksi_k'] as $kind=>$table){
        $s=$db->prepare("SELECT id,unit_id,NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan FROM $table WHERE NO_INDUK=? ORDER BY TANGGAL,id FOR UPDATE");$s->bind_param('s',$nis);$s->execute();foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $row){$row['jenis']=$kind;$rows[]=$row;}$s->close();
    }
    $opening=lop_opening($db,(int)$student['id'],(int)$student['unit_id']);
    if(!$opening&&legacy_finance_ready($db))$opening=lf_one($db,'SELECT amount,effective_date FROM legacy_savings_openings WHERE student_id=? AND unit_id=? AND cancelled_at IS NULL',[$student['id'],$student['unit_id']]);
    $openingCents=$opening?lf_cents($opening['amount']):0;
    $sum=$openingCents+array_sum(array_map(static fn($r)=>(int)round($r['MASUK']*100)-(int)round($r['KELUAR']*100),$rows));
    if($sum!==(int)round((float)($stored['SALDO']??0)*100))throw new RuntimeException('Saldo tersimpan tidak sesuai jurnal. Koreksi dibatalkan; lakukan rekonsiliasi terlebih dahulu.');
    return ['student'=>$student,'rows'=>$rows,'balance'=>$sum,'opening'=>$openingCents];
}
function savings_ledger_balance(array $rows,bool $check=true,int $opening=0):int {
    usort($rows,static fn($a,$b)=>strcmp($a['TANGGAL'],$b['TANGGAL'])?: (($a['jenis']==='masuk'?0:1)<=>($b['jenis']==='masuk'?0:1))?:($a['id']<=>$b['id']));
    $saldo=$opening;foreach($rows as $r){$saldo+=(int)round($r['MASUK']*100)-(int)round($r['KELUAR']*100);if($check&&$saldo<0)throw new RuntimeException('Perubahan membuat saldo berjalan negatif. Periksa tanggal, nominal, dan penarikan setelah transaksi ini.');}return $saldo;
}
function savings_snapshot(array $row,array $student):array {
    // A snapshot covers the target only: new deposits do not stale unrelated proposals.
    $keys=['id','unit_id','NO_INDUK','TANGGAL','MASUK','KELUAR','user_id','keterangan','jenis'];$t=array_replace(array_fill_keys($keys,null),array_intersect_key($row,array_flip($keys)));
    $t['NAMA']=$student['NAMA'];$t['NO_induk_diknas']=$student['NO_induk_diknas'];$t['KELAS']=$student['KELAS'];
    $json=json_encode(['transaction'=>$t],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);return ['data'=>['transaction'=>$t],'json'=>$json,'hash'=>hash('sha256',$json)];
}
function savings_target_locked(mysqli $db,string $kind,int $id):array {
    if(!in_array($kind,['masuk','keluar'],true)||$id<1)throw new RuntimeException('Identitas tabungan tidak valid.');
    $table=$kind==='masuk'?'transaksi_m':'transaksi_k';$s=$db->prepare("SELECT NO_INDUK FROM $table WHERE id=?");$s->bind_param('i',$id);$s->execute();$nis=$s->get_result()->fetch_row()[0]??null;$s->close();if(!$nis)throw new RuntimeException('Transaksi tidak ditemukan atau sudah dihapus.');
    $ledger=savings_locked_ledger($db,$nis);foreach($ledger['rows'] as $row)if($row['jenis']===$kind&&(int)$row['id']===$id)return [$row,$ledger];throw new RuntimeException('Transaksi sudah berubah atau dihapus.');
}
function savings_event(mysqli $db,array $row,string $action,int $actor,?array $before,?array $after,string $key,?int $request=null,string $note='',bool $reconstructed=false):void {
    $a=null;if($actor>0){$s=$db->prepare('SELECT id,nama,username,role FROM admin WHERE id=?');$s->bind_param('i',$actor);$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();}
    $actorId=$a?(int)$a['id']:null;$name=$a['nama']??'';$username=$a['username']??'';$role=$a['role']??'';
    $kind=$row['jenis'];$id=(int)$row['id'];$b=$before?json_encode($before,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR):null;$n=$after?json_encode($after,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR):null;$recon=(int)$reconstructed;
    $s=$db->prepare('INSERT INTO tabungan_aktivitas(jenis,transaction_id,event_key,action,actor_id,actor_name,actor_username,actor_role,occurred_at,authorization_id,note,before_snapshot,after_snapshot,reconstructed) VALUES(?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?)');
    $s->bind_param('sississsisssi',$kind,$id,$key,$action,$actorId,$name,$username,$role,$request,$note,$b,$n,$recon);$s->execute();$s->close();
}
function savings_ensure_baseline(mysqli $db,array $row,array $snapshot):void {
    $s=$db->prepare('SELECT 1 FROM tabungan_aktivitas WHERE jenis=? AND transaction_id=? LIMIT 1');$id=(int)$row['id'];$s->bind_param('si',$row['jenis'],$id);$s->execute();$exists=$s->get_result()->num_rows;$s->close();if($exists)return;
    $enriched=savings_enrich_rows($db,[array_merge($row,['nominal'=>$row['MASUK'],'keluar'=>$row['KELUAR']])])[0];
    savings_event($db,$row,'baseline',(int)$enriched['owner_id'],null,$snapshot,'baseline:'.$row['jenis'].':'.$id,null,'Snapshot data sebelum koreksi; waktu input asli tidak direkonstruksi.',true);
}
/** Match savings entry precision so editing a fractional deposit never drops its cents. */
function savings_nominal_value($raw): int|float {
    // Existing approval payloads are JSON numbers, while HTML forms send text.
    if(is_float($raw)){
        if(!is_finite($raw)||abs($raw*100-round($raw*100))>0.00001)throw new RuntimeException('Nominal maksimal dua desimal.');
        $raw=number_format($raw,2,'.','');
    }
    if(!is_string($raw)&&!is_int($raw))throw new RuntimeException('Nominal harus berupa rupiah positif, maksimal 12 digit.');
    require_once __DIR__.'/savings_owners.php';
    $canonical=so_amount((string)$raw);$cents=lf_cents($canonical);
    if(strlen(explode('.',$canonical)[0])>12)throw new RuntimeException('Nominal harus berupa rupiah positif, maksimal 12 digit.');
    return $cents%100===0?intdiv($cents,100):$cents/100;
}
function savings_edit_payload(array $source,string $action):array {
    if($action==='hapus')return [];
    $raw=savings_nominal_value($source['nominal']??null);
    $date=$source['tanggal']??'';if(!is_string($date))throw new RuntimeException('Tanggal tidak valid.');$date=str_replace('T',' ',$date);if(strlen($date)===16)$date.=':00';
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,new DateTimeZone('Asia/Jakarta'));if(!$d||$d->format('Y-m-d H:i:s')!==$date)throw new RuntimeException('Tanggal/waktu WIB tidak valid.');
    if($d>new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))throw new RuntimeException('Tanggal transaksi tidak boleh di masa depan.');
    $note=$source['keterangan']??'';if(!is_string($note)||mb_strlen($note)>255)throw new RuntimeException('Keterangan maksimal 255 karakter.');
    return ['nominal'=>$raw,'tanggal'=>$date,'keterangan'=>trim($note)];
}
function savings_request_find(mysqli $db,int $id,bool $lock=false):?array {
    $s=$db->prepare('SELECT r.*,a.nama requested_by_name,a.username requested_by_username,a.role requested_by_role FROM tabungan_otorisasi r JOIN admin a ON a.id=r.requested_by WHERE r.id=?'.($lock?' FOR UPDATE':''));$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
}
/** All actions are atomic. Lock order: student, account, journals, then request. */
function savings_correct(mysqli $db,string $action,string $kind,int $id,array $source,int $actor,int $requestId=0):int {
    if(!savings_authorization_ready($db))throw new RuntimeException('Otorisasi tabungan belum siap. Jalankan migrasi yang sudah diverifikasi.');
    if(isset($source['reason'])&&!is_string($source['reason']))throw new RuntimeException('Alasan harus berupa teks.');
    $db->begin_transaction();
    try{
        $a=savings_actor($db,$actor,in_array($action,['approve','reject'],true));
        if(in_array($action,['request_edit','request_hapus'],true))throw new RuntimeException('Administrator memakai Edit/Hapus langsung; pengajuan baru Tabungan tidak diperlukan.');
        if(!in_array($action,['request_edit','request_hapus','cancel','approve','reject','direct_edit','direct_hapus'],true))throw new RuntimeException('Tindakan tidak dikenal.');
        $r=$requestId?savings_request_find($db,$requestId):null;
        if($requestId){if(!$r)throw new RuntimeException('Pengajuan tidak ditemukan.');$kind=$r['jenis'];$id=(int)$r['transaction_id'];}
        if(in_array($action,['cancel','reject'],true)){
            if(!$r)throw new RuntimeException('Pengajuan tidak ditemukan.');
            // Follow the same student-before-request order without requiring a healthy monetary journal for a refusal.
            $s=$db->prepare('SELECT id FROM siswa WHERE NO_INDUK=? FOR UPDATE');$s->bind_param('s',$r['no_induk_snapshot']);$s->execute();$s->get_result();$s->close();
            $r=savings_request_find($db,$requestId,true);if(!$r||$r['status']!=='pending')throw new RuntimeException('Pengajuan sudah diproses.');
            if(($action==='cancel'&&(int)$r['requested_by']!==$actor)||($action==='reject'&&(int)$r['requested_by']===$actor))throw new RuntimeException('Keputusan oleh akun ini tidak diizinkan.');
            $status=$action==='cancel'?'cancelled':'rejected';$reason=$action==='cancel'?'Dibatalkan oleh pemohon.':savings_reason((string)($source['reason']??''),1000);
            $s=$db->prepare('UPDATE tabungan_otorisasi SET status=?,decided_by=?,decision_note=?,decided_at=NOW() WHERE id=?');$s->bind_param('sisi',$status,$actor,$reason,$requestId);$s->execute();$s->close();
            $before=json_decode($r['before_snapshot'],true,512,JSON_THROW_ON_ERROR);$row=$before['transaction'];savings_event($db,$row,$status,$actor,$before,null,$status.':'.$requestId,$requestId,$reason);$db->commit();return $id;
        }
        [$row,$ledger]=savings_target_locked($db,$kind,$id);$snapshot=savings_snapshot($row,$ledger['student']);savings_ensure_baseline($db,$row,$snapshot['data']);
        if(isset($source['expected_hash'])&&(!is_string($source['expected_hash'])||!hash_equals($snapshot['hash'],$source['expected_hash'])))throw new RuntimeException('Transaksi berubah sejak formulir dibuka. Muat ulang sebelum mengirim koreksi.');
        if($requestId){$r=savings_request_find($db,$requestId,true);if(!$r||$r['status']!=='pending')throw new RuntimeException('Pengajuan sudah diproses.');}
        $s=$db->prepare("SELECT id FROM tabungan_otorisasi WHERE jenis=? AND transaction_id=? AND status='pending' FOR UPDATE");$s->bind_param('si',$kind,$id);$s->execute();$pending=$s->get_result()->fetch_row()[0]??0;$s->close();
        if(str_starts_with($action,'request_')){
            if(!in_array($a['role'],['admin','kasir'],true))throw new RuntimeException('Super Admin memakai tindakan koreksi langsung.');
            if($pending)throw new RuntimeException('Transaksi sudah memiliki pengajuan menunggu.');
            $operation=$action==='request_hapus'?'hapus':'edit';$payload=savings_edit_payload($source,$operation);$reason=savings_reason((string)($source['reason']??''));$json=json_encode($payload,JSON_THROW_ON_ERROR);$reference='TB-'.($kind==='masuk'?'M':'K').'-'.unit_active_id().'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);$student=$ledger['student'];
            $s=$db->prepare("INSERT INTO tabungan_otorisasi(jenis,transaction_id,transaction_reference,no_induk_snapshot,student_name_snapshot,action,before_snapshot,snapshot_hash,proposed_payload,request_reason,requested_by,requested_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $s->bind_param('sissssssssi',$kind,$id,$reference,$row['NO_INDUK'],$student['NAMA'],$operation,$snapshot['json'],$snapshot['hash'],$json,$reason,$actor);$s->execute();$newId=(int)$db->insert_id;$s->close();
            savings_event($db,$row,$operation==='edit'?'request_edit':'request_delete',$actor,$snapshot['data'],['proposal'=>$payload],'request:'.$newId,$newId,$reason);$db->commit();return $newId;
        }
        if($action==='approve'){
            if(!$r||(int)$r['requested_by']===$actor)throw new RuntimeException('Pemohon tidak boleh menyetujui sendiri.');
            if(!in_array($r['requested_by_role'],['admin','kasir'],true))throw new RuntimeException('Peran pemohon sudah berubah. Tolak pengajuan lalu periksa kembali.');
            if(!hash_equals($r['snapshot_hash'],$snapshot['hash']))throw new RuntimeException('Transaksi berubah sejak pengajuan. Batalkan atau tolak, lalu ajukan kembali.');
            $payload=json_decode($r['proposed_payload'],true,512,JSON_THROW_ON_ERROR);$operation=$r['action'];$payload=savings_edit_payload($payload,$operation);$reason=trim((string)($source['reason']??''));if(mb_strlen($reason)>1000)throw new RuntimeException('Catatan maksimal 1000 karakter.');
        }else{
            if($pending)throw new RuntimeException('Putuskan pengajuan yang menunggu terlebih dahulu.');$operation=$action==='direct_hapus'?'hapus':'edit';$payload=savings_edit_payload($source,$operation);$reason=savings_reason((string)($source['reason']??''));
        }
        $afterRow=$row;if($operation==='edit'){$afterRow['TANGGAL']=$payload['tanggal'];$afterRow['keterangan']=$payload['keterangan'];$afterRow[$kind==='masuk'?'MASUK':'KELUAR']=$payload['nominal'];}
        $changed=[];foreach($ledger['rows'] as $entry){if($entry['jenis']===$kind&&(int)$entry['id']===$id){if($operation==='edit')$changed[]=$afterRow;}else $changed[]=$entry;}
        $balance=savings_ledger_balance($changed,true,$ledger['opening']);$table=$kind==='masuk'?'transaksi_m':'transaksi_k';
        if($operation==='hapus'){$s=$db->prepare("DELETE FROM $table WHERE id=?");$s->bind_param('i',$id);}else{$column=$kind==='masuk'?'MASUK':'KELUAR';$s=$db->prepare("UPDATE $table SET $column=?,TANGGAL=?,keterangan=? WHERE id=?");$s->bind_param('dssi',$payload['nominal'],$payload['tanggal'],$payload['keterangan'],$id);}$s->execute();$s->close();
        $newBalance=$balance/100;$s=$db->prepare('UPDATE tabungan SET SALDO=? WHERE NO_INDUK=?');$s->bind_param('ds',$newBalance,$row['NO_INDUK']);$s->execute();$s->close();
        if($r){$s=$db->prepare("UPDATE tabungan_otorisasi SET status='approved',decided_by=?,decision_note=?,decided_at=NOW(),applied_at=NOW() WHERE id=?");$s->bind_param('isi',$actor,$reason,$requestId);$s->execute();$s->close();savings_event($db,$row,'approved',$actor,$snapshot['data'],null,'approved:'.$requestId,$requestId,$reason);}
        $after=$operation==='edit'?savings_snapshot($afterRow,$ledger['student'])['data']:['deleted'=>true];$before=$snapshot['data'];$before['balance']=$ledger['balance'];$after['balance']=$balance;
        savings_event($db,$row,$operation==='edit'?'edited':'deleted',$actor,$before,$after,($r?'apply:'.$requestId:'direct:'.bin2hex(random_bytes(16))),$requestId?:null,$reason);$db->commit();return $id;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
