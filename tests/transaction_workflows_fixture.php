<?php
if(getenv('SPP_UI_IDS_FILE')){
    if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))throw new RuntimeException('Gunakan salinan uji.');
    require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/transaction_authorization.php';
    $ids=json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents(getenv('SPP_UI_IDS_FILE'))),true,512,JSON_THROW_ON_ERROR);
}else require __DIR__.'/payment_activity_fixture.php';
$hash=password_hash(trim(file_get_contents(getenv('SPP_TEST_ADMIN_PASSWORD_FILE'))),PASSWORD_DEFAULT);
$s=$koneksi->prepare("UPDATE admin SET password=? WHERE role='bendahara'");$s->bind_param('s',$hash);$s->execute();$s->close();
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$koneksi->begin_transaction();
    try{
        $fixture=$ids[$unit];$events=payment_activity_for_payments($koneksi,[$fixture['id']])[$fixture['id']]??[];
        $snapshot=json_decode($events[0]['after_snapshot'],true);
        for($i=1;$i<=28;$i++){
            $id=9000000+$unit*100+$i;$before=$snapshot;$before['payment']['id']=$id;$before['payment']['NAMA']='UJI PAGINATION '.$i;$before['payment']['NO_INDUK']='PAG'.$unit.$i;
            payment_activity_record($koneksi,$id,'deleted','unknown-operator',$before,null,'test:pagination:'.$id);
        }
        $nis=$fixture['nis'];$s=$koneksi->prepare('INSERT INTO tabungan(NO_INDUK,SALDO) VALUES(?,100000)');$s->bind_param('s',$nis);$s->execute();$s->close();
        $s=$koneksi->prepare("INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES(?,NOW(),100000,0,'1','UJI VALIDASI SALDO')");$s->bind_param('s',$nis);$s->execute();$s->close();
        $koneksi->commit();
    }catch(Throwable $e){$koneksi->rollback();throw $e;}
}
