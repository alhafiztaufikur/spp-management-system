<?php
require_once __DIR__.'/student_out_schema.php';

function sout_rows(mysqli $db,string $sql,array $args=[]): array {
    $s=$db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
}
function sout_write(mysqli $db,string $sql,array $args=[]): int {
    $s=$db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$changed=$s->affected_rows;$s->close();return $changed;
}
function sout_now(?string $stamp=null): DateTimeImmutable {
    $zone=new DateTimeZone('Asia/Jakarta');$date=$stamp===null?new DateTimeImmutable('now',$zone):DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$stamp,$zone);
    if(!$date||($stamp!==null&&$date->format('Y-m-d H:i:s')!==$stamp))throw new DomainException('Waktu keputusan OUT tidak valid.');return $date;
}
function sout_year(DateTimeImmutable $date): string {$y=(int)$date->format('Y')-((int)$date->format('n')<7?1:0);return $y.'/'.($y+1);}
function sout_student(mysqli $db,string $nis,bool $lock=false,?int $unit=null): ?array {
    $args=[$nis];if($unit!==null)$args[]=$unit;
    $open=sout_ready($db)?' OR EXISTS(SELECT 1 FROM student_out_periods o WHERE o.student_id=s.id AND o.unit_id=s.unit_id AND o.returned_at IS NULL)':'';
    $r=sout_rows($db,"SELECT s.*, (s.KELAS='OUT' OR COALESCE(m.jenis,'')='out' OR (s.master_kelas_id IS NULL AND EXISTS(SELECT 1 FROM legacy_op_owners o WHERE o.student_id=s.id AND o.unit_id=s.unit_id AND o.kind='out'))$open) is_out FROM siswa s LEFT JOIN master_kelas m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id WHERE s.NO_INDUK=?".($unit!==null?' AND s.unit_id=?':'').($lock?' FOR UPDATE':''),$args);
    if(count($r)>1)throw new DomainException('Pilih satu unit siswa.');return $r[0]??null;
}
function sout_assert_student(mysqli $db,string $nis,bool $lock=true): void {
    $s=sout_student($db,$nis,$lock);if($s&&!empty($s['is_out']))throw new DomainException('Siswa OUT tidak dapat melakukan transaksi.');
}
function sout_assert_owner(mysqli $db,int $ownerId,bool $lock=true): void {
    $r=sout_rows($db,'SELECT * FROM legacy_op_owners WHERE id=?'.($lock?' FOR UPDATE':''),[$ownerId])[0]??null;if(!$r)return;
    if($r['student_id']){$s=sout_rows($db,'SELECT NO_INDUK FROM siswa WHERE id=? AND unit_id=?',[$r['student_id'],$r['unit_id']])[0]??null;if($s)sout_assert_student($db,$s['NO_INDUK'],$lock);}
    elseif($r['kind']==='out')throw new DomainException('Rekening OUT tidak dapat melakukan transaksi.');
}
function sout_period_blocked(mysqli $db,string $nis,string $component,string $period,?int $unit=null): bool {
    if(!in_array($component,['spp','komite','du'],true)||!sout_ready($db))return false;
    $unit??=unit_active_id();if($unit<1||$unit>3)return false;
    $key=$unit.'|'.$nis;$policies=$GLOBALS['sout_periods'][$db->thread_id][$key]??=sout_rows($db,'SELECT o.* FROM student_out_periods o JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE s.NO_INDUK=? AND s.unit_id=?',[$nis,$unit]);
    foreach($policies as $p){
        if($component==='du'){
            if($period>$p['departure_year']&&($p['resume_month']===null||$period<sout_year(sout_now($p['resume_month'].' 00:00:00'))))return true;
        }elseif($period>=substr($p['first_free_month'],0,7)&&($p['resume_month']===null||$period<substr($p['resume_month'],0,7)))return true;
    }return false;
}
function sout_invalidate(mysqli $db,bool $billing=false): void {unset($GLOBALS['sout_periods'][$db->thread_id]);if($billing&&empty($GLOBALS['sout_batch'][$db->thread_id])&&function_exists('lb_cache'))lb_cache($db,true,null,true);if(function_exists('sct_invalidate'))sct_invalidate($db);}

