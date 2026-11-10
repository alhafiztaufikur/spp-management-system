<?php
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/student_out.php';require_once __DIR__.'/../includes/kelas.php';require_once __DIR__.'/../includes/spp_billing.php';
$db=$koneksi;if((int)$db->query('SELECT @@port')->fetch_row()[0]!==3320)throw new RuntimeException('Use the separate browser restore.');
$file=$argv[2]??'';
if(($argv[1]??'')==='reset'){
    $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    foreach($fixture['cases'] as $c){$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>$c['unit']];unit_set_context($db,$c['unit']);$db->begin_transaction();try{$s=sout_student($db,$c['nis'],true);if($s['is_out']){$class=sout_rows($db,'SELECT tingkat FROM master_kelas WHERE id=?',[$c['class']])[0];sout_write($db,'UPDATE siswa SET KELAS=?,master_kelas_id=?,is_active=1 WHERE id=?',[$class['tingkat'],$c['class'],$s['id']]);sout_transition($db,(int)$s['id'],$s,'reguler',1);$sync=null;class_sync_student_current_year($db,$c['nis'],$c['class'],(float)$s['SPP_PERBULAN'],(float)$s['POMG'],true,$sync);}$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}}
    echo 'Disposable fixtures returned through official service',PHP_EOL;exit;
}
if(($argv[1]??'')==='state'){
    $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$out=[];
    foreach($fixture['cases'] as $c){unit_set_context($db,$c['unit']);$item=[];
        foreach(['siswa'=>'id','bayar'=>'NO_INDUK','spp_alokasi_batch'=>'no_induk','transaksi_m'=>'NO_INDUK','transaksi_k'=>'NO_INDUK','tabungan'=>'NO_INDUK','tagihan_spp'=>'no_induk','tagihan_komite'=>'no_induk','tagihan_daftar_ulang'=>'no_induk','student_out_periods'=>'student_id'] as $table=>$key)$item[$table]=sout_rows($db,"SELECT * FROM $table WHERE $key=? ORDER BY id",[in_array($table,['siswa','student_out_periods'],true)?$c['id']:$c['nis']]);
        $item['allocations']=sout_rows($db,'SELECT a.* FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id WHERE b.no_induk=? ORDER BY a.id',[$c['nis']]);
        $out[$c['unit'].'|'.$c['role']]=$item;
    }echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
if(!str_starts_with(str_replace('\\','/',$file),'C:/laragon/backups/')||is_file($file))throw new RuntimeException('New private fixture path required.');
sout_schema_apply($db);$actors=[];
foreach(['super_admin','admin'] as $role){$actor=sout_rows($db,'SELECT id,nama FROM admin WHERE role=? AND is_active=1 LIMIT 1',[$role])[0]??null;if(!$actor){sout_write($db,'INSERT INTO admin(username,password,nama,role,is_active) VALUES(?,?,?,?,1)',['qa_out_gui_'.$role,password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'QA OUT '.$role,$role]);$actor=['id'=>$db->insert_id,'nama'=>'QA OUT '.$role];}$actors[$role]=$actor;}
$fixture=['database'=>DB_NAME,'cases'=>[],'year'=>du_current_academic_year()];$db->begin_transaction();
try{
    foreach([1,2,3] as $unit){
        $_SESSION=['admin_id'=>(int)$actors['super_admin']['id'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);[$first,$last]=unit_level_bounds();$grade=$first+1;$class=sout_rows($db,"SELECT * FROM master_kelas WHERE tingkat=? AND jenis='reguler' AND is_placeholder=0 LIMIT 1",[$grade])[0];$out=sout_rows($db,"SELECT id FROM master_kelas WHERE jenis='out' LIMIT 1")[0];$master=spp_master_ensure_year($db,$fixture['year']);
        $state=spp_master_state($db,(int)$master['id']);$rates=spp_master_rates($db,(int)$master['id']);$complete=$rates;for($level=$first+1;$level<=$last;$level++)if($complete[$level]<=.001)$complete[$level]=100000;
        if(spp_master_rates_editable($state))spp_master_save_rates($db,(int)$master['id'],$complete);elseif($rates!==$complete)spp_master_correct_published_rates($db,(int)$master['id'],$complete,spp_master_rate_version($state,$rates),true);
        foreach($actors as $role=>$actor){
            $_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>$role,'active_unit_id'=>$unit];$nis='99880077'.($role==='admin'?'02':'01');
            sout_write($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,POMG,SPP_PERBULAN,is_active) VALUES(?,?,?,?,15000,100000,1)",[$nis,'QA OUT '.$role,$grade,$class['id']]);$id=(int)$db->insert_id;
            $sync=null;$placement=class_sync_student_current_year($db,$nis,(int)$class['id'],100000,15000,true,$sync);spp_publish_students($db,(int)$master['id'],[$nis]);
            $month='07';$year=substr($fixture['year'],0,4);$bill=sout_rows($db,'SELECT * FROM tagihan_spp WHERE no_induk=? AND bulan=? AND tahun=?',[$nis,$month,$year])[0];$amount=(float)$bill['nominal_tagihan'];
            sout_write($db,"INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,U_KOMITE,total_jumlah,BULAN,TAHUN,TGL_BYR,user_id,payment_link_version) VALUES(?,?,?,15000,?,?,?,'2026-11-11 08:00:00',?,1)",[$nis,$grade,$amount,$amount+15000,$month,$year,$actor['id']]);$payment=(int)$db->insert_id;
            komite_save_payment($db,$payment,komite_bill($db,$nis,$month,$year),15000);spp_allocate_payment($db,$nis,$payment,$month,$year,$amount,'2026-11-11 08:00:00','Tunai',(string)$actor['id']);
            sout_write($db,'INSERT INTO tabungan(NO_INDUK,SALDO) VALUES(?,50000)',[$nis]);sout_write($db,"INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES(?,'2026-11-11 08:00:00',50000,0,?,'QA OUT GUI')",[$nis,$actor['id']]);$journal=(int)$db->insert_id;
            session_id(bin2hex(random_bytes(16)));session_start();$_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>$role,'active_unit_id'=>$unit];$session=session_id();session_write_close();
            $fixture['cases'][]=['unit'=>$unit,'role'=>$role,'nis'=>$nis,'id'=>$id,'class'=>(int)$class['id'],'out'=>(int)$out['id'],'payment'=>$payment,'journal'=>$journal,'session'=>$session];
        }
    }$db->commit();file_put_contents($file,json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'Browser fixtures ready',PHP_EOL;
}catch(Throwable $e){$db->rollback();throw $e;}
