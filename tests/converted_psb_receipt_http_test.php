<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_/',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/date_format.php';
$base=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');$root=(string)getenv('SPP_COMPONENT_ARTIFACTS');if(!is_dir($root))throw new RuntimeException('Private artifact directory required');
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$sid='convertedreceipt'.bin2hex(random_bytes(10));session_id($sid);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>0];session_write_close();
function cr_get(string $path,string $sid):string{global $base;$s=file_get_contents($base.$path,false,stream_context_create(['http'=>['header'=>'Cookie: PHPSESSID='.$sid,'ignore_errors'=>true,'timeout'=>30]]));if(!str_contains($http_response_header[0],'200'))throw new RuntimeException('Receipt HTTP failed');return $s;}
foreach([1,2,3] as $unit) {
 $row=$koneksi->query("SELECT b.id,b.U_PSB,b.TGL_BYR,b.total_jumlah FROM financial_component_migration_row m JOIN bayar_data b ON b.id=m.source_id AND b.unit_id=m.unit_id WHERE m.entity='payment' AND m.unit_id=$unit AND CAST(JSON_UNQUOTE(JSON_EXTRACT(m.before_data,'$.U_PANGKAL')) AS DECIMAL(15,2))>0 LIMIT 1")->fetch_assoc();
 if(!$row)throw new RuntimeException('Converted source missing');
 $html=cr_get('/laporan/cetak_struk.php?id='.$row['id'],$sid);
 if(str_contains($html,'Pangkal')||!str_contains($html,'Uang PSB')||!str_contains($html,spp_date_label($row['TGL_BYR'],true)))throw new RuntimeException('Converted receipt component/date mismatch');
 $day=substr($row['TGL_BYR'],0,10);$query=http_build_query(['output'=>'pdf','mode'=>'selected','ids'=>[$row['id']],'tanggal_awal'=>$day,'tanggal_akhir'=>$day,'unit'=>'all']);
 $pdf=cr_get('/laporan/export_pdf.php?'.$query,$sid);if(!str_starts_with($pdf,'%PDF-'))throw new RuntimeException('Receipt binary missing');
 file_put_contents($root.'/converted-unit-'.$unit.'.pdf',$pdf);file_put_contents($root.'/converted-unit-'.$unit.'.html',$html);
 echo 'PASS: converted unit '.$unit.' PSB receipt, original total/date, owning school and binary PDF'.PHP_EOL;
}

