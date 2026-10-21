<?php
/** Controlled layout sample rendered by the production report renderer, without database writes. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/units.php';require_once __DIR__.'/../includes/excel.php';require_once __DIR__.'/../includes/transaction_report_pdf.php';require_once __DIR__.'/../includes/pdf.php';require_pdf_library();
$root=realpath((string)getenv('SPP_DESKTOP_QA'));if(!$root||!str_contains($root,'desktop_refinements_')||str_starts_with($root,realpath(__DIR__.'/..')))throw new RuntimeException('Explicit private output required.');
$columns=[spp_excel_column('no','No','number'),spp_excel_column('nis','NIS'),spp_excel_column('nama','Nama Siswa'),spp_excel_column('kelas','Kelas'),spp_excel_column('nominal','Total Bayar','money'),spp_excel_column('tanggal','Tanggal Bayar','datetime')];
$rows=[['no'=>1,'nis'=>'00001234','nama'=>'Nama Uji','kelas'=>'2A','nominal'=>100000.13,'tanggal'=>'2026-10-19 14:30:15','time_precision'=>'datetime'],['no'=>2,'nis'=>'00005678','nama'=>'Nama Siswa Panjang yang Membungkus Dengan Tetap Terlihat Rapi','kelas'=>'3B','nominal'=>125000,'tanggal'=>'2026-10-20 00:00:00','time_precision'=>'date']];
$doc=spp_excel_document('Pemeriksaan Tata Letak PDF','Data contoh untuk pemeriksaan visual',1,[['name'=>'Pembayaran','sections'=>[spp_excel_section('Semua transaksi',$columns,$rows,[['label'=>'Total Pembayaran','value'=>225000.13]])]]]);
$options=new \Dompdf\Options();$options->setIsRemoteEnabled(false);$options->setChroot(realpath(__DIR__.'/..'));$pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml(transaction_report_document_html($doc),'UTF-8');$pdf->setPaper('A4','landscape');$pdf->render();file_put_contents($root.'/artifacts/pdf-centering-sample.pdf',$pdf->output());
echo "Controlled production PDF created for centering and line alignment checks.\n";
