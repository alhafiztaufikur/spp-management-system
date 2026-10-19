<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/authorization_presentation.php';require_once __DIR__.'/../includes/biaya_lain.php';require_once __DIR__.'/../sql/savings_authorization_schema.php';
$checks=0;function so_assert(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function so_denied(callable $action,string $message):void{$failed=false;try{$action();}catch(Throwable $e){$failed=true;}so_assert($failed,$message);}
savings_authorization_schema_apply($koneksi);savings_authorization_schema_apply($koneksi);so_assert(savings_authorization_ready($koneksi),'Idempotent schema');
$suffix=bin2hex(random_bytes(3));unit_set_context($koneksi,0);$actors=[];
foreach(['super_admin','admin','kasir','bendahara'] as $role){$name='Petugas QA '.$role;$username='qa.savings.'.$role.'.'.$suffix;$home=$role==='super_admin'?null:1;$s=$koneksi->prepare("INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,'!disabled-qa!',?,?,?,1)");$s->bind_param('sssi',$username,$name,$role,$home);$s->execute();$actors[$role]=['id'=>(int)$koneksi->insert_id,'role'=>$role,'name'=>$name,'unit'=>$home];$s->close();}
$nis='QS'.$suffix;$fixture=['actors'=>$actors,'students'=>[],'nis'=>$nis];
foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);$_SESSION=['admin_id'=>$actors['admin']['id'],'admin_role'=>'admin','active_unit_id'=>$unit];
    $class=$koneksi->query('SELECT id,tingkat FROM master_kelas WHERE is_active=1 AND is_placeholder=0 ORDER BY tingkat,id LIMIT 1')->fetch_assoc();$name='Siswa Uji Seragam dan Tabungan '.$unit;$level=(string)$class['tingkat'];$cid=(int)$class['id'];$s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB) VALUES(?,?,?,?,100000)');$s->bind_param('sssi',$nis,$name,$level,$cid);$s->execute();$sid=(int)$koneksi->insert_id;$s->close();
    $koneksi->query("INSERT INTO tabungan(NO_INDUK,SALDO) VALUES('$nis',100000)");$owner=$actors['kasir']['id'];
    $koneksi->query("INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES('$nis','2026-10-01 08:00:00',100000,0,'$owner','Setoran awal')");$in=(int)$koneksi->insert_id;
    $koneksi->query("INSERT INTO transaksi_k(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES('$nis','2026-10-02 08:00:00',0,80000,'$owner','Penarikan')");$out=(int)$koneksi->insert_id;
    $koneksi->query("INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES('$nis','2026-10-03 08:00:00',80000,0,'$owner','Setoran berikutnya')");$later=(int)$koneksi->insert_id;
    $before=$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0];
    $proposal=['nominal'=>'120000','tanggal'=>'2026-10-01T08:00:00','keterangan'=>'Dikoreksi','reason'=>'Salah input nominal setoran.'];
    $request=savings_correct($koneksi,'request_edit','masuk',$in,$proposal,$actors['admin']['id']);so_assert($koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0]===$before,'Proposal changed balance');
    so_denied(fn()=>savings_correct($koneksi,'request_hapus','masuk',$in,['reason'=>'Pengajuan ganda'], $actors['kasir']['id']),'Duplicate request accepted');
    so_denied(fn()=>savings_correct($koneksi,'approve','masuk',$in,[],$actors['admin']['id'],$request),'Admin approved');
    savings_correct($koneksi,'approve','masuk',$in,[],$actors['super_admin']['id'],$request);so_assert((float)$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0]===120000.0,'Approved balance');so_assert($koneksi->query("SELECT user_id FROM transaksi_m WHERE id=$in")->fetch_row()[0]===(string)$owner,'Creator rewritten');
    so_denied(fn()=>savings_correct($koneksi,'approve','masuk',$in,[],$actors['super_admin']['id'],$request),'Approval replay');
    so_denied(fn()=>savings_correct($koneksi,'direct_edit','masuk',$in,array_replace($proposal,['nominal'=>'70000']),$actors['super_admin']['id']),'Historical negative balance accepted');
    so_denied(fn()=>savings_correct($koneksi,'direct_hapus','masuk',$in,['reason'=>'Hapus setoran awal'], $actors['super_admin']['id']),'Delete creating historical negative accepted');
    so_denied(fn()=>savings_correct($koneksi,'request_edit','masuk',$in,$proposal,$actors['bendahara']['id']),'Treasury correction');
    $request=savings_correct($koneksi,'request_hapus','keluar',$out,['reason'=>'Transaksi penarikan salah.'],$actors['kasir']['id']);savings_correct($koneksi,'reject','keluar',$out,['reason'=>'Penarikan sudah benar.'],$actors['super_admin']['id'],$request);so_assert((int)$koneksi->query("SELECT COUNT(*) FROM transaksi_k WHERE id=$out")->fetch_row()[0]===1,'Rejection removed row');
    $request=savings_correct($koneksi,'request_hapus','keluar',$out,['reason'=>'Pembatalan permintaan saya.'],$actors['admin']['id']);so_denied(fn()=>savings_correct($koneksi,'cancel','keluar',$out,[],$actors['kasir']['id'],$request),'Other user cancelled');savings_correct($koneksi,'cancel','keluar',$out,[],$actors['admin']['id'],$request);
    $request=savings_correct($koneksi,'request_edit','masuk',$in,$proposal,$actors['admin']['id']);$koneksi->query("UPDATE transaksi_m SET keterangan='Benturan snapshot' WHERE id=$in");so_denied(fn()=>savings_correct($koneksi,'approve','masuk',$in,[],$actors['super_admin']['id'],$request),'Stale snapshot applied');savings_correct($koneksi,'reject','masuk',$in,['reason'=>'Data sudah berubah.'],$actors['super_admin']['id'],$request);
    $request=savings_correct($koneksi,'request_hapus','keluar',$out,['reason'=>'Penarikan salah dan dibatalkan.'],$actors['kasir']['id']);savings_correct($koneksi,'approve','keluar',$out,[],$actors['super_admin']['id'],$request);so_assert((int)$koneksi->query("SELECT COUNT(*) FROM transaksi_k WHERE id=$out")->fetch_row()[0]===0,'Approved deletion active');so_assert(!empty(savings_transaction($koneksi,'keluar',$out)['deleted']),'Deleted audit missing');
    so_denied(fn()=>$koneksi->query("UPDATE tabungan_aktivitas SET note='rusak'"),'Audit mutable');
    $koneksi->query("UPDATE tabungan SET SALDO=SALDO+1 WHERE NO_INDUK='$nis'");so_denied(fn()=>savings_correct($koneksi,'direct_edit','masuk',$in,$proposal,$actors['super_admin']['id']),'Mismatched balance accepted');$koneksi->query("UPDATE tabungan SET SALDO=SALDO-1 WHERE NO_INDUK='$nis'");
    $model=authorization_detail_model($koneksi,$in,false,'tabungan_masuk');so_assert($model['nis']===$nis&&count($model['events'])>3,'Savings detail model');
    $history=authorization_combined_page($koneksi,true,'tabungan_masuk','all',$nis,1,['approved']);so_assert($history['total']===1,'History source/status filter');
    $name='Seragam QA '.$suffix;$koneksi->query("INSERT INTO master_biaya_lain(nama,nominal,is_active) VALUES('$name',100000,1)");$master=(int)$koneksi->insert_id;
    $koneksi->query("INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,status,created_by) VALUES($master,'$nis','$name',50000,'open',".$actors['super_admin']['id'].")");$bill=(int)$koneksi->insert_id;
    $pids=[];foreach(['2026-10-04 09:00:00','2026-10-05 09:00:00'] as $date){$seen=[];$line=other_fee_optional_line($koneksi,'master:'.$master,110000,'Ukuran L',null,$seen);so_assert($line['bill_id']===null&&$line['nominal']===110000.0,'Optional reference');so_denied(fn()=>other_fee_optional_line($koneksi,'master:'.$master,100000,'',null,$seen),'Duplicate optional master');
        $koneksi->query("INSERT INTO bayar(NO_INDUK,KELAS,U_LAIN,TGL_BYR,BULAN,TAHUN,sistem_pembayaran,total_jumlah) VALUES('$nis','$level',110000,'$date','10','2026','Tunai',110000)");$pid=(int)$koneksi->insert_id;$pids[]=$pid;$koneksi->query("INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot,urutan) VALUES($pid,$master,NULL,'$name',110000,1)");
    }
    so_assert(other_fee_paid_for_bill($koneksi,$bill,0)===0.0,'Optional settled old bill');
    $fixture['students'][$unit]=compact('sid','in','out','later','master','bill','pids','request');
}
unit_set_context($koneksi,0);so_denied(fn()=>savings_correct($koneksi,'direct_hapus','masuk',$in,['reason'=>'Salah unit global'],$actors['super_admin']['id']),'All-unit mutation');
$_SESSION=['admin_id'=>$actors['super_admin']['id'],'admin_role'=>'super_admin','active_unit_id'=>0];$all=authorization_combined_page($koneksi,true,'all','all',$nis,1,['*']);so_assert($all['total']===6,'Combined composite identities');
file_put_contents(getenv('SPP_SAVINGS_QA').'/fixture.json',json_encode($fixture,JSON_THROW_ON_ERROR));echo "PASS $checks checks: savings authorization, historical balances, archive, optional purchases and unit identity.\n";
