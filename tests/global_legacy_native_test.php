<?php
/** Current-schema native fixtures; every write rolls back on the owned report instance. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58470)throw new RuntimeException('Use the isolated report server.');
require_once __DIR__.'/../includes/reports.php';
$checks=[];function gn_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;echo 'PASS '.$label.PHP_EOL;}
$periodFilter=['include_undated'=>true,'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-21'];
foreach(['10 2026'=>true,'11 2026'=>false,'2026/2027'=>true,'2025/2026'=>false,'2026'=>true,'2025'=>false,''=>false,'Imported 2026-10-21'=>false,'2026/2028'=>false] as $period=>$expected)gn_check(report_undated_matches($periodFilter,(string)$period)===$expected,'Undated period proof '.json_encode($period).' matches only the selected interval');
$periodFilter['include_undated']=false;gn_check(!report_undated_matches($periodFilter,'10 2026'),'Unchecked filter excludes undated cash even with matching proof');
function gn_sql(string $sql,array $args=[]):int{global $koneksi;lf_query($koneksi,$sql,$args);return (int)$koneksi->insert_id;}
function gn_report(string $template,string|array $category,string $nis,array $extra=[]):array{global $koneksi;$source=array_replace(['template'=>$template,'kategori'=>$category,'q'=>$nis,'tanggal_awal'=>'2026-11-01','tanggal_akhir'=>'2026-11-21','tahun_ajaran'=>'2026/2027','bulan_awal'=>'11','bulan_akhir'=>'11','tahun_awal'=>'2026','tahun_akhir'=>'2026','siswa_status'=>'all'],$extra);return report_build($koneksi,$template,report_filters($koneksi,$source));}
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];$nis='9799000901';
$koneksi->begin_transaction();
try{
 foreach([1,2,3] as $unit){
    $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);[$first]=unit_level_bounds();$class=hp_one($koneksi,'SELECT * FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 LIMIT 1',[$first]);$label=class_label($class);$year=hp_one($koneksi,"SELECT id FROM tahun_ajaran WHERE label='2026/2027'")['id'];
    $sid=gn_sql('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB,POMG,is_active) VALUES(?,?,?,?,100000.25,5000.10,1)',[$nis,'QA Native Global '.$unit,(string)$first,$class['id']]);
    $placement=gn_sql("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,100000.25,5000.10,'aktif')",[$year,$nis,(string)$first,$class['id'],$label]);
    $master=hp_one($koneksi,'SELECT id FROM master_spp_tahun WHERE tahun_ajaran_id=?',[$year])['id'];
    $bill=gn_sql("INSERT INTO tagihan_spp(master_spp_tahun_id,tahun_ajaran_id,penempatan_id,no_induk,tingkat_snapshot,master_kelas_id,kelas_rombel_snapshot,bulan,tahun,tarif_dasar_snapshot,nominal_tagihan,status) VALUES(?,?,?,?,?,?,?,'11','2026',100000.25,100000.25,'open')",[$master,$year,$placement,$nis,$first,$class['id'],$label]);
    $komite=gn_sql("INSERT INTO tagihan_komite(tahun_ajaran_id,penempatan_id,no_induk,kelas_rombel_snapshot,bulan,tahun,nominal_tagihan) VALUES(?,?,?,?,'11','2026',5000.10)",[$year,$placement,$nis,$label]);
    $duMaster=hp_one($koneksi,'SELECT id FROM Daftar_ulang WHERE tahun_ajaran_id=? AND kelas=?',[$year,(string)$first])['id'];
    $du=gn_sql("INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,'2026/2027',75000.50,75000.50)",[$year,$placement,$duMaster,$nis,(string)$first]);
    $fee=gn_sql("INSERT INTO master_biaya_lain(nama,nominal,is_active) VALUES(?,3000.99,1)",['QA Global Fee '.$unit]);
    $other=gn_sql("INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,status,created_by,master_kelas_id,kelas_rombel_snapshot) VALUES(?,?,?,60000.25,'open',?,?,?)",[$fee,$nis,'QA Global Fee '.$unit,$actor,$class['id'],$label]);
    $payment=gn_sql("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_PSB,U_SPP,U_KOMITE,U_LAIN,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,total_jumlah) VALUES(?,?,?,?,20000.10,25000.13,1000.01,12000.27,'2026-11-14 09:12:34','11','2026',?,'Tunai',70500.76)",[$nis,(string)$first,$class['id'],$label,(string)$actor]);
    $batch=gn_sql("INSERT INTO spp_alokasi_batch(no_induk,bayar_id,tanggal,user_id,komite_required,uang_baru) VALUES(?,?,'2026-11-14 09:12:34',?,0,25000.13)",[$nis,$payment,(string)$actor]);gn_sql('INSERT INTO spp_alokasi(batch_id,tagihan_spp_id,nominal_dari_bayar) VALUES(?,?,25000.13)',[$batch,$bill]);
    gn_sql('INSERT INTO bayar_komite(bayar_id,tagihan_komite_id,nominal) VALUES(?,?,1000.01)',[$payment,$komite]);gn_sql('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,th_ajaran,kelas,jumlah) VALUES(?,?,?,\'2026/2027\',?,12500.25)',[$payment,$du,$nis,(string)$first]);
    gn_sql('INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot) VALUES(?,?,?,?,10000.10)',[$payment,$fee,$other,'QA Global Fee '.$unit]);
    gn_sql('INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot) VALUES(?,?,?,2000.17)',[$payment,$fee,'QA Global Fee '.$unit]);
    foreach(['spp'=>[100000.25,25000.13,75000.12],'komite'=>[5000.10,1000.01,4000.09],'daftar_ulang'=>[75000.50,12500.25,62500.25],'psb'=>[100000.25,20000.10,80000.15]] as $category=>$expected){
        $report=gn_report('status',$category,$nis,['bulan_awal'=>'11']);$r=$report['rows'][0]??[];
        gn_check(count($report['rows'])===1&&abs($r['tagihan']-$expected[0])<.001&&abs($r['terbayar']-$expected[1])<.001&&abs($r['sisa']-$expected[2])<.001,"Unit $unit native $category snapshot, payment and remainder keep exact cents");
    }
    foreach(['daftar_ulang','psb'] as $category){$s=gn_report('status',$category,$nis)['rows'][0];$i=gn_report('per-item',$category,$nis)['rows'][0];gn_check($s['tagihan']===$i['tagihan']&&$s['terbayar']===$i['terbayar']&&$s['sisa']===$i['sisa'],"Unit $unit $category item and status use the same obligation");}
    $scalar=gn_report('penerimaan','semua',$nis);$multi=gn_report('penerimaan',['*'],$nis);gn_check($scalar['rows']===$multi['rows']&&!isset($multi['sections']),"Unit $unit all-category checkbox reads the same combined receipt once");
    $selected=gn_report('penerimaan',['spp','komite'],$nis);gn_check(count($selected['columns'])===6&&abs($selected['rows'][0]['total_penerimaan']-26000.14)<.001,"Unit $unit receipt category selection keeps only selected components");
    $r=gn_report('per-item','spp',$nis)['rows'][0];gn_check(abs($r['total_bayar']-25000.13)<.001&&abs($r['tunggakan']-75000.12)<.001,"Unit $unit per-item agrees with native status");
    $r=gn_report('spp-tahunan','spp',$nis)['rows'][0];gn_check(abs($r['total_bayar']-25000.13)<.001,"Unit $unit annual SPP retains native allocation");
    $r=gn_report('per-item','biaya_lain:'.$fee,$nis);$billed=array_values(array_filter($r['rows'],static fn($row)=>!empty($row['bill_id'])))[0];gn_check(abs($billed['terbayar']-10000.10)<.001&&abs(array_sum(array_column($r['rows'],'terbayar'))-12000.27)<.001,"Unit $unit optional purchase does not repay the old bill");
    $moved=hp_one($koneksi,'SELECT id,tingkat FROM master_kelas WHERE id<>? AND tingkat>0 AND is_placeholder=0 AND is_active=1 LIMIT 1',[$class['id']]);gn_sql('UPDATE siswa SET master_kelas_id=?,KELAS=? WHERE id=?',[$moved['id'],(string)$moved['tingkat'],$sid]);
    foreach(['status','per-item'] as $template){$rows=gn_report($template,'biaya_lain:'.$fee,$nis,['kelas'=>'rombel:'.$class['id']])['rows'];gn_check(count($rows)===1&&$rows[0]['kelas']===$label&&abs($rows[0]['tagihan']-60000.25)<.001,"Unit $unit $template other-fee class filter keeps the bill snapshot after transfer");}
    gn_sql('UPDATE siswa SET master_kelas_id=?,KELAS=? WHERE id=?',[$class['id'],(string)$first,$sid]);
    gn_sql("UPDATE tagihan_biaya_lain SET status='cancelled',cancel_reason='QA cancellation' WHERE id=?",[$other]);
    foreach(['status','per-item'] as $template){$r=gn_report($template,'biaya_lain:'.$fee,$nis)['rows'][0];gn_check($r['status']==='Dibatalkan'&&$r['sisa']===0.0&&abs($r['terbayar']-10000.10)<.001,"Unit $unit $template retains cancelled other-fee bill and its receipts");}
    $r=gn_report('setoran','semua',$nis);gn_check(abs($r['total_setoran']-70500.76)<.001,"Unit $unit settlement includes DU and optional components exactly once");
    $before=hp_one($koneksi,'SELECT COUNT(*) n FROM bayar')['n'];gn_report('spp-tahunan','spp',$nis);gn_report('spp-tahunan','spp',$nis);gn_check(hp_one($koneksi,'SELECT COUNT(*) n FROM bayar')['n']===$before,"Unit $unit report reload creates no payment");
    gn_sql("UPDATE tagihan_spp SET status='cancelled',cancel_reason='QA cancellation' WHERE id=?",[$bill]);$r=gn_report('status','spp',$nis)['rows'][0];gn_check($r['status']==='Dibatalkan'&&$r['sisa']===0.0,"Unit $unit cancelled bill has no outstanding liability");
    gn_sql("UPDATE siswa SET is_active=0 WHERE id=?",[$sid]);gn_sql("UPDATE siswa_tahun_ajaran SET status='lulus' WHERE id=?",[$placement]);
    gn_check(!gn_report('status','spp',$nis,['siswa_status'=>'active'])['rows']&&count(gn_report('status','spp',$nis,['siswa_status'=>'archived'])['rows'])===1,"Unit $unit graduation/archive filtering retains historical bills");
 }
 unit_set_context($koneksi,0);$r=gn_report('status','spp',$nis,['bulan_awal'=>'11']);gn_check(count($r['rows'])===3&&count(array_unique(array_column($r['rows'],'unit_id')))===3,'Same NIS in three units remains three separate identities');
}finally{$koneksi->rollback();}
file_put_contents(getenv('SPP_GLOBAL_QA_DIR').'/native-tests.json',json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT));echo 'OK: '.count($checks)." native report checks, all fixtures rolled back\n";
