<?php
/** Presentation only: callers supply the original receipt values and wording. */
function receipt_print_document(array $receipts, string $title, string $toolbar, bool $pdf = false): void {
    $e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $money=static fn($value,bool $zero=false)=>!$zero&&abs((float)$value)<.005?'':number_format((float)$value,0,',','.');
    ?>
    <!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $e($title) ?></title><?php if($pdf): ?><style><?= file_get_contents(__DIR__.'/../assets/css/receipt_print.css') ?></style><?php else: ?><link rel="stylesheet" href="../assets/css/receipt_print.css?v=<?= filemtime(__DIR__.'/../assets/css/receipt_print.css') ?>"><?php endif; ?></head>
    <body><header class="print-toolbar"><span><?= $e($toolbar) ?></span><div><button type="button" class="print-secondary" onclick="window.close()">Tutup</button><button type="button" onclick="window.print()">Cetak Lagi</button></div></header>
    <?php foreach($receipts as $receiptIndex=>$receipt):
        $p=$receipt['payment'];$right=[];
        foreach($receipt['remaining'] as $line)$right[]=['line'=>$line,'remaining'=>true];
        if(!$right)$right[]=['line'=>['—',null]];
        $right[]=['heading'=>'Pembayaran Lain-lain:'];
        foreach($receipt['other'] as $line)$right[]=['line'=>$line];
        if(!$receipt['other'])$right[]=['line'=>['—',null]];
        $count=max(count($receipt['primary']),count($right));
    ?>
    <article class="receipt-sheet<?= $receiptIndex===count($receipts)-1?' receipt-last':'' ?>"><table class="receipt-document"><colgroup><col style="width:52%"><col style="width:48%"></colgroup>
      <thead><tr><td colspan="2" class="receipt-header">
        <h1><?= $e($receipt['school']) ?></h1>
        <p class="address">Perum Bekasi Griya Asri II, Blok E Jl.H.Nabrih Ds. Sumber Jaya Kp.Buwek Tambun Selatan Telp. 021.88363466</p>
        <div class="document-title">SLIP PEMBAYARAN SEKOLAH<?= $e($receipt['reference']??'') ?></div>
        <table class="identity"><tr><td><dl><div><dt>No. Induk</dt><dd><?= $e($p['NO_INDUK']) ?></dd></div><?php if(!empty($p['NO_induk_diknas'])): ?><div><dt>NIS Diknas</dt><dd><?= $e($p['NO_induk_diknas']) ?></dd></div><?php endif; ?><div><dt>Nama Siswa</dt><dd><?= $e($p['NAMA']) ?></dd></div></dl></td><td><dl><div><dt>Kelas</dt><dd><?= $e(($p['kelas_rombel_snapshot']??'') ?: (($p['KELAS']??'') ?: ($p['KELAS_SISWA']??''))) ?></dd></div><div><dt>Periode</dt><dd><?= $e($receipt['period']) ?></dd></div></dl></td></tr></table>
      </td></tr><tr class="receipt-column-labels"><th>Data Pembayaran:</th><th>Sisa Pembayaran:</th></tr></thead>
      <tbody>
      <?php for($i=0;$i<$count;$i++): ?><tr class="receipt-line">
        <td><?php if(isset($receipt['primary'][$i])): [$label,$amount]=$receipt['primary'][$i]; ?><table class="payments"><tr><td class="number"><?= $i+1 ?>.</td><td><?= $e($label) ?></td><td class="amount"><?= $e($money($amount)) ?></td></tr></table><?php endif; ?></td>
        <td><?php if(isset($right[$i]['heading'])): ?><strong class="section-label"><?= $e($right[$i]['heading']) ?></strong><?php elseif(isset($right[$i]['line'])): [$label,$amount]=$right[$i]['line']; ?><table class="payments"><tr><td><?= !empty($right[$i]['remaining'])?'<strong>'.$e($label).'</strong>':$e($label) ?></td><td class="amount"><?= $amount===null?'':$e($money($amount,!empty($right[$i]['remaining']))) ?></td></tr></table><?php endif; ?></td>
      </tr><?php endfor; ?>
      <tr class="receipt-closing"><td colspan="2">
        <table class="total"><tr><td>JUMLAH TOTAL</td><td class="amount"><?= $e($money($p['total_jumlah'],true)) ?></td></tr></table>
        <table class="receipt-footer"><tr><td class="footer-left"><p class="words"><strong>Terbilang:</strong> <?= $e($receipt['words']) ?></p><p><strong>Sistem Pembayaran:</strong> <?= $e($p['sistem_pembayaran']??'VA') ?></p></td><td class="footer-right"><div>Bekasi, <?= $e($receipt['date']) ?></div><div>Bagian Keuangan</div><div class="signature-space"></div><strong><?= $e($receipt['signer']) ?></strong></td></tr></table>
      </td></tr></tbody>
    </table></article>
    <?php endforeach; ?>
    <script><?= file_get_contents(__DIR__.'/../assets/js/receipt_print.js') ?></script>
    <?php if(!$pdf): ?><script>window.addEventListener('load',function(){Promise.resolve(document.fonts&&document.fonts.ready).then(function(){setTimeout(function(){window.print()},40)})});</script><?php endif; ?>
    </body></html>
    <?php
}
