<?php
/** Client policy, 27 October 2026: first grades include DU in the PSB package. */
require_once __DIR__.'/units.php';
const DU_PSB_POLICY_VERSION=1;
function du_psb_initial_grade(int $unit,int|string $grade): bool {return isset([1=>1,2=>7,3=>10][$unit])&&(int)$grade===[1=>1,2=>7,3=>10][$unit];}
function du_psb_schema_ready(mysqli $db,bool $refresh=false): bool {
    static $cache=[];if($refresh)unset($cache[$db->thread_id]);
    return $cache[$db->thread_id]??=$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('siswa_tahun_ajaran_data','siswa_tahun_ajaran') AND COLUMN_NAME='du_covered_by_psb'")->fetch_row()[0]==2;
}
function du_psb_schema_apply(mysqli $db): void {
    if(PHP_SAPI!=='cli')throw new LogicException('Pemasangan skema DU melalui CLI.');
    $has=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_tahun_ajaran_data' AND COLUMN_NAME='du_covered_by_psb'")->fetch_row()[0];
    if(!$has)$db->query('ALTER TABLE siswa_tahun_ajaran_data ADD COLUMN du_covered_by_psb TINYINT(1) NOT NULL DEFAULT 0');
    $db->query('CREATE OR REPLACE VIEW siswa_tahun_ajaran AS SELECT * FROM siswa_tahun_ajaran_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION');
    if(!du_psb_schema_ready($db,true))throw new RuntimeException('Skema cakupan DU belum lengkap.');
}
function du_psb_decision(array $p,string $year,bool $bill=false,bool $evidence=false): array {
    $out=['status'=>'normal','covered_psb'=>false,'conflict'=>false,'reason'=>''];
    if(!du_psb_initial_grade((int)$p['unit_id'],(string)$p['kelas'])||($p['status']??'aktif')==='dibatalkan')return $out;
    $saved=!empty($p['du_covered_by_psb']);
    if(!$saved&&($year<du_current_academic_year()||$year<'2026/2027'||($p['status']??'')!=='aktif'||empty($p['is_active'])||($p['year_status']??'')==='closed'))return $out;
    if($bill||$evidence)return ['status'=>'conflict','covered_psb'=>false,'conflict'=>true,'reason'=>'Kelas awal tercakup PSB, tetapi terdapat tagihan, pembayaran, atau penetapan DU tersendiri; histori dipertahankan.'];
    return ['status'=>'covered_psb','covered_psb'=>true,'conflict'=>false,'reason'=>'Kebijakan klien: DU kelas awal tercakup PSB; sisa pembayaran mengikuti kewajiban PSB.'];
}
function du_psb_invalidate(mysqli $db): void {foreach(array_keys($GLOBALS['du_psb_states']??[]) as $key)if(str_starts_with($key,$db->thread_id.':'))unset($GLOBALS['du_psb_states'][$key]);}
/** Read-only shared model; no tariff, placement, bill, or audit is created on GET. */
function du_psb_states(mysqli $db,string $year,bool $refresh=false,bool $includeUnapplied=false): array {
    if(!du_psb_schema_ready($db))return [];
    $key=$db->thread_id.':'.(int)($GLOBALS['app_unit_id']??unit_active_id()).':'.$year.':'.(int)$includeUnapplied;
    if($refresh)unset($GLOBALS['du_psb_states'][$key]);if(isset($GLOBALS['du_psb_states'][$key]))return $GLOBALS['du_psb_states'][$key];
    $placements=hp_rows($db,"SELECT p.*,y.label,y.status year_status,s.id student_id,s.NAMA,s.NO_induk_diknas,s.is_active,s.DAFTAR_ULANG,s.potong_du,s.tot_du FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id JOIN siswa s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id WHERE y.label=? AND ((p.unit_id=1 AND p.kelas='1') OR (p.unit_id=2 AND p.kelas='7') OR (p.unit_id=3 AND p.kelas='10')) AND (p.du_covered_by_psb=1 OR (y.label>=? AND y.status<>'closed' AND p.status='aktif' AND s.is_active=1))",[$year,max('2026/2027',du_current_academic_year())]);
    if(!$placements)return $GLOBALS['du_psb_states'][$key]=[];
    $data=lb_cache($db);$bills=[];$history=[];
    foreach(hp_rows($db,'SELECT unit_id,no_induk FROM tagihan_daftar_ulang WHERE tahun_ajaran_snapshot=?',[$year]) as $b)$bills[$b['unit_id'].'|'.$b['no_induk']]=true;
    if(lop_ready($db)){
        $period=lb_du_year_sql('p');
        foreach(hp_rows($db,"SELECT DISTINCT o.unit_id,s.NO_INDUK FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE (p.source_table='bayar_du' OR JSON_SEARCH(p.items,'one','daftar_ulang',NULL,'$[*].category') IS NOT NULL) AND ($period)=?",[$year]) as $r)$history[$r['unit_id'].'|'.$r['NO_INDUK']]=true;
        foreach(hp_rows($db,"SELECT s.unit_id,s.NO_INDUK FROM legacy_debts d JOIN legacy_debt_masters m ON m.id=d.master_id AND m.unit_id=d.unit_id JOIN siswa s ON s.id=d.student_id AND s.unit_id=d.unit_id WHERE m.period=? AND m.component IN ('Daftar Ulang','DU')",[$year]) as $r)$history[$r['unit_id'].'|'.$r['NO_INDUK']]=true;
    }
    $out=[];
    foreach($placements as $p){
        $identity=$p['unit_id'].'|'.$p['no_induk'];$s=$data['students'][$identity]??null;$evidence=isset($history[$identity]);
        if($s){$source=ldu_source_proof($s,$data['tariffs'][$s['source_id']]??[],$year);$evidence=$evidence||!empty($source['known'])||!empty($s['settings']['billing_v1']['du_individual'][$year])||!empty($data['paid'][$s['id']]['du'][$year]);}
        elseif($year===du_current_academic_year()&&(float)$p['DAFTAR_ULANG']>0)$evidence=true;
        $state=du_psb_decision($p,$year,isset($bills[$identity]),$evidence);
        // A schema-only upgrade must still restore/verify under the prior batch.
        // Existing Legacy coverage becomes visible only after its audited write.
        if($s&&!$includeUnapplied&&empty($p['du_covered_by_psb'])&&$state['covered_psb'])$state=['status'=>'normal','covered_psb'=>false,'conflict'=>false,'reason'=>''];
        $out[$identity]=$state+['placement'=>$p,'year'=>$year];
    }
    return $GLOBALS['du_psb_states'][$key]=$out;
}
function du_psb_state(mysqli $db,string $nis,string $year,?int $unit=null): array {return du_psb_states($db,$year)[($unit??(int)($GLOBALS['app_unit_id']??unit_active_id())).'|'.$nis]??['status'=>'normal','covered_psb'=>false,'conflict'=>false];}
/** Caller owns the existing transaction; snapshot plus audit are written together. */
function du_psb_reconcile(mysqli $db,string $year,int $actor,?int $placementId=null): array {
    $out=['covered_psb'=>0,'conflicts'=>0,'changes'=>0];if(!du_psb_schema_ready($db))return $out;
    foreach(du_psb_states($db,$year,true,true) as $state){
        if($placementId!==null&&(int)$state['placement']['id']!==$placementId)continue;
        if($state['conflict']){$out['conflicts']++;continue;}if(!$state['covered_psb'])continue;$out['covered_psb']++;$p=$state['placement'];
        if(!empty($p['du_covered_by_psb']))continue;
        $locked=lf_one($db,'SELECT du_covered_by_psb FROM siswa_tahun_ajaran WHERE id=? FOR UPDATE',[$p['id']]);if(!$locked||$locked['du_covered_by_psb'])continue;
        lf_query($db,'UPDATE siswa_tahun_ajaran SET du_covered_by_psb=1 WHERE id=?',[$p['id']]);
        du_write_audit($db,(int)$p['tahun_ajaran_id'],null,'du_tercakup_psb',['placement_id'=>(int)$p['id'],'covered_psb'=>false],['placement_id'=>(int)$p['id'],'student_id'=>(int)$p['student_id'],'no_induk'=>$p['no_induk'],'unit_id'=>(int)$p['unit_id'],'kelas'=>$p['kelas'],'tahun_ajaran'=>$year,'covered_psb'=>true,'basis'=>'client_psb_policy','policy_version'=>DU_PSB_POLICY_VERSION,'actor'=>$actor,'reason'=>$state['reason']],1);
        $out['changes']++;
    }
    du_psb_invalidate($db);return $out;
}