/** Read the same native and Legacy credit as the payment form, never the cash date. */
function sout_has_credit(mysqli $db,string $kind,array $bill): bool {
    require_once __DIR__.'/legacy_billing.php';
    $nis=$bill['no_induk'];$id=(int)$bill['id'];
    $linked= !empty(sout_rows($db,'SELECT o.id FROM legacy_op_owners o JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE s.NO_INDUK=? LIMIT 1',[$nis]));
    if($kind==='spp'){
        $native=sout_rows($db,"SELECT COALESCE(SUM(a.nominal_dari_bayar),0) paid FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.status='active' WHERE a.tagihan_spp_id=?",[$id])[0]['paid'];
        if((float)$native>.001)return true;$legacy=$linked?lb_spp_credit($db,$nis,$bill['bulan'],$bill['tahun']):0;
    }elseif($kind==='komite'){
        $native=sout_rows($db,'SELECT COALESCE(SUM(nominal),0) paid FROM bayar_komite WHERE tagihan_komite_id=?',[$id])[0]['paid'];
        if((float)$native>.001)return true;$legacy=$linked?lb_monthly_credit($db,$nis,'komite',$bill['bulan'],$bill['tahun']):0;
    }else{
        $native=sout_rows($db,'SELECT COALESCE(SUM(jumlah),0) paid FROM bayar_du WHERE tagihan_daftar_ulang_id=?',[$id])[0]['paid'];
        if((float)$native>.001)return true;$legacy=$linked?lb_du_bill_credit($db,$nis,$bill['tahun_ajaran_snapshot']):0;
    }
    return (float)$native>.001||(float)$legacy>.001;
}
function sout_bill_tables(): array {return ['spp'=>'tagihan_spp','komite'=>'tagihan_komite','du'=>'tagihan_daftar_ulang'];}

/** Caller owns the student lock and transaction. No payment/savings row is written. */
function sout_cancel(mysqli $db,array $student,array $event): array {
    $changed=[];$protected=[];
    foreach(sout_bill_tables() as $kind=>$table){
        $where=$kind==='du'?'tahun_ajaran_snapshot>?':"CONCAT(tahun,'-',LPAD(bulan,2,'0'),'-01')>=?";
        $cutoff=$kind==='du'?$event['departure_year']:$event['first_free_month'];
        foreach(sout_rows($db,"SELECT * FROM $table WHERE no_induk=? AND status='open' AND $where ORDER BY id FOR UPDATE",[$student['NO_INDUK'],$cutoff]) as $bill){
            if(sout_has_credit($db,$kind,$bill)){$protected[$kind][]=(int)$bill['id'];continue;}
            $extra=$kind==='komite'?'':',cancel_reason=?';$args=[$event['id']];if($extra)$args[]='OUT:'.$event['id'];$args[]=$bill['id'];
            sout_write($db,"UPDATE $table SET status='cancelled',out_period_id=?$extra WHERE id=?",$args);
            $changed[$kind][]=['id'=>(int)$bill['id'],'nominal_tagihan'=>$bill['nominal_tagihan'],'period'=>$kind==='du'?$bill['tahun_ajaran_snapshot']:$bill['tahun'].'-'.$bill['bulan']];
        }
    }
    if($changed){require_once __DIR__.'/spp_billing.php';spp_write_audit($db,null,$student['NO_INDUK'],'out_batalkan',null,['out_period_id'=>(int)$event['id'],'bills'=>$changed,'protected'=>$protected],array_sum(array_map('count',$changed)));}
    sout_invalidate($db);return ['cancelled'=>array_sum(array_map('count',$changed)),'protected'=>array_sum(array_map('count',$protected))];
}
function sout_restore(mysqli $db,array $student,array $event): int {
    $changed=[];$resume=$event['resume_month'];$resumeYear=sout_year(sout_now($resume.' 00:00:00'));
    foreach(sout_bill_tables() as $kind=>$table){
        $where=$kind==='du'?'tahun_ajaran_snapshot>=?':"CONCAT(tahun,'-',LPAD(bulan,2,'0'),'-01')>=?";
        foreach(sout_rows($db,"SELECT * FROM $table WHERE no_induk=? AND out_period_id=? AND status='cancelled' AND $where ORDER BY id FOR UPDATE",[$student['NO_INDUK'],$event['id'],$kind==='du'?$resumeYear:$resume]) as $bill){
            $period=$kind==='du'?$bill['tahun_ajaran_snapshot']:$bill['tahun'].'-'.$bill['bulan'];if(sout_period_blocked($db,$student['NO_INDUK'],$kind,$period))continue;
            if($kind!=='komite'&&$bill['cancel_reason']!=='OUT:'.$event['id'])continue;
            sout_write($db,"UPDATE $table SET status='open',out_period_id=NULL".($kind==='komite'?'':',cancel_reason=NULL').' WHERE id=?',[$bill['id']]);$changed[$kind][]=(int)$bill['id'];
            if($kind==='spp')$GLOBALS['sout_restored_spp'][$db->thread_id][$student['id']][]=(int)$bill['id'];
        }
    }
    $count=array_sum(array_map('count',$changed));if($count){require_once __DIR__.'/spp_billing.php';spp_write_audit($db,null,$student['NO_INDUK'],'out_pulihkan',null,['out_period_id'=>(int)$event['id'],'bills'=>$changed],$count);}sout_invalidate($db);return $count;
}

