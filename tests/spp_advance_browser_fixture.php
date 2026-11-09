<?php
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/spp_advance.php';require_once __DIR__.'/../includes/kelas.php';
$db=$koneksi;if((int)$db->query('SELECT @@port')->fetch_row()[0]===3306)throw new RuntimeException('Separate server required');
if(($argv[1]??'')==='state'){
    $fixture=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);$out=[];
    foreach($fixture['cases'] as $case){unit_set_context($db,$case['unit']);$out[$case['unit']][$case['role']]=['bills'=>hp_rows($db,'SELECT id,bulan,tahun,penempatan_id,nominal_tagihan FROM tagihan_spp WHERE no_induk=? ORDER BY id',[$case['nis']]),'payments'=>hp_rows($db,'SELECT id,KELAS,U_SPP,U_KOMITE,BULAN,TAHUN,TGL_BYR,riwayat_kelas_snapshot FROM bayar WHERE NO_INDUK=? ORDER BY id',[$case['nis']])];}
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
$file=$argv[1]??'';if(!str_starts_with(str_replace('\\','/',$file),'C:/laragon/backups/')||is_file($file))throw new RuntimeException('New private fixture file required');
$current=du_current_academic_year();$future=du_next_year($current);$cashDate=substr($current,0,4).'-11-10 10:00:00';$output=['database'=>DB_NAME,'year'=>$current,'future'=>$future,'cases'=>[]];
$db->begin_transaction();
try{
    $actors=[];foreach(['super_admin','admin'] as $role){$a=hp_one($db,'SELECT id,nama FROM admin WHERE role=? AND is_active=1 LIMIT 1',[$role]);if(!$a){lf_query($db,'INSERT INTO admin(username,password,nama,role,is_active) VALUES(?,?,?,?,1)',['qa_advance_'.$role,password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'QA advance '.$role,$role]);$a=['id'=>$db->insert_id,'nama'=>'QA advance '.$role];}$actors[$role]=$a;}
    foreach([1,2,3,4] as $unit){unit_set_context($db,$unit);$db->query('UPDATE siswa SET is_active=0');}
    foreach([1,2,3] as $unit){
        $_SESSION=['admin_id'=>(int)$actors['super_admin']['id'],'admin_nama'=>$actors['super_admin']['nama'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);sct_invalidate($db);lb_cache($db,true);[$first,$last]=unit_level_bounds();$grade=$unit===1?4:$last-1;
        $class=hp_one($db,"SELECT * FROM master_kelas WHERE tingkat=? AND jenis='reguler' AND is_active=1 AND is_placeholder=0 ORDER BY (kode_rombel='C') DESC,id LIMIT 1",[$grade]);$target=hp_one($db,"SELECT * FROM master_kelas WHERE tingkat=? AND jenis='reguler' AND is_active=1 AND is_placeholder=0 LIMIT 1",[$grade+1]);
        $m=spp_master_ensure_year($db,$current);if(spp_master_rates_editable(spp_master_state($db,(int)$m['id'])))spp_master_save_rates($db,(int)$m['id'],array_fill($first+1,$last-$first,100000));
        foreach($actors as $role=>$actor){
            $nis='9988108'.($role==='admin'?'2':'1').'01';lf_query($db,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,100000,15000,1)',[$nis,'QA advance '.$role,$grade,$class['id']]);$id=(int)$db->insert_id;$sync=null;$pid=class_sync_student_current_year($db,$nis,(int)$class['id'],100000,15000,true,$sync);$context=sct_context_for_student($db,(int)$m['id'],$id);sct_save($db,(int)$m['id'],$id,substr($current,0,4).'-07','100000','QA current-year individual fixture',$context['version']);
            foreach(spp_academic_periods($current) as $p){$amount=(float)hp_one($db,'SELECT nominal_tagihan FROM tagihan_spp WHERE no_induk=? AND bulan=? AND tahun=?',[$nis,$p['bulan'],$p['tahun']])['nominal_tagihan'];lf_query($db,'INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,U_KOMITE,BULAN,TAHUN,TGL_BYR,total_jumlah,user_id,payment_link_version) VALUES(?,?,?,15000,?,?,?,?,?,1)',[$nis,(string)$grade,$amount,$p['bulan'],$p['tahun'],$cashDate,$amount+15000,(string)$actor['id']]);$payment=(int)$db->insert_id;komite_save_payment($db,$payment,komite_bill($db,$nis,$p['bulan'],$p['tahun']),15000);if($amount>.001)spp_allocate_payment($db,$nis,$payment,$p['bulan'],$p['tahun'],$amount,$cashDate,'Tunai',(string)$actor['id']);}
            session_id(bin2hex(random_bytes(16)));session_start();$_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>$role,'active_unit_id'=>$unit];$session=session_id();session_write_close();
            $output['cases'][]=['role'=>$role,'unit'=>$unit,'nis'=>$nis,'id'=>$id,'origin'=>$pid,'source_year_id'=>$m['tahun_ajaran_id'],'grade'=>$grade,'target'=>$target['id'],'target_label'=>class_label($target),'session'=>$session,'first'=>$first,'last'=>$last];
        }
    }
    $db->commit();file_put_contents($file,json_encode($output,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'Disposable GUI fixtures ready'.PHP_EOL;
}catch(Throwable $e){$db->rollback();throw $e;}
