<?php
function receipt_amount_words(int $number): string {
    $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($number < 12) return $words[$number];
    if ($number < 20) return receipt_amount_words($number - 10) . ' belas';
    if ($number < 100) return receipt_amount_words(intdiv($number, 10)) . ' puluh' . ($number % 10 ? ' ' . receipt_amount_words($number % 10) : '');
    if ($number < 200) return 'seratus' . ($number - 100 ? ' ' . receipt_amount_words($number - 100) : '');
    if ($number < 1000) return receipt_amount_words(intdiv($number, 100)) . ' ratus' . ($number % 100 ? ' ' . receipt_amount_words($number % 100) : '');
    if ($number < 2000) return 'seribu' . ($number - 1000 ? ' ' . receipt_amount_words($number - 1000) : '');
    if ($number < 1000000) return receipt_amount_words(intdiv($number, 1000)) . ' ribu' . ($number % 1000 ? ' ' . receipt_amount_words($number % 1000) : '');
    if ($number < 1000000000) return receipt_amount_words(intdiv($number, 1000000)) . ' juta' . ($number % 1000000 ? ' ' . receipt_amount_words($number % 1000000) : '');
    return receipt_amount_words(intdiv($number, 1000000000)) . ' miliar' . ($number % 1000000000 ? ' ' . receipt_amount_words($number % 1000000000) : '');
}

function receipt_money_words(float $amount): string {
    $cents=(int)round($amount*100);$whole=intdiv($cents,100);$fraction=$cents%100;$digits=['nol','satu','dua','tiga','empat','lima','enam','tujuh','delapan','sembilan'];
    return ucfirst(($whole?trim(receipt_amount_words($whole)):'nol').($fraction?' koma '.$digits[intdiv($fraction,10)].' '.$digits[$fraction%10]:'').' rupiah');
}

