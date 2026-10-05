<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
ob_start();
require __DIR__.'/../koneksi.php';require __DIR__.'/../includes/reports.php';require __DIR__.'/http_form_scope.php';
$base=rtrim((string)getenv('SPP_HTTP_BASE'),'/');spp_test_assert_http_clone($base,DB_NAME);
function export_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function export_get(string $url,string $sid):string{
    $body=file_get_contents($url,false,stream_context_create(['http'=>['header'=>'Cookie: '.session_name().'='.$sid,"ignore_errors"=>true,'timeout'=>90]]));
    export_assert(str_contains($http_response_header[0]??'','200'),'HTTP failure: '.$url);return (string)$body;
}
function export_pdf_text(string $bytes):string{
    export_assert(str_starts_with($bytes,'%PDF-'),'Invalid PDF binary');$file=tempnam(sys_get_temp_dir(),'spp_all_pdf_');$out=tempnam(sys_get_temp_dir(),'spp_all_txt_');file_put_contents($file,$bytes);
    try{$p=proc_open([getenv('SPP_PDFTOTEXT'),'-layout',$file,$out],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);export_assert(is_resource($p),'Missing pdftotext');fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);export_assert(proc_close($p)===0,'PDF extraction failed');return (string)file_get_contents($out);}finally{unlink($file);unlink($out);}
}
$id=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$sid='allexports'.bin2hex(random_bytes(10));session_id($sid);session_start();$_SESSION=['admin_id'=>$id,'admin_role'=>'super_admin','active_unit_id'=>0];session_write_close();
try{
 unit_set_context($koneksi,0);$_SESSION['active_unit_id']=0;
 foreach(array_keys(report_registry()) as $template){
  $params=['template'=>$template,'tanggal_awal'=>'2026-01-01','tanggal_akhir'=>'2026-12-31','tahun_ajaran'=>'2026/2027','siswa_status'=>'all','kategori'=>in_array($template,['status','per-item'],true)?'spp':'semua','bulan_awal'=>'07','bulan_akhir'=>'06','tahun_awal'=>2026,'tahun_akhir'=>2027,'per_page'=>100];
  $report=report_build($koneksi,$template,report_filters($koneksi,$params));$query=http_build_query($params);
  $screen=export_get($base.'/laporan/template.php?'.$query,$sid);$excel=export_get($base.'/laporan/export_global.php?'.$query.'&format=excel&download=1',$sid);$pdf=export_pdf_text(export_get($base.'/laporan/export_global.php?'.$query.'&format=pdf&download=1',$sid));
  foreach(['screen'=>$screen,'excel'=>$excel,'pdf'=>$pdf] as $format=>$body){
   export_assert(!str_contains($body,'Gagal memuat laporan')&&!str_contains($body,'Fatal error'),"$template/$format rendering failed");
   $text=$format==='pdf'?$body:html_entity_decode(strip_tags($body),ENT_QUOTES,'UTF-8');$normalized=preg_replace('/\s+/','',$text);
   foreach(report_money_totals($report,$template) as $total){
    // The grouped billing matrix summarizes bill amounts; its established
    // screen/export layout does not show grand totals of paid/remaining money.
    if($template==='riwayat-tagihan'&&($total['key']??'')!=='tagihan')continue;
    export_assert(str_contains($normalized,number_format($total['value'],0,',','.')),"$template/$format total missing: ".report_money($total['value']));
   }
   if($format!=='screen'&&isset($report['rows'][0]['unit']))foreach(array_unique(array_column($report['rows'],'unit')) as $unit)export_assert(str_contains($body,$unit),"$template/$format missing unit $unit");
  }
  echo "OK: $template database/screen/Excel/PDF reconciled\n";
 }
 // Combined access must still print the school belonging to each student.
 foreach([1=>'SEKOLAH DASAR',2=>'SEKOLAH MENENGAH PERTAMA',3=>'SEKOLAH MENENGAH ATAS'] as $unitId=>$school){
  $student=$koneksi->query('SELECT s.NO_INDUK,b.id FROM siswa s JOIN bayar b ON b.NO_INDUK=s.NO_INDUK AND b.unit_id=s.unit_id WHERE s.unit_id='.$unitId.' ORDER BY b.id DESC LIMIT 1')->fetch_assoc();
  $nis=rawurlencode($student['NO_INDUK']);
  $receipt=export_get($base.'/laporan/cetak_struk.php?id='.$student['id'],$sid);
  export_assert(str_contains($receipt,$school),"Receipt school mismatch for unit $unitId");
  $book=export_pdf_text(export_get($base.'/tabungan/cetak_buku.php?nis='.$nis.'&output=pdf',$sid));
  export_assert(str_contains(preg_replace('/\s+/',' ',$book),$school),"Book PDF school mismatch for unit $unitId");
  $debtors=report_student_debt_groups($koneksi,report_filters($koneksi,['siswa_status'=>'active']),'',[],report_letter_today());
  $debtor=null;foreach($debtors as $row)if($row['unit_id']===$unitId){$debtor=$row;break;}
  export_assert($debtor!==null,"Missing parent-letter fixture for unit $unitId");
  $compose=export_get($base.'/laporan/surat_orang_tua_susun.php?mode=single&student_key='.rawurlencode(unit_student_key($debtor)),$sid);
  export_assert(preg_match('/id="parent-draft-data"[^>]*>(.*?)<\/script>/s',$compose,$draftMatch)===1,'Parent draft is unavailable');
  $draftData=json_decode($draftMatch[1],true,512,JSON_THROW_ON_ERROR);
  $parent=export_pdf_text(export_get($base.'/laporan/surat_orang_tua_pdf.php?draft='.rawurlencode($draftData['token']),$sid));
  export_assert(str_contains(preg_replace('/\s+/',' ',$parent),$school),"Parent PDF school mismatch for unit $unitId");
  echo "OK: unit $unitId receipt and book/parent PDF use owning school in combined scope\n";
 }
}finally{session_id($sid);session_start();$_SESSION=[];session_destroy();session_write_close();}
