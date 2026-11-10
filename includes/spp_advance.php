<?php
require_once __DIR__.'/spp_advance_schema.php';
require_once __DIR__.'/psb_spp.php';
require_once __DIR__.'/legacy_full_read.php';

/** One roster for issuance, individual tariffs and the master summary; no writes on reads. */
function spa_roster(mysqli $db,string $year): array {
    if(unit_active_id()===0){
        $had=array_key_exists('active_unit_id',$_SESSION);$saved=$_SESSION['active_unit_id']??null;$context=(int)($GLOBALS['app_unit_id']??0);$rows=[];
        try{foreach([1,2,3] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);$rows+=spa_roster($db,$year);}}
        finally{if($had)$_SESSION['active_unit_id']=$saved;else unset($_SESSION['active_unit_id']);unit_set_context($db,$context);}return $rows;
    }
    $placements=[];$pending=[];$out=[];$counts=[];$current=du_current_academic_year();
    $students=hp_rows($db,'SELECT s.*,m.jenis rombel_jenis FROM siswa s LEFT JOIN master_kelas m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id ORDER BY s.id');
    $periodKeys=array_map(static fn($p)=>$p['tahun'].'-'.$p['bulan'],spp_academic_periods($year));$coverage=[];
    foreach(['spp','komite'] as $kind)$coverage[$kind]=lful_monthly_coverage_snapshot($db,array_map('strval',array_column($students,'NO_INDUK')),$kind,$periodKeys)['coverage'];
    $plans=[];foreach(hp_rows($db,'SELECT r.student_id,y.label,r.tahun_ajaran_id FROM psb_spp_plans r JOIN tahun_ajaran y ON y.id=r.tahun_ajaran_id AND y.unit_id=r.unit_id WHERE y.label=?',[$year]) as $plan)$plans[(int)$plan['student_id']]=$plan;
    foreach(hp_rows($db,"SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE y.label IN (?,?) AND p.status<>'dibatalkan'",[$year,$current]) as $p)$placements[$p['unit_id'].'|'.$p['no_induk']][$p['label']]=$p;
    if(spa_ready($db))foreach(hp_rows($db,'SELECT t.*,y.label,a.kelas origin_grade FROM tagihan_spp t JOIN tahun_ajaran y ON y.id=t.tahun_ajaran_id AND y.unit_id=t.unit_id JOIN siswa_tahun_ajaran a ON a.id=t.penempatan_asal_id AND a.unit_id=t.unit_id WHERE y.label=?',[$year]) as $b)$pending[$b['unit_id'].'|'.$b['no_induk']]=$b;
    foreach(['spp','komite'] as $kind)foreach(hp_rows($db,"SELECT t.no_induk,t.unit_id,t.bulan,t.tahun,t.status FROM tagihan_$kind t JOIN tahun_ajaran y ON y.id=t.tahun_ajaran_id AND y.unit_id=t.unit_id WHERE y.label=?",[$year]) as $b)$counts[$b['unit_id'].'|'.$b['no_induk']][$kind][$b['tahun'].'-'.$b['bulan']]=$b['status'];
    foreach($students as $s){
        $key=$s['unit_id'].'|'.$s['NO_INDUK'];$p=$placements[$key][$year]??null;$source=$placements[$key][$current]??null;$bill=$pending[$key]??null;
        $bounds=[1=>[1,6],2=>[7,9],3=>[10,12]][(int)$s['unit_id']]??[0,0];[$first,$last]=$bounds;
        $row=null;
        if($p&&(int)$p['kelas']>=$first&&(int)$p['kelas']<=$last)$row=['placement_id'=>$p['id'],'tahun_ajaran_id'=>$p['tahun_ajaran_id'],'kelas'=>$p['kelas'],'master_kelas_id'=>$p['master_kelas_id'],'kelas_rombel_snapshot'=>$p['kelas_rombel_snapshot'],'spp_covered_by_psb'=>$p['spp_covered_by_psb'],'komite_mulai_bulan'=>$p['komite_mulai_bulan']??'07'];
        elseif($bill)$row=['placement_id'=>null,'penempatan_asal_id'=>$bill['penempatan_asal_id'],'tahun_ajaran_id'=>$bill['tahun_ajaran_id'],'kelas'=>$bill['tingkat_snapshot'],'master_kelas_id'=>null,'kelas_rombel_snapshot'=>(string)$bill['tingkat_snapshot'],'spp_covered_by_psb'=>0,'komite_mulai_bulan'=>'07'];
        elseif($year===du_next_year()&&(int)$s['is_active']===1&&!(int)$s['legacy_pending']&&(int)$s['KELAS']>=$first&&(int)$s['KELAS']<$last&&($s['rombel_jenis']===null||$s['rombel_jenis']==='reguler')){
            $reason=(!$source||$source['status']!=='aktif'||(int)$source['kelas']!==(int)$s['KELAS'])?'Penempatan tahun berjalan belum sesuai.':null;
            $row=['placement_id'=>null,'penempatan_asal_id'=>$source['id']??null,'tahun_ajaran_id'=>null,'kelas'=>(int)$s['KELAS']+1,'master_kelas_id'=>null,'kelas_rombel_snapshot'=>(string)((int)$s['KELAS']+1),'spp_covered_by_psb'=>0,'komite_mulai_bulan'=>'07','issue_reason'=>$reason];
        }
        $plan=$plans[(int)$s['id']]??null;
        if($plan&&$plan['label']===$year){$admission=sct_admission_student($db,(int)$s['id'],(int)$plan['tahun_ajaran_id']);if($admission)$row=array_merge($row??[],$admission);}
        if(!$row)continue;
        if($bill)$row['penempatan_asal_id']=$bill['penempatan_asal_id'];
        $row=array_merge(['student_id'=>$s['id'],'unit_id'=>$s['unit_id'],'no_induk'=>$s['NO_INDUK'],'NAMA'=>$s['NAMA'],'NO_induk_diknas'=>$s['NO_induk_diknas'],'is_active'=>$s['is_active'],'potongan_spp_nominal'=>$s['potongan_spp_nominal'],'komite_rate'=>$s['POMG'],'issue_reason'=>null],$row);
        $row['bill_count']=count($counts[$key]['spp']??[]);$row['open_count']=count(array_filter($counts[$key]['spp']??[],static fn($v)=>$v==='open'));
        foreach(['covered_psb'=>'covered','waived'=>'waived','cancelled'=>'cancelled'] as $status=>$label)$row[$label.'_count']=count(array_filter($counts[$key]['spp']??[],static fn($v)=>$v===$status));
        $complete=true;$start=false;
        foreach(spp_academic_periods($year) as $period){if($period['bulan']===($row['komite_mulai_bulan']??'07'))$start=true;if(!$start)continue;$pk=$period['tahun'].'-'.$period['bulan'];foreach(['spp','komite'] as $kind)if(!isset($counts[$key][$kind][$pk])&&!isset($coverage[$kind][(int)$s['id']][$kind][$pk]))$complete=false;}
        $row['billing_complete']=$complete;$out[(int)$s['id']]=$row;
    }
    uasort($out,static fn($a,$b)=>[(int)$a['kelas'],$a['kelas_rombel_snapshot'],$a['NAMA']]<=>[(int)$b['kelas'],$b['kelas_rombel_snapshot'],$b['NAMA']]);return $out;
}

