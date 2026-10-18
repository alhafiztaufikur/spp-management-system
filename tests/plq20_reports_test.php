<?php
/** Pure layout/PDF regression: no database writes. */
require_once __DIR__.'/../includes/savings_book.php';
require_once __DIR__.'/../includes/savings_book_render.php';
require_once __DIR__.'/../includes/authorization_presentation.php';
require_once __DIR__.'/../includes/authorization_pdf.php';
require_once __DIR__.'/../includes/excel.php';
require_once __DIR__.'/../includes/transaction_report_pdf.php';
$root=getenv('SPP_QA_DIR');if(!$root||!is_dir($root))throw new RuntimeException('Private artifact directory required.');$checks=0;
function pq_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function pq_pdf(string $html,string $name):int{global $root;$pdf=new \Dompdf\Dompdf();$pdf->loadHtml($html);$pdf->setPaper('A4','landscape');$pdf->render();file_put_contents($root.'/'.$name.'.pdf',$pdf->output());return $pdf->getCanvas()->get_page_count();}
$transactions=[];for($i=1;$i<=57;$i++)$transactions[]=['id'=>$i,'tanggal'=>sprintf('2026-10-%02d 12:00:00',($i-1)%28+1),'masuk'=>1000,'keluar'=>0,'urutan_mutasi'=>0];
$book=savings_book_make($transactions,57000);$settings=savings_book_print_settings([],57);$pages=savings_book_positioned($book,$settings);
pq_check(count($pages)===3&&count($pages[0])===28&&count($pages[2])===1,'Physical spread pagination');
pq_check($pages[0][13]['side']==='top'&&$pages[0][14]['side']==='bottom'&&$pages[1][0]['entry']['number']===29,'Fold and number continuity');
$partial=savings_book_print_settings(['entry_from'=>'15','entry_to'=>'18','start_side'=>'bottom','start_row'=>'14'],57);$positioned=savings_book_positioned($book,$partial);
pq_check(count($positioned)===2&&$positioned[0][0]['row']===14&&$positioned[1][0]['entry']['number']===16,'Partial range starts at last bottom row');
pq_check($positioned[0][0]['entry']['saldo']===$book['entries'][14]['saldo'],'Partial range preserves full ledger balance');
foreach(['full'=>$settings,'partial'=>$partial,'guide'=>$settings,'calibration'=>savings_book_print_settings(['calibration'=>'1'],57)] as $case=>$config){$pdf=savings_book_pdf($book,$config,$case==='guide');file_put_contents($root.'/plq-'.$case.'.pdf',$pdf->output());pq_check($pdf->getCanvas()->get_page_count()===count(savings_book_positioned($book,$config)),'No blank/omitted pages '.$case);}
$empty=savings_book_make([],0);$pdf=savings_book_pdf($empty,savings_book_print_settings([],0));file_put_contents($root.'/plq-empty.pdf',$pdf->output());pq_check($pdf->getCanvas()->get_page_count()===1,'Empty physical page');
$mixed=savings_book_make([['id'=>1,'tanggal'=>'2026-10-01','masuk'=>100.25,'keluar'=>0,'urutan_mutasi'=>0],['id'=>2,'tanggal'=>'2026-10-02','masuk'=>0,'keluar'=>100.25,'urutan_mutasi'=>1]],0);$pdf=savings_book_pdf($mixed,savings_book_print_settings([],2));file_put_contents($root.'/plq-zero.pdf',$pdf->output());
foreach([['layout'=>'bad'],['layout'=>['x_top'=>'0']],['layout'=>['y_bottom'=>'108']],['layout'=>['rows_top'=>'14.5']],['layout'=>['row_height'=>'10']],['layout'=>['w_in'=>'200']],['layout'=>['text_offset'=>'5']],['layout'=>['unknown'=>'1']],['start_side'=>[]],['start_row'=>'15'],['entry_from'=>'58'],['entry_to'=>['1']],['entry_from'=>'20','entry_to'=>'10'],['calibration'=>'2']] as $bad){try{savings_book_print_settings($bad,57);throw new RuntimeException('Invalid settings accepted');}catch(InvalidArgumentException $e){$checks++;}}
$event=['label'=>'Pembayaran diubah','time'=>'18/10/2026 12:00:00 WIB','authorization_id'=>0,'name'=>'Super Admin','username'=>'secret_username','role'=>'super_admin','proposed'=>false,'reconstructed'=>false,'note'=>'','action'=>'edited','changes'=>[['label'=>'Total bayar','before'=>'Rp 90.000','after'=>'Rp 40.000']]];
$model=['reference'=>'TRX-TEST-1','student'=>'Siswa Uji','nis'=>'001234','unit'=>'SD','menu'=>'pembayaran','latest'=>$event,'events'=>[$event]];
$meta=['color'=>'168a46','school'=>'SEKOLAH UJI','unit'=>'SD','generated'=>'18/10/2026 12:00:00 WIB','operator'=>'Super Admin','menu'=>'Riwayat Pembayaran'];
$html=authorization_pdf_html([$model],$meta);pq_check(!str_contains($html,'secret_username')&&!str_contains($html,'Pencarian:')&&!str_contains($html,'Jenis:')&&!str_contains($html,'Filter mencocokkan'),'Removed output copy and usernames');pq_check(pq_pdf($html,'authorization-short')===1,'Small audit fits one landscape page');
$long=str_repeat("Alasan panjang dengan data lengkap.\n",180).'PENUTUP-ALASAN';$model['events'][0]['note']=$long;$model['events'][0]['changes'][0]['before']=$long.' AKHIR-SEBELUM';$model['events'][0]['changes'][0]['after']=$long.' AKHIR-SESUDAH';
pq_check(implode('',report_pdf_text_chunks($long))===$long,'Long audit chunks preserve all characters');pq_check(pq_pdf(authorization_pdf_html([$model],$meta),'authorization-long')>1,'Long audit continues across pages');
$rows=[];for($i=1;$i<=350;$i++)$rows[]=['NAMA'=>'Siswa '.sprintf('%04d',$i),'NO_INDUK'=>(string)(100000+$i),'KELAS'=>'1A','total'=>12345,'tanggal'=>'2026-10-18 12:00:00'];
$columns=[spp_excel_column('NO_INDUK','NIS'),spp_excel_column('NAMA','Nama Siswa'),spp_excel_column('KELAS','Kelas'),spp_excel_column('total','Nominal','money'),spp_excel_column('tanggal','Tanggal','datetime')];
$doc=['unit'=>1,'school'=>'SEKOLAH UJI','title'=>'Rekap Laporan Transaksi','subtitle'=>'Periode Oktober 2026','unit_label'=>'SD','generated'=>'18/10/2026 12:00:00 WIB','operator'=>'Super Admin','sheets'=>[['name'=>'Ringkasan','sections'=>[spp_excel_section('Ringkasan Keuangan',[spp_excel_column('label','Ringkasan'),spp_excel_column('value','Nominal','money')],[['label'=>'Total Pembayaran','value'=>350*12345]],[],false)]],['name'=>'Pembayaran','sections'=>[spp_excel_section('Transaksi Pembayaran',$columns,$rows,[['label'=>'Total Pembayaran','value'=>350*12345]])]]]];
$html=transaction_report_document_html($doc);pq_check(substr_count($html,'Siswa 0')===350,'Every typed model row retained');$pageCount=pq_pdf($html,'recap-350');pq_check($pageCount>=5&&$pageCount<=20,'Readable compact pagination for 350 rows');
$doc['sheets'][1]['sections'][0]['columns'][]=spp_excel_column('keterangan','Keterangan');
$doc['sheets'][1]['sections'][0]['rows']=[['NAMA'=>'Siswa Catatan Panjang','NO_INDUK'=>'0001234567','KELAS'=>'GK','total'=>12345,'tanggal'=>'2026-10-18 12:00:00','keterangan'=>str_repeat("Catatan buku lengkap.\n",150).'AKHIR-REKAP-PANJANG']];
pq_check(pq_pdf(transaction_report_document_html($doc),'recap-long')>1,'Long multiline report cells continue');
file_put_contents($root.'/pure-verification.json',json_encode(['checks'=>$checks,'recap_pages'=>$pageCount],JSON_PRETTY_PRINT));echo "OK $checks layout/PDF checks; recap $pageCount pages\n";
