<?php
if (PHP_SAPI!=='cli' || getenv('SPP_TEST_ALLOW_MUTATION')!=='1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))) throw new RuntimeException('Clone uji wajib.');
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/transaction_authorization.php';
$file=(string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE');
if(!is_file($file) || strlen($password=trim(file_get_contents($file)))<12)throw new RuntimeException('Kata sandi uji wajib.');
$hash=password_hash($password,PASSWORD_DEFAULT);
$s=$koneksi->prepare("UPDATE admin SET password=?,is_active=1 WHERE role IN ('super_admin','admin','kasir')");$s->bind_param('s',$hash);$s->execute();$s->close();
$ids=[];
foreach([1,2,3] as $unit) {
    unit_set_context($koneksi,$unit);$koneksi->begin_transaction();
    try{
        $nis='99'.$unit.(string)random_int(1000000,9999999);$name='UJI OPERATOR '.unit_label($unit).' '.$nis;
        $class=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=0 AND kode_rombel='PSB' LIMIT 1")->fetch_row()[0];
        $s=$koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB) VALUES(?,?,'PSB',?,100000)");$s->bind_param('ssi',$nis,$name,$class);$s->execute();$student=(int)$koneksi->insert_id;$s->close();
        $cashier=$koneksi->query("SELECT id,username FROM admin WHERE unit_id=$unit AND role='kasir' ORDER BY id LIMIT 1")->fetch_assoc();
        $identity=(string)$cashier['id'];
        $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_PSB,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,total_jumlah,payment_link_version)
            VALUES(?,'PSB',?,'PSB',100,NOW(),'10','2026',?,'Tunai',100,1)");$s->bind_param('sis',$nis,$class,$identity);$s->execute();$id=(int)$koneksi->insert_id;$s->close();
        payment_activity_record($koneksi,$id,'created',$cashier['id'],null,transaction_authorization_snapshot($koneksi,$id)['data'],'created:'.$id);
        $ids[$unit]=['id'=>$id,'nis'=>$nis,'student'=>$student,'cashier'=>$cashier['username']];
        $koneksi->commit();
    }catch(Throwable $e){$koneksi->rollback();throw $e;}
}
echo json_encode($ids,JSON_THROW_ON_ERROR),PHP_EOL;
