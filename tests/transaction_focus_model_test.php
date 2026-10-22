<?php
/** Independent source oracle and native fixtures, exclusively on the owned QA clone. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58470||!str_contains($koneksi->query('SELECT @@datadir')->fetch_row()[0],'global_audit_20261021'))throw new RuntimeException('Owned migrated report clone required.');
require_once __DIR__.'/../includes/transaction_report.php';
$qa=(string)getenv('SPP_GLOBAL_QA_DIR');if(!$qa||!is_dir($qa)||str_starts_with(strtolower(realpath($qa)),strtolower(realpath(__DIR__.'/..'))))throw new RuntimeException('Private evidence directory required.');
$checks=0;$models=[];
function tf_check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function tf_cents($value):int{return (int)round((float)$value*100);}
function tf_sql(string $sql,array $args=[]):int{global $koneksi;$s=$koneksi->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$s->close();return $koneksi->insert_id;}
function tf_period(string $period):?array{
    $period=trim($period);
    if(preg_match('~^(\d{4})/(\d{4})$~D',$period,$m)&&(int)$m[2]===(int)$m[1]+1)return [$m[1].'-07-01',$m[2].'-06-30'];
    if(preg_match('~^(\d{4})$~D',$period))return [$period.'-01-01',$period.'-12-31'];
    if(preg_match('~^(\d{1,2}) (\d{4})$~D',$period,$m)){$month=(int)$m[1];$year=(int)$m[2];}
    elseif(preg_match('~^(\d{4})-(\d{2})$~D',$period,$m)){$month=(int)$m[2];$year=(int)$m[1];}else return null;
    if($month<1||$month>12)return null;$start=sprintf('%04d-%02d-01',$year,$month);return [$start,date('Y-m-t',strtotime($start))];
}
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];
$fixture=json_decode(file_get_contents($qa.'/fixture.json'),true);
if(empty($fixture['native'])){
$fixture['native']=[];
// Same identity across units deliberately exercises unit/student isolation.
$nis='98'.substr((string)time(),-8);$koneksi->begin_transaction();
try{
 foreach([1,2,3] as $unit){
    $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);[$level]=unit_level_bounds();$class=hp_one($koneksi,'SELECT * FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 LIMIT 1',[$level]);$label=class_label($class);
    $sid=tf_sql('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,?,?,?,1)',[$nis,'QA Fokus Transaksi '.$unit,(string)$level,$class['id']]);$ids=[];
    foreach(['2026-10-22 12:34:56','2026-10-22 14:15:16','2026-11-02 09:10:11'] as $date){
        $id=tf_sql("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_PSB,U_SPP,U_KOMITE,U_LAIN,potong_spp,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran) VALUES(?,?,?,?,10000.10,20000.20,3000.30,7000.70,1000.01,44001.69,?,'10','2026',?,'Tunai')",[$nis,(string)$level,$class['id'],$label,$date,(string)$actor]);$ids[]=$id;
        tf_sql("INSERT INTO bayar_biaya_lain(bayar_id,nama_biaya_snapshot,nominal_snapshot) VALUES(?,'QA Biaya Snapshot',7000.70)",[$id]);
        tf_sql("INSERT INTO bayar_du(bayar_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,'2026/2027',5000.40)",[$id,$nis,(string)$level]);
    }
    $fixture['native'][$unit]=['student_id'=>$sid,'nis'=>$nis,'ids'=>$ids,'class'=>$label];
 }
 $koneksi->commit();
}catch(Throwable $error){$koneksi->rollback();throw $error;}
file_put_contents($qa.'/fixture.json',json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
}

// The oracle uses stored source tables and its own period matching, not report helpers.
$_SESSION['active_unit_id']=0;unit_set_context($koneksi,0);
$source=$koneksi->query("SELECT p.id,p.unit_id,p.total_jumlah amount,p.TGL_BYR date_value,CONCAT_WS(' ',p.BULAN,p.TAHUN) period,s.id student_id FROM bayar p LEFT JOIN siswa s ON s.unit_id=p.unit_id AND s.NO_INDUK=p.NO_INDUK UNION ALL SELECT -p.id,p.unit_id,p.total,p.source_date,p.source_period,p.student_id FROM legacy_full_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id JOIN siswa s ON s.id=p.student_id AND s.unit_id=p.unit_id WHERE b.state='completed' UNION ALL SELECT -1000000000-p.id,p.unit_id,p.total,p.source_date,p.source_period,o.student_id FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0")->fetch_all(MYSQLI_ASSOC);
$profiles=['dated'=>['2026-10-01','2026-10-22',false,false],'undated'=>['2026-10-01','2026-10-22',true,false],'past'=>['2023-06-01','2023-07-31',true,false],'empty'=>['2090-01-01','2090-01-31',true,false],'all'=>['2026-10-01','2026-10-22',false,true]];
foreach([1,2,3,0] as $unit)foreach($profiles as $profile=>[$start,$end,$undated,$all]){
    $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$_GET=['include_undated'=>$undated?'1':'0','all_periods'=>$all?'1':'0'];$expected=[];$sum=0;
    foreach($source as $row){
        if($unit&&(int)$row['unit_id']!==$unit)continue;
        $period=tf_period((string)$row['period']);$date=$row['date_value']===null?null:substr($row['date_value'],0,10);
        if(!$all&&!($date!==null&&$date>=$start&&$date<=$end)&&!($undated&&$date===null&&$period&&$period[0]<=$end&&$period[1]>=$start))continue;
        $expected[(int)$row['id']]=['unit_id'=>(int)$row['unit_id'],'amount'=>tf_cents($row['amount'])];$sum+=tf_cents($row['amount']);
    }
    $section=transaction_report_section($koneksi,$start.' 00:00:00',date('Y-m-d',strtotime($end.' +1 day')).' 00:00:00','','terbaru',25,999999);
    tf_check($section['row_count']===count($expected),"$unit $profile source row count");
    tf_check(tf_cents($section['totals'][0]['value'])===$sum,"$unit $profile independent source cents");
    tf_check($section['page']===$section['total_pages'],"$unit $profile bounds clamp");
    tf_check(count($section['rows'])<=25,"$unit $profile bounded page");
    foreach($section['rows'] as $row)tf_check($row['periode']===trim((string)$row['evidence_period']),'Stored source period retained without reconstruction');
    foreach($section['rows'] as $row)tf_check(isset($expected[(int)$row['id']])&&$expected[(int)$row['id']]===['unit_id'=>(int)$row['unit_id'],'amount'=>tf_cents($row['total_jumlah'])],"$unit $profile page identities and cents");
    if(in_array($profile,['dated','undated','empty'],true)){
        $full=transaction_report_section($koneksi,$start.' 00:00:00',date('Y-m-d',strtotime($end.' +1 day')).' 00:00:00','','terbaru');$seen=[];
        foreach($full['rows'] as $row){tf_check(!isset($seen[(int)$row['id']]),'No duplicate payment ID');$seen[(int)$row['id']]=true;}
        tf_check(count($seen)===count($expected),'Every matching source receipt appears exactly once');
        $models[$unit][$profile]=['count'=>count($expected),'cents'=>$sum,'rows'=>$full['rows'],'export'=>transaction_report_export_section($koneksi,$full)];
    }
}
foreach(['dated','undated','empty'] as $profile){tf_check($models[0][$profile]['cents']===array_sum(array_map(static fn($u)=>$models[$u][$profile]['cents'],[1,2,3])),'All-unit cents equal three units');tf_check($models[0][$profile]['count']===array_sum(array_map(static fn($u)=>$models[$u][$profile]['count'],[1,2,3])),'All-unit count equals three units');}
foreach(['belum_spp',['*'],['semua','belum_du']] as $old){$_GET=['jenis_laporan'=>$old,'panel'=>'komponen','urut'=>'sisa_terbesar'];tf_check(transaction_report_normalize_request()==='terbaru'&&!isset($_GET['jenis_laporan'])&&$_GET['panel']==='komponen','Obsolete report types ignored; valid presentation retained');}
foreach([1,2,3] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$_GET=['student_id'=>$fixture['native'][$unit]['student_id'],'include_undated'=>'1'];foreach(array_keys(transaction_report_sort_options()) as $sort){$s=transaction_report_section($koneksi,'2026-10-01 00:00:00','2026-10-23 00:00:00','',$sort);tf_check(count($s['rows'])===2&&tf_cents($s['totals'][0]['value'])===8800338,'Native fractions and same identity across units');$x=transaction_report_export_section($koneksi,$s);tf_check(str_contains($x['rows'][0]['komponen'],'Daftar Ulang: Rp 5.000,40')&&str_contains($x['rows'][0]['komponen'],'QA Biaya Snapshot: Rp 7.000,70'),'Native component snapshots retained');}}
file_put_contents($qa.'/payment-models.json',json_encode($models,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
file_put_contents($qa.'/model-result.json',json_encode(['checks'=>$checks,'source_rows'=>count($source)]));
echo "OK: $checks payment-only checks against independent source cents\n";