/** Presentation only: callers supply the original receipt values and wording. */
function receipt_print_document(array $receipts, string $title, string $toolbar, bool $pdf = false, array $options = []): void {
    $e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $money=static fn($value,bool $zero=false)=>!$zero&&abs((float)$value)<.005?'':number_format((float)$value,abs((float)$value-round((float)$value))>.00001?2:0,',','.');
    ?>
    <!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $e($title) ?></title><?php if($pdf): ?><style><?= file_get_contents(__DIR__.'/../assets/css/receipt_print.css') ?></style><?php else: ?><link rel="stylesheet" href="../assets/css/receipt_print.css?v=<?= filemtime(__DIR__.'/../assets/css/receipt_print.css') ?>"><?php endif; ?><?php if(!$pdf): ?><style>@font-face{font-family:"DejaVu Serif";src:url("../vendor/dompdf/dompdf/lib/fonts/DejaVuSerif.ttf") format("truetype");font-weight:400;font-style:normal}@font-face{font-family:"DejaVu Serif";src:url("../vendor/dompdf/dompdf/lib/fonts/DejaVuSerif-Bold.ttf") format("truetype");font-weight:700;font-style:normal}@font-face{font-family:"DejaVu Serif";src:url("../vendor/dompdf/dompdf/lib/fonts/DejaVuSerif-Italic.ttf") format("truetype");font-weight:400;font-style:italic}</style><?php endif; ?></head>
    <body><header class="print-toolbar"><span><?= $e($toolbar) ?></span><div><button type="button" class="print-secondary" onclick="window.close()">Tutup</button><?php if(!empty($options['pdf_url'])): ?><a class="print-secondary" href="<?= $e($options['pdf_url']) ?>">PDF</a><?php endif; ?><button type="button" data-receipt-png>Unduh PNG</button><button type="button" onclick="window.print()">Cetak Lagi</button></div><span role="status" data-receipt-export-status></span></header>
    <?php foreach($receipts as $receiptIndex=>$receipt):
        $p=$receipt['payment'];$savings=($receipt['type']??'payment')==='savings';$right=[];
        foreach($receipt['remaining'] as $line)if($savings||$line[0]!=='Sisa Komite')$right[]=['line'=>$line,'remaining'=>true];
        if(!$right)$right[]=['line'=>['—',null]];
        if(!$savings){$right[]=['heading'=>'Pembayaran Lain-lain:'];
        foreach($receipt['other'] as $line)$right[]=['line'=>$line];
        if(!$receipt['other'])$right[]=['line'=>['—',null]];}
        $count=max(count($receipt['primary']),count($right));
    ?>
    <article class="receipt-sheet<?= $receiptIndex===count($receipts)-1?' receipt-last':'' ?><?= $savings?' receipt-savings-sheet':'' ?>"><table class="receipt-document"><colgroup><col style="width:52%"><col style="width:48%"></colgroup>
      <thead><tr><td colspan="2" class="receipt-header">
        <h1><?= implode('<br>',array_map($e,preg_split('/\s+(?=\((?:SDA|SMP|SMA)\))/',$receipt['school'],2))) ?></h1>
        <p class="address">Perum Bekasi Griya Asri II, Blok E Jl.H.Nabrih Ds. Sumber Jaya Kp.Buwek Tambun Selatan Telp. 021.88363466</p>
        <div class="document-title"><?= $savings?'BUKTI TRANSAKSI TABUNGAN':'SLIP PEMBAYARAN SEKOLAH' ?><?= $savings?'':$e($receipt['reference']??'') ?></div>
        <table class="identity"><colgroup><col style="width:27mm"><col style="width:3mm"><col style="width:68mm"><col style="width:48mm"><col style="width:3mm"><col></colgroup>
          <tr><td style="width:27mm"><?= ($p['owner_type']??'student')==='account'?'Identitas':'No. Induk' ?></td><td class="receipt-colon" style="width:3mm">:</td><td class="identity-value" style="width:68mm"><?= $e($p['NO_INDUK']) ?></td><td style="width:48mm">Kelas</td><td class="receipt-colon" style="width:3mm">:</td><td><?= $e(($p['kelas_rombel_snapshot']??'') ?: (($p['KELAS']??'') ?: ($p['KELAS_SISWA']??''))) ?></td></tr>
          <tr><td><?= !empty($p['NO_induk_diknas'])?'NIS Diknas':(($p['owner_type']??'student')==='account'?'Nama Pemilik':'Nama Siswa') ?></td><td class="receipt-colon">:</td><td class="identity-value"><?= $e(!empty($p['NO_induk_diknas'])?$p['NO_induk_diknas']:$p['NAMA']) ?></td><td><?= $savings?'Referensi':'Untuk Pembayaran Bulan' ?></td><td class="receipt-colon">:</td><td><?= $e($savings?$receipt['reference']:$receipt['period']) ?></td></tr>
          <?php if(!empty($p['NO_induk_diknas'])): ?><tr><td>Nama Siswa</td><td class="receipt-colon">:</td><td class="identity-value"><?= $e($p['NAMA']) ?></td><td></td><td></td><td></td></tr><?php endif; ?>
        </table>
      </td></tr><tr class="receipt-column-labels"><th><?= $savings?'Data Tabungan:':'Data Pembayaran:' ?></th><th><?= $savings?'Ringkasan Saldo:':'Sisa Pembayaran:' ?></th></tr></thead>
      <tbody>
      <?php for($i=0;$i<$count;$i++): ?><tr class="receipt-line">
        <td><?php if(isset($receipt['primary'][$i])): [$label,$amount]=$receipt['primary'][$i]; ?><table class="payments"><tr><td class="number"><?= $i+1 ?>.</td><td><?= $e($label) ?></td><td class="receipt-colon">:</td><td class="amount"><?= $e($money($amount)) ?></td></tr></table><?php endif; ?></td>
        <td><?php if(isset($right[$i]['heading'])): ?><strong class="section-label"><?= $e($right[$i]['heading']) ?></strong><?php elseif(isset($right[$i]['line'])): [$label,$amount]=$right[$i]['line']; ?><table class="payments"><tr><td><?= !empty($right[$i]['remaining'])?'<strong>'.$e($label).'</strong>':$e($label) ?></td><?php if(!empty($right[$i]['remaining'])&&!$savings): ?><td class="receipt-colon">:</td><?php endif; ?><td class="amount"><?= $amount===null?(!empty($right[$i]['remaining'])?$e($right[$i]['line'][2]??'Belum dapat ditentukan'):''):$e($money($amount,!empty($right[$i]['remaining']))) ?></td></tr></table><?php endif; ?></td>
      </tr><?php endfor; ?>
      <?php if($savings): ?><tr class="receipt-line"><td colspan="2" style="padding-top:2mm"><strong>Keterangan:</strong> <?= nl2br($e($receipt['note']?:'—')) ?></td></tr><?php endif; ?>
      <tr class="receipt-closing"><td colspan="2">
        <table class="total"><tr><td><?= $savings?'JUMLAH '.($receipt['kind']==='masuk'?'SETORAN':'PENARIKAN'):'JUMLAH TOTAL' ?></td><td class="amount"><?= $e($money($p['total_jumlah'],true)) ?></td></tr></table>
        <table class="receipt-footer"><tr><td class="footer-left"><p class="words"><strong>Terbilang:</strong> <?= $e($receipt['words']) ?></p><?php if($savings): ?><p>Saldo mengikuti urutan jurnal sampai transaksi ini.</p><?php else: ?><p><strong>Sistem Pembayaran:</strong> <?= $e(($p['sistem_pembayaran']??'')?:'Tidak tercatat') ?></p><p class="receipt-pay-time">Jam Bayar: <?= $e(!empty($p['TGL_BYR'])?date('H:i:s',strtotime($p['TGL_BYR'])).' WIB':'Tidak tercatat') ?></p><?php endif; ?></td><td class="footer-right"><div class="signature-date">Bekasi, <?= $e(!empty($p['TGL_BYR'])?date('d-m-Y',strtotime($p['TGL_BYR'])):$receipt['date']) ?></div><div>Bagian Keuangan</div><div class="signature-space"></div><strong><?= $e(mb_strtoupper((string)$receipt['signer'],'UTF-8')) ?></strong></td></tr></table>
      </td></tr></tbody>
    </table></article>
    <?php endforeach; ?>
    <script><?= file_get_contents(__DIR__.'/../assets/js/receipt_print.js') ?></script>
    <?php if(!$pdf): ?><script src="../assets/vendor/html2canvas/html2canvas.min.js"></script><script src="../assets/vendor/jszip/jszip.min.js"></script><script src="../assets/js/receipt_image.js"></script><?php if($options['auto_print']??true): ?><script>window.addEventListener('load',function(){Promise.resolve(document.fonts&&document.fonts.ready).then(function(){setTimeout(function(){window.print()},40)})});</script><?php endif; endif; ?>
    </body></html>
    <?php
}

/** Same source document for browser preview, native PDF and image download. */
function receipt_http_output(array $receipts,string $title,string $toolbar): void {
    $output=$_GET['output']??'preview';if(!is_string($output)||!in_array($output,['preview','pdf'],true)){http_response_code(400);exit('Format keluaran tidak valid.');}
    $params=$_GET;$params['output']='pdf';$url='?'.http_build_query($params);
    if($output==='preview'){receipt_print_document($receipts,$title,$toolbar,false,['pdf_url'=>$url]);return;}
    require_once __DIR__.'/pdf.php';require_pdf_library();ob_start();receipt_print_document($receipts,$title,$toolbar,true);$html=ob_get_clean();
    // DejaVu's default 1.1 metric multiplier enlarges every nested table row.
    // Use native font metrics so normal five-component slips fit the same paper as preview.
    $options=new \Dompdf\Options();$options->setIsRemoteEnabled(false);$options->setDefaultMediaType('print');$options->setFontHeightRatio(1.0);$pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper([0,0,612,396]);$pdf->render();$pdf->stream('slip-pembayaran.pdf',['Attachment'=>false]);
}