/** Shared emitter; caller owns the unit transaction and student/master locks. */
function spa_issue_student(mysqli $db,array $master,array $row,array $rates): array {
    if(!empty($row['issue_reason']))throw new DomainException($row['no_induk'].': '.$row['issue_reason']);
    $live=hp_one($db,'SELECT * FROM siswa WHERE id=? FOR UPDATE',[$row['student_id']]);
    if(!$live||(int)$live['is_active']!==1||(int)$live['legacy_pending'])throw new DomainException($row['no_induk'].': status siswa berubah. Muat ulang sebelum menerbitkan.');
    if(!empty($row['penempatan_asal_id'])){
        $origin=hp_one($db,'SELECT * FROM siswa_tahun_ajaran WHERE id=? FOR UPDATE',[$row['penempatan_asal_id']]);
        if(!$origin||(int)$origin['kelas']+1!==(int)$row['kelas']||($row['placement_id']===null&&($origin['status']!=='aktif'||(int)$live['KELAS']!==(int)$origin['kelas'])))throw new DomainException($row['no_induk'].': penempatan asal berubah.');
    }
    $row['komite_rate']=$live['POMG'];$row['potongan_spp_nominal']=$live['potongan_spp_nominal'];
    $row['tahun_ajaran_id']=$master['tahun_ajaran_id'];$nis=(string)$row['no_induk'];$basis=$row;$basis['id']=$row['placement_id'];
    if(!empty($row['rencana_masuk_id']))return ['created'=>0,'existing'=>$row['bill_count']??0,'legacy_covered'=>0];
    if(empty($row['placement_id'])&&empty($row['penempatan_asal_id']))throw new DomainException($nis.': dasar kelas tujuan belum tersedia.');
    if(!empty($row['placement_id']))spa_bind_placement($db,(int)$row['placement_id']);
    $result=spp_publish_placement_periods($db,(int)$master['id'],$master,$basis,$rates,(string)($row['komite_mulai_bulan']??'07'),null,false);
    $result['komite_created']=0;
    $start=false;
    foreach(spp_academic_periods($master['label']) as $p){
        if($p['bulan']===($row['komite_mulai_bulan']??'07'))$start=true;if(!$start)continue;
        $period=$p['tahun'].'-'.$p['bulan'];require_once __DIR__.'/student_out.php';if(sout_period_blocked($db,$nis,'komite',$period)||lful_is_covered($db,$nis,'komite',$period))continue;
        if(!hp_one($db,'SELECT id FROM tagihan_komite WHERE no_induk=? AND bulan=? AND tahun=?',[$nis,$p['bulan'],$p['tahun']])){
            lf_query($db,'INSERT INTO tagihan_komite(tahun_ajaran_id,penempatan_id,penempatan_asal_id,no_induk,kelas_rombel_snapshot,bulan,tahun,nominal_tagihan) VALUES(?,?,?,?,?,?,?,?)',[$master['tahun_ajaran_id'],$row['placement_id'],$row['penempatan_asal_id']??null,$nis,$row['kelas_rombel_snapshot'],$p['bulan'],$p['tahun'],$row['komite_rate']]);
            $result['komite_created']++;
        }
        foreach(['spp','komite'] as $kind)if(!lful_is_covered($db,$nis,$kind,$period)&&!hp_one($db,"SELECT id FROM tagihan_$kind WHERE no_induk=? AND tahun_ajaran_id=? AND bulan=? AND tahun=?",[$nis,$master['tahun_ajaran_id'],$p['bulan'],$p['tahun']]))throw new RuntimeException($nis.': pasangan tagihan belum lengkap.');
    }
    return $result;
}
function spa_complete_year(mysqli $db,int $masterId,?array $selected=null): array {
    if(!spa_ready($db))throw new DomainException('Skema SPP tahun berikutnya belum dipasang.');
    if(!in_array(unit_active_id(),[1,2,3],true))throw new DomainException('Pilih satu unit operasional.');
    $master=spp_master_state($db,$masterId,true);if($master['status']==='closed')throw new DomainException('Tahun SPP ditutup.');
    if($master['label']!==du_next_year())throw new DomainException('Penerbitan proyeksi hanya untuk tepat tahun ajaran berikutnya.');
    $rates=spp_master_rates($db,$masterId,true);$roster=spa_roster($db,$master['label']);$out=['students'=>0,'created'=>0,'komite_created'=>0,'existing'=>0];$errors=[];
    if($selected!==null){$wanted=array_fill_keys($selected,true);$roster=array_filter($roster,static fn($r)=>isset($wanted[$r['no_induk']]));if(count($roster)!==count($wanted))throw new DomainException('Pilihan siswa tidak sesuai daftar tujuan. Muat ulang Master SPP.');}
    foreach($roster as $row)if((int)$row['is_active']===1&&!empty($row['issue_reason']))$errors[]=$row['no_induk'].': '.$row['issue_reason'];
    if($errors)throw new DomainException('Penerbitan dibatalkan. '.implode(' ',$errors));
    foreach($roster as $row){
        if((int)$row['is_active']!==1)continue;
        hp_one($db,'SELECT id FROM siswa WHERE id=? FOR UPDATE',[$row['student_id']]);
        $r=spa_issue_student($db,$master,$row,$rates);$out['students']++;$out['created']+=$r['created'];$out['existing']+=$r['existing'];$out['komite_created']+=$r['komite_created']??0;
    }
    lf_query($db,"UPDATE master_spp_tahun SET status='published',published_at=COALESCE(published_at,NOW()) WHERE id=? AND status='draft'",[$masterId]);
    if($out['created']||$out['komite_created'])spp_write_audit($db,$masterId,null,'lengkapi_spp_mendatang',null,$out,$out['created']+$out['komite_created']);
    return $out;
}
/** Bind the very same monthly invoices before regular placement-based sync runs. */
function spa_bind_placement(mysqli $db,int $placementId): int {
    if(!spa_ready($db))return 0;
    $p=hp_one($db,'SELECT * FROM siswa_tahun_ajaran WHERE id=? FOR UPDATE',[$placementId]);if(!$p)return 0;$changed=0;
    foreach(['tagihan_spp','tagihan_komite','spp_tarif_siswa'] as $table){
        $student=$table==='spp_tarif_siswa'?'student_id=(SELECT id FROM siswa WHERE NO_INDUK=?)':'no_induk=?';
        $rows=hp_rows($db,"SELECT * FROM $table WHERE $student AND tahun_ajaran_id=? AND penempatan_asal_id IS NOT NULL FOR UPDATE",[$p['no_induk'],$p['tahun_ajaran_id']]);
        foreach($rows as $r){$a=hp_one($db,'SELECT kelas FROM siswa_tahun_ajaran WHERE id=?',[$r['penempatan_asal_id']]);if(!$a||(int)$p['kelas']!==(int)$a['kelas']+1||$p['status']==='dibatalkan')throw new DomainException('Tingkat penempatan berbeda dari tagihan SPP–Komite mendatang.');if($r['penempatan_id']!==null&&(int)$r['penempatan_id']!==$placementId)throw new DomainException('Tagihan telah terikat pada penempatan lain.');}
        $extra=$table==='spp_tarif_siswa'?'':',kelas_rombel_snapshot=?';$params=$table==='spp_tarif_siswa'?[$placementId]:[$placementId,$p['kelas_rombel_snapshot']];
        if($table==='tagihan_spp'){$extra.=',master_kelas_id=?';$params[]=$p['master_kelas_id'];}
        $params[]=$p['no_induk'];$params[]=$p['tahun_ajaran_id'];
        $stmt=$db->prepare("UPDATE $table SET penempatan_id=?$extra WHERE $student AND tahun_ajaran_id=? AND penempatan_asal_id IS NOT NULL AND (penempatan_id IS NULL".($table==='spp_tarif_siswa'?'':" OR NOT(kelas_rombel_snapshot <=> '".$db->real_escape_string($p['kelas_rombel_snapshot'])."')").')');$stmt->bind_param(str_repeat('s',count($params)),...$params);$stmt->execute();$changed+=$stmt->affected_rows;$stmt->close();
    }
    if($changed)spp_write_audit($db,null,$p['no_induk'],'ikat_spp_mendatang',null,['penempatan_id'=>$placementId],$changed);sct_invalidate($db);return $changed;
}
/** A newly eligible student is included on existing write paths, never on GET. */
function spa_sync_student(mysqli $db,string $nis): void {
    if(!spa_ready($db))return;
    $year=du_next_year();$master=hp_one($db,"SELECT m.*,y.label FROM master_spp_tahun m JOIN tahun_ajaran y ON y.id=m.tahun_ajaran_id AND y.unit_id=m.unit_id WHERE y.label=? AND m.status='published'",[$year]);if(!$master)return;
    $id=(int)(hp_one($db,'SELECT id FROM siswa WHERE NO_INDUK=?',[$nis])['id']??0);$row=spa_roster($db,$year)[$id]??null;
    if($row&&(int)$row['is_active']===1){$r=spa_issue_student($db,$master,$row,spp_master_rates($db,(int)$master['id'],true));$changes=$r['created']+($r['komite_created']??0);if($changes)spp_write_audit($db,(int)$master['id'],$nis,'lengkapi_siswa_mendatang',null,$r,$changes);}
}
function spa_sync_komite_rate(mysqli $db,string $nis,float $rate): int {
    if(!spa_ready($db))return 0;$changed=0;$outcomes=[];
    foreach(hp_rows($db,"SELECT t.* FROM tagihan_komite t JOIN tahun_ajaran y ON y.id=t.tahun_ajaran_id AND y.unit_id=t.unit_id JOIN master_spp_tahun m ON m.tahun_ajaran_id=y.id AND m.unit_id=y.unit_id WHERE t.no_induk=? AND t.penempatan_asal_id IS NOT NULL AND y.label>=? AND m.status<>'closed' AND t.status='open' AND NOT EXISTS(SELECT 1 FROM bayar_komite b WHERE b.tagihan_komite_id=t.id) FOR UPDATE",[$nis,du_current_academic_year()]) as $bill){
        if(lb_monthly_credit($db,$nis,'komite',$bill['bulan'],$bill['tahun'])>.001||lful_is_covered($db,$nis,'komite',$bill['tahun'].'-'.$bill['bulan'])||abs((float)$bill['nominal_tagihan']-$rate)<.001)continue;
        lf_query($db,'UPDATE tagihan_komite SET nominal_tagihan=? WHERE id=?',[$rate,$bill['id']]);$changed++;$outcomes[]=['bill_id'=>$bill['id'],'before'=>$bill['nominal_tagihan'],'after'=>$rate];
    }
    if($changed)spp_write_audit($db,null,$nis,'tarif_komite_mendatang',null,$outcomes,$changed);return $changed;
}
/** Individual SPP publication also prepares its matching Komite periods. */
function spa_pair_individual_bills(mysqli $db,string $nis,int $yearId): int {
    $student=hp_one($db,'SELECT POMG FROM siswa WHERE NO_INDUK=? FOR UPDATE',[$nis]);$created=0;
    foreach(hp_rows($db,'SELECT * FROM tagihan_spp WHERE no_induk=? AND tahun_ajaran_id=? AND penempatan_asal_id IS NOT NULL',[$nis,$yearId]) as $bill){
        if(lful_is_covered($db,$nis,'komite',$bill['tahun'].'-'.$bill['bulan'])||hp_one($db,'SELECT id FROM tagihan_komite WHERE no_induk=? AND bulan=? AND tahun=?',[$nis,$bill['bulan'],$bill['tahun']]))continue;
        lf_query($db,'INSERT INTO tagihan_komite(tahun_ajaran_id,penempatan_id,penempatan_asal_id,no_induk,kelas_rombel_snapshot,bulan,tahun,nominal_tagihan) VALUES(?,?,?,?,?,?,?,?)',[$yearId,$bill['penempatan_id'],$bill['penempatan_asal_id'],$nis,$bill['kelas_rombel_snapshot'],$bill['bulan'],$bill['tahun'],$student['POMG']]);$created++;
    }
    if($created)spp_write_audit($db,null,$nis,'pasangan_komite_mendatang',null,['created'=>$created],$created);return $created;
}
