<?php
/** Pure amount/parser and document fixtures; never connects to the school database. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../includes/savings_authorization.php';
require_once __DIR__.'/../includes/receipt_print.php';
$checks=0;function sr_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
foreach(['90000'=>90000,'90.000'=>90000,'1.000.000'=>1000000,'999.999.999.999'=>999999999999,'1'=>1,'00090'=>90] as $raw=>$expected)sr_check(savings_nominal_value((string)$raw)===$expected,'Amount normalization');
foreach(['0','000','0.000','90.00','9.0000','1.00.000','1,000','1e5','-90000','90 000',' 90000','90000 ',[],null,1.5,'1000000000000','1.000.000.000.000'] as $raw){
    $denied=false;try{savings_nominal_value($raw);}catch(RuntimeException $e){$denied=true;}sr_check($denied,'Invalid amount accepted');
}
$source=['nominal'=>'90.000','tanggal'=>'2026-10-01T08:30:00','keterangan'=>'Dikoreksi'];
sr_check(savings_edit_payload($source,'edit')['nominal']===90000,'Canonical payload');
sr_check(savings_edit_payload(['nominal'=>'not-an-amount'],'hapus')===[],'Delete does not require nominal');
// Exercise the actual form option template for every month, including numeric PHP array keys.
$form=file_get_contents(__DIR__.'/../pembayaran/form.php');
preg_match('/\$month_labels = \[.*?\n\s*\?>/s',$form,$match);sr_check(!empty($match),'Month rendering fixture');
foreach(range(1,12) as $month){
    $cur=str_pad((string)$month,2,'0',STR_PAD_LEFT);
    $template=str_replace("\$cur = date('m');",'',$match[0]);$template=preg_replace('/\?>\s*$/','',$template);
    ob_start();eval($template);$html=ob_get_clean();
    sr_check(substr_count($html,' selected')===1&&str_contains($html,'value="'.$cur.'" data-label="'),'One current month selected');
    preg_match('/<option value="([0-9]+)"[^>]* selected>/', $html,$selected);sr_check(($selected[1]??'')===$cur,'Wrong default month '.$cur);
}
$edit=file_get_contents(__DIR__.'/../pembayaran/edit.php');
preg_match('/function month_code\(\$value\) \{.*?\n\}/s',$edit,$function);sr_check(!empty($function),'Edit month parser fixture');eval($function[0]);
preg_match('/\$month_labels = \[.*?<\?php endforeach; \?>/s',$edit,$editMatch);sr_check(!empty($editMatch),'Edit month rendering fixture');
foreach(range(1,12) as $month){
    $d=['BULAN'=>str_pad((string)$month,2,'0',STR_PAD_LEFT)];ob_start();eval($editMatch[0]);$html=ob_get_clean();
    preg_match('/<option value="([0-9]+)"[^>]* selected>/', $html,$selected);sr_check(($selected[1]??'')===$d['BULAN'],'Edit period changed '.$d['BULAN']);
}
$dir=getenv('SPP_BOOK_PDF_ARTIFACTS');if(!$dir||str_starts_with(strtolower(str_replace(chr(92),'/',$dir)),strtolower(str_replace(chr(92),'/',realpath(__DIR__.'/..')))))throw new RuntimeException('Private artifact directory required');
if(!is_dir($dir))mkdir($dir,0700,true);
$receipt=['type'=>'savings','kind'=>'masuk','school'=>"SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH",'reference'=>'TB-M-1-123456',
    'payment'=>['NO_INDUK'=>'00012345','NO_induk_diknas'=>'00098765','NAMA'=>'Siswa Contoh Nusantara','KELAS'=>'1A','total_jumlah'=>90000],
    'primary'=>[['Setoran / Tabungan Masuk',90000]],'remaining'=>[['Saldo setelah transaksi',190000]],'other'=>[],
    'words'=>ucfirst(receipt_amount_words(90000)).' rupiah','date'=>'01/10/2026 08:30:00 WIB','signer'=>'Petugas Uji','note'=>'Setoran bulan Oktober'];
foreach(['masuk','keluar','long'] as $kind){
    $r=$receipt;if($kind==='keluar'){$r['kind']='keluar';$r['primary']=[['Penarikan / Tabungan Keluar',90000]];}
    if($kind==='long')$r['note']=str_repeat('Keterangan panjang dan tetap terbaca. ',30);
    ob_start();receipt_print_document([$r],'Bukti Uji','Bukti Transaksi Tabungan',true);$html=ob_get_clean();
    sr_check(str_contains($html,'BUKTI TRANSAKSI TABUNGAN')&&!str_contains($html,'Sistem Pembayaran:')&&!str_contains($html,'Pembayaran Lain-lain:'),'Receipt variant');
    $options=new \Dompdf\Options();$options->setDefaultMediaType('print');$pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper([0,0,612,396]);$pdf->render();
    if($kind!=='long')sr_check($pdf->getCanvas()->get_page_count()===1,'Normal receipt exceeds one page');
    file_put_contents($dir.'/refined-'.$kind.'.html',$html);file_put_contents($dir.'/refined-'.$kind.'.pdf',$pdf->output());
}
echo "PASS $checks amount, month rendering and receipt checks.\n";
