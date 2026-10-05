<?php
require_once __DIR__.'/spp_billing.php';

/** Read only: available history, never create years on GET. */
function promotion_year_options(mysqli $db):array {
    if(!in_array(unit_active_id(),[1,2,3],true))return [];
    $today=du_current_academic_year();$unit=unit_active_id();
    $s=$db->prepare("SELECT y.id,y.label,COUNT(p.id) placements FROM tahun_ajaran y JOIN siswa_tahun_ajaran p ON p.tahun_ajaran_id=y.id AND p.unit_id=y.unit_id WHERE y.unit_id=? AND y.label<=? GROUP BY y.id,y.label ORDER BY y.label DESC");
    $s->bind_param('is',$unit,$today);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
}
function promotion_context(mysqli $db,int $sourceYearId,?string $targetYear=null):array {
    $unit=unit_active_id();if(!in_array($unit,[1,2,3],true))throw new RuntimeException('Pilih satu unit untuk proses tahun ajaran.');
    $s=$db->prepare('SELECT id,unit_id,label FROM tahun_ajaran WHERE id=? AND unit_id=?');$s->bind_param('ii',$sourceYearId,$unit);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if(!$row)throw new RuntimeException('Pilih tahun ajaran asal yang tersedia pada unit ini.');
    $label=du_normalize_academic_year($row['label']);
    if(strcmp($label,du_current_academic_year())>0)throw new RuntimeException('Tahun asal belum berjalan; siswa tahun tujuan belum dapat diproses untuk siklus berikutnya.');
    $next=class_next_academic_year_label($label);
    if($targetYear!==null&&du_normalize_academic_year($targetYear)!==$next)throw new RuntimeException('Tahun tujuan harus tepat satu tahun setelah tahun asal.');
    return ['source_year_id'=>(int)$row['id'],'source_year'=>$label,'target_year'=>$next,'unit_id'=>$unit];
}
function promotion_context_from_target(mysqli $db,string $targetYear):array {
    $source=class_previous_academic_year_label($targetYear);$unit=unit_active_id();
    $s=$db->prepare('SELECT id FROM tahun_ajaran WHERE label=? AND unit_id=?');$s->bind_param('si',$source,$unit);$s->execute();$id=(int)($s->get_result()->fetch_row()[0]??0);$s->close();
    return promotion_context($db,$id,$targetYear);
}
function promotion_roster(mysqli $db,int $sourceYearId,int $level):array {
    $ctx=promotion_context($db,$sourceYearId);[$first,$last]=unit_level_bounds();if($level<$first||$level>$last)return [];
    $s=$db->prepare("SELECT s.id student_id,s.unit_id,s.NO_INDUK,s.NO_induk_diknas,s.NAMA,p.kelas KELAS,p.master_kelas_id,p.kelas_rombel_snapshot,p.id source_placement_id,p.status source_status,
      s.is_active,s.legacy_pending,s.KELAS active_level,s.SPP_PERBULAN,s.POMG,mk.tingkat,mk.kode_rombel,mk.is_active source_rombel_active,COALESCE(mk.is_placeholder,1) is_placeholder,
      am.tingkat active_master_level,am.kode_rombel active_code,am.is_placeholder active_placeholder,am.is_active active_rombel,
      EXISTS(SELECT 1 FROM siswa_tahun_ajaran newer JOIN tahun_ajaran ny ON ny.id=newer.tahun_ajaran_id AND ny.unit_id=newer.unit_id WHERE newer.unit_id=p.unit_id AND newer.no_induk=p.no_induk AND ny.label>?) newer
      FROM siswa_tahun_ajaran p JOIN siswa s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id
      LEFT JOIN master_kelas mk ON mk.id=p.master_kelas_id AND mk.unit_id=p.unit_id
      LEFT JOIN master_kelas am ON am.id=s.master_kelas_id AND am.unit_id=s.unit_id
      WHERE p.tahun_ajaran_id=? AND p.unit_id=? AND CAST(p.kelas AS UNSIGNED)=? ORDER BY p.id");
    $s->bind_param('siii',$ctx['source_year'],$sourceYearId,$ctx['unit_id'],$level);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    foreach($rows as &$r){
        $r['kelas_label']=$r['kelas_rombel_snapshot']?:class_label($r);
        $r['blocked_reason']='';
        if($r['source_status']!=='aktif')$r['blocked_reason']='Sudah diproses pada tahun asal.';
        elseif($r['legacy_pending']||!(int)$r['is_active'])$r['blocked_reason']='Siswa Legacy atau tidak aktif.';
        elseif($r['master_kelas_id']!==null&&($r['tingkat']===null||(int)$r['tingkat']!==$level))$r['blocked_reason']='Rombel penempatan asal tidak sesuai tingkatnya.';
        elseif((int)$r['newer'])$r['blocked_reason']='Sudah memiliki penempatan tahun tujuan atau lebih baru.';
        elseif((int)$r['active_level']!==$level||($r['active_master_level']!==null&&(int)$r['active_master_level']!==$level))$r['blocked_reason']='Kelas aktif tidak sesuai penempatan asal.';
        $r['eligible']=$r['blocked_reason']==='';
        $r['promotion_kode_rombel']=((int)$r['active_master_level']===$level&&!(int)$r['active_placeholder']&&(int)$r['active_rombel'])?strtoupper((string)$r['active_code']):($r['active_master_level']===null&&!$r['is_placeholder']&&$r['source_rombel_active']?strtoupper((string)$r['kode_rombel']):'');
        $r['kelas_aktif_label']=$r['promotion_kode_rombel']!==''?$level.$r['promotion_kode_rombel']:'';
    }unset($r);return $rows;
}
function promotion_actor_lock(mysqli $db):array {
    $id=(int)($_SESSION['admin_id']??0);$unit=unit_active_id();
    if(!$id&&PHP_SAPI==='cli'&&getenv('SPP_TEST_ALLOW_MUTATION')==='1'&&preg_match('/^db_spp_(audit|test)_/',(string)$db->query('SELECT DATABASE()')->fetch_row()[0])){
        $id=(int)($db->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0]??0);
    }
    $s=$db->prepare('SELECT id,nama,role,unit_id,is_active FROM admin WHERE id=? FOR UPDATE');$s->bind_param('i',$id);$s->execute();$actor=$s->get_result()->fetch_assoc();$s->close();
    if(!in_array($unit,[1,2,3],true)||!$actor||!(int)$actor['is_active']||!in_array($actor['role'],['super_admin','admin','kasir'],true)||($actor['role']!=='super_admin'&&(int)$actor['unit_id']!==$unit))throw new RuntimeException('Hak proses atau unit akun sudah berubah.');
    return $actor;
}
function promotion_lock_student(mysqli $db,string $nis):array {
    $unit=unit_active_id();$s=$db->prepare('SELECT * FROM siswa WHERE NO_INDUK=? AND unit_id=? FOR UPDATE');$s->bind_param('si',$nis,$unit);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if(!$row||!(int)$row['is_active']||$row['legacy_pending'])throw new RuntimeException('Siswa tidak aktif atau belum diaktifkan dari Legacy.');
    return $row;
}
function promotion_source_lock(mysqli $db,array $student,array $ctx,?int $expectedPlacementId,?int $expectedLevel):array {
    $s=$db->prepare('SELECT * FROM siswa_tahun_ajaran WHERE unit_id=? AND no_induk=? AND tahun_ajaran_id=? FOR UPDATE');
    $s->bind_param('isi',$ctx['unit_id'],$student['NO_INDUK'],$ctx['source_year_id']);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
    [$first,$last]=unit_level_bounds();
    if(!$p||$p['status']!=='aktif'||(int)$p['kelas']<$first||(int)$p['kelas']>$last||($expectedPlacementId!==null&&(int)$p['id']!==$expectedPlacementId)||($expectedLevel!==null&&(int)$p['kelas']!==$expectedLevel))throw new RuntimeException('Penempatan asal sudah berubah atau siswa sudah diproses. Muat ulang konteks.');
    if($p['master_kelas_id']!==null){$sourceClass=class_find($db,(int)$p['master_kelas_id']);if(!$sourceClass||(int)$sourceClass['tingkat']!==(int)$p['kelas'])throw new RuntimeException('Rombel riwayat asal tidak sesuai tingkatnya.');}
    if((int)$student['KELAS']!==(int)$p['kelas'])throw new RuntimeException('Kelas aktif siswa tidak sesuai penempatan tahun asal.');
    if($student['master_kelas_id']!==null){$mk=class_find($db,(int)$student['master_kelas_id']);if(!$mk||(int)$mk['tingkat']!==(int)$p['kelas'])throw new RuntimeException('Rombel aktif tidak sesuai tingkat asal.');}
    $s=$db->prepare("SELECT p.id FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.unit_id=? AND p.no_induk=? AND y.label>? FOR UPDATE");
    $s->bind_param('iss',$ctx['unit_id'],$student['NO_INDUK'],$ctx['source_year']);$s->execute();$newer=$s->get_result()->num_rows;$s->close();
    if($newer)throw new RuntimeException('Siswa sudah memiliki penempatan tujuan atau tahun lebih baru.');
    return $p;
}
function promotion_write_audit(mysqli $db,array $actor,array $student,array $source,array $ctx,?array $target):void {
    $before=json_encode(['student_id'=>$student['id'],'unit_id'=>$ctx['unit_id'],'source'=>$source,'kelas_aktif'=>$student['KELAS']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $after=json_encode($ctx+['target'=>$target,'action'=>$target?'naik_kelas':'lulus'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $action=$target?'naik_kelas':'lulus';$s=$db->prepare('INSERT INTO siswa_audit_log(siswa_id,no_induk_snapshot,aksi,before_data,after_data,admin_id,admin_name) VALUES(?,?,?,?,?,?,?)');
    $s->bind_param('issssis',$student['id'],$student['NO_INDUK'],$action,$before,$after,$actor['id'],$actor['nama']);$s->execute();$s->close();
}
/** Called inside a transaction. Actor → student → target class → source placement/year. */
function promotion_apply_student(mysqli $db,string $nis,int $targetClassId,array $ctx,?int $placementId=null,?int $level=null,bool $graduate=false):array {
    $ctx=promotion_context($db,(int)$ctx['source_year_id'],(string)$ctx['target_year']);
    $actor=promotion_actor_lock($db);$student=promotion_lock_student($db,$nis);
    $target=null;
    if(!$graduate){$target=class_find($db,$targetClassId,true,true);if(!$target||$target['is_placeholder'])throw new RuntimeException('Pilih rombel target yang aktif.');}
    $source=promotion_source_lock($db,$student,$ctx,$placementId,$level);[, $last]=unit_level_bounds();
    if($graduate&&(int)$source['kelas']!==$last)throw new RuntimeException('Kelulusan hanya untuk kelas terakhir pada tahun asal.');
    if(!$graduate&&((int)$source['kelas']===$last||(int)$target['tingkat']!==(int)$source['kelas']+1))throw new RuntimeException('Target wajib tepat satu tingkat di atas penempatan asal.');
    $sourceId=(int)$source['id'];$status=$graduate?'lulus':'pindah';
    $s=$db->prepare("UPDATE siswa_tahun_ajaran SET status=? WHERE id=? AND unit_id=? AND status='aktif'");$s->bind_param('sii',$status,$sourceId,$ctx['unit_id']);$s->execute();$changed=$s->affected_rows;$s->close();
    if($changed!==1)throw new RuntimeException('Siswa sudah diproses; muat ulang halaman.');
    if($graduate){
        $s=$db->prepare('UPDATE siswa SET is_active=0 WHERE id=? AND unit_id=?');$s->bind_param('ii',$student['id'],$ctx['unit_id']);$s->execute();$s->close();promotion_write_audit($db,$actor,$student,$source,$ctx,null);
        return ['student'=>$student['NAMA'],'action'=>'lulus','source_year'=>$ctx['source_year'],'graduation_year'=>$ctx['source_year'],'target_year'=>$ctx['target_year'],'source_placement_id'=>$sourceId];
    }
    $yearId=class_ensure_academic_year($db,$ctx['target_year']);$newLevel=(string)$target['tingkat'];$label=class_label($target);
    $rate=spp_current_effective_rate($db,$newLevel,(float)$student['potongan_spp_nominal'],$ctx['target_year']);$rateReady=$rate['year']!=='Belum disiapkan'&&(float)$rate['base']>0;$spp=$rateReady?(float)$rate['net']:0.0;$komite=(float)$student['POMG'];
    $s=$db->prepare('UPDATE siswa SET KELAS=?,master_kelas_id=?,SPP_PERBULAN=? WHERE id=? AND unit_id=?');$s->bind_param('sidii',$newLevel,$targetClassId,$spp,$student['id'],$ctx['unit_id']);$s->execute();$s->close();
    $s=$db->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,'aktif')");
    $s->bind_param('issisdd',$yearId,$nis,$newLevel,$targetClassId,$label,$spp,$komite);$s->execute();$destination=(int)$db->insert_id;$s->close();
    komite_sync_placement($db,$destination);
    $result=['student'=>$student['NAMA'],'action'=>'naik','source_year'=>$ctx['source_year'],'target_year'=>$ctx['target_year'],'target'=>$label,'source_placement_id'=>$sourceId,'target_placement_id'=>$destination,'spp_status'=>$rateReady?'Sesuai master':'Belum disiapkan','spp'=>$spp];
    promotion_write_audit($db,$actor,$student,$source,$ctx,$result);return $result;
}
function promotion_batch(mysqli $db,array $selected,array $targets,array $ctx,int $level,array $placements=[]):array {
    [$first,$last]=unit_level_bounds();if($level<$first||$level>$last)throw new RuntimeException('Pilih tingkat asal pada unit ini.');
    $selected=array_values(array_unique(array_map('strval',array_filter($selected,'is_scalar'))));if(!$selected)throw new RuntimeException('Pilih minimal satu siswa.');
    promotion_actor_lock($db);
    // Lock selected students in primary-key order before target classes to avoid reverse student edits.
    $q=$db->prepare('SELECT id,NO_INDUK,NAMA FROM siswa WHERE unit_id=? AND NO_INDUK IN ('.implode(',',array_fill(0,count($selected),'?')).') ORDER BY id FOR UPDATE');
    $args=array_merge([$ctx['unit_id']],$selected);$q->bind_param('i'.str_repeat('s',count($selected)),...$args);$q->execute();$ids=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $names=array_column($ids,'NAMA','NO_INDUK');$order=array_column($ids,'NO_INDUK');foreach($selected as $nis)if(!in_array($nis,$order,true))$order[]=$nis;
    $targetIds=[];foreach($selected as $nis)if(isset($targets[$nis])&&is_scalar($targets[$nis]))$targetIds[]=(int)$targets[$nis];sort($targetIds,SORT_NUMERIC);
    foreach(array_unique($targetIds) as $id)if($id>0)class_find($db,$id,false,true);
    $success=[];$fail=[];
    foreach($order as $nis){
        $db->query('SAVEPOINT promotion_student');
        try{
            if($level!==$last&&(!isset($targets[$nis])||!is_scalar($targets[$nis])||!preg_match('/^[0-9]+$/D',(string)$targets[$nis])||(int)$targets[$nis]<=0))throw new RuntimeException('Pilih rombel tujuan yang valid.');
            $expected=$placements?((int)($placements[$nis]??0)):null;
            $success[]=promotion_apply_student($db,$nis,(int)($targets[$nis]??0),$ctx,$expected,$level,$level===$last);
            $db->query('RELEASE SAVEPOINT promotion_student');
        }catch(mysqli_sql_exception $e){if(in_array($e->getCode(),[1205,1213],true))throw $e;$db->query('ROLLBACK TO SAVEPOINT promotion_student');$db->query('RELEASE SAVEPOINT promotion_student');$fail[]=['no_induk'=>$nis,'student'=>$names[$nis]??$nis,'reason'=>'Relasi siswa berubah; proses siswa dibatalkan.'];}
        catch(Throwable $e){$db->query('ROLLBACK TO SAVEPOINT promotion_student');$db->query('RELEASE SAVEPOINT promotion_student');$fail[]=['no_induk'=>$nis,'student'=>$names[$nis]??$nis,'reason'=>$e->getMessage()];}
    }
    return ['level'=>$level,'source_year'=>$ctx['source_year'],'target_year'=>$ctx['target_year'],'attempted'=>count($selected),'successes'=>$success,'failures'=>$fail];
}
function promotion_run_batch(mysqli $db,array $selected,array $targets,int $yearId,string $targetYear,int $level,array $placements):array {
    for($attempt=0;$attempt<3;$attempt++){
        $db->begin_transaction();try{$ctx=promotion_context($db,$yearId,$targetYear);$result=promotion_batch($db,$selected,$targets,$ctx,$level,$placements);$db->commit();return $result;}
        catch(Throwable $e){$db->rollback();if($e instanceof mysqli_sql_exception&&in_array($e->getCode(),[1205,1213],true)){if($attempt<2){usleep(100000*($attempt+1));continue;}throw new RuntimeException('Proses bersamaan belum selesai; muat ulang halaman dan coba lagi.',0,$e);}throw $e;}
    }throw new RuntimeException('Proses bersamaan belum selesai; muat ulang halaman.');
}

