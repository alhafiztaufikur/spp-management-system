<?php
/** Read-only reconciliation against native and Legacy models on an identified copy. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
require_once __DIR__.'/../includes/global_report_print.php';
require_once __DIR__.'/../includes/excel.php';
function clone_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$tables=['siswa','bayar','bayar_du','bayar_biaya_lain','tabungan','tabungan_masuk','tabungan_keluar','tagihan_spp','tagihan_komite','tagihan_du'];
$existing=[];foreach($koneksi->query('SHOW TABLES')as $row)$existing[]=array_values($row)[0];$tables=array_values(array_intersect($tables,$existing));
$checksum=static function()use($koneksi,$tables){$r=[];foreach($koneksi->query('CHECKSUM TABLE '.implode(',',array_map(static fn($t)=>'`'.$t.'`',$tables)).' EXTENDED')as $row)$r[$row['Table']]=$row['Checksum'];return $r;};
$before=$checksum();$_SESSION=['admin_role'=>'admin','active_unit_id'=>1,'admin_nama'=>'Pengujian'];$results=[];
foreach([1,2,3,0]as $unit){unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;
 foreach(['riwayat-tagihan','penerimaan','spp-tahunan','per-item','status','tabungan-siswa','saldo-tabungan']as $template){
  $params=['template'=>$template,'kelas'=>'tingkat:1','siswa_status'=>'active','tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-11-12','tahun_ajaran'=>'2026/2027','bulan_awal'=>'9','tahun_awal'=>'2026','bulan_akhir'=>'11','tahun_akhir'=>'2026'];
  if($unit===2)$params['kelas']='tingkat:7';if($unit===3)$params['kelas']='tingkat:10';
  if(in_array($template,['status','per-item'],true))$params['kategori']=['spp','komite','daftar_ulang','psb'];
  if($template==='penerimaan')$params['kategori']='semua';
  $f=report_filters($koneksi,$params);$source=report_build($koneksi,$template,$f);$original=serialize($source);$totals=report_money_totals($source,$template);
  foreach(['screen','print','excel']as $surface){$shown=global_report_present($source,$template,$surface);
   $compare=static function($a,$b)use(&$compare){clone_check(count($a['rows'])===count($b['rows']),'Row count changed');foreach($a['rows']as $i=>$row)foreach($row as $key=>$v)if(is_numeric($v)||$v===null)clone_check($b['rows'][$i][$key]===$v,'Financial/identity value changed: '.$key);foreach($a['sections']??[]as $i=>$s)$compare($s,$b['sections'][$i]);};$compare($source,$shown);
   $new=array_column(report_money_totals($shown,$template),'value','key');foreach($totals as $t)if(!($template==='tabungan-siswa'&&$t['key']==='penyesuaian'))clone_check(abs($new[$t['key']]-$t['value'])<.0001,'Total changed: '.$template.'/'.$t['key']);
   $matrix=global_report_matrix($shown,$template,$unit);if($matrix){$old=global_report_matrix($source,$template,$unit);clone_check(count($matrix['rows'])===count($old['rows']),'Matrix identities changed');foreach($old['subtotals']as $key=>$total)clone_check(abs($matrix['subtotals'][$key]['value']-$total['value'])<.0001,'Matrix subtotal changed');}
   if($surface==='excel'){$doc=$matrix?global_report_matrix_excel($matrix,$shown,$template,$unit):spp_excel_report_document($shown,$template,$unit);$doc['presentation']='global';$doc=global_report_clean_excel_document($doc,$template);$book=spp_excel_workbook($doc);clone_check($book->getSheetCount()>0,'Missing workbook');$book->disconnectWorksheets();}
  }
  clone_check(serialize($source)===$original,'Source model mutated');$results[]=['unit'=>$unit,'template'=>$template,'rows'=>count($source['rows']),'totals'=>$totals];echo "PASS unit $unit $template: source rows, numeric/null values, subtotal and total invariants.\n";
 }
}
clone_check($checksum()===$before,'Student/payment/savings tables changed during rendering');
if($out=getenv('SPP_UI_OUTPUT'))file_put_contents($out.'/model-reconciliation.json',json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'PASS financial table checksums unchanged ('.count($tables)." tables).\n";
