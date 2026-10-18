<?php
/** Pure renderer checks: no database or financial writes. */
require_once __DIR__.'/../includes/savings_book.php';
require_once __DIR__.'/../includes/savings_book_render.php';
require_once __DIR__.'/../includes/pdf.php';require_pdf_library();
$dir=getenv('SPP_QA_DIR');if(!$dir||!is_dir($dir))throw new RuntimeException('Set SPP_QA_DIR to an existing artifact directory.');
$student=['NO_INDUK'=>'0000123456','NO_induk_diknas'=>'0012345678','NAMA'=>'Nama siswa sangat panjang dengan karakter khusus < & > '.str_repeat('Nusantara ',8),'KELAS'=>'12 IPA 1'];
$logo='data:image/png;base64,'.base64_encode(file_get_contents(__DIR__.'/../assets/img/school-logo.png'));
foreach(['empty'=>0,'long'=>55] as $case=>$count){$transactions=[];for($i=0;$i<$count;$i++)$transactions[]=['id'=>$i+1,'tanggal'=>sprintf('2026-10-01 12:%02d:00',$i),'masuk'=>999999999,'keluar'=>0,'urutan_mutasi'=>0];
    $book=savings_book_make($transactions,$count*999999999);
    $pdf=savings_book_pdf($book,savings_book_print_settings([],$count));
    if(($count===0&&$pdf->getCanvas()->get_page_count()!==1)||($count>0&&$pdf->getCanvas()->get_page_count()<2))throw new RuntimeException('Extra/omitted PDF pages.');
    file_put_contents($dir.'/book-'.$case.'.pdf',$pdf->output());echo 'OK PDF '.$case.': '.$pdf->getCanvas()->get_page_count().' pages'.PHP_EOL;
}
