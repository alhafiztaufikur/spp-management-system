<?php
require_once __DIR__.'/psb_spp_schema.php';
require_once __DIR__.'/spp_billing.php';
require_once __DIR__.'/komite_billing.php';

function pspp_find(mysqli $db,int $studentId,bool $lock=false): ?array {
    if(!pspp_ready($db))return null;
    return hp_one($db,'SELECT r.*,y.label,s.NO_INDUK FROM psb_spp_plans r JOIN tahun_ajaran y ON y.id=r.tahun_ajaran_id AND y.unit_id=r.unit_id JOIN siswa s ON s.id=r.student_id AND s.unit_id=r.unit_id WHERE r.student_id=?'.($lock?' FOR UPDATE':''),[$studentId]);
}
function pspp_version(mysqli $db,?array $plan): string {
    if(!$plan)return '';
    $bills=[];foreach(['tagihan_spp','tagihan_komite'] as $t)$bills[$t]=hp_rows($db,"SELECT * FROM $t WHERE rencana_masuk_id=? ORDER BY id",[$plan['id']]);
    $paid=['spp'=>hp_rows($db,"SELECT a.*,b.status allocation_status FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id JOIN tagihan_spp t ON t.id=a.tagihan_spp_id AND t.unit_id=a.unit_id WHERE t.rencana_masuk_id=? ORDER BY a.id",[$plan['id']]),'komite'=>hp_rows($db,'SELECT a.* FROM bayar_komite a JOIN tagihan_komite t ON t.id=a.tagihan_komite_id AND t.unit_id=a.unit_id WHERE t.rencana_masuk_id=? ORDER BY a.bayar_id',[$plan['id']])];
    $rules=hp_rows($db,'SELECT * FROM spp_tarif_siswa WHERE rencana_masuk_id=? ORDER BY period',[$plan['id']]);
    return hash('sha256',json_encode([$plan,$bills,$paid,$rules],JSON_THROW_ON_ERROR));
}
function pspp_display_rate(mysqli $db,array $plan): string {
    $period=spp_open_bills($db,(string)$plan['NO_INDUK'])[0]??null;
    if($period&&(int)$period['tahun_ajaran_id']===(int)$plan['tahun_ajaran_id'])return (string)$period['nominal_tagihan'];
    $rules=sct_rules($db)[$plan['NO_INDUK']]??[];
    foreach($rules as $rule)if((int)$rule['tahun_ajaran_id']===(int)$plan['tahun_ajaran_id'])return (string)$rule['nominal_akhir'];
    return (string)$plan['spp_rate'];
}
function pspp_assert_period(mysqli $db,string $nis,string $month,string $year): void {
    $plan=hp_one($db,'SELECT r.*,y.label FROM psb_spp_plans r JOIN siswa s ON s.id=r.student_id AND s.unit_id=r.unit_id JOIN tahun_ajaran y ON y.id=r.tahun_ajaran_id AND y.unit_id=r.unit_id WHERE s.NO_INDUK=? FOR UPDATE',[$nis]);
    $period=$year.'-'.str_pad($month,2,'0',STR_PAD_LEFT);
    if(!$plan||!in_array($period,array_map(static fn($p)=>$p['tahun'].'-'.$p['bulan'],spp_academic_periods($plan['label'])),true))throw new DomainException('Tagihan SPP–Komite PSB belum disiapkan untuk periode ini. Simpan tarif pada Data Siswa.');
    foreach(['tagihan_spp','tagihan_komite'] as $table)if(!hp_one($db,"SELECT id FROM $table WHERE rencana_masuk_id=? AND no_induk=? AND bulan=? AND tahun=?",[$plan['id'],$nis,$month,$year]))throw new DomainException('Pasangan tagihan rencana masuk belum lengkap.');
}
/** Caller holds actor and student locks and owns the transaction. No retrospective activation. */
function pspp_save(mysqli $db,int $studentId,string $spp,string $komite,string $year,string $expected='',bool $updateSpp=true,bool $updateKomite=true): array {
    if(!pspp_ready($db)||!sct_ready($db))throw new DomainException('Pembaruan skema SPP PSB belum dipasang.');
    $spp=sct_amount($spp);$komite=sct_amount($komite);
    if(!in_array(unit_active_id(),[1,2,3],true))throw new DomainException('Pilih satu unit operasional.');
    $account=hp_one($db,'SELECT role,unit_id,is_active FROM admin WHERE id=? FOR UPDATE',[(int)($_SESSION['admin_id']??0)]);
    if(!$account||!(int)$account['is_active']||!operational_role($account['role'])||$account['role']!==($_SESSION['admin_role']??''))throw new DomainException('Akun tidak diizinkan menetapkan tarif siswa.');
    $student=hp_one($db,'SELECT * FROM siswa WHERE id=? FOR UPDATE',[$studentId]);
    if(!$student||!(int)$student['is_active']||!empty($student['legacy_pending']))throw new DomainException('Siswa aktif dalam unit ini diperlukan untuk menyiapkan tarif.');
    $plan=pspp_find($db,$studentId,true);$before=$plan;
    if($plan&&(!hash_equals(pspp_version($db,$plan),$expected)||$year!==$plan['label']))throw new DomainException('Tarif, penempatan, atau pembayaran sudah berubah. Muat ulang Data Siswa.');
    if($plan){if(!$updateSpp)$spp=(string)$plan['spp_rate'];if(!$updateKomite)$komite=(string)$plan['komite_rate'];}
    [$first]=unit_level_bounds();$nis=(string)$student['NO_INDUK'];$placement=null;
    if(!in_array((string)$student['KELAS'],['0','PSB'],true)){
        $placement=hp_one($db,"SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.no_induk=? AND y.label=? AND p.status='aktif' FOR UPDATE",[$nis,$year]);
        if(!$placement||(int)$placement['kelas']!==$first)throw new DomainException('Tarif masuk hanya untuk PSB atau penempatan kelas awal yang sesuai.');
        if(!empty($placement['spp_covered_by_psb']))throw new DomainException('Cakupan SPP historis dipertahankan. Tarif masuk baru tidak dapat menimpanya.');
    }
    $required=$placement?$placement['label']:du_next_year();
    if(!$plan&&$year!==$required)throw new DomainException('Tahun ajaran formulir sudah berubah. Muat ulang sebelum menyimpan siswa.');
    $master=spp_master_ensure_year($db,$year,true);
    if($master['status']==='closed')throw new DomainException('Tahun SPP tujuan ditutup. Buka kembali melalui Master SPP.');
    if(!$plan){
        foreach(['tagihan_spp','tagihan_komite'] as $table)if(!$placement&&hp_one($db,"SELECT id FROM $table WHERE no_induk=? AND tahun_ajaran_id=? LIMIT 1",[$nis,$master['tahun_ajaran_id']]))throw new DomainException('Tagihan existing perlu diperiksa sebelum mengaktifkan rencana masuk.');
        $unit=unit_active_id();$registered=du_current_academic_year();$actor=(int)$_SESSION['admin_id'];$placementId=$placement?(int)$placement['id']:null;
        $stmt=$db->prepare('INSERT INTO psb_spp_plans(unit_id,student_id,tahun_ajaran_id,registered_year,tingkat,spp_rate,komite_rate,penempatan_id,actor_id) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('iiisissii',$unit,$studentId,$master['tahun_ajaran_id'],$registered,$first,$spp,$komite,$placementId,$actor);$stmt->execute();$stmt->close();
        $plan=pspp_find($db,$studentId,true);
    }elseif($plan['spp_rate']!==$spp||$plan['komite_rate']!==$komite){
        lf_query($db,'UPDATE psb_spp_plans SET spp_rate=?,komite_rate=?,revision=revision+1 WHERE id=?',[$spp,$komite,$plan['id']]);
    }
    $changed=0;$locked=0;$outcomes=[];$actor=(int)$_SESSION['admin_id'];$reason='Tarif masuk melalui Master Siswa';
    $rules=sct_rules($db)[$nis]??[];$bills=sct_bill_map($db,(int)$master['id'],true,$nis);
    foreach(spp_academic_periods($year) as $p){
        $period=$p['tahun'].'-'.$p['bulan'];$bill=$bills[$nis][$period]??null;
        $protected=$bill&&((int)$bill['allocated']||!in_array($bill['status'],['open','waived'],true));
        $protected=$protected||lb_spp_credit($db,$nis,$p['bulan'],$p['tahun'])>.001;
        require_once __DIR__.'/legacy_full_read.php';$protected=$protected||lful_is_covered($db,$nis,'spp',$period);
        if($protected||!$updateSpp){$locked++;$outcomes[]=['period'=>$period,'component'=>'spp','result'=>'preserved'];}
        else{
            $rule=$rules[$period]??null;
            if(!$rule||$rule['nominal_akhir']!==$spp){
                lf_query($db,'INSERT INTO spp_tarif_siswa(student_id,penempatan_id,rencana_masuk_id,tahun_ajaran_id,period,nominal_akhir,reason,actor_id) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nominal_akhir=VALUES(nominal_akhir),revision=revision+1,reason=VALUES(reason),actor_id=VALUES(actor_id)',[$studentId,$plan['penempatan_id'],$plan['id'],$plan['tahun_ajaran_id'],$period,$spp,$reason,$actor]);$changed++;
            }
            if($bill&&abs((float)$bill['nominal_tagihan']-(float)$spp)>.001){
                lf_query($db,'UPDATE tagihan_spp SET tarif_dasar_snapshot=?,potongan_nominal_ditetapkan_snapshot=0,potongan_nominal_snapshot=0,nominal_tagihan=?,status=? WHERE id=?',[$spp,$spp,(float)$spp>.001?'open':'waived',$bill['id']]);$changed++;
                $outcomes[]=['period'=>$period,'component'=>'spp','before'=>$bill['nominal_tagihan'],'after'=>$spp];
            }
        }
        $kb=hp_one($db,'SELECT t.*,EXISTS(SELECT 1 FROM bayar_komite b WHERE b.tagihan_komite_id=t.id) allocated FROM tagihan_komite t WHERE t.no_induk=? AND t.bulan=? AND t.tahun=? FOR UPDATE',[$nis,$p['bulan'],$p['tahun']]);
        $protectedKomite=$kb&&((int)$kb['allocated']||$kb['status']!=='open');
        $protectedKomite=$protectedKomite||lb_monthly_credit($db,$nis,'komite',$p['bulan'],$p['tahun'])>.001||lful_is_covered($db,$nis,'komite',$period);
        if($protectedKomite||!$updateKomite){$locked++;$outcomes[]=['period'=>$period,'component'=>'komite','result'=>'preserved'];}
        elseif(!$kb){
            lf_query($db,'INSERT INTO tagihan_komite(tahun_ajaran_id,penempatan_id,rencana_masuk_id,no_induk,kelas_rombel_snapshot,bulan,tahun,nominal_tagihan) VALUES(?,?,?,?,?,?,?,?)',[$plan['tahun_ajaran_id'],$plan['penempatan_id'],$plan['id'],$nis,$placement['kelas_rombel_snapshot']??(string)$first,$p['bulan'],$p['tahun'],$komite]);$changed++;
        }elseif(abs((float)$kb['nominal_tagihan']-(float)$komite)>.001){
            lf_query($db,'UPDATE tagihan_komite SET nominal_tagihan=? WHERE id=?',[$komite,$kb['id']]);$changed++;
            $outcomes[]=['period'=>$period,'component'=>'komite','before'=>$kb['nominal_tagihan'],'after'=>$komite];
        }
    }
    sct_invalidate($db);
    $basis=['id'=>$plan['penempatan_id'],'rencana_masuk_id'=>(int)$plan['id'],'no_induk'=>$nis,'kelas'=>$first,'master_kelas_id'=>$placement['master_kelas_id']??null,'kelas_rombel_snapshot'=>$placement['kelas_rombel_snapshot']??(string)$first,'potongan_spp_nominal'=>0,'spp_covered_by_psb'=>0];
    $emitted=spp_publish_placement_periods($db,(int)$master['id'],$master,$basis,[$first=>(float)$spp],'07',null,false);$changed+=$emitted['created'];
    foreach(spp_academic_periods($year) as $p)foreach(['spp','komite'] as $kind){
        if(lful_is_covered($db,$nis,$kind,$p['tahun'].'-'.$p['bulan']))continue;
        if(!hp_one($db,"SELECT id FROM tagihan_$kind WHERE no_induk=? AND bulan=? AND tahun=? AND tahun_ajaran_id=?",[$nis,$p['bulan'],$p['tahun'],$plan['tahun_ajaran_id']]))throw new RuntimeException('Penerbitan pasangan tagihan belum lengkap. Seluruh perubahan dibatalkan.');
    }
    $after=pspp_find($db,$studentId,true);
    if($changed||$before!==$after)spp_write_audit($db,(int)$master['id'],$nis,'tarif_masuk_psb',$before,['plan'=>$after,'outcomes'=>$outcomes,'created'=>$emitted['created'],'locked'=>$locked],$changed);
    return ['changed'=>$changed,'locked'=>$locked,'plan'=>$after];
}
/** Bind in place before any regular publication. Financial snapshots and allocations stay intact. */
function pspp_bind_placement(mysqli $db,int $placementId): int {
    if(!pspp_ready($db))return 0;
    $p=hp_one($db,'SELECT p.*,y.label,s.id student_id FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id JOIN siswa s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id WHERE p.id=? FOR UPDATE',[$placementId]);
    if(!$p)return 0;$plan=pspp_find($db,(int)$p['student_id'],true);if(!$plan)return 0;
    if($plan['penempatan_id']!==null){if((int)$plan['penempatan_id']===$placementId)return 0;return 0;}
    if((int)$p['tahun_ajaran_id']!==(int)$plan['tahun_ajaran_id']||(int)$p['kelas']!==(int)$plan['tingkat'])throw new DomainException('Tahun atau tingkat penempatan berbeda dari rencana SPP–Komite '.$plan['label'].'.');
    if($p['status']==='dibatalkan'||!empty($p['spp_covered_by_psb']))throw new DomainException('Status penempatan bertentangan dengan rencana masuk.');
    lf_query($db,'UPDATE psb_spp_plans SET penempatan_id=? WHERE id=?',[$placementId,$plan['id']]);$changed=1;
    foreach(['tagihan_spp','tagihan_komite','spp_tarif_siswa'] as $table){
        $extra=$table==='spp_tarif_siswa'?'':',kelas_rombel_snapshot=?';$params=$table==='spp_tarif_siswa'?[$placementId,$plan['id']]:[$placementId,$p['kelas_rombel_snapshot'],$plan['id']];
        if($table==='tagihan_spp'){$extra.=',master_kelas_id=?';array_splice($params,2,0,[(int)$p['master_kelas_id']]);}
        $stmt=$db->prepare("UPDATE $table SET penempatan_id=?$extra WHERE rencana_masuk_id=? AND penempatan_id IS NULL");$stmt->bind_param(str_repeat('s',count($params)),...$params);$stmt->execute();$changed+=$stmt->affected_rows;$stmt->close();
    }
    spp_write_audit($db,null,$p['no_induk'],'ikat_rencana_masuk',null,['rencana_masuk_id'=>$plan['id'],'penempatan_id'=>$placementId],$changed);
    sct_invalidate($db);return $changed;
}
