<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))) throw new RuntimeException('Salinan audit wajib.');
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/transaction_authorization.php';
$file=(string)getenv('SPP_HISTORY_IDS_FILE');$mode=$argv[1]??'setup';
if($mode!=='setup') {
    $data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$unit=(int)($argv[2]??1);unit_set_context($koneksi,$unit);$id=(int)($argv[3]??$data['payments'][$unit]['kasir']['id']);
    if($mode==='state') {
        $s=$koneksi->prepare('SELECT * FROM bayar WHERE id=?');$s->bind_param('i',$id);$s->execute();$payment=$s->get_result()->fetch_assoc();$s->close();
        $s=$koneksi->prepare('SELECT id,status,action,requested_by,decided_by FROM transaksi_otorisasi WHERE bayar_id=? OR JSON_UNQUOTE(JSON_EXTRACT(before_snapshot,"$.payment.id"))=? ORDER BY id');$s->bind_param('ii',$id,$id);$s->execute();$requests=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        echo json_encode(['payment'=>$payment,'requests'=>$requests,'events'=>payment_activity_for_payments($koneksi,[$id])[$id]??[]],JSON_THROW_ON_ERROR);exit;
    }
    if($mode==='shared') {
        $cash=$data['accounts'][$unit]['kasir'];$admin=$data['accounts'][$unit]['admin'];$_SESSION=['admin_id'=>$admin['id'],'admin_role'=>'admin','active_unit_id'=>$unit];
        $denied=false;try{transaction_authorization_create($koneksi,$id,'hapus',['id'=>$id],'Uji pemilik asing',(int)$admin['id']);}catch(Throwable $e){$denied=true;}
        if(!$denied)throw new RuntimeException('Pengajuan pemilik asing lolos.');
        $_SESSION=['admin_id'=>$cash['id'],'admin_role'=>'kasir','active_unit_id'=>$unit];$koneksi->begin_transaction();payment_assert_owner($koneksi,$id,(int)$cash['id']);$snapshot=transaction_authorization_snapshot($koneksi,$id)['data'];payment_activity_record($koneksi,$id,'edited',$cash['id'],$snapshot,$snapshot,'rollback-test:'.$id);$koneksi->rollback();
        echo "OK helper: pemilik asing ditolak; jurnal edit rollback tidak tersimpan.\n";exit;
    }
    if($mode==='inactive') {$active=(int)($argv[4]??0);$koneksi->query('UPDATE admin SET is_active='.$active.' WHERE id='.(int)$data['accounts'][$unit]['kasir2']['id']);echo "OK\n";exit;}
    if($mode==='stale') {$koneksi->query("UPDATE bayar SET KETERANGAN='Uji snapshot berubah pada salinan' WHERE id=".$id);echo "OK\n";exit;}
    throw new RuntimeException('Mode tidak dikenal.');
}
$password=trim(file_get_contents(getenv('SPP_TEST_ADMIN_PASSWORD_FILE')));if(strlen($password)<12)throw new RuntimeException('Password uji wajib.');
$hash=password_hash($password,PASSWORD_DEFAULT);$s=$koneksi->prepare('UPDATE admin SET password=? WHERE is_active=1');$s->bind_param('s',$hash);$s->execute();$s->close();
$data=['accounts'=>[],'payments'=>[]];
$super=$koneksi->query("SELECT id,username,role,unit_id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();$data['super']=$super;
foreach([1,2,3] as $unit) {
    unit_set_context($koneksi,$unit);
    $accounts=$koneksi->query('SELECT id,username,role,unit_id FROM admin WHERE unit_id='.$unit.' AND is_active=1 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    foreach(['admin','kasir','bendahara'] as $role) $data['accounts'][$unit][$role]=array_values(array_filter($accounts,fn($a)=>$a['role']===$role))[0];
    $data['accounts'][$unit]['kasir2']=array_values(array_filter($accounts,fn($a)=>$a['role']==='kasir'))[1];
    $class=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=0 AND kode_rombel='PSB' LIMIT 1")->fetch_row()[0];
    $nis='H'.$unit.bin2hex(random_bytes(3));$name='UJI RIWAYAT '.unit_label($unit).' nama panjang <>&';
    $s=$koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB) VALUES(?,?,'PSB',?,100000)");$s->bind_param('ssi',$nis,$name,$class);$s->execute();$s->close();
    foreach(['admin','kasir','kasir2','bendahara','super_admin','unknown'] as $role) {
        $actor=$role==='super_admin'?$super:($data['accounts'][$unit][$role]??null);$actorId=(string)($actor['id']??$data['accounts'][$unit]['kasir']['id']);
        $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_PSB,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,total_jumlah,payment_link_version) VALUES(?,'PSB',?,'PSB',100,NOW(),'10','2026',?,'Tunai',100,1)");$s->bind_param('sis',$nis,$class,$actorId);$s->execute();$id=(int)$koneksi->insert_id;$s->close();
        $koneksi->begin_transaction();payment_activity_record($koneksi,$id,$role==='unknown'?'baseline':'created',$role==='unknown'?null:$actor['id'],null,transaction_authorization_snapshot($koneksi,$id)['data'],'created:'.$id);$koneksi->commit();
        $data['payments'][$unit][$role]=['id'=>$id,'nis'=>$nis];
    }
    foreach(['own','mixed'] as $kind) {
        $token=bin2hex(random_bytes(16));$ids=[];
        for($i=1;$i<=12;$i++) {
            $actor=$data['accounts'][$unit][$kind==='mixed'&&$i===12?'kasir2':'kasir'];$actorId=(string)$actor['id'];
            $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_PSB,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,total_jumlah,payment_link_version,payment_batch_token,payment_batch_sequence,payment_batch_count) VALUES(?,'PSB',?,'PSB',100,NOW(),?,'2026',?,'Tunai',100,1,?,?,12)");$month=str_pad((string)$i,2,'0',STR_PAD_LEFT);$s->bind_param('sisssi',$nis,$class,$month,$actorId,$token,$i);$s->execute();$id=(int)$koneksi->insert_id;$s->close();
            $koneksi->begin_transaction();payment_activity_record($koneksi,$id,'created',$actor['id'],null,transaction_authorization_snapshot($koneksi,$id)['data'],'created:'.$id);$koneksi->commit();$ids[]=$id;
        }
        $data['batches'][$unit][$kind]=['token'=>$token,'ids'=>$ids];
    }
}
file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));echo "OK fixtures tiga unit pada salinan audit.\n";
