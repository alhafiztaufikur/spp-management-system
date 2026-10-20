<?php
require_once __DIR__.'/kelas.php';
require_once __DIR__.'/promotion.php';

/** Read-only presentation shared by monitoring, detail and exports. */
function participants_level_label($level):string {
    return match(strtoupper((string)$level)){'0','PSB'=>'PSB','CUSTOM'=>'Nonakademik','OUT'=>'Keluar',default=>ctype_digit((string)$level)?'Kelas '.(int)$level:'Tidak tercatat'};
}
function participants_model(mysqli $db,int $yearId,int $level,string $mode,?array $roster=null):array {
    if(!in_array($mode,['regular','custom'],true))throw new InvalidArgumentException('Sistem kenaikan tidak valid.');
    [$first,$last]=unit_level_bounds();if($level<$first||$level>$last)throw new InvalidArgumentException('Tingkat asal tidak valid.');
    $ctx=promotion_context($db,$yearId,null,true);$roster??=promotion_roster($db,$yearId,$level);
    $classes=[];foreach(class_all($db,false) as $c)$classes[(int)$c['id']]=$c;
    $targets=$mode==='regular'&&$level<$last?class_target_rombel_options($db,$level+1):[];$byCode=[];
    foreach($targets as $c)$byCode[strtoupper($c['kode_rombel'])]=$c['label'];
    $destinations=[];
    if($roster){
        $nis=array_values(array_unique(array_column($roster,'NO_INDUK')));$marks=implode(',',array_fill(0,count($nis),'?'));
        $s=$db->prepare("SELECT p.no_induk,p.kelas,p.kelas_rombel_snapshot,p.master_kelas_id,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.unit_id=? AND y.label>? AND p.status<>'dibatalkan' AND p.no_induk IN ($marks) ORDER BY y.label,p.id");
        $types='is'.str_repeat('s',count($nis));$params=array_merge([$ctx['unit_id'],$ctx['source_year']],$nis);$s->bind_param($types,...$params);$s->execute();
        foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $p){$key=$p['no_induk'];if(!isset($destinations[$key]))$destinations[$key]=['label'=>$p['kelas_rombel_snapshot']?:($classes[(int)$p['master_kelas_id']]['label']??participants_level_label($p['kelas'])),'year'=>$p['label']];}$s->close();
    }
    $rows=[];foreach($roster as $r){
        $status=['aktif'=>'Aktif','pindah'=>'Pindah','lulus'=>'Lulus','dibatalkan'=>'Dibatalkan'][$r['source_status']]??'Tidak tercatat';
        $target='Belum tersedia';$targetNote='';$destination=$destinations[$r['NO_INDUK']]??null;
        if($destination){$target=$destination['label'];$targetNote=$destination['year'].' · Tersimpan';}
        elseif($r['source_status']==='lulus'||($mode==='regular'&&$level===$last)){
            $alumni=$classes[(int)$r['active_class_id']]??null;
            $target=$alumni&&class_is_alumni($alumni)?class_label($alumni):($r['source_status']==='lulus'?'Kelulusan':'Alumni'.promotion_alumni_year($ctx['source_year']));
            $targetNote=$r['source_status']==='lulus'?'Tersimpan':'Rencana';
        }
        elseif($r['eligible']){$target=$mode==='regular'?($byCode[strtoupper((string)$r['promotion_kode_rombel'])]??'Belum ditentukan'):'Belum ditentukan';$targetNote='Rencana · '.$ctx['target_year'];}
        $current=$classes[(int)$r['active_class_id']]['label']??participants_level_label($r['active_level']);
        $rows[]=['id'=>(int)$r['source_placement_id'],'student_id'=>(int)$r['student_id'],'unit'=>$ctx['unit_id'],'nama'=>$r['NAMA'],'nis'=>$r['NO_INDUK'],'diknas'=>trim((string)$r['NO_induk_diknas'])?:'Tidak tercatat',
            'current_level'=>participants_level_label($r['active_level']),'current_rombel'=>$current,'rombel'=>$r['kelas_rombel_snapshot']?:$r['kelas_label'],'status'=>$status,'status_key'=>$r['source_status'],
            'eligible'=>(bool)$r['eligible'],'process'=>$r['eligible']?'Dapat diproses':$r['blocked_reason'],'target'=>$target,'target_note'=>$targetNote];
    }
    return $ctx+['level'=>$level,'mode'=>$mode,'rows'=>$rows];
}
function participants_filter(array $rows,string $query):array {
    $query=mb_strtolower(trim($query),'UTF-8');if($query==='')return $rows;
    return array_values(array_filter($rows,static fn($r)=>str_contains(mb_strtolower($r['nama'].' '.$r['nis'].' '.$r['diknas'].' '.$r['rombel'],'UTF-8'),$query)));
}
function participants_export_rows(array $model,$selected,string $query):array {
    if(!is_array($selected)||count($selected)>10000)throw new InvalidArgumentException('Pilihan peserta tidak valid.');
    if(!$selected)return participants_filter($model['rows'],$query);
    $allowed=array_column($model['rows'],null,'id');$ids=[];
    foreach($selected as $id){if(!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/D',(string)$id)||!isset($allowed[(int)$id]))throw new InvalidArgumentException('Peserta tidak tersedia pada unit, tahun, dan tingkat yang dipilih.');$ids[(int)$id]=true;}
    return array_values(array_filter($model['rows'],static fn($r)=>isset($ids[$r['id']])));
}
function participants_columns():array {
    return ['no'=>'No','nama'=>'Nama Siswa','nis'=>'NIS','diknas'=>'NIS Diknas','current_level'=>'Tingkat Saat Ini','rombel'=>'Rombel Penempatan','status'=>'Status Penempatan','process'=>'Kenaikan','target'=>'Rombel Tujuan'];
}
function participants_export_values(array $rows):array {
    foreach($rows as $i=>&$r){$r['no']=$i+1;if($r['target_note']!=='')$r['target'].=' ('.$r['target_note'].')';}unset($r);return $rows;
}
function participants_e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
