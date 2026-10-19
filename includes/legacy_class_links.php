<?php
/** Canonical operational classes; source codes and historical snapshots remain immutable. */
require_once __DIR__.'/special_classes.php';
require_once __DIR__.'/legacy_operational_schema.php';
require_once __DIR__.'/legacy_operational_review_support.php';

function legacy_class_target(mysqli $db,array $group,bool $create=false): ?array {
    $unit=(int)$group['unit_id'];$kind=$group['kind'];$id=(int)($group['master_kelas_id']??0);
    if(!$id){$existing=special_class_query($db,'SELECT master_kelas_id FROM legacy_op_groups WHERE unit_id=? AND code=? AND kind=?',[$unit,$group['code'],$kind])->fetch_assoc();$id=(int)($existing['master_kelas_id']??0);}
    $current=$id?class_find($db,$id):null;
    if($current&&(int)$current['unit_id']!==$unit)throw new DomainException('Kelas tujuan berbeda unit.');
    if($kind==='alumni'){
        $year=$group['graduation_year']??lop_alumni_year($group['code'])??($current&&class_is_alumni($current)?($current['graduation_year']??substr($current['kode_rombel'],6)):null);
        if(!$year||$year<2000||$year>(int)date('Y'))return null;
        $name='Alumni'.$year;$class=special_class_query($db,"SELECT * FROM master_kelas WHERE unit_id=? AND tingkat=0 AND kode_rombel=?",[$unit,$name])->fetch_assoc();
        if($class&&!class_is_alumni($class))throw new DomainException('Nama Alumni sudah dipakai kelas dengan klasifikasi berbeda.');
        if(!$class&&$create){special_class_query($db,"INSERT INTO master_kelas(unit_id,tingkat,kode_rombel,is_placeholder,is_active,legacy_kind,graduation_year) VALUES(?,0,?,0,0,'alumni',?)",[$unit,$name,$year]);$class=class_find($db,(int)$db->insert_id);}
        return $class?:null;
    }
    if($kind==='out'){
        $class=special_class_query($db,"SELECT * FROM master_kelas WHERE unit_id=? AND jenis='out' AND tingkat IS NULL LIMIT 1",[$unit])->fetch_assoc();
        if(!$class&&$create){special_class_query($db,"INSERT INTO master_kelas(unit_id,tingkat,kode_rombel,jenis,is_placeholder,is_active) VALUES(?,NULL,'OUT','out',0,0)",[$unit]);$class=class_find($db,(int)$db->insert_id);}
        return $class?:null;
    }
    if($kind!=='account'||in_array($group['code'],['ARSIP TANPA SISWA','TANPA KELAS'],true))return null;
    if($current){if(class_kind($current)!=='custom')throw new DomainException('Kelompok rekening sudah terkait kelas yang bukan Kelas Khusus.');return $current;}
    $name=trim($group['label']?:$group['code']);
    if($name===''||mb_strlen($name)>100||in_array(mb_strtolower($name),['psb','out'],true)||preg_match('/^alumni\s*20[0-9]{2}$/iu',$name)||preg_match('/[\x00-\x1F\x7F]/u',$name))throw new DomainException('Nama kelompok perlu diperbaiki sebelum disiapkan sebagai kelas khusus.');
    $class=special_class_query($db,"SELECT * FROM master_kelas WHERE unit_id=? AND jenis='custom' AND nama_custom=?",[$unit,$name])->fetch_assoc();
    if(!$class&&$create){special_class_query($db,"INSERT INTO master_kelas(unit_id,tingkat,kode_rombel,jenis,nama_custom,is_placeholder,is_active) VALUES(?,NULL,?,'custom',?,0,1)",[$unit,'C'.strtoupper(bin2hex(random_bytes(4))),$name]);$class=class_find($db,(int)$db->insert_id);}
    return $class?:null;
}

function legacy_class_label(mysqli $db,array $group): string {
    if(!empty($group['master_kelas_id'])&&($class=class_find($db,(int)$group['master_kelas_id'])))return class_label($class);
    if($group['kind']==='alumni'&&($year=lop_alumni_year($group['code'])))return 'Alumni'.$year;
    return $group['label'];
}