/** Called after the official class write, inside its existing transaction. */
function sout_transition(mysqli $db,int $studentId,?array $before,string $targetKind,int $actor,?string $stamp=null,string $origin='student'): array {
    if(!sout_ready($db))throw new RuntimeException('Skema OUT belum tersedia. Jalankan installer yang telah diverifikasi.');
    $student=sout_rows($db,'SELECT * FROM siswa WHERE id=? FOR UPDATE',[$studentId])[0]??null;if(!$student)throw new DomainException('Siswa tidak ditemukan pada unit ini.');
    $events=sout_rows($db,'SELECT * FROM student_out_periods WHERE student_id=? AND returned_at IS NULL FOR UPDATE',[$studentId]);$event=$events[0]??null;
    $now=sout_now($stamp);$timestamp=$now->format('Y-m-d H:i:s');$out=['cancelled'=>0,'protected'=>0,'restored'=>0,'event_created'=>0];
    if($targetKind==='out'){
        sout_write($db,'UPDATE siswa SET is_active=0 WHERE id=? AND is_active<>0',[$studentId]);
        if(!$event){
            $free=$now->modify('first day of next month')->format('Y-m-01');
            sout_write($db,'INSERT INTO student_out_periods(unit_id,student_id,departed_at,first_free_month,departure_year,origin,actor_id) VALUES(?,?,?,?,?,?,?)',[$student['unit_id'],$studentId,$timestamp,$free,sout_year($now),$origin,$actor]);
            $event=sout_rows($db,'SELECT * FROM student_out_periods WHERE id=?',[$db->insert_id])[0];$out['event_created']=1;
            require_once __DIR__.'/spp_billing.php';spp_write_audit($db,null,$student['NO_INDUK'],'out_mulai',null,$event,1);
        }
        $out=array_replace($out,sout_cancel($db,$student,$event));
    }elseif($targetKind==='reguler'&&$event){
        if($timestamp<$event['departed_at'])throw new DomainException('Waktu aktif kembali mendahului keputusan OUT.');
        $resume=$now->format('Y-m-01');sout_write($db,'UPDATE student_out_periods SET returned_at=?,resume_month=?,return_actor_id=? WHERE id=?',[$timestamp,$resume,$actor,$event['id']]);
        sout_write($db,'UPDATE siswa SET is_active=1 WHERE id=?',[$studentId]);$event['returned_at']=$timestamp;$event['resume_month']=$resume;
        $GLOBALS['sout_returning'][$db->thread_id][$studentId]=$resume;$GLOBALS['sout_returning_nis'][$db->thread_id][$student['NO_INDUK']]=$studentId;sout_invalidate($db);$out['restored']=sout_restore($db,$student,$event);
        require_once __DIR__.'/spp_billing.php';spp_write_audit($db,null,$student['NO_INDUK'],'out_kembali',null,['out_period_id'=>$event['id'],'returned_at'=>$timestamp,'resume_month'=>$resume],1);
    }
    if($event&&$targetKind!=='reguler')sout_write($db,'UPDATE siswa SET is_active=0 WHERE id=? AND is_active<>0',[$studentId]);
    $out['is_active']=$targetKind==='out'||($event&&$targetKind!=='reguler')?0:($targetKind==='reguler'&&$event?1:(int)$student['is_active']);
    sout_invalidate($db,true);return $out;
}

