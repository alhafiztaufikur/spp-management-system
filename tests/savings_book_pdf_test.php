<?php
/** Pure renderer checks: no database or financial writes. */
require_once __DIR__.'/../includes/savings_book.php';
require_once __DIR__.'/../includes/savings_book_render.php';
require_once __DIR__.'/../includes/pdf.php';require_pdf_library();
$dir=getenv('SPP_QA_DIR');if(!$dir||!is_dir($dir))throw new RuntimeException('Set SPP_QA_DIR to an existing artifact directory.');
$student=['NO_INDUK'=>'0000123456','NO_induk_diknas'=>'0012345678','NAMA'=>'Nama siswa sangat panjang dengan karakter khusus < & > '.str_repeat('Nusantara ',8),'KELAS'=>'12 IPA 1'];
$logo='data:image/png;base64,'.base64_encode(file_get_contents(__DIR__.'/../assets/img/school-logo.png'));
foreach(['empty'=>0,'long'=>55] as $case=>$count){$transactions=[];for($i=0;$i<$count;$i++)$transactions[]=['id'=>$i+1,'tanggal'=>sprintf('2026-10-01 12:%02d:00',$i),'masuk'=>999999999,'keluar'=>0,'urutan_mutasi'=>0];
    $book=savings_book_make($transactions,$count*999999999);$html=savings_book_html($book,$student,'SEKOLAH MENENGAH ATAS (SMA) MUTIARA HIKMAH',$logo);
    if(str_contains($html,'< & >'))throw new RuntimeException('Identity not escaped.');
    $pdf=new \Dompdf\Dompdf();$pdf->loadHtml($html);$pdf->setPaper('A5','portrait');$pdf->render();
    if($pdf->getCanvas()->get_page_count()!==count($book['sides']))throw new RuntimeException('Extra/omitted PDF pages.');
    file_put_contents($dir.'/book-'.$case.'.pdf',$pdf->output());echo 'OK PDF '.$case.': '.count($book['sides']).' sides'.PHP_EOL;
}
