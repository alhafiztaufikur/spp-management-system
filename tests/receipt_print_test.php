<?php
/** Pure receipt fixtures; never queries a school database or prints physically. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../vendor/autoload.php';require_once __DIR__.'/../includes/receipt_print.php';
$dir=getenv('SPP_BOOK_PDF_ARTIFACTS');if(!$dir||str_starts_with(strtolower(str_replace('\\','/',$dir)),strtolower(str_replace('\\','/',realpath(__DIR__.'/..')))))throw new RuntimeException('Private artifacts directory required.');
if(!is_dir($dir))mkdir($dir,0700,true);
$receipt=['payment'=>['NO_INDUK'=>'0000123456','NO_induk_diknas'=>'001234567890','NAMA'=>'Siswa Contoh Nusantara','KELAS'=>'1A','total_jumlah'=>445000,'sistem_pembayaran'=>'Tunai'],
    'school'=>"SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH",'primary'=>[['Uang PSB',0],['Uang Daftar Ulang',0],['Komite Oktober 2026',140000],['SPP Oktober 2026',305000]],
    'remaining'=>[['Sisa Komite Oktober 2026',0]],'other'=>[['SPP Oktober 2026',305000]],'period'=>'Oktober 2026','words'=>'Empat ratus empat puluh lima ribu rupiah','date'=>'16/10/2026 12:30:00 WIB','signer'=>'Petugas Uji'];
$cases=['normal'=>[$receipt],'annual'=>array_fill(0,12,$receipt)];
$long=$receipt;$long['payment']['NAMA']='Siswa Contoh dengan Nama Sangat Panjang Nusantara Indonesia Merdeka Bersama';$long['other']=[];
for($i=1;$i<=35;$i++)$long['other'][]=['Biaya tambahan nomor '.$i.' dengan keterangan panjang yang tetap harus terbaca',123456789012];
$cases['long']=[$long];
foreach($cases as $name=>$receipts){
    ob_start();receipt_print_document($receipts,'Struk Uji','Struk siap dicetak',true);$html=ob_get_clean();file_put_contents($dir.'/receipt-'.$name.'.html',$html);
    $options=new \Dompdf\Options();$options->setDefaultMediaType('print');$options->setIsRemoteEnabled(false);
    $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper([0,0,612,396]);$pdf->render();$pages=$pdf->getCanvas()->get_page_count();
    if($name==='normal'&&$pages!==1||$name==='annual'&&$pages!==12||$name==='long'&&$pages<2)throw new RuntimeException('Unexpected page count: '.$name.' '.$pages);
    file_put_contents($dir.'/receipt-'.$name.'.pdf',$pdf->output());echo 'PASS receipt '.$name.': '.$pages.' pages'.PHP_EOL;
}