function sout_complete_return(mysqli $db,int $studentId,?int $placementId): void {
    $resume=$GLOBALS['sout_returning'][$db->thread_id][$studentId]??null;if(!$resume||!$placementId)return;
    require_once __DIR__.'/spp_billing.php';require_once __DIR__.'/komite_billing.php';
    $p=sout_rows($db,'SELECT p.*,s.potongan_spp_nominal FROM siswa_tahun_ajaran p JOIN siswa s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id WHERE p.id=?',[$placementId])[0];
    $m=sout_rows($db,"SELECT m.*,y.label FROM master_spp_tahun m JOIN tahun_ajaran y ON y.id=m.tahun_ajaran_id AND y.unit_id=m.unit_id WHERE m.tahun_ajaran_id=? AND m.status='published'",[$p['tahun_ajaran_id']])[0]??null;
    if($m){
        $allowed=[];foreach(spp_academic_periods($m['label']) as $period)if($period['tahun'].'-'.$period['bulan']>=substr($resume,0,7))$allowed[]=$period['tahun'].'-'.$period['bulan'];
        if($allowed){$rates=spp_master_rates($db,(int)$m['id'],true);spp_publish_placement_periods($db,(int)$m['id'],$m,$p,$rates,substr($allowed[0],5,2),$allowed);spp_sync_active_placement_rates($db,(int)$m['tahun_ajaran_id'],$rates,[$p['no_induk']]);}
    }
    $restored=$GLOBALS['sout_restored_spp'][$db->thread_id][$studentId]??[];
    foreach($restored as $billId){
        $bill=sout_rows($db,"SELECT b.*,y.label,m.status master_status FROM tagihan_spp b JOIN tahun_ajaran y ON y.id=b.tahun_ajaran_id AND y.unit_id=b.unit_id JOIN master_spp_tahun m ON m.id=b.master_spp_tahun_id AND m.unit_id=b.unit_id WHERE b.id=? FOR UPDATE",[$billId])[0]??null;
        if(!$bill||$bill['status']!=='open'||$bill['master_status']==='closed'||sout_has_credit($db,'spp',$bill))continue;
        $custom=sct_rule($db,$p['no_induk'],$bill['bulan'],$bill['tahun']);
        $rate=$custom?sct_period_rate($db,$p['no_induk'],$bill['bulan'],$bill['tahun'],[]):spp_current_effective_rate($db,(string)$bill['tingkat_snapshot'],(float)$p['potongan_spp_nominal'],$bill['label'],$p['no_induk']);
        if(($rate['year']??'')==='Belum disiapkan')continue;
        $declared=$custom?0:(float)$p['potongan_spp_nominal'];$discount=$custom?0:$rate['discount'];$status=$rate['net']<=.001?'waived':'open';
        if(abs((float)$bill['nominal_tagihan']-$rate['net'])<=.001&&abs((float)$bill['tarif_dasar_snapshot']-$rate['base'])<=.001&&abs((float)$bill['potongan_nominal_ditetapkan_snapshot']-$declared)<=.001)continue;
        sout_write($db,'UPDATE tagihan_spp SET tarif_dasar_snapshot=?,potongan_nominal_ditetapkan_snapshot=?,potongan_nominal_snapshot=?,nominal_tagihan=?,status=? WHERE id=?',[$rate['base'],$declared,$discount,$rate['net'],$status,$billId]);
        spp_write_audit($db,(int)$bill['master_spp_tahun_id'],$p['no_induk'],'out_tarif_kembali',$bill,['bill_id'=>$billId,'tarif'=>$rate,'status'=>$status],1);
    }
    unset($GLOBALS['sout_restored_spp'][$db->thread_id][$studentId]);
    unset($GLOBALS['sout_returning'][$db->thread_id][$studentId]);
    unset($GLOBALS['sout_returning_nis'][$db->thread_id][$p['no_induk']]);
}

function sout_rollout_plan(mysqli $db,string $stamp): array {
    $now=sout_now($stamp);$summary=['students'=>0,'events'=>0,'cancelled'=>0,'protected'=>0,'deactivate'=>0];$rows=[];
    foreach(sout_rows($db,"SELECT s.NO_INDUK FROM siswa s LEFT JOIN master_kelas m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id WHERE s.KELAS='OUT' OR m.jenis='out' OR (s.master_kelas_id IS NULL AND EXISTS(SELECT 1 FROM legacy_op_owners o WHERE o.student_id=s.id AND o.unit_id=s.unit_id AND o.kind='out')) ORDER BY s.id") as $identity){
        $s=sout_student($db,$identity['NO_INDUK']);if(empty($s['is_out']))continue;
        $summary['students']++;$event=sout_ready($db)?(sout_rows($db,'SELECT * FROM student_out_periods WHERE student_id=? AND returned_at IS NULL',[$s['id']])[0]??null):null;
        if(!$event)$summary['events']++;if((int)$s['is_active']!==0)$summary['deactivate']++;
        $free=$event['first_free_month']??$now->modify('first day of next month')->format('Y-m-01');$year=$event['departure_year']??sout_year($now);$cancel=0;$protect=0;
        foreach(sout_bill_tables() as $kind=>$table){$where=$kind==='du'?'tahun_ajaran_snapshot>?':"CONCAT(tahun,'-',LPAD(bulan,2,'0'),'-01')>=?";
            foreach(sout_rows($db,"SELECT * FROM $table WHERE no_induk=? AND status='open' AND $where",[$s['NO_INDUK'],$kind==='du'?$year:$free]) as $bill){if(sout_has_credit($db,$kind,$bill))$protect++;else $cancel++;}
        }
        $summary['cancelled']+=$cancel;$summary['protected']+=$protect;$rows[]=['id'=>(int)$s['id'],'nis'=>$s['NO_INDUK'],'first_free_month'=>$free,'cancelled'=>$cancel,'protected'=>$protect];
    }return ['summary'=>$summary,'students'=>$rows];
}
