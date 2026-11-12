<?php
/** Render actual standalone preview functions without a database or financial writes. */
if(PHP_SAPI!=='cli')exit(1);
$out=(string)getenv('SPP_UI_OUTPUT');
if(!is_dir($out)||!str_contains(str_replace('\\','/',$out),'/backups/spp-management-system/'))throw new RuntimeException('Use private QA output outside the web root.');
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/excel.php';
require_once __DIR__.'/../includes/report_preview.php';
$_SESSION=['admin_nama'=>'Fixture bentuk komponen'];
$columns=[spp_excel_column('no','No','number'),spp_excel_column('nis','NIS'),spp_excel_column('nama','Nama Siswa'),spp_excel_column('kelas','Kelas'),spp_excel_column('status','Status','status')];
$section=spp_excel_section('Fixture pratinjau',$columns,[['no'=>1,'nis'=>'0000001234','nama'=>'Fixture nama panjang untuk pemeriksaan label','kelas'=>'12 IPA A','status'=>'Belum Diketahui']],[]);
$doc=spp_excel_document('Fixture Pratinjau','',1,[['name'=>'Fixture','sections'=>[$section],'signatures'=>false]]);
$ordinary=spp_excel_preview_html($doc);file_put_contents($out.'/shapes-preview-ordinary.html',$ordinary);
$doc['presentation']='global';$global=spp_excel_preview_html($doc);file_put_contents($out.'/shapes-preview-global.html',$global);
if(!str_contains($ordinary,'desktop-actions')||!str_contains($global,'desktop-actions'))throw new RuntimeException('Standalone preview is missing the shared shape rule.');
register_shutdown_function(static function()use($out):void{file_put_contents($out.'/shapes-preview-shell.html',ob_get_clean());});ob_start();
render_report_export_preview($global,['presentation'=>'global','unit'=>1,'file_type'=>'EXCEL','title'=>'Fixture Pratinjau','row_count'=>1,'download_url'=>'#download','back_url'=>'#back']);
