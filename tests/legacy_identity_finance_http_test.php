<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
ob_start();require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/http_form_scope.php';
$base=rtrim(getenv('SPP_TEST_BASE_URL'),'/');spp_test_assert_http_clone($base,DB_NAME);
function lf_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
function lf_http($path,$sid,$post=null){global $base;$opts=['method'=>$post===null?'GET':'POST','header'=>'Cookie: PHPSESSID='.$sid,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>60];if($post!==null){$opts['header'].="\r\nContent-Type: application/x-www-form-urlencoded";$opts['content']=http_build_query($post);} $body=file_get_contents($base.$path,false,stream_context_create(['http'=>$opts]));preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$m);return [(int)$m[1],$body];}
function lf_token($body){$f=spp_test_form_scope($body,'request_key');preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$f,$csrf);preg_match('/name="request_key" value="([a-f0-9]+)"/',$f,$key);return ['csrf_token'=>$csrf[1],'request_key'=>$key[1]];}
$nis='0000000099';$ids=[];$receipts=[];$sessions=[];$sum=0;
foreach([1,2,3] as $unit){
 unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;$student=$koneksi->query("SELECT * FROM siswa WHERE NO_INDUK='$nis'")->fetch_assoc();lf_assert($student&&!$student['legacy_pending'],'Activate three fixture students first');$ids[$unit]=(int)$student['id'];
 $actor=$koneksi->query("SELECT id,role FROM admin WHERE unit_id=$unit AND role='admin' AND is_active=1 LIMIT 1")->fetch_assoc();$sid='identityfinance'.bin2hex(random_bytes(10));session_id($sid);session_start();$_SESSION=['admin_id'=>$actor['id'],'admin_role'=>$actor['role'],'active_unit_id'=>$unit];session_write_close();$sessions[$unit]=$sid;
 foreach(['bayar','transaksi_m','transaksi_k'] as $table)$koneksi->query("DELETE FROM $table WHERE NO_INDUK='$nis'");$koneksi->query("UPDATE tabungan SET SALDO=0 WHERE NO_INDUK='$nis'");
 $payment=(float)($unit*1000);$sum+=$payment;[$status,$page]=lf_http('/pembayaran/form.php',$sid);lf_assert($status===200&&!str_contains($page,'Fatal error'),'Payment form');
 lf_http('/pembayaran/proses.php',$sid,['aksi'=>'input','payment_plan'=>'monthly','no_induk'=>$nis,'bulan_bayar'=>'07','tahun_bayar'=>'2026','sistem_pembayaran'=>'Tunai','uang_psb'=>$payment]+lf_token($page));
 $row=$koneksi->query("SELECT id,total_jumlah,U_PSB,unit_id FROM bayar WHERE NO_INDUK='$nis' ORDER BY id DESC LIMIT 1")->fetch_assoc();lf_assert($row&&(float)$row['total_jumlah']===$payment&&(int)$row['unit_id']===$unit,'Payment crossed student unit');$receipts[$unit]=(int)$row['id'];
 [$status,$page]=lf_http('/tabungan/masuk.php',$sid);lf_assert($status===200,'Savings form');lf_http('/tabungan/proses.php',$sid,['aksi'=>'masuk','no_induk'=>$nis,'nominal'=>(string)($unit*10000)]+lf_token($page));
 lf_assert((float)$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0]===(float)($unit*10000),'Savings shared a crossunit number');
 [$status,$balance]=lf_http('/tabungan/get_saldo.php?nis='.$nis,$sid);lf_assert($status===200&&(float)json_decode($balance,true)['saldo']===(float)($unit*10000),'Balance API wrong identity');
}
$super=$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$sid='identityall'.bin2hex(random_bytes(10));session_id($sid);session_start();$_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>0];session_write_close();unit_set_context($koneksi,0);$_SESSION['active_unit_id']=0;
lf_assert(lf_http('/tabungan/get_saldo.php?nis='.$nis,$sid)[0]===409,'Ambiguous NIS selected an identity');
lf_assert(lf_http('/pembayaran/history_siswa.php?no_induk='.$nis,$sid)[0]===409,'Ambiguous history selected an identity');
$params=['q'=>$nis,'tanggal_awal'=>'2020-01-01','tanggal_akhir'=>'2030-12-31','siswa_status'=>'all','kategori'=>'semua'];$all=report_build($koneksi,'penerimaan',report_filters($koneksi,$params));lf_assert(count($all['rows'])===3,'Combined receipts duplicated/merged identities');lf_assert((float)array_sum(array_column($all['rows'],'total_penerimaan'))===$sum,'Combined receipt total wrong');
foreach([1,2,3] as $unit){
 $p=$params+['student_id'=>$ids[$unit]];$selected=report_build($koneksi,'penerimaan',report_filters($koneksi,$p));lf_assert(count($selected['rows'])===1&&(int)$selected['rows'][0]['unit_id']===$unit&&(float)$selected['rows'][0]['total_penerimaan']===(float)($unit*1000),'ID filter changed rows without totals');
 [$status,$receipt]=lf_http('/laporan/cetak_struk.php?id='.$receipts[$unit],$sid);lf_assert($status===200&&str_contains($receipt,'Audit Legacy '.$unit)&&!str_contains($receipt,'Audit Legacy '.($unit===3?1:$unit+1)),'Receipt wrong identity');
 [$status,$book]=lf_http('/tabungan/cetak_buku.php?nis='.$nis.'&student_id='.$ids[$unit],$sid);lf_assert($status===200&&str_contains($book,'Audit Legacy '.$unit),'Savings book wrong identity');
 [$status,$balance]=lf_http('/tabungan/get_saldo.php?nis='.$nis.'&student_id='.$ids[$unit],$sid);lf_assert($status===200&&(float)json_decode($balance,true)['saldo']===(float)($unit*10000),'Explicit ID balance wrong');
 $query=http_build_query($p+['template'=>'penerimaan']);foreach(['excel','pdf'] as $format){[$status,$body]=lf_http('/laporan/export_global.php?'.$query.'&format='.$format.'&download=1',$sid);lf_assert($status===200&&($format!=='pdf'||str_starts_with($body,'%PDF-')),'Combined exact identity export failed');}
}
echo "PASS: three equal leading-zero NIS, distinct HTTP payments/savings, isolated balances, combined totals, explicit ID selection, receipts, books, Excel and PDF binaries\n";
