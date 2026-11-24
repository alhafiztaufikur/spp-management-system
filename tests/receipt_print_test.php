<?php
/** Pure receipt fixtures; never queries a school database or prints physically. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../vendor/autoload.php';require_once __DIR__.'/../includes/receipt_print.php';
$dir=getenv('SPP_BOOK_PDF_ARTIFACTS');if(!$dir||str_starts_with(strtolower(str_replace('\\','/',$dir)),strtolower(str_replace('\\','/',realpath(__DIR__.'/..')))))throw new RuntimeException('Private artifacts directory required.');
if(!is_dir($dir))mkdir($dir,0700,true);
$receipt=['payment'=>['NO_INDUK'=>'0000123456','NO_induk_diknas'=>'001234567890','NAMA'=>'Siswa Contoh Nusantara','KELAS'=>'1A','total_jumlah'=>445000,'sistem_pembayaran'=>'Tunai'],
    'school'=>"SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH",'primary'=>[['Uang PSB',0],['Uang DU',0],['Uang SPP',305000],['Komite Sekolah',140000],['Tabungan Wajib',0]],
    'remaining'=>[['Sisa PSB',0],['Sisa DU',0],['Sisa Komite',0]],'other'=>[['Buku Paket dengan keterangan pembelian yang perlu dibaca',125000]],'period'=>'Oktober 2026','words'=>'Empat ratus empat puluh lima ribu rupiah','date'=>'16/10/2026','signer'=>'Petugas Uji'];
$cases=['normal'=>[$receipt],'annual'=>array_fill(0,12,$receipt)];
$range=array_replace($receipt,['payment'=>array_replace($receipt['payment'],['total_jumlah'=>3600000,'U_SPP'=>3000000,'U_KOMITE'=>600000,'TGL_BYR'=>'2026-11-23 13:55:49']),
    'period'=>'Juli 2026 – Juni 2027','period_range'=>true,'words'=>receipt_money_words(3600000),'other'=>[],
    'remaining'=>[['Sisa PSB',0],['Sisa DU',400000]],'signer'=>'Super Admin']);
$range['primary']=receipt_school_primary($range['payment']);
$cases['academic-range']=[$range];
$multi=$range;
$multi['period']='November 2026 – Februari 2027';
$multi['payment']=array_replace($multi['payment'],['U_SPP'=>925000.25,'U_KOMITE'=>150000,'total_jumlah'=>1075000.25]);
$multi['primary']=receipt_school_primary($multi['payment']);
$multi['words']=receipt_money_words(1075000.25);
$cases['multi-range']=[$multi];
$duReceipt=$receipt;
$duReceipt['payment']+=['du_tahun_ajaran'=>'2027/2028','du_kelas'=>'6','uang_du'=>1000000,'U_SPP'=>305000,'U_KOMITE'=>140000];
$duReceipt['payment']['total_jumlah']=1445000;
$duReceipt['primary']=receipt_school_primary($duReceipt['payment']);
$duReceipt['words']=receipt_money_words(1445000);
$duReceipt['other']=[];
$cases['du-label']=[$duReceipt];
$long=$receipt;$long['payment']['NAMA']='Siswa Contoh dengan Nama Sangat Panjang Nusantara Indonesia Merdeka Bersama';$long['other']=[];
for($i=1;$i<=35;$i++)$long['other'][]=['Biaya tambahan nomor '.$i.' dengan keterangan panjang yang tetap harus terbaca',123456789012];
$cases['long']=[$long];
$noNis=$receipt;$noNis['payment']['NO_induk_diknas']='';$noNis['signer']='admin';$cases['no-nis']=[$noNis];
$longWords=$receipt;$longWords['words']='Sembilan ratus sembilan puluh sembilan miliar sembilan ratus sembilan puluh sembilan juta sembilan ratus sembilan puluh sembilan ribu sembilan ratus sembilan puluh sembilan rupiah';$cases['long-words']=[$longWords];
$unknownDu=$receipt;$unknownDu['remaining']=[['Sisa PSB',0],['Sisa DU',null]];
$cases['unknown-du']=[$unknownDu];$cases['annual-unknown-du']=array_fill(0,12,$unknownDu);
$coveredDu=$receipt;$coveredDu['remaining']=[['Sisa PSB',9000000],['DU tahun pertama',null,'Tercakup PSB']];$cases['covered-du']=[$coveredDu];
foreach($cases as $name=>$receipts){
    if(str_contains($name,'unknown-du')){ob_start();receipt_print_document($receipts,'Struk Uji','Struk siap dicetak',true);$unknownHtml=ob_get_clean();if(str_contains($unknownHtml,'Belum dapat ditentukan')||substr_count($unknownHtml,'class="amount">-</td>')!==count($receipts))throw new RuntimeException('Unknown DU must remain a dash on every receipt.');}
    ob_start();receipt_print_document($receipts,'Struk Uji','Struk siap dicetak',true);$html=ob_get_clean();if(str_contains($html,'Sisa Komite'))throw new RuntimeException('Komite remaining must not appear on the slip.');if($name==='no-nis'&&(!str_contains($html,'ADMIN')||str_contains($html,'NIS Diknas')))throw new RuntimeException('Signer capitalization or missing NIS rendering.');file_put_contents($dir.'/receipt-'.$name.'.html',$html);
    if ($name==='du-label' && !str_contains($html,'>Uang DU 2027/2028</td>')) throw new RuntimeException('DU receipt label must contain only Uang DU and the billed academic year.');
    if (substr_count($html,'class="signature-space receipt-payment-signature"') !== count($receipts)) {
        throw new RuntimeException('Every school receipt requires the payment signature space.');
    }
    if (!empty($receipts[0]['period_range'])) {
        $amounts=$name==='multi-range'?['925.000,25','150.000','1.075.000,25']:['3.000.000','600.000','3.600.000'];
        foreach (array_merge(['Data Pembayaran:', 'Sisa Pembayaran:', 'Periode Tagihan', 'JUMLAH TOTAL', $receipts[0]['period']],$amounts) as $label) {
            if (!str_contains($html,$label)) throw new RuntimeException('Missing range receipt value: '.$label);
        }
        if (str_contains($html,'TRX-') || str_contains($html,'Rincian Pembayaran per Bulan') || str_contains($html,'Bulan Tagihan')) {
            throw new RuntimeException('The range slip should show the component totals and period only.');
        }
    }
    if (in_array($name,['normal','academic-range','multi-range','du-label'],true)) {
        ob_start();
        receipt_print_document($receipts,'Struk Uji','Struk siap dicetak',false,['auto_print'=>false,'pdf_url'=>'?output=pdf']);
        file_put_contents($dir.'/receipt-'.$name.'-preview.html',ob_get_clean());
    }
    $options=receipt_pdf_options();
    $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper([0,0,612,396]);$pdf->render();$pages=$pdf->getCanvas()->get_page_count();
    if(in_array($name,['normal','academic-range','multi-range','du-label'],true)&&$pages!==1||$name==='annual'&&$pages!==12||$name==='long'&&$pages<2)throw new RuntimeException('Unexpected page count: '.$name.' '.$pages);
    file_put_contents($dir.'/receipt-'.$name.'.pdf',$pdf->output());echo 'PASS receipt '.$name.': '.$pages.' pages'.PHP_EOL;
}
