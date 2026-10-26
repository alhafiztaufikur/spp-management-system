<?php
/** Real-case readback on an explicitly verified private MySQL instance. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest();
$app=getenv('SPP_REPAIR_BASELINE')==='1'?(string)getenv('SPP_REPAIR_BASELINE_ROOT'):dirname(__DIR__);
if(!is_file($app.'/koneksi.php'))throw new RuntimeException('Explicit baseline application root required.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require $app.'/koneksi.php';spp_test_isolated_manifest($koneksi);
require_once $app.'/includes/reports.php';require_once $app.'/includes/global_report_matrix.php';
require_once $app.'/includes/tagihan_sekali.php';
$private=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$audit=(string)getenv('SPP_GLOBAL_AUDIT_EVIDENCE');
if(!is_file($audit.'/kasus-psb.csv'))throw new RuntimeException('SPP_GLOBAL_AUDIT_EVIDENCE must point to private real-case evidence.');
$checks=0;$failures=[];$cases=[];$baseline=getenv('SPP_REPAIR_BASELINE')==='1';
function repair_check(bool $ok,string $case,$expected=null,$actual=null):void {global $checks,$failures;$checks++;if(!$ok){$failures[]=compact('case','expected','actual');file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/'.(getenv('SPP_REPAIR_BASELINE')==='1'?'baseline':'fixed').'-failures.json',json_encode($failures,JSON_PRETTY_PRINT));}}
function repair_csv(string $name):array {global $audit;$f=fopen($audit.'/'.$name,'rb');$header=fgetcsv($f);$header[0]=ltrim($header[0],"\xEF\xBB\xBF");$out=[];while(($r=fgetcsv($f))!==false)$out[]=array_combine($header,$r);fclose($f);return $out;}
function repair_model(int $unit,string $template,array $q=[]):array {global $koneksi,$cases;unit_set_context($koneksi,$unit);$q=array_replace(['template'=>$template,'kategori'=>'spp','siswa_status'=>'all','tahun_ajaran'=>'2026/2027','tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-27','bulan_awal'=>'10','bulan_akhir'=>'10','tahun_awal'=>'2026','tahun_akhir'=>'2026'],$q);static $bases=[];if(!isset($bases[$unit]))$bases[$unit]=report_filters($koneksi,[]);if(array_filter($q,'is_array'))$f=report_filters($koneksi,$q);else{$f=array_replace($bases[$unit],$q);$f['_template']=$template;$f['all_periods']=!empty($q['all_periods']);$f['filter_tanggal_tagihan']=$template==='riwayat-tagihan';}$r=report_build($koneksi,$template,$f);file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/'.(getenv('SPP_REPAIR_BASELINE')==='1'?'baseline':'fixed').'-progress.json',json_encode(['unit'=>$unit,'template'=>$template,'cases'=>count($cases),'checks'=>$GLOBALS['checks']]));$cases[]=['unit'=>$unit,'template'=>$template,'query'=>$q,'rows'=>count($r['rows']),'totals'=>report_money_totals($r,$template)];return [$r,$f];}
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try{
 foreach(repair_csv('kasus-psb.csv') as $c){
    foreach(['status','per-item','riwayat-tagihan'] as $template){
        [$r]=repair_model((int)$c['unit'],$template,['kategori'=>'psb','komponen_tagihan'=>'psb','q'=>$c['nis'],'tanggal_awal'=>'','tanggal_akhir'=>'']);
        $rows=array_values(array_filter($r['rows'],static fn($r)=>(string)$r['nis']===$c['nis']));$row=$rows[0]??[];
        repair_check(count($rows)===1,$template.' PSB row '.$c['nis'],1,count($rows));
        repair_check(abs((float)($row['terbayar']??0)-(float)$c['penerimaan_terbukti'])<.001,$template.' PSB paid '.$c['nis'],$c['penerimaan_terbukti'],$row['terbayar']??null);
        repair_check(array_key_exists('tagihan',$row)&&$row['tagihan']===null&&$row['sisa']===null,$template.' PSB unknown liability '.$c['nis']);
        repair_check(($row['status']??'')==='Perlu Rekonsiliasi',$template.' PSB status '.$c['nis']);
    }
    $fee=one_time_fee_status($koneksi,$c['nis'])['psb'];
    repair_check(abs($fee['paid']-(float)$c['penerimaan_terbukti'])<.001&&!$fee['known']&&$fee['total']===null&&$fee['remaining']===null,'PSB cashier shared '.$c['nis']);
 }
 foreach(repair_csv('kasus-filter-ada-pembayaran.csv') as $c){
    [$r]=repair_model((int)$c['unit'],'status',['kategori'=>'psb','status'=>'ada_pembayaran','q'=>$c['nis']]);
    repair_check(count($r['rows'])===1&&(float)$r['rows'][0]['terbayar']===(float)$c['terbayar']&&$r['rows'][0]['status']==='Perlu Rekonsiliasi','positive reconciliation '.$c['nis']);
 }
 $bankCases=repair_csv('kasus-filter-tabungan.csv');
 foreach([1,2,3] as $unit){
    $relevant=array_values(array_filter($bankCases,static fn($c)=>(int)$c['unit']===$unit));$grades=array_unique(array_column($relevant,'filter_yang_menyembunyikan'));
    foreach($grades as $grade){
        [$r]=repair_model($unit,'tabungan-siswa',['kelas'=>$grade]);$indexed=[];foreach($r['rows'] as $row)$indexed[$row['nis'].'|'.$row['tanggal'].'|'.$row['masuk'].'|'.$row['keluar']]=true;
        foreach($relevant as $c)if($c['filter_yang_menyembunyikan']===$grade){$key=$c['nis'].'|'.$c['tanggal'].'|'.(float)$c['masuk'].'|'.(float)$c['keluar'];repair_check(isset($indexed[$key]),'bank grade journal '.$c['jurnal_id']);}
    }
 }
 $duCases=repair_csv('kasus-filter-du.csv');
 foreach([1,2,3] as $unit){
    $relevant=array_values(array_filter($duCases,static fn($c)=>(int)$c['unit']===$unit));
    foreach(array_unique(array_column($relevant,'filter')) as $grade){
        [$r]=repair_model($unit,'riwayat-tagihan',['kelas'=>$grade,'komponen_tagihan'=>'daftar_ulang','tanggal_awal'=>'','tanggal_akhir'=>'']);
        $indexed=[];foreach($r['rows'] as $row)$indexed[$row['nis'].'|'.$row['tahun_ajaran']]=true;
        foreach($relevant as $c)if($c['filter']===$grade)repair_check(!isset($indexed[$c['nis'].'|'.$c['tahun_ajaran']]),'DU no old class fallback '.$unit.' '.$c['nis']);
    }
 }
 $emptyCases=repair_csv('kasus-kelas-kosong.csv');
 foreach([2,3] as $unit){
    $unitCases=array_values(array_filter($emptyCases,static fn($c)=>(int)$c['unit']===$unit));
    foreach(array_unique(array_column($unitCases,'identitas')) as $owner){
        [$r]=repair_model($unit,'tabungan-siswa',['all_periods'=>1,'q'=>$owner,'owner_id'=>(int)substr($owner,9)]);$indexed=[];foreach($r['rows'] as $row)$indexed[$row['nis'].'|'.$row['tanggal']][]=$row;
        foreach($unitCases as $c)if($c['identitas']===$owner){$matches=$indexed[$c['identitas'].'|'.$c['tanggal']]??[];repair_check((bool)$matches&&!array_filter($matches,static fn($r)=>$r['kelas']!=='Tidak tercatat'),'empty class journal '.$c['jurnal_id']);}
    }
 }
 // 36 report projections, actual totals, pagination, matrices and multi filters.
 if(!$baseline)foreach([1,2,3,0] as $unit)foreach(global_report_templates() as $template){
    [$r,$f]=repair_model($unit,$template,['kategori'=>$template==='penerimaan'?'semua':'spp']);
    repair_check(!isset($r['error']),'projection '.$unit.' '.$template);$paged=report_paginate($r['rows'],$f,false);
    repair_check($paged['total']===count($r['rows']),'pagination '.$unit.' '.$template);
    global_report_matrix($r,$template,$unit);
 }
 if(!$baseline){
    $raw=['tot_pangkal'=>'1000','potong_pangkal'=>'100','PANGKAL'=>'900','PANGKAL_BAYAR'=>'700'];
    $p=lb_psb_evidence($raw,false,50000,50000);repair_check($p['known']&&(float)$p['paid']===700.0,'PSB cumulative no double count');
    $p=lb_psb_evidence($raw,false,50000,30000);repair_check((float)$p['paid']===500.0,'PSB corrected delta');
    $p=lb_psb_evidence($raw,false,50000,0);repair_check((float)$p['paid']===200.0,'PSB cancelled detail delta');
    $raw['PANGKAL']='800';$p=lb_psb_evidence($raw,false,50000,30000);repair_check(!$p['known']&&(float)$p['paid']===300.0&&$p['total']===null,'PSB invalid header corrected detail');
    $p=lb_psb_evidence($raw,true,50000,0);repair_check(!$p['known']&&(float)$p['paid']===0.0,'PSB ambiguous header cancelled receipt');
    repair_check(!report_row_has_payment(['_status'=>'Lunas','terbayar'=>0]),'waiver does not imply payment');
    foreach(['terbayar','total_bayar','sudah_dibayar','paid','pembayaran_opsional'] as $field)repair_check(report_row_has_payment([$field=>1,'_status'=>'Perlu Rekonsiliasi']),'paid field '.$field);
    foreach(['PSB2','X9A20','14X12','Alumni2026'] as $label)repair_check(!report_class_matches_row(['kelas'=>'tingkat:2'],['kelas'=>$label]),'not academic digits '.$label);
    foreach([1,2,3] as $unit){
        [$r,$f]=repair_model($unit,'status',['kategori'=>['psb','spp'],'status'=>['ada_pembayaran','rekonsiliasi'],'siswa_status'=>'all']);repair_check(!isset($r['error']),'multiple category/status '.$unit);
        [$r,$f]=repair_model($unit,'riwayat-tagihan',['kelas'=>array_map(static fn($n)=>'tingkat:'.$n,array_slice(range(...unit_level_bounds($unit)),0,2)),'komponen_tagihan'=>['daftar_ulang','komite'],'tanggal_awal'=>'','tanggal_akhir'=>'']);repair_check(!isset($r['error']),'multiple history '.$unit);
    }
 }
}finally{$koneksi->rollback();}
$result=['baseline'=>$baseline,'checks'=>$checks,'failures'=>$failures,'cases'=>$cases];
file_put_contents($private.'/'.($baseline?'baseline':'fixed').'-readback.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode(['baseline'=>$baseline,'checks'=>$checks,'failures'=>count($failures),'cases'=>count($cases)]),PHP_EOL;
exit(!$baseline&&$failures?1:0);
