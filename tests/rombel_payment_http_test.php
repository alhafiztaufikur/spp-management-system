<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/http_form_scope.php';
spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require_once __DIR__.'/../includes/kelas.php';
require_once __DIR__.'/../includes/biaya_lain.php';
require_once __DIR__.'/../includes/student_class_transition.php';
$checks=0;$root=getenv('SPP_SAVINGS_QA');$fixture=['units'=>[],'sessions'=>[]];
function rp_assert(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rp_http(string $sid,string $path,?array $post=null):array {
    $c=curl_init(getenv('SPP_TEST_BASE_URL').'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sid,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>30]);
    if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$length=curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);$body=substr($raw,$length);
    rp_assert(!preg_match('/Fatal error|Warning:|Parse error|SQLSTATE/',$body),'Runtime failure on '.$path.': '.substr(strip_tags($body),0,400));return [$code,$body,substr($raw,0,$length)];
}
function rp_fields(string $html,string $field):array {
    $form=spp_test_form_scope($html,$field);$d=new DOMDocument();@$d->loadHTML($form);$fields=[];
    foreach($d->getElementsByTagName('input') as $i){$name=$i->getAttribute('name');if($name!==''&&!str_contains($name,'[')&&!$i->hasAttribute('disabled'))$fields[$name]=$i->getAttribute('value');}return $fields;
}
if(defined('ROMBEL_HELPERS_ONLY')&&ROMBEL_HELPERS_ONLY)return;
ini_set('session.save_path',getenv('SPP_QA_SESSION_PATH'));
foreach(['super_admin'=>'superadmin','admin'=>'administrator1'] as $role=>$username){
    $a=$koneksi->query("SELECT id,nama FROM admin WHERE username='$username'")->fetch_assoc();$sid='rombel'.bin2hex(random_bytes(12));$csrf=bin2hex(random_bytes(32));session_id($sid);session_start();$_SESSION=['admin_id'=>(int)$a['id'],'admin_nama'=>$a['nama'],'admin_role'=>$role,'active_unit_id'=>1,'csrf_unit_switch'=>$csrf,'csrf_payment'=>$csrf,'csrf_student'=>$csrf,'csrf_master_kelas'=>$csrf,'csrf_master_biaya_lain'=>$csrf,'csrf_savings'=>$csrf,'csrf_token'=>$csrf];session_write_close();$fixture['sessions'][$role]=['id'=>$sid,'csrf'=>$csrf,'actor'=>(int)$a['id']];
}
$session=$fixture['sessions']['super_admin'];$admin=$fixture['sessions']['admin'];$suffix=(string)random_int(10000,99999);
foreach([1,2,3] as $unit){
    $_SESSION=['admin_id'=>$session['actor'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
    foreach([$session,$admin] as $ss)rp_http($ss['id'],'unit_switch.php',['unit_id'=>$unit,'csrf_token'=>$ss['csrf'],'next'=>'/master_kelas.php']);
    [$first,$last]=unit_level_bounds();
    $classes=[];foreach($koneksi->query("SELECT * FROM master_kelas WHERE tingkat IN ($first,".($first+1).") AND kode_rombel IN ('A','B') AND is_active=1")->fetch_all(MYSQLI_ASSOC) as $c)$classes[$c['tingkat'].$c['kode_rombel']]=$c;
    $source=$classes[$first.'A'];$target=$classes[($first+1).'A'];$psb=(int)$koneksi->query("SELECT id FROM master_kelas WHERE jenis='psb'")->fetch_row()[0];$out=(int)$koneksi->query("SELECT id FROM master_kelas WHERE jenis='out'")->fetch_row()[0];
    $customName='Tabungan Guru QA '.$suffix;
    rp_http($session['id'],'master_kelas.php',['aksi'=>'tambah_custom','nama_custom'=>$customName,'csrf_token'=>$session['csrf'],'_operational_unit'=>$unit]);
    $custom=$koneksi->query("SELECT * FROM master_kelas WHERE nama_custom='$customName'")->fetch_assoc();rp_assert($custom&&$custom['tingkat']===null,'Custom rombel has no level');
    rp_http($session['id'],'master_kelas.php',['aksi'=>'tambah_custom','nama_custom'=>$customName,'csrf_token'=>$session['csrf'],'_operational_unit'=>$unit]);rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM master_kelas WHERE nama_custom='$customName'")->fetch_row()[0]===1,'Duplicate custom accepted');
    [, $html]=rp_http($session['id'],'siswa/daftar.php');$form=rp_fields($html,'no_induk');
    $nis='94'.$unit.$suffix;$studentName='Rombel Payment QA '.$unit.' '.$suffix;
    $studentPost=array_replace($form,['aksi'=>'tambah','no_induk'=>$nis,'nama'=>$studentName,'master_kelas_id'=>$source['id'],'psb'=>0,'pomg'=>10000,'daftar_ulang'=>50000,'potongan_psb_nominal'=>0,'potong_du'=>0,'komite_mulai_bulan'=>'07','_operational_unit'=>$unit]);
    rp_http($session['id'],'siswa/daftar.php',$studentPost);
    $student=$koneksi->query("SELECT * FROM siswa WHERE NO_INDUK='$nis'")->fetch_assoc();rp_assert((bool)$student,'Student creation failed');$studentId=(int)$student['id'];
    $placement=$koneksi->query("SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id WHERE p.no_induk='$nis' AND p.status='aktif'")->fetch_assoc();rp_assert((bool)$placement,'Placement missing');
    $koneksi->query("INSERT INTO master_biaya_lain(nama,nominal,jenis_pembayaran) VALUES('Buku Paket QA $suffix',125000,'berulang')");$master=(int)$koneksi->insert_id;
    $pay=function(float $amount,array $extra=[])use($session,$nis,$master,$unit):array{
        [, $html]=rp_http($session['id'],'pembayaran/form.php');$fields=rp_fields($html,'request_key');$post=array_replace($fields,['aksi'=>'input','no_induk'=>$nis,'bulan_bayar'=>'10','tahun_bayar'=>'2026','sistem_pembayaran'=>'Tunai','payment_plan'=>'monthly','uang_psb'=>0,'uang_spp'=>0,'uang_komite'=>0,'uang_du'=>0,'biaya_lain_tagihan_id'=>['master:'.$master],'biaya_lain_nominal'=>[$amount],'biaya_lain_keterangan'=>['QA exact tariff'],'_other_fee_tariffs'=>['master:'.$master=>125000],'_operational_unit'=>$unit],$extra);return rp_http($session['id'],'pembayaran/proses.php',$post);
    };
    foreach([110000,130000,0] as $amount){$pay($amount);rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis'")->fetch_row()[0]===0,'Wrong tariff saved: '.$amount);}
    $pay(125000);$payment=$koneksi->query("SELECT * FROM bayar WHERE NO_INDUK='$nis' ORDER BY id DESC LIMIT 1")->fetch_assoc();rp_assert($payment&&(float)$payment['total_jumlah']===125000.0,'Exact payment failed');$pid=(int)$payment['id'];
    $line=$koneksi->query('SELECT * FROM bayar_biaya_lain WHERE bayar_id='.$pid)->fetch_assoc();rp_assert((float)$line['tarif_referensi_snapshot']===125000.0,'Tariff snapshot missing');
    $koneksi->query("UPDATE master_biaya_lain SET nominal=140000 WHERE id=$master");$pay(125000);rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis'")->fetch_row()[0]===1,'Stale tariff saved');
    $koneksi->query("UPDATE master_biaya_lain SET nominal=125000 WHERE id=$master");
    // Invalid changes must not even queue an authorization request.
    $edit=['aksi'=>'update','id'=>$pid,'csrf_token'=>$admin['csrf'],'no_induk'=>$nis,'authorization_reason'=>'QA invalid recurring amount','biaya_lain_detail_id'=>[$line['id']],'biaya_lain_tagihan_id'=>['master:'.$master],'biaya_lain_nominal'=>[110000],'biaya_lain_keterangan'=>['QA'],'_operational_unit'=>$unit];
    rp_http($admin['id'],'pembayaran/proses.php',$edit);rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM transaksi_otorisasi WHERE bayar_id=$pid AND status='pending'")->fetch_row()[0]===0,'Invalid edit queued');
    $du=$koneksi->query("SELECT * FROM tagihan_daftar_ulang WHERE no_induk='$nis' AND penempatan_id=".(int)$placement['id'])->fetch_assoc();$duPayments=[];
    foreach([10000,20000] as $paid) {
        $paidBefore=(float)$koneksi->query('SELECT COALESCE(SUM(jumlah),0) FROM bayar_du WHERE tagihan_daftar_ulang_id='.(int)$du['id'])->fetch_row()[0];
        $pay(125000,['uang_du'=>$paid,'tagihan_daftar_ulang_id'=>$du['id'],'du_expected_total'=>$du['nominal_tagihan'],'du_expected_paid'=>$paidBefore]);
        $lastPayment=$koneksi->query("SELECT * FROM bayar WHERE NO_INDUK='$nis' ORDER BY id DESC LIMIT 1")->fetch_assoc();rp_assert((float)$koneksi->query('SELECT COALESCE(SUM(jumlah),0) FROM bayar_du WHERE bayar_id='.(int)$lastPayment['id'])->fetch_row()[0]===$paid*1.0&&(float)$lastPayment['total_jumlah']===(float)(125000+$paid),'Combined DU installment failed');$duPayments[]=(int)$lastPayment['id'];
    }
    [, $html]=rp_http($session['id'],'pembayaran/riwayat_daftar_ulang.php?q='.$nis);
    foreach($duPayments as $id)rp_assert(str_contains($html,'cetak_struk.php?id='.$id),'DU receipt button missing for installment');
    [$code,$pdf]=rp_http($session['id'],'laporan/cetak_struk.php?id='.$duPayments[0].'&output=pdf');rp_assert($code===200&&str_starts_with($pdf,'%PDF'),'Combined receipt PDF failed');file_put_contents($root.'/outputs/combined-'.$unit.'.pdf',$pdf);
    $koneksi->query("INSERT INTO master_biaya_lain(nama,nominal,jenis_pembayaran) VALUES('Cicilan QA $suffix',100000,'tidak_berulang')");$installmentMaster=(int)$koneksi->insert_id;$m=$koneksi->query('SELECT * FROM master_biaya_lain WHERE id='.$installmentMaster)->fetch_assoc();
    $koneksi->begin_transaction();other_fee_publish($koneksi,$installmentMaster,'siswa',[$studentId],other_fee_master_version($m));$koneksi->commit();$bill=$koneksi->query('SELECT * FROM tagihan_biaya_lain WHERE master_biaya_lain_id='.$installmentMaster)->fetch_assoc();
    foreach([40000,60000] as $paid)$pay(0,['biaya_lain_tagihan_id'=>[$bill['id']],'biaya_lain_nominal'=>[$paid]]);
    rp_assert((float)other_fee_bill_find($koneksi,(int)$bill['id'],$nis)['sisa']===0.0,'Nonrecurring installments regressed');
    // Same-level transfer follows active rombel while the paid receipt retains its original class.
    [, $html]=rp_http($session['id'],'siswa/daftar.php?edit='.$studentId);$editStudent=array_replace(rp_fields($html,'no_induk'),$studentPost,['aksi'=>'update','id'=>$studentId,'master_kelas_id'=>$classes[$first.'B']['id']]);
    rp_http($session['id'],'siswa/daftar.php',$editStudent);
    $roster=promotion_roster($koneksi,(int)$placement['tahun_ajaran_id'],$first);$member=array_values(array_filter($roster,fn($r)=>$r['NO_INDUK']===$nis))[0]??null;
    rp_assert($member&&$member['kelas_label']===$first.'B','Transfer not visible under new rombel');rp_assert($koneksi->query("SELECT kelas_rombel_snapshot FROM bayar WHERE id=$pid")->fetch_row()[0]===$first.'A','Receipt history rewritten');
    $editStudent['master_kelas_id']=$target['id'];rp_http($session['id'],'siswa/daftar.php',$editStudent);
    $years=$koneksi->query("SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id WHERE p.no_induk='$nis' ORDER BY y.label")->fetch_all(MYSQLI_ASSOC);
    rp_assert(count($years)===2&&$years[1]['label']===class_next_academic_year_label($placement['label']),'Data Siswa promotion did not create next year');
    $targetYear=(int)$years[1]['tahun_ajaran_id'];$nextLevel=$first+1;
    [, $html]=rp_http($session['id'],"master_kelas.php?source_year_id=$targetYear&source_level=$nextLevel");rp_assert(str_contains($html,$nis)&&str_contains($html,'Tahun ajaran ini belum berjalan'),'Future roster hidden');
    [, $html]=rp_http($session['id'],'master_kelas.php?promotion_lookup='.$nis);rp_assert(str_contains($html,$years[1]['label'])&&str_contains($html,$nis),'Cross-year search missing');
    $blocked=false;try{promotion_context($koneksi,$targetYear);}catch(Throwable $e){$blocked=true;}rp_assert($blocked,'Future year can be promoted again');
    // Return to PSB with payment and future placement. No correction checks or cancellation.
    $beforeBills=$koneksi->query("SELECT id,nominal_tagihan,status FROM tagihan_komite WHERE no_induk='$nis' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $editStudent['master_kelas_id']=$psb;$editStudent['psb']=500000;rp_http($session['id'],'siswa/daftar.php',$editStudent);
    $psbStudent=$koneksi->query("SELECT * FROM siswa WHERE id=$studentId")->fetch_assoc();rp_assert($psbStudent['KELAS']==='0'&&(int)$psbStudent['asal_psb']===1,'Direct regular to PSB blocked');
    rp_assert($beforeBills===$koneksi->query("SELECT id,nominal_tagihan,status FROM tagihan_komite WHERE no_induk='$nis' ORDER BY id")->fetch_all(MYSQLI_ASSOC),'PSB transition changed old bills');
    rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$nis' AND status='aktif'")->fetch_row()[0]===0,'PSB retains active regular placement');
    [$code]=rp_http($session['id'],'siswa/koreksi_psb.php',['csrf_token'=>$session['csrf'],'_operational_unit'=>$unit]);rp_assert($code===410,'Retired correction endpoint active');
    $editStudent['master_kelas_id']=$out;rp_http($session['id'],'siswa/daftar.php',$editStudent);$row=$koneksi->query("SELECT * FROM siswa WHERE id=$studentId")->fetch_assoc();rp_assert($row['KELAS']==='OUT'&&!(int)$row['is_active'],'Out not archived');
    [, $html]=rp_http($session['id'],'siswa/daftar.php?kelas='.$out);rp_assert(str_contains($html,$studentName),'Out filter hides member');
    $editStudent['master_kelas_id']=$custom['id'];rp_http($session['id'],'siswa/daftar.php',$editStudent);$row=$koneksi->query("SELECT * FROM siswa WHERE id=$studentId")->fetch_assoc();rp_assert($row['KELAS']==='CUSTOM'&&(int)$row['is_active']===1,'Custom restore failed');
    rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$nis' AND status='aktif'")->fetch_row()[0]===0,'Custom has academic placement');
    [, $html]=rp_http($session['id'],'tabungan/riwayat.php?nis='.$nis);rp_assert(str_contains($html,$studentName),'Custom absent from savings');
    foreach(['masuk'=>75000,'keluar'=>25000] as $action=>$amount){
        rp_http($session['id'],'tabungan/proses.php',['aksi'=>$action,'no_induk'=>$nis,'nominal'=>$amount,'keterangan'=>'Tabungan Guru QA','request_key'=>bin2hex(random_bytes(16)),'csrf_token'=>$session['csrf'],'_operational_unit'=>$unit]);
    }
    rp_assert((float)$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0]===50000.0,'Custom savings deposit/withdrawal failed');
    [$code,$book]=rp_http($session['id'],'tabungan/cetak_buku.php?nis='.$nis.'&student_id='.$studentId.'&output=pdf');rp_assert($code===200&&str_starts_with($book,'%PDF'),'Custom savings book PDF failed');file_put_contents($root.'/outputs/custom-book-'.$unit.'.pdf',$book);
    [, $html]=rp_http($session['id'],'pembayaran/form.php');rp_assert(!str_contains($html,'value="'.$nis.'"'),'Custom offered for new school payments');
    $koneksi->query("INSERT INTO master_biaya_lain(nama,nominal,jenis_pembayaran) VALUES('Sekali QA $suffix',100000,'tidak_berulang')");$once=(int)$koneksi->insert_id;$masterRow=$koneksi->query("SELECT * FROM master_biaya_lain WHERE id=$once")->fetch_assoc();$failed=false;
    $koneksi->begin_transaction();try{other_fee_publish($koneksi,$once,'siswa',[$studentId],other_fee_master_version($masterRow));}catch(Throwable $e){$failed=true;}finally{$koneksi->rollback();}rp_assert($failed,'Custom received academic fee publication');
    // Keep a regular student with debt and several history rows for browser checks.
    $browserNis='95'.$unit.$suffix;$browserPost=array_replace($studentPost,['no_induk'=>$browserNis,'nama'=>'Browser Payment QA '.$unit,'master_kelas_id'=>$psb,'psb'=>500000]);rp_http($session['id'],'siswa/daftar.php',$browserPost);
    $browserId=(int)$koneksi->query("SELECT id FROM siswa WHERE NO_INDUK='$browserNis'")->fetch_row()[0];rp_assert($browserId>0,'Browser student creation');
    $browserPost=array_replace($browserPost,['aksi'=>'update','id'=>$browserId,'master_kelas_id'=>$source['id']]);rp_http($session['id'],'siswa/daftar.php',$browserPost);
    for($i=0;$i<3;$i++)$pay(125000,['no_induk'=>$browserNis]);
    // Exercise the Master Kelas POST route separately from the Data Siswa route.
    $menuNis='96'.$unit.$suffix;$menuPost=array_replace($studentPost,['no_induk'=>$menuNis,'nama'=>'Menu Promotion QA '.$unit]);rp_http($session['id'],'siswa/daftar.php',$menuPost);
    $menuSource=$koneksi->query("SELECT * FROM siswa_tahun_ajaran WHERE no_induk='$menuNis' AND status='aktif'")->fetch_assoc();rp_assert((bool)$menuSource,'Menu source missing');
    $promotionPost=['aksi'=>'proses_siswa_batch','csrf_token'=>$session['csrf'],'source_year_id'=>$menuSource['tahun_ajaran_id'],'source_level'=>$first,'target_tahun_ajaran'=>class_next_academic_year_label($placement['label']),'promotion_mode'=>'regular','selected_students'=>[$menuNis],'source_placement_id'=>[$menuNis=>$menuSource['id']],'target_master_kelas_id'=>[$menuNis=>$target['id']],'_operational_unit'=>$unit];
    rp_http($session['id'],'master_kelas.php',$promotionPost);
    rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$menuNis'")->fetch_row()[0]===2,'Menu promotion missing destination');
    rp_http($session['id'],'master_kelas.php',$promotionPost);rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$menuNis'")->fetch_row()[0]===2,'Menu replay duplicated placement');
    $menuTarget=$koneksi->query("SELECT * FROM siswa_tahun_ajaran WHERE no_induk='$menuNis' AND status='aktif'")->fetch_assoc();
    rp_assert((int)$menuTarget['master_kelas_id']===(int)$target['id'],'Menu promotion wrong active rombel');
    $crossUnit=$koneksi->query('SELECT id FROM master_kelas_data WHERE unit_id<>'.$unit.' AND jenis=\'reguler\' LIMIT 1')->fetch_row()[0];
    $badPost=$menuPost;$badPost['no_induk']='97'.$unit.$suffix;$badPost['master_kelas_id']=$crossUnit;rp_http($session['id'],'siswa/daftar.php',$badPost);
    rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa WHERE NO_INDUK='".$badPost['no_induk']."'")->fetch_row()[0]===0,'Cross-unit class accepted');
    // Rejection of custom school payments also applies to a direct POST.
    $customPaymentCount=(int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis'")->fetch_row()[0];$pay(125000);
    rp_assert((int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis'")->fetch_row()[0]===$customPaymentCount,'Custom direct school payment saved');
    [, $entry]=rp_http($session['id'],'tabungan/masuk.php?nis='.$nis);rp_assert(str_contains($entry,$customName),'Savings entry missing custom label');
    [, $export]=rp_http($session['id'],'siswa/export_excel.php?kelas='.$custom['id']);rp_assert(str_contains($export,$customName),'Student export missing custom label');
    $fixture['units'][$unit]=['nis'=>$nis,'student_id'=>$studentId,'browser_nis'=>$browserNis,'browser_student_id'=>$browserId,'source_level'=>$first,'source_year_id'=>(int)$placement['tahun_ajaran_id'],'target_year_id'=>$targetYear,'target_level'=>$nextLevel,'payment_id'=>$pid,'master_id'=>$master,'custom_id'=>(int)$custom['id'],'out_id'=>$out,'du_payment_ids'=>$duPayments];
}
file_put_contents($root.'/rombel-fixture.json',json_encode($fixture,JSON_PRETTY_PRINT));
$message='PASS: '.$checks." HTTP/model assertions across SD/SMP/SMA.\n";file_put_contents($root.'/http-result.txt',$message);echo $message;
