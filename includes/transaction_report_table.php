<?php
require_once __DIR__.'/payment_permissions.php';

/** Shared payment table for both scalar and multiple report selections. */
function transaction_report_table(mysqli $db,array $rows,int $offset=0,bool $selectable=true,string $id=''): string {
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $nativeIds=array_values(array_filter(array_map('intval',array_column($rows,'id')),static fn($id)=>$id>0));
    $events=$nativeIds?payment_activity_for_payments($db,$nativeIds):[];
    ob_start(); ?>
    <div class="table-container"><table class="payment-table report-transactions-table report-structured-table<?= $selectable?' has-report-selection':'' ?>"<?= $id!==''?' id="'.$e($id).'"':'' ?> style="--report-table-min:<?= $selectable?1280:1240 ?>px;--report-id-left:<?= $selectable?88:48 ?>px;--report-name-left:<?= $selectable?224:184 ?>px">
      <colgroup><?php if($selectable): ?><col style="width:40px"><?php endif; ?><col style="width:48px"><col style="width:136px"><col><col style="width:80px"><col style="width:120px"><col style="width:120px"><col style="width:152px"><col style="width:176px"><col style="width:110px"></colgroup>
      <thead><tr><?php if($selectable): ?><th scope="col" class="select-col"><input data-print-all type="checkbox" class="select-print-check" aria-label="Pilih semua transaksi dalam tabel ini"></th><?php endif; ?><th scope="col" class="report-number-col">No</th><th scope="col" class="report-id-col">No. Induk</th><th scope="col" class="report-name-col">Nama Siswa</th><th scope="col" class="kelas-col">Kelas</th><th scope="col" class="report-period-col">Bulan Tagihan</th><th scope="col" class="report-method-col">Sistem</th><th scope="col" class="nominal">Total Bayar</th><th scope="col" class="report-date-col">Tanggal Bayar</th><th scope="col" class="aksi-col">Aksi</th></tr></thead>
      <tbody><?php if(!$rows): ?><tr><td colspan="<?= $selectable?10:9 ?>">Tidak ada transaksi sesuai filter.</td></tr><?php endif; ?>
      <?php foreach($rows as $i=>$row):
        $legacy=(int)$row['id']<0;
        $caps=$legacy?['can_print'=>operational_role()&&((int)$row['unit_id']===unit_active_id()||unit_all_readonly())]:payment_capabilities($db,$row,$events[(int)$row['id']]??[],false,[]);
        $print=$legacy?'cetak_legacy.php?'.http_build_query(['id'=>-(int)$row['id'],'unit_id'=>(int)$row['unit_id']]):'cetak_struk.php?id='.(int)$row['id']; ?>
        <tr><?php if($selectable): ?><td class="select-col"><input type="checkbox" class="select-print-check row-print-check" name="ids[]" value="<?= (int)$row['id'] ?>" <?= !$caps['can_print']||$legacy?'disabled':'' ?> aria-label="Pilih transaksi <?= $e($row['NAMA']) ?>"></td><?php endif; ?>
          <td class="report-number-col"><?= $offset+$i+1 ?></td><td class="report-id-col"><span class="badge-nis"><?= $e($row['NO_INDUK']) ?></span><?php if(unit_all_readonly()): ?><small class="report-secondary-id"><?= $e(unit_label((int)$row['unit_id'])) ?></small><?php endif; ?><?php if(!empty($row['NO_induk_diknas'])): ?><small class="report-secondary-id">Diknas <?= $e($row['NO_induk_diknas']) ?></small><?php endif; ?></td>
          <td class="report-name-col"><?= $e($row['NAMA']) ?><small class="report-mobile-id">NIS <?= $e($row['NO_INDUK']) ?></small></td><td class="kelas-col"><span class="kelas-badge"><?= $e($row['KELAS']) ?></span></td><td class="report-period-col"><?= $e($row['BULAN'].' '.$row['TAHUN']) ?></td><td class="report-method-col"><?= $e($row['sistem_pembayaran']??'VA') ?></td><td class="nominal"><?= $e('Rp '.number_format((float)$row['total_jumlah'],(float)$row['total_jumlah']===floor((float)$row['total_jumlah'])?0:2,',','.')) ?></td><td class="report-date-col"><?php $parts=spp_date_parts($row['TGL_BYR'],$row['time_precision']??null); ?><span class="report-date-day"><?= $e($parts['date']) ?></span><small class="report-date-time"><?= $e($parts['time']) ?></small></td>
          <td class="aksi-col"><?php if($caps['can_print']): ?><a class="btn-tbl btn-tbl-print" href="<?= $e($print) ?>" target="_blank" rel="noopener"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z"/></svg>Cetak</a><?php else: ?><span class="report-secondary-id">Terkunci</span><?php endif; ?><?php if(!$legacy): ?><details class="finance-row-menu"><summary aria-label="Lihat detail transaksi <?= (int)$row['id'] ?>">&middot;&middot;&middot;</summary><button type="button" class="open-payment-activity" hidden data-id="<?= (int)$row['id'] ?>" data-unit="<?= (int)$row['unit_id'] ?>">Riwayat Aktivitas</button><noscript><a href="../pembayaran/lihat.php?search=<?= urlencode($row['NO_INDUK']) ?>">Lihat riwayat siswa</a></noscript></details><?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody></table></div>
    <?php return ob_get_clean();
}