/** Caller owns the transaction, unit context and actor verification. */
function legacy_class_repair(mysqli $db,int $actor,bool $apply=false): array {
    if($apply&&PHP_SAPI!=='cli')throw new LogicException('Perbaikan data hanya melalui CLI.');
    $unit=unit_active_id();if(!in_array($unit,[1,2,3],true))throw new DomainException('Pilih satu unit.');
    $groups=special_class_query($db,"SELECT g.* FROM legacy_op_groups g WHERE g.kind IN ('alumni','out','account') AND EXISTS(SELECT 1 FROM legacy_op_owners o JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE o.group_id=g.id AND o.unit_id=g.unit_id AND b.state='completed') ORDER BY g.id")->fetch_all(MYSQLI_ASSOC);
    $changes=[];
    foreach($groups as $g){
        if(in_array($g['code'],['ARSIP TANPA SISWA','TANPA KELAS'],true))continue;
        if($g['kind']==='alumni'){
            $years=[];foreach(special_class_query($db,"SELECT DISTINCT b.manifest FROM legacy_full_batches b JOIN legacy_op_owners o ON o.batch_id=b.id AND o.unit_id=b.unit_id WHERE o.group_id=? AND b.state='completed'",[$g['id']])->fetch_all(MYSQLI_ASSOC) as $batch){$saved=json_decode($batch['manifest'],true,512,JSON_THROW_ON_ERROR)['mapping']['op_groups'][$g['code']]??[];if(($saved['kind']??'')==='alumni'&&isset($saved['graduation_year']))$years[(int)$saved['graduation_year']]=true;}
            if(count($years)>1)throw new DomainException('Tahun alumni yang disahkan berbeda antar batch. Periksa kelompok '.$g['code'].'.');
            if($years)$g['graduation_year']=(int)array_key_first($years);
        }
        $priorTarget=legacy_class_target($db,$g,false);$target=$apply?legacy_class_target($db,$g,true):$priorTarget;$targetId=(int)($target['id']??0);$oldId=(int)($g['master_kelas_id']??0);
        $members=[];
        if($g['kind']==='alumni')foreach(special_class_query($db,"SELECT s.id,s.master_kelas_id,s.KELAS,s.is_active,m.kode_rombel,m.legacy_kind FROM siswa s JOIN legacy_op_owners o ON o.student_id=s.id AND o.unit_id=s.unit_id LEFT JOIN master_kelas m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id WHERE o.group_id=?".($apply?' FOR UPDATE':''),[$g['id']])->fetch_all(MYSQLI_ASSOC) as $s){
            if((int)$s['master_kelas_id']===$targetId&&$targetId)continue;
            // Do not undo a subsequent academic placement or reactivation.
            if((int)$s['is_active']||($s['master_kelas_id']&&!class_is_alumni($s)&&strcasecmp($s['kode_rombel']??'',$g['code'])!==0))continue;
            $members[]=$s;
        }
        if($targetId===$oldId&&$targetId&&!$members)continue;
        $name=$target?class_label($target):($g['kind']==='alumni'?'Alumni'.lop_alumni_year($g['code']):($g['kind']==='out'?'Out':$g['label']));
        $changes[]=['group_id'=>(int)$g['id'],'source'=>$g['code'],'class'=>$name,'create'=>!$priorTarget,'members'=>count($members)];
        if(!$apply)continue;
        if(!$targetId)throw new DomainException('Kelas tujuan tidak dapat ditentukan.');
        special_class_query($db,'UPDATE legacy_op_groups SET master_kelas_id=? WHERE id=?',[$targetId,$g['id']]);
        foreach($members as $s)special_class_query($db,"UPDATE siswa SET master_kelas_id=?,KELAS='0' WHERE id=?",[$targetId,$s['id']]);
        $before=['master_kelas_id'=>$oldId,'members'=>$members];$after=['master_kelas_id'=>$targetId,'student_ids'=>array_map('intval',array_column($members,'id'))];
        special_class_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'class_link',?,?,? ,?,?)",[$unit,$actor,$g['id'],hash('sha256','class-link-v1:'.$unit.':'.$g['id'].':'.random_bytes(32)),'Penyatuan kelas operasional dengan kelompok sumber Legacy.',json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);
    }
    return $changes;
}

/** One membership per student, plus non-student owners with their original internal IDs. */
function special_class_members_sql(mysqli $db): string {
    $native="SELECT s.id, s.unit_id, s.NAMA name,s.NO_INDUK identity,'student' owner_type,s.is_active,s.master_kelas_id class_id,NULL batch_id,NULL owner_id FROM siswa s";
    if(!lop_ready($db))return $native;
    return $native." UNION ALL SELECT o.id,o.unit_id,o.name,o.source_identity,'account',IF(o.state='ready',1,0),g.master_kelas_id,o.batch_id,o.id FROM legacy_op_owners o JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE o.student_id IS NULL AND b.state='completed' AND g.master_kelas_id IS NOT NULL UNION ALL SELECT s.id,s.unit_id,s.NAMA,s.NO_INDUK,'student',s.is_active,g.master_kelas_id,NULL,NULL FROM siswa s JOIN legacy_op_owners o ON o.student_id=s.id AND o.unit_id=s.unit_id JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE b.state='completed' AND g.master_kelas_id IS NOT NULL AND s.master_kelas_id IS NULL";
}
