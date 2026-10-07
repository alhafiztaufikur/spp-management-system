<?php
if (PHP_SAPI!=='cli' || getenv('SPP_TEST_ALLOW_MUTATION')!=='1' || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))) throw new RuntimeException('Use an audit clone.');
$mode=$argv[1]??'setup';
if($mode==='setup') {
    ob_start();require __DIR__.'/payment_activity_fixture.php';ob_end_clean();
    $hash=password_hash(trim(file_get_contents(getenv('SPP_TEST_ADMIN_PASSWORD_FILE'))),PASSWORD_DEFAULT);
    $s=$koneksi->prepare("UPDATE admin SET password=?,is_active=1 WHERE role='bendahara'");$s->bind_param('s',$hash);$s->execute();$s->close();
    $accounts=$koneksi->query('SELECT id,username,role,unit_id FROM admin WHERE is_active=1')->fetch_all(MYSQLI_ASSOC);
    $result=['payments'=>$ids,'accounts'=>$accounts];
    file_put_contents(getenv('SPP_AUTH_IDS_FILE'),json_encode($result,JSON_THROW_ON_ERROR));
    echo "OK: three-unit authorization fixtures created on clone.\n";exit;
}
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/transaction_authorization.php';
$ids=json_decode(file_get_contents(getenv('SPP_AUTH_IDS_FILE')),true,512,JSON_THROW_ON_ERROR);
$unit=(int)($argv[2]??1);unit_set_context($koneksi,$unit);
$id=$ids['payments'][$unit]['id'];$nis=$ids['payments'][$unit]['nis'];
if($mode==='state'){
    $p=$koneksi->query('SELECT id,total_jumlah FROM bayar WHERE id='.(int)$id)->fetch_assoc();
    $s=$koneksi->prepare('SELECT id,status,action,requested_by,decided_by FROM transaksi_otorisasi WHERE no_induk_snapshot=? ORDER BY id');$s->bind_param('s',$nis);$s->execute();$requests=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $events=payment_activity_for_payments($koneksi,[$id])[$id]??[];
    echo json_encode(['payment'=>$p,'requests'=>$requests,'events'=>$events],JSON_THROW_ON_ERROR);exit;
}
if($mode==='stale'){
    $koneksi->query('UPDATE bayar SET KETERANGAN=\'Changed concurrently on audit clone\' WHERE id='.(int)$id);echo "OK\n";exit;
}
if($mode==='pagination'){
    $snapshot=transaction_authorization_snapshot($koneksi,$id)['data'];
    $koneksi->begin_transaction();
    for($i=1;$i<=28;$i++){
        $testId=9100000+$unit*100+$i;$before=$snapshot;$before['payment']['id']=$testId;
        $before['payment']['NAMA']='UJI REDESIGN PAGINATION '.$i;$before['payment']['NO_INDUK']='AUTHPAG'.$unit.$i;
        payment_activity_record($koneksi,$testId,'edited','missing-actor',$before,$before,'redesign:pagination:'.$testId);
    }
    $koneksi->commit();echo "OK\n";exit;
}
if($mode==='pending'){
    $snapshot=payment_activity_for_payments($koneksi,[$id])[$id][0];
    $payment=json_decode($snapshot['after_snapshot'],true)['payment'];
    $class=(int)$payment['master_kelas_id'];
    $admin=$koneksi->query("SELECT id FROM admin WHERE unit_id=$unit AND role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_row()[0];
    $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_PSB,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,total_jumlah,payment_link_version) VALUES(?,'PSB',?,'PSB',100,NOW(),'10','2026',?,'Tunai',100,1)");
    $s->bind_param('sis',$nis,$class,$admin);$s->execute();$newId=(int)$koneksi->insert_id;$s->close();
    $koneksi->begin_transaction();payment_activity_record($koneksi,$newId,'created',$admin,null,transaction_authorization_snapshot($koneksi,$newId)['data'],'created:'.$newId);$koneksi->commit();
    $_SESSION=['admin_id'=>(int)$admin,'admin_role'=>'admin','active_unit_id'=>$unit];
    $request=transaction_authorization_create($koneksi,$newId,'hapus',['id'=>$newId,'aksi'=>'hapus'],"Uji pending dari Admin: <>&\nBaris kedua.",(int)$admin);
    echo json_encode(['payment_id'=>$newId,'request_id'=>$request]);exit;
}
if($mode==='long'){
    $snapshot=payment_activity_for_payments($koneksi,[$id])[$id][0];$before=json_decode($snapshot['after_snapshot'],true);
    $before['payment']['id']=9200000+$unit;
    $before['payment']['NAMA']='UJI PDF PANJANG '.str_repeat('Nama panjang ',15).' <>&';
    $before['payment']['NO_INDUK']='LONGAUTH'.$unit;
    $after=$before;$after['payment']['KETERANGAN']=implode("\n",array_fill(0,75,'CATATAN <>&'))."\nAKHIR_PERUBAHAN";
    $note=implode("\n",array_fill(0,70,'PESAN KHUSUS <>&'))."\nAKHIR_CATATAN";
    $super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
    $koneksi->begin_transaction();payment_activity_record($koneksi,9200000+$unit,'edited',$super,$before,$after,'redesign:long:'.$unit,null,$note);$koneksi->commit();echo "OK\n";exit;
}
throw new RuntimeException('Unknown fixture mode.');
