<?php
/** Financial fixtures are allowed only on a separately owned disposable server. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/http_form_scope.php';
spp_test_isolated_manifest($koneksi);
spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
$root=getenv('SPP_REPORT_QA');$checks=0;
function fee_report_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function fee_report_build(mysqli $db,array $params):array{
    global_report_scope($db,$params);
    return report_build($db,'per-item',report_filters($db,$params));
}
function fee_report_payment(mysqli $db,string $nis,string $kelas,int $master,float $amount,?int $bill=null,string $date='2026-10-17 10:00:00'):void{
    $q=$db->prepare("INSERT INTO bayar(NO_INDUK,KELAS,U_LAIN,TGL_BYR,BULAN,TAHUN,total_jumlah,sistem_pembayaran) VALUES(?,?,?,?,'10','2026',?,'Tunai')");
    $q->bind_param('ssdsd',$nis,$kelas,$amount,$date,$amount);$q->execute();$pid=(int)$db->insert_id;$q->close();
    $q=$db->prepare("INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot,urutan) SELECT ?,id,?,nama,?,1 FROM master_biaya_lain WHERE id=?");
    $q->bind_param('iidi',$pid,$bill,$amount,$master);$q->execute();$q->close();
}
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','admin_unit_id'=>0,'active_unit_id'=>1];
$prefix='QFR'.bin2hex(random_bytes(3));$fixture=['prefix'=>$prefix,'units'=>[]];
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);
    $class=$koneksi->query('SELECT id,tingkat FROM master_kelas WHERE is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 1')->fetch_assoc();
    $classId=(int)$class['id'];$kelas=(string)$class['tingkat'];$name='Item Laporan '.$prefix;
    $q=$koneksi->prepare('INSERT INTO master_biaya_lain(nama,nominal,is_active) VALUES(?,100000,1)');$q->bind_param('s',$name);$q->execute();$master=(int)$koneksi->insert_id;$q->close();
    foreach(['A','B','C'] as $letter){$nis=$prefix.$letter;$nama='Siswa Laporan '.$prefix.' '.$letter;
        $q=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id) VALUES(?,?,?,?)');$q->bind_param('sssi',$nis,$nama,$kelas,$classId);$q->execute();$q->close();
    }
    fee_report_payment($koneksi,$prefix.'A',$kelas,$master,100000);
    fee_report_payment($koneksi,$prefix.'A',$kelas,$master,120000,null,'2026-10-16 10:00:00');
    foreach(['B','C'] as $letter){$nis=$prefix.$letter;
        $q=$koneksi->prepare("INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,status,created_by) VALUES(?,?,?,200000,'open',1)");$q->bind_param('iss',$master,$nis,$name);$q->execute();$bill=(int)$koneksi->insert_id;$q->close();
        if($letter==='B'){fee_report_payment($koneksi,$nis,$kelas,$master,50000,$bill);fee_report_payment($koneksi,$nis,$kelas,$master,30000);}
    }
    $params=['template'=>'per-item','unit'=>(string)$unit,'kategori'=>'biaya_lain:'.$master,'tanggal_awal'=>'2026-10-16','tanggal_akhir'=>'2026-10-17','q'=>$prefix,'siswa_status'=>'active'];
    $report=fee_report_build($koneksi,$params);$rows=array_column($report['rows'],null,'nis');
    fee_report_check(count($rows)===3,'All fixture students appear');
    $a=$rows[$prefix.'A'];$b=$rows[$prefix.'B'];$c=$rows[$prefix.'C'];
    fee_report_check($a['pembayaran_opsional']===220000.0&&(float)$a['terbayar']===0.0&&(float)$a['sisa']===0.0&&$a['status']==='Ada Pembayaran','Optional receipt without a bill has an accurate status');
    fee_report_check($b['pembayaran_opsional']===30000.0&&$b['terbayar']===50000.0&&$b['sisa']===150000.0&&$b['status']==='Cicilan','Optional does not reduce old debt');
    fee_report_check($c['pembayaran_opsional']===0&&$c['status']==='Belum Bayar','Unpaid old bill retains status');
    foreach(['ada_pembayaran',['ada_pembayaran'],['ada_pembayaran','belum_bayar']] as $status){
        $filtered=fee_report_build($koneksi,array_replace($params,['status'=>$status]));
        fee_report_check(count($filtered['rows'])===(is_array($status)&&count($status)===2?3:2),'Scalar and multiple status filters include optional receipts');
    }
    $filtered=fee_report_build($koneksi,array_replace($params,['status'=>['tidak_ditagihkan']]));fee_report_check(count($filtered['rows'])===0,'Paid optional row is not unbilled');
    $filtered=fee_report_build($koneksi,array_replace($params,['status'=>['tunggakan']]));fee_report_check(count($filtered['rows'])===2,'Only legacy debts are arrears');
    $singleDay=fee_report_build($koneksi,array_replace($params,['tanggal_awal'=>'2026-10-17']));$dayRows=array_column($singleDay['rows'],null,'nis');fee_report_check($dayRows[$prefix.'A']['pembayaran_opsional']===100000.0,'Transaction date applies to optional receipts');
    $multi=fee_report_build($koneksi,array_replace($params,['kategori'=>['biaya_lain:'.$master,'spp'],'status'=>['ada_pembayaran']]));
    $optionalPart=array_values(array_filter($multi['sections'],static fn($part)=>in_array('pembayaran_opsional',array_column($part['columns'],0),true)))[0];
    fee_report_check(array_sum(array_column($optionalPart['rows'],'pembayaran_opsional'))===250000.0,'Mixed categories preserve optional receipts');
    $matrix=global_report_matrix($report,'per-item',$unit);fee_report_check(array_sum(array_column($matrix['rows'],'g0_pembayaran_opsional'))===250000.0,'Matrix copies each receipt exactly once');
    $fixture['units'][$unit]=['master'=>$master,'params'=>$params];
}
// The same NIS in three units must remain three distinct rows in combined scope.
$params=$fixture['units'][1]['params'];$params['unit']='all';$params['kategori']=array_map(static fn($u)=>'biaya_lain:'.$u['master'],$fixture['units']);$params['status']=['ada_pembayaran'];
$combined=fee_report_build($koneksi,$params);fee_report_check(array_sum(array_column($combined['rows'],'pembayaran_opsional'))===750000.0,'Combined scope preserves unit isolation and totals');
unit_set_context($koneksi,0);$actor=$koneksi->query("SELECT id,nama,unit_id FROM admin WHERE is_active=1 AND role='super_admin' ORDER BY id LIMIT 1")->fetch_assoc();
session_save_path(getenv('SPP_QA_SESSION_PATH'));$paths=database_access_paths(DB_NAME,(int)getenv('SPP_DB_PORT'));$generation=is_file($paths['generation'])?trim(file_get_contents($paths['generation'])):'0';
foreach([0,1,2,3] as $unit){session_id('feereport'.bin2hex(random_bytes(12)));session_start();$_SESSION=['admin_id'=>$actor['id'],'admin_role'=>'super_admin','admin_nama'=>$actor['nama'],'admin_unit_id'=>$actor['unit_id'],'active_unit_id'=>$unit,'database_generation'=>$generation];$fixture['sessions'][$unit]=session_id();session_write_close();}
file_put_contents($root.'/report-fixture.json',json_encode($fixture,JSON_THROW_ON_ERROR));
echo "PASS $checks optional report status, filters, date, matrix and unit checks.\n";
