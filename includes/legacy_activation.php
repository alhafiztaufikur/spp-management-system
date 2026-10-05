<?php
require_once __DIR__.'/kelas.php';require_once __DIR__.'/spp_billing.php';
/** Explicit first placement; deliberately does not call the automatic student billing sync. */
function legacy_activate(mysqli $db,int $id,int $classId,int $yearId,array $fees,int $actor,string $actorName):void {
    if(array_key_exists('pangkal',$fees))throw new RuntimeException('Formulir aktivasi kedaluwarsa.');
    if(!in_array(unit_active_id(),[1,2,3],true))throw new RuntimeException('Pilih unit untuk aktivasi.');
    foreach(['spp','psb','komite','du'] as $key)if(!isset($fees[$key])||!is_numeric($fees[$key])||(float)$fees[$key]<0||(float)$fees[$key]>999999999999)throw new RuntimeException('Konfirmasikan nominal tarif operasional yang valid.');
    $db->begin_transaction();
    try {
        $access=$db->prepare("SELECT id,role,unit_id FROM admin WHERE id=? AND is_active=1 FOR UPDATE");$access->bind_param('i',$actor);$access->execute();$account=$access->get_result()->fetch_assoc();$access->close();
        if(!$account||!in_array($account['role'],['super_admin','admin','kasir'],true)||($account['role']!=='super_admin'&&(int)$account['unit_id']!==unit_active_id()))throw new RuntimeException('Hak aktivasi atau unit akun sudah berubah.');
        $s=$db->prepare('SELECT * FROM siswa WHERE id=? FOR UPDATE');$s->bind_param('i',$id);$s->execute();$student=$s->get_result()->fetch_assoc();$s->close();
        if(!$student||!(int)$student['legacy_pending']||(int)$student['is_active']||$student['master_kelas_id']!==null)throw new RuntimeException('Siswa bukan Legacy yang menunggu aktivasi; kiriman ulang ditolak.');
        $class=class_find($db,$classId,true,true);if(!$class)throw new RuntimeException('Pilih rombel aktif pada unit siswa.');
        $s=$db->prepare("SELECT * FROM tahun_ajaran WHERE id=? AND status IN ('published','draft') FOR UPDATE");$s->bind_param('i',$yearId);$s->execute();$year=$s->get_result()->fetch_assoc();$s->close();if(!$year)throw new RuntimeException('Pilih tahun ajaran unit yang berlaku.');
        $nis=$student['NO_INDUK'];$s=$db->prepare('SELECT id FROM siswa_tahun_ajaran WHERE no_induk=? FOR UPDATE');$s->bind_param('s',$nis);$s->execute();if($s->get_result()->num_rows)throw new RuntimeException('Legacy sudah mempunyai riwayat; perlu pemeriksaan.');$s->close();
        $level=(string)$class['tingkat'];$spp=(float)$fees['spp'];$komite=(float)$fees['komite'];$psb=(float)$fees['psb'];$du=(float)$fees['du'];
        $rate=spp_current_effective_rate($db,$level,0,$year['label']);
        if($spp!==(float)$rate['net']||((int)$level>0&&$rate['year']==='Belum disiapkan'))throw new RuntimeException('Nominal SPP harus sesuai master tahun/kelas yang dikonfirmasi.');
        $s=$db->prepare('UPDATE siswa SET legacy_pending=0,is_active=1,KELAS=?,master_kelas_id=?,SPP_PERBULAN=?,POMG=?,PSB=?,DAFTAR_ULANG=?,tot_du=? WHERE id=?');$s->bind_param('sidddddi',$level,$classId,$spp,$komite,$psb,$du,$du,$id);$s->execute();$s->close();
        $label=class_label($class);$s=$db->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,'aktif')");$s->bind_param('issisdd',$yearId,$nis,$level,$classId,$label,$spp,$komite);$s->execute();$placement=(int)$db->insert_id;$s->close();
        $s=$db->prepare('UPDATE legacy_student_import SET activated_by=?,activated_at=NOW() WHERE student_id=?');$s->bind_param('ii',$actor,$id);$s->execute();$s->close();
        $before=json_encode($student,JSON_THROW_ON_ERROR);$after=json_encode(['placement_id'=>$placement,'class'=>$label,'year'=>$year['label'],'fees'=>$fees,'bills_created'=>0],JSON_THROW_ON_ERROR);
        $s=$db->prepare("INSERT INTO siswa_audit_log(siswa_id,no_induk_snapshot,aksi,before_data,after_data,admin_id,admin_name) VALUES(?,?,'legacy_activate',?,?,?,?)");$s->bind_param('isssis',$id,$nis,$before,$after,$actor,$actorName);$s->execute();$s->close();$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
