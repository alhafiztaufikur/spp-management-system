<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';$manifest=spp_test_isolated_manifest();
require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/spp_billing.php';require_once __DIR__.'/../includes/spp_student_tariff_schema.php';
$root=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$file=$root.'/tariff-fixture.json';$action=$argv[1]??'state';
if($action==='setup'){
    if(is_file($file))throw new RuntimeException('Use a fresh fixture.');
    require_once __DIR__.'/../includes/legacy_migration_verification.php';
    $proof=json_decode(file_get_contents($root.'/backup-proof.json'),true,512,JSON_THROW_ON_ERROR);
    if(lmr_database_checksums($koneksi)!==$proof['database_checksums']||lmr_snapshot($koneksi)!==$proof['snapshot'])throw new RuntimeException('Clone differs from restored backup.');
    $changed=sct_schema_apply($koneksi);if(sct_schema_apply($koneksi)!==0)throw new RuntimeException('Schema replay changed data.');
    $actor=$koneksi->query("SELECT id,nama FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    $f=['year'=>'2188/2189','actor'=>(int)$actor['id'],'units'=>[],'sessions'=>[]];
    foreach([1,2,3] as $unit){
        $_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
        if($koneksi->query("SELECT id FROM tahun_ajaran WHERE label='2188/2189'")->num_rows)throw new RuntimeException('Fixture year already exists.');
        $koneksi->begin_transaction();
        try{
            $m=spp_master_ensure_year($koneksi,$f['year'],true);$base=400000+$unit*10000;[$first,$last]=unit_level_bounds();
            spp_master_save_rates($koneksi,(int)$m['id'],array_fill($first,$last-$first+1,(float)$base));
            $c=$koneksi->query("SELECT id,tingkat,kode_rombel FROM master_kelas WHERE jenis='reguler' AND is_active=1 AND is_placeholder=0 ORDER BY tingkat,id LIMIT 1")->fetch_assoc();
            $label=$c['tingkat'].$c['kode_rombel'];$items=[];
            foreach(['custom','control','new','covered'] as $index=>$kind){
                $nis=(string)(9988262000+$unit*10+$index);$name=$kind==='new'?'QA Tarif Belum Terbit':'QA Tarif Khusus';
                $s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,potongan_spp_nominal,POMG,is_active) VALUES(?,?,?,?,?,25000,15000,1)');$s->bind_param('sssid',$nis,$name,$c['tingkat'],$c['id'],$base);$s->execute();$studentId=(int)$koneksi->insert_id;$s->close();
                $covered=$kind==='covered'?1:0;$net=$covered?0:$base-25000;
                $s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,spp_covered_by_psb,komite_snapshot,komite_mulai_bulan,status) VALUES(?,?,?,?,?,?,?,15000,'07','aktif')");$s->bind_param('issisdi',$m['tahun_ajaran_id'],$nis,$c['tingkat'],$c['id'],$label,$net,$covered);$s->execute();$pid=(int)$koneksi->insert_id;$s->close();
                $items[$kind]=['id'=>$studentId,'nis'=>$nis,'placement'=>$pid,'class'=>$label,'base'=>$base];
            }
            spp_publish_students($koneksi,(int)$m['id'],[$items['custom']['nis'],$items['control']['nis'],$items['covered']['nis']]);
            $nis=$items['custom']['nis'];$paid=$base-25000;$actorString=(string)$actor['id'];
            $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,BULAN,TAHUN,TGL_BYR,total_jumlah,user_id,payment_link_version) VALUES(?,?,?,'07','2188','2188-07-01 08:00:00',?,?,1)");$s->bind_param('ssdds',$nis,$c['tingkat'],$paid,$paid,$actorString);$s->execute();$paymentId=(int)$koneksi->insert_id;$s->close();
            spp_allocate_payment($koneksi,$nis,$paymentId,'07','2188',$paid,'2188-07-01 08:00:00','Tunai',$actorString);
            $s=$koneksi->prepare("UPDATE tagihan_spp SET status='cancelled',cancel_reason='QA cancellation fixture' WHERE no_induk=? AND bulan='12' AND tahun='2188'");$s->bind_param('s',$nis);$s->execute();$s->close();
            $koneksi->commit();$f['units'][$unit]=$items+['master'=>(int)$m['id'],'payment'=>$paymentId];
        }catch(Throwable $e){$koneksi->rollback();throw $e;}
        session_id(bin2hex(random_bytes(16)));session_start();$_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>'super_admin','active_unit_id'=>$unit];$f['sessions'][$unit]=session_id();session_write_close();
    }
    file_put_contents($file,json_encode($f,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo "PASS $changed schema objects, zero-change schema replay, three-unit billing fixtures and sessions.\n";exit;
}
$f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$out=[];
if($action==='draft'){
    if(isset($f['draft'])){echo json_encode($f['draft'],JSON_THROW_ON_ERROR);exit;}
    $_SESSION=['admin_id'=>$f['actor'],'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);$koneksi->begin_transaction();
    try{
        $m=spp_master_ensure_year($koneksi,'2186/2187',true);[$first,$last]=unit_level_bounds();spp_master_save_rates($koneksi,(int)$m['id'],array_fill($first,$last-$first+1,410000.0));
        foreach(['new','control'] as $kind){$original=(int)$f['units'][1][$kind]['placement'];
            $s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,spp_covered_by_psb,komite_snapshot,komite_mulai_bulan,status) SELECT ?,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,0,komite_snapshot,'07','aktif' FROM siswa_tahun_ajaran WHERE id=?");$s->bind_param('ii',$m['tahun_ajaran_id'],$original);$s->execute();$s->close();
        }
        $koneksi->commit();$f['draft']=['year'=>'2186/2187','master'=>(int)$m['id']];file_put_contents($file,json_encode($f,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($f['draft'],JSON_THROW_ON_ERROR);exit;
    }catch(Throwable $e){$koneksi->rollback();throw $e;}
}
foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);foreach(['custom','control','new','covered'] as $kind){$nis=$f['units'][$unit][$kind]['nis'];$out[$unit][$kind]=$koneksi->query("SELECT id,bulan,tahun,nominal_tagihan,tarif_dasar_snapshot,potongan_nominal_snapshot,potongan_nominal_ditetapkan_snapshot,status FROM tagihan_spp WHERE no_induk='$nis' ORDER BY tahun,bulan")->fetch_all(MYSQLI_ASSOC);}}
echo json_encode($out,JSON_THROW_ON_ERROR);
