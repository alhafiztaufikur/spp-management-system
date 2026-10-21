<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/general_multiple.php';require_once __DIR__.'/../includes/excel.php';require_once __DIR__.'/../includes/global_report_matrix.php';
$qa=(string)getenv('SPP_RECAP_QA');if(!is_dir($qa)||!str_contains($qa,'recap_refinements_'))throw new RuntimeException('Explicit audit directory required.');
$models=[];$global=[];$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try{foreach(['sd'=>[1,false],'all'=>[0,false],'undated'=>[1,true]] as $name=>[$unit,$undated]){
 $_SESSION=['admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);$_GET=['include_undated'=>$undated?'1':'0'];
 $parts=general_sections($koneksi,['*'],'2026-10-01','2026-10-22','','nama');$models[$name]=['unit'=>$unit,'undated'=>$undated,'sections'=>general_excel_sections($parts),'payment_total'=>general_payment_summary($koneksi,'2026-10-01','2026-10-22')['total']];
}
 unit_set_context($koneksi,1);$_GET=[];
 foreach(['setoran','kas-tabungan','penerimaan','tabungan-siswa'] as $template){$filters=report_filters($koneksi,['template'=>$template,'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-21','kategori'=>$template==='penerimaan'?'semua':'spp']);$report=report_build($koneksi,$template,$filters);$matrix=global_report_matrix($report,$template,1);$global[$template]=($matrix?global_report_matrix_excel($matrix,$report,$template,1):spp_excel_report_document($report,$template,1))['sheets'][0]['sections'];}
}finally{$koneksi->rollback();}
file_put_contents($qa.'/export-models.json',json_encode($models,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));echo "Export models captured on a read-only audit snapshot.\n";
file_put_contents($qa.'/export-global-models.json',json_encode($global,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
