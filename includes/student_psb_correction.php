<?php
require_once __DIR__.'/kelas.php';

function student_psb_correction_state(mysqli $db,int $studentId,bool $lock=false): array {
    $suffix=$lock?' FOR UPDATE':'';$s=$db->prepare('SELECT * FROM siswa WHERE id=?'.$suffix);$s->bind_param('i',$studentId);$s->execute();$student=$s->get_result()->fetch_assoc();$s->close();
    if(!$student||!(int)$student['is_active']||$student['legacy_pending']||(int)$student['KELAS']===0)throw new RuntimeException('Pilih siswa reguler aktif yang salah didaftarkan.');
    $nis=$student['NO_INDUK'];$s=$db->prepare('SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.no_induk=? ORDER BY p.id'.$suffix);$s->bind_param('s',$nis);$s->execute();$placements=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if(count($placements)>1||($placements&&($placements[0]['status']!=='aktif'||$placements[0]['label']!==du_current_academic_year())))throw new RuntimeException('Koreksi awal tidak tersedia setelah proses tahun ajaran.');
    $s=$db->prepare("SELECT (SELECT COUNT(*) FROM bayar WHERE NO_INDUK=?) +(SELECT COUNT(*) FROM bayar_du WHERE no_induk=? AND jumlah>0) +(SELECT COUNT(*) FROM transaksi_otorisasi WHERE no_induk_snapshot=? AND status='pending') +(SELECT COUNT(*) FROM tabungan_otorisasi WHERE no_induk_snapshot=? AND status='pending') +(SELECT COUNT(*) FROM spp_alokasi_batch WHERE no_induk=? AND status='active' AND uang_baru>0) total");$s->bind_param('sssss',$nis,$nis,$nis,$nis,$nis);$s->execute();$used=(int)$s->get_result()->fetch_row()[0];$s->close();
    if($used)throw new RuntimeException('Siswa sudah memiliki pembayaran atau pengajuan pending. Gunakan pemeriksaan rekonsiliasi, bukan koreksi pendaftaran awal.');
    $s=$db->prepare("SELECT COUNT(*) FROM pembayaran_aktivitas WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(after_snapshot,'$.payment.NO_INDUK')),JSON_UNQUOTE(JSON_EXTRACT(before_snapshot,'$.payment.NO_INDUK'))) = ?");$s->bind_param('s',$nis);$s->execute();$historical=(int)$s->get_result()->fetch_row()[0];$s->close();
    if($historical)throw new RuntimeException('Riwayat pembayaran historis ditemukan. Koreksi awal tidak tersedia.');
    $bills=[];foreach(['tagihan_spp','tagihan_komite','tagihan_daftar_ulang'] as $table){$s=$db->prepare("SELECT * FROM $table WHERE no_induk=? ORDER BY id$suffix");$s->bind_param('s',$nis);$s->execute();$bills[$table]=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();}
    $data=['student'=>$student,'placements'=>$placements,'bills'=>$bills];return $data+['hash'=>hash('sha256',json_encode($data,JSON_THROW_ON_ERROR))];
}
function student_correct_initial_psb(mysqli $db,int $studentId,string $expected,float $amount,float $discount,string $reason): void {
    if(!is_finite($amount)||!is_finite($discount)||$amount<=0||$amount>999999999999||$discount<0||$discount>$amount||$amount!==floor($amount)||$discount!==floor($discount))throw new RuntimeException('Nominal PSB/potongan tidak valid.');
    $reason=trim($reason);if(mb_strlen($reason)<5||mb_strlen($reason)>255)throw new RuntimeException('Alasan koreksi harus 5–255 karakter.');
    $db->begin_transaction();try{
        $actor=promotion_actor_lock($db);$state=student_psb_correction_state($db,$studentId,true);
        if(!hash_equals($state['hash'],$expected))throw new RuntimeException('Data siswa/tagihan berubah. Muat ulang dan tinjau kembali.');
        class_ensure_psb($db);$psbId=(int)$db->query("SELECT id FROM master_kelas WHERE tingkat=0 AND kode_rombel='PSB' LIMIT 1 FOR UPDATE")->fetch_row()[0];$nis=$state['student']['NO_INDUK'];$note='Koreksi pendaftaran awal PSB: '.$reason;
        foreach(['tagihan_spp','tagihan_daftar_ulang'] as $table){$s=$db->prepare("UPDATE $table SET status='cancelled',cancel_reason=? WHERE no_induk=?");$s->bind_param('ss',$note,$nis);$s->execute();$s->close();}
        $s=$db->prepare("UPDATE tagihan_komite SET status='cancelled' WHERE no_induk=?");$s->bind_param('s',$nis);$s->execute();$s->close();
        $s=$db->prepare("UPDATE siswa_tahun_ajaran SET status='dibatalkan' WHERE no_induk=?");$s->bind_param('s',$nis);$s->execute();$s->close();
        $s=$db->prepare("UPDATE siswa SET KELAS='0',master_kelas_id=?,asal_psb=1,PSB=?,potongan_psb_nominal=?,SPP_PERBULAN=0 WHERE id=?");$s->bind_param('iddi',$psbId,$amount,$discount,$studentId);$s->execute();$s->close();
        $before=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$after=json_encode(['action'=>'koreksi_pendaftaran_psb','reason'=>$reason,'PSB'=>$amount,'potongan_psb_nominal'=>$discount,'cancelled_placement_ids'=>array_column($state['placements'],'id')],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $s=$db->prepare("INSERT INTO siswa_audit_log(siswa_id,no_induk_snapshot,aksi,before_data,after_data,admin_id,admin_name) VALUES(?,?,'update',?,?,?,?)");$s->bind_param('isssis',$studentId,$nis,$before,$after,$actor['id'],$actor['nama']);$s->execute();$s->close();$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
