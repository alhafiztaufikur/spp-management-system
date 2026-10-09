<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/kelas.php';
function correction_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
unit_set_context($koneksi,0);$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
foreach([1,2,3] as $unit){
    $_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);$koneksi->begin_transaction();
    try{
        [$first,$last]=unit_level_bounds();$master=spp_master_ensure_year($koneksi,'2164/2165',true);$id=(int)$master['id'];$yearId=(int)$master['tahun_ajaran_id'];
        spp_master_save_rates($koneksi,$id,array_fill_keys(range($first,$last),250000.0));
        $class=$koneksi->query('SELECT id,tingkat,kode_rombel FROM master_kelas WHERE tingkat='.$first.' AND is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 1')->fetch_assoc();$classId=(int)$class['id'];$label=class_label($class);$level=(string)$first;$students=[];
        foreach([0,250000,0] as $index=>$discount){
            $nis=(string)random_int(9700000000,9799999999);$students[]=$nis;$name='TEST CORRECTION '.$index;
            $s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,potongan_spp_nominal,is_active) VALUES(?,?,?,?,250000,?,1)');$s->bind_param('sssid',$nis,$name,$level,$classId,$discount);$s->execute();$s->close();
            $covered=$index===2?1:0;
            $snapshot=$covered?0.0:250000.0;
            $s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,spp_covered_by_psb,status) VALUES(?,?,?,?,?,?,?,'aktif')");$s->bind_param('issisdi',$yearId,$nis,$level,$classId,$label,$snapshot,$covered);$s->execute();$s->close();
        }
        spp_publish_students($koneksi,$id,$students);
        foreach(['07','08'] as $month)spp_allocate_payment($koneksi,$students[0],null,$month,'2164',250000,'2164-07-01 00:00:00','Tunai','test');
        $s=$koneksi->prepare("UPDATE tagihan_spp SET status='cancelled',cancel_reason='test' WHERE no_induk=? AND bulan='06'");$s->bind_param('s',$students[0]);$s->execute();$s->close();
        $protected=$koneksi->query("SELECT * FROM tagihan_spp WHERE master_spp_tahun_id=$id AND (status IN('cancelled','covered_psb') OR (no_induk='{$students[0]}' AND bulan IN('07','08'))) ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $rates=array_fill_keys(range($first,$last),300000.0);$version=spp_master_rate_version(spp_master_state($koneksi,$id),spp_master_rates($koneksi,$id));
        $rejected=false;try{spp_master_correct_published_rates($koneksi,$id,$rates,$version,false);}catch(RuntimeException $e){$rejected=true;}correction_assert($rejected,'Unconfirmed correction accepted');
        $result=spp_master_correct_published_rates($koneksi,$id,$rates,$version,true);
        correction_assert($result['bills_updated']===21&&$result['bills_locked']===15,'Wrong affected/protected count');
        correction_assert($protected===$koneksi->query("SELECT * FROM tagihan_spp WHERE master_spp_tahun_id=$id AND (status IN('cancelled','covered_psb') OR (no_induk='{$students[0]}' AND bulan IN('07','08'))) ORDER BY id")->fetch_all(MYSQLI_ASSOC),'Protected snapshots changed');
        $s=$koneksi->prepare("SELECT nominal_tagihan,status,potongan_nominal_ditetapkan_snapshot FROM tagihan_spp WHERE no_induk=? AND bulan='09'");$s->bind_param('s',$students[1]);$s->execute();$waived=$s->get_result()->fetch_assoc();$s->close();
        correction_assert((float)$waived['nominal_tagihan']===50000.0&&$waived['status']==='open'&&(float)$waived['potongan_nominal_ditetapkan_snapshot']===250000.0,'Requested discount not preserved');
        $rejected=false;try{spp_master_correct_published_rates($koneksi,$id,array_fill_keys(range($first,$last),350000.0),$version,true);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'sudah berubah');}correction_assert($rejected,'Stale correction accepted');
        $now=spp_master_rate_version(spp_master_state($koneksi,$id),spp_master_rates($koneksi,$id));
        $koneksi->query("UPDATE master_spp_tahun SET status='closed' WHERE id=$id");$rejected=false;
        try{spp_master_correct_published_rates($koneksi,$id,$rates,$now,true);}catch(RuntimeException $e){$rejected=true;}correction_assert($rejected,'Closed year edited');
        echo "PASS: unit $unit confirmed correction, paid/cancelled/PSB snapshots, waived discount, audit/version and closed year\n";
    }finally{$koneksi->rollback();}
}
