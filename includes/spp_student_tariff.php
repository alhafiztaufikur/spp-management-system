<?php
/** Explicit final SPP rates, scoped to one student and academic period. */
require_once __DIR__.'/spp_student_tariff_constraints.php';
function sct_ready(mysqli $db): bool {
    $key=$db->thread_id;
    return $GLOBALS['sct_ready'][$key]??= (int)$db->query("SELECT
        (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='spp_tarif_siswa_data' AND TABLE_TYPE='BASE TABLE') OR (TABLE_NAME='spp_tarif_siswa' AND TABLE_TYPE='VIEW')))+
        (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('spp_tarif_siswa_data_i','spp_tarif_siswa_data_u','spp_tarif_siswa_data_d'))
    ")->fetch_row()[0]===5 && (($contract=sct_constraint_inspect($db)) && !$contract['missing'] && !$contract['conflicts']);
}
function sct_invalidate(mysqli $db): void {unset($GLOBALS['sct_rules'][$db->thread_id]);}
function sct_rules(mysqli $db): array {
    if(!sct_ready($db))return [];
    $unit=(int)($GLOBALS['app_unit_id']??unit_active_id());$key=$db->thread_id;
    if(!isset($GLOBALS['sct_rules'][$key][$unit])){
        $out=[];
        $rows=$db->query('SELECT r.*,s.NO_INDUK FROM spp_tarif_siswa r JOIN siswa s ON s.id=r.student_id AND s.unit_id=r.unit_id ORDER BY r.period');
        while($r=$rows->fetch_assoc())$out[$r['NO_INDUK']][$r['period']]=$r;
        $GLOBALS['sct_rules'][$key][$unit]=$out;
    }
    return $GLOBALS['sct_rules'][$key][$unit];
}
function sct_rule(mysqli $db,string $nis,string $month,string $year): ?array {
    if(!in_array((int)($GLOBALS['app_unit_id']??unit_active_id()),[1,2,3],true))return null;
    return sct_rules($db)[$nis][$year.'-'.str_pad($month,2,'0',STR_PAD_LEFT)]??null;
}
function sct_period_rate(mysqli $db,string $nis,string $month,string $year,array $fallback): array {
    $rule=sct_rule($db,$nis,$month,$year);
    if(!$rule)return $fallback;
    $value=(float)$rule['nominal_akhir'];
    return ['base'=>$value,'discount_amount'=>0.0,'discount'=>0.0,'net'=>$value,'basis'=>'individual'];
}
function sct_amount($raw): string {
    if(!is_string($raw)||$raw==='')throw new DomainException('Isi nominal tarif akhir.');
    $raw=trim($raw);
    if(preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?$/D',$raw))$raw=str_replace(['.',','],['','.'],$raw);
    elseif(!preg_match('/^[0-9]+\.[0-9]{1,2}$/D',$raw))throw new DomainException('Gunakan nominal positif atau nol dengan maksimal dua desimal.');
    [$whole,$fraction]=array_pad(explode('.',$raw),2,'');$whole=ltrim($whole,'0')?:'0';
    if(strlen($whole)>13)throw new DomainException('Nominal melebihi batas penyimpanan.');
    return $whole.'.'.str_pad($fraction,2,'0');
}

/** Batch bill reads keep the 12-period preview inexpensive for a whole roster. */
function sct_bill_map(mysqli $db,int $masterId,bool $lock=false,?string $nis=null): array {
    $sql="SELECT t.*,EXISTS(SELECT 1 FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id WHERE a.tagihan_spp_id=t.id AND a.unit_id=t.unit_id AND b.status='active') allocated FROM tagihan_spp t WHERE t.master_spp_tahun_id=?";
    if($nis!==null)$sql.=' AND t.no_induk=?';$sql.=' ORDER BY t.id'.($lock?' FOR UPDATE':'');
    $s=$db->prepare($sql);if($nis!==null)$s->bind_param('is',$masterId,$nis);else $s->bind_param('i',$masterId);
    $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();$out=[];
    $paid=$lock?$db->prepare("SELECT a.id FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id WHERE a.tagihan_spp_id=? AND b.status='active' LIMIT 1 FOR UPDATE"):null;
    foreach($rows as $r){
        if($paid){$id=(int)$r['id'];$paid->bind_param('i',$id);$paid->execute();$r['allocated']=$paid->get_result()->num_rows>0?1:0;}
        $out[$r['no_induk']][$r['tahun'].'-'.$r['bulan']]=$r;
    }
    if($paid)$paid->close();return $out;
}
function sct_context(mysqli $db,array $master,array $student,array $rates,array $bills,?array $coverage=null): array {
    require_once __DIR__.'/legacy_full_read.php';
    $nis=(string)$student['no_induk'];$year=(string)$master['label'];
    $base=lop_tariff($db,$nis,$year)??(float)($rates[(int)$student['kelas']]??0);
    $fallback=spp_net_tariff($base,(float)$student['potongan_spp_nominal']);$source=[];
    if(!lb_spp_override($db,$nis,$year))foreach(lb_monthly_plan($db,$nis)['positions'] as $p){
        if($p['category']==='spp'&&$p['year']===$year&&strcasecmp($p['class'],$student['kelas_rombel_snapshot'])===0)$source[$p['period']]=$p;
    }
    $calendar=spp_academic_periods($year);
    $coverage??=lful_monthly_coverage_snapshot($db,[$nis],'spp',array_map(static fn($p)=>$p['tahun'].'-'.$p['bulan'],$calendar));
    $periods=[];
    foreach($calendar as $period){
        $key=$period['tahun'].'-'.$period['bulan'];$bill=$bills[$nis][$key]??null;$rule=sct_rule($db,$nis,$period['bulan'],$period['tahun']);
        $default=isset($source[$key])?spp_net_tariff($source[$key]['gross']/100,$source[$key]['discount']/100):$fallback;
        $rate=sct_period_rate($db,$nis,$period['bulan'],$period['tahun'],$default);
        $paid=($bill&&(int)$bill['allocated']===1)||lb_spp_credit($db,$nis,$period['bulan'],$period['tahun'])>.001;
        $legacyCovered=isset($coverage['coverage'][(int)$student['student_id']]['spp'][$key]);
        $blocked=$legacyCovered||!empty($student['spp_covered_by_psb'])||($bill&&!in_array($bill['status'],['open','waived'],true));
        [$first,$last]=unit_level_bounds();$eligible=(int)($student['is_active']??0)===1&&(int)$student['kelas']>=$first&&(int)$student['kelas']<=$last;
        $periods[]=['period'=>$key,'month'=>$period['bulan'],'year'=>$period['tahun'],'label'=>$period['label'],
            'default'=>$default,'rate'=>$rate['net'],'custom'=>$rule!==null,'revision'=>(int)($rule['revision']??0),
            'bill_id'=>(int)($bill['id']??0),'before'=>$bill?(float)$bill['nominal_tagihan']:null,
            'locked'=>(bool)($paid||$blocked),'status'=>$bill['status']??null,
            'can_publish'=>!$bill&&!$paid&&!$blocked&&$eligible,
            'note'=>$paid?'Pembayaran/kredit dipertahankan':($legacyCovered?'Histori Legacy dipertahankan':($blocked?'Status tagihan dipertahankan':($bill?'Dapat diperbarui':($eligible?'Akan diterbitkan':'Siswa tidak memenuhi syarat penerbitan'))))];
    }
    // Year status has its own locked version check; publishing another student must
    // not invalidate this student's unchanged tariff/payment preview.
    $version=hash('sha256',json_encode([unit_active_id(),(int)$master['id'],(int)$student['student_id'],(int)$student['placement_id'],$student['kelas_rombel_snapshot'],$periods],JSON_THROW_ON_ERROR));
    return ['student_id'=>(int)$student['student_id'],'placement_id'=>(int)$student['placement_id'],'nis'=>$nis,
        'name'=>$student['NAMA'],'class'=>$student['kelas_rombel_snapshot'],'year'=>$year,'version'=>$version,'periods'=>$periods];
}

function sct_context_for_student(mysqli $db,int $masterId,int $studentId): array {
    $master=spp_master_state($db,$masterId);
    $s=$db->prepare("SELECT s.id student_id,s.NAMA,s.is_active,s.potongan_spp_nominal,p.id placement_id,p.no_induk,p.kelas,p.kelas_rombel_snapshot,p.spp_covered_by_psb FROM siswa s JOIN siswa_tahun_ajaran p ON p.no_induk=s.NO_INDUK AND p.unit_id=s.unit_id WHERE s.id=? AND p.tahun_ajaran_id=? AND p.status<>'dibatalkan'");
    $yearId=(int)$master['tahun_ajaran_id'];$s->bind_param('ii',$studentId,$yearId);$s->execute();$student=$s->get_result()->fetch_assoc();$s->close();
    if(!$student)throw new DomainException('Penempatan siswa tidak ditemukan.');
    return sct_context($db,$master,$student,spp_master_rates($db,$masterId),sct_bill_map($db,$masterId,false,(string)$student['no_induk']));
}

/** Same roster and native/Legacy paid definitions as the master page's summary cards. */
function sct_year_summary(mysqli $db,int $masterId,string $year): array {
    $s=$db->prepare("SELECT s.is_active,COUNT(t.id) bill_count FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id LEFT JOIN tagihan_spp t ON t.penempatan_id=p.id WHERE p.status<>'dibatalkan' AND y.label=? AND CAST(p.kelas AS UNSIGNED) ".unit_level_between_sql()." GROUP BY p.id,s.is_active");
    $s->bind_param('s',$year);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();$active=0;$published=0;
    foreach($rows as $r)if((int)$r['is_active']===1){$active++;if((int)$r['bill_count']>0)$published++;}
    $s=$db->prepare("SELECT t.no_induk,t.bulan,t.tahun,t.nominal_tagihan,t.status,COALESCE(SUM(CASE WHEN b.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) paid FROM tagihan_spp t LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=t.id LEFT JOIN spp_alokasi_batch b ON b.id=a.batch_id WHERE t.master_spp_tahun_id=? GROUP BY t.id");
    $s->bind_param('i',$masterId);$s->execute();$bills=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();$unpaid=0;
    foreach($bills as $bill)if($bill['status']==='open'&&(float)$bill['paid']+lb_spp_credit($db,$bill['no_induk'],$bill['bulan'],$bill['tahun'])+.001<(float)$bill['nominal_tagihan'])$unpaid++;
    $pending=$active-$published;
    return ['active'=>$active,'published'=>$published,'pending'=>$pending,'published_percent'=>$active?(int)round(100*$published/$active):0,'pending_percent'=>$active?(int)round(100*$pending/$active):0,'bills'=>count($bills),'unpaid_bills'=>$unpaid,'status'=>spp_master_state($db,$masterId)['status']];
}

/** Caller owns transaction. Lock order follows the existing master tariff correction. */
function sct_save(mysqli $db,int $masterId,int $studentId,string $start,?string $amount,string $reason,string $expected): array {
    if(!sct_ready($db))throw new DomainException('Penyimpanan tarif khusus belum tersedia.');
    if($amount!==null)$amount=sct_amount($amount);
    if(!in_array(unit_active_id(),[1,2,3],true))throw new DomainException('Pilih satu unit operasional.');
    $actor=(int)($_SESSION['admin_id']??0);$a=$db->prepare('SELECT role,unit_id,is_active FROM admin WHERE id=? FOR UPDATE');$a->bind_param('i',$actor);$a->execute();$account=$a->get_result()->fetch_assoc();$a->close();
    if(!$account||!(int)$account['is_active']||!operational_role($account['role'])||($account['role']!=='super_admin'&&(int)$account['unit_id']!==unit_active_id()))throw new DomainException('Akun tidak diizinkan mengubah tarif siswa.');
    $master=spp_master_state($db,$masterId,true);
    if($master['status']==='closed')throw new DomainException('Buka kembali tahun SPP sebelum mengubah tarif siswa.');
    $reason=trim($reason);if(mb_strlen($reason)<5||mb_strlen($reason)>255)throw new DomainException('Isi alasan perubahan 5–255 karakter.');
    $s=$db->prepare('SELECT id,NO_INDUK FROM siswa WHERE id=?');$s->bind_param('i',$studentId);$s->execute();$identity=$s->get_result()->fetch_assoc();$s->close();
    if(!$identity)throw new DomainException('Siswa tidak ditemukan dalam unit ini.');
    $nis=$identity['NO_INDUK'];$s=$db->prepare('SELECT id FROM siswa WHERE NO_INDUK=? FOR UPDATE');$s->bind_param('s',$nis);$s->execute();$s->get_result()->fetch_all();$s->close();
    $s=$db->prepare("SELECT s.id student_id,s.NAMA,s.is_active,s.potongan_spp_nominal,p.id placement_id,p.tahun_ajaran_id,p.no_induk,p.kelas,p.master_kelas_id,p.kelas_rombel_snapshot,p.spp_covered_by_psb FROM siswa s JOIN siswa_tahun_ajaran p ON p.no_induk=s.NO_INDUK AND p.unit_id=s.unit_id WHERE s.id=? AND p.tahun_ajaran_id=? AND p.status<>'dibatalkan' FOR UPDATE");
    $yearId=(int)$master['tahun_ajaran_id'];$s->bind_param('ii',$studentId,$yearId);$s->execute();$student=$s->get_result()->fetch_assoc();$s->close();
    if(!$student)throw new DomainException('Penempatan siswa tidak sesuai tahun ajaran ini.');
    sct_invalidate($db);lb_cache($db,true);$rates=spp_master_rates($db,$masterId,true);$bills=sct_bill_map($db,$masterId,true,$nis);$context=sct_context($db,$master,$student,$rates,$bills);
    if(!hash_equals($context['version'],$expected))throw new DomainException('Tarif atau pembayaran siswa sudah berubah. Muat ulang dan periksa pratinjau kembali.');
    $keys=array_column($context['periods'],'period');$offset=array_search($start,$keys,true);
    if($offset===false)throw new DomainException('Bulan mulai tidak sesuai tahun ajaran yang dipilih.');
    $range=array_slice($context['periods'],$offset);$rules=sct_rules($db)[$nis]??[];$changes=0;
    foreach($range as $p){$old=$rules[$p['period']]??null;if($amount===null?$old!==null:(!$old||(string)$old['nominal_akhir']!==$amount))$changes++;}
    $write=$amount===null?$db->prepare('DELETE FROM spp_tarif_siswa WHERE student_id=? AND period=?'):$db->prepare('INSERT INTO spp_tarif_siswa(student_id,penempatan_id,tahun_ajaran_id,period,nominal_akhir,reason,actor_id) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nominal_akhir=VALUES(nominal_akhir),reason=VALUES(reason),actor_id=VALUES(actor_id),revision=revision+1');
    $update=$db->prepare('UPDATE tagihan_spp SET tarif_dasar_snapshot=?,potongan_nominal_ditetapkan_snapshot=?,potongan_nominal_snapshot=?,nominal_tagihan=?,status=? WHERE id=?');
    $updated=0;$locked=0;$ineligible=0;$after=[];$outcomes=[];$publish=[];
    foreach($range as $p){
        $key=$p['period'];$old=$rules[$key]??null;$changed=$amount===null?$old!==null:(!$old||(string)$old['nominal_akhir']!==$amount);
        if($changed){
            if($amount===null)$write->bind_param('is',$studentId,$key);
            else {$placementId=(int)$student['placement_id'];$write->bind_param('iiisssi',$studentId,$placementId,$yearId,$key,$amount,$reason,$actor);}
            $write->execute();
        }
        if($p['locked']){$locked++;$outcomes[]=['period'=>$key,'bill_id'=>$p['bill_id'],'result'=>'preserved','before'=>$p['before'],'after'=>$p['before'],'reason'=>$p['note']];continue;}
        if(!$p['bill_id']){
            if($p['can_publish'])$publish[]=$key;else $ineligible++;
            $outcomes[]=['period'=>$key,'bill_id'=>0,'result'=>$p['can_publish']?'pending_publication':'ineligible','reason'=>$p['note']];continue;
        }
        $net=$amount===null?$p['default']:['base'=>(float)$amount,'discount_amount'=>0.0,'discount'=>0.0,'net'=>(float)$amount];
        $status=$net['net']<=.001?'waived':'open';$bill=$bills[$nis][$key];
        if(abs((float)$bill['tarif_dasar_snapshot']-$net['base'])<.001&&abs((float)$bill['potongan_nominal_ditetapkan_snapshot']-$net['discount_amount'])<.001&&abs((float)$bill['potongan_nominal_snapshot']-$net['discount'])<.001&&abs((float)$bill['nominal_tagihan']-$net['net'])<.001&&$bill['status']===$status){$outcomes[]=['period'=>$key,'bill_id'=>$p['bill_id'],'result'=>'unchanged','before'=>$p['before'],'after'=>$net['net']];continue;}
        $update->bind_param('ddddsi',$net['base'],$net['discount_amount'],$net['discount'],$net['net'],$status,$p['bill_id']);$update->execute();$updated++;
        $after[]=['period'=>$key,'bill_id'=>$p['bill_id'],'before'=>$p['before'],'after'=>$net['net']];
        $outcomes[]=['period'=>$key,'bill_id'=>$p['bill_id'],'result'=>'updated','before'=>$p['before'],'after'=>$net['net']];
    }
    $write->close();$update->close();sct_invalidate($db);
    $created=0;
    if($publish){
        foreach($rates as $level=>$rate)if($rate<=0&&lop_tariff($db,$nis,$master['label'])===null)throw new DomainException('Lengkapi tarif dasar kelas '.$level.' sebelum menerbitkan.');
        $placement=$student;$placement['id']=$student['placement_id'];
        $emitted=spp_publish_placement_periods($db,$masterId,$master,$placement,$rates,substr($start,5,2),$publish,false);$created=$emitted['created'];
        $now=sct_bill_map($db,$masterId,true,$nis);
        foreach($outcomes as &$outcome)if($outcome['result']==='pending_publication'){
            $bill=$now[$nis][$outcome['period']]??null;
            if(!$bill)throw new RuntimeException('Penerbitan tagihan belum lengkap. Perubahan dibatalkan.');
            $outcome=['period'=>$outcome['period'],'bill_id'=>(int)$bill['id'],'result'=>'created','before'=>null,'after'=>(float)$bill['nominal_tagihan']];
        }unset($outcome);
        if($created)$db->query("UPDATE master_spp_tahun SET status='published',published_at=COALESCE(published_at,NOW()) WHERE id=".(int)$masterId." AND status='draft'");
    }
    if($changes||$updated||$created)spp_write_audit($db,$masterId,$nis,$amount===null?'reset_tarif_siswa':'simpan_tarif_siswa',
        ['periods'=>$range],['student_id'=>$studentId,'start'=>$start,'nominal_akhir'=>$amount,'reason'=>$reason,'bills'=>$after,'outcomes'=>$outcomes,'rules_changed'=>$changes,'created'=>$created,'locked'=>$locked,'ineligible'=>$ineligible],$updated+$created);
    return ['updated'=>$updated,'created'=>$created,'locked'=>$locked,'ineligible'=>$ineligible,'rules'=>$changes];
}
