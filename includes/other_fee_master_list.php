<?php
$e = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$csrf = $e($_SESSION['csrf_master_biaya_lain']);
?>
<div id="fee-master-body" <?= $detailsReady ? 'data-usage-ready data-total="'.$masterTotal.'"' : '' ?>>
  <?php if ($masterRows || $masterTab !== 'hapus' || $detailsReady): ?>
  <div class="table-container">
    <table class="payment-table responsive-table">
      <thead><tr><th>No</th><th>Nama Biaya</th><th>Nominal</th><th>Jenis</th><th>Status</th><th>Tagihan</th><th>Transaksi</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php if (!$masterRows): ?>
          <tr><td colspan="8"><div class="empty-state"><p>Tidak ada master pada bagian ini</p>
            <?php if ($masterTab === 'aktif' && $masterCounts['nonaktif']): ?><span>Lihat tab Nonaktif untuk master yang belum bisa dipakai.</span><?php endif; ?>
          </div></td></tr>
        <?php endif; ?>
        <?php foreach ($masterRows as $index => $item):
            $id = (int)$item['id'];
            $active = (bool)$item['is_active'];
            $reserved = $item['reserved'] ?? lf_reserved_master($koneksi, $id);
            $deletable = $detailsReady && $item['can_delete'];
            $actionQuery = ['master_tab'=>$masterTab,'page'=>$masterPage,'edit'=>$id];
            $historyQuery = ['fee_master_id'=>$id,'all_periods'=>'1'];
            $nominal = $item['jenis_pembayaran']==='input_manual' ? 'Diisi saat bayar' :
                ($reserved ? 'Per pengesahan' : (!empty($item['legacy_tariff_pending']) ? 'Tarif belum ditetapkan' :
                'Rp '.number_format((float)$item['nominal'], 0, ',', '.')));
        ?>
          <tr data-master-id="<?= $id ?>">
            <td data-label="No"><?= $offset + $index + 1 ?></td>
            <td data-label="Nama Biaya"><strong><?= unit_record_badge($item) ?><?= $e($item['nama']) ?></strong></td>
            <td data-label="Nominal" class="nominal"><?= $e($nominal) ?></td>
            <td data-label="Jenis"><?= $e($feeModes[$item['jenis_pembayaran']] ?? 'Input Manual') ?></td>
            <td data-label="Status"><span class="master-status <?= $active ? 'is-active' : 'is-inactive' ?>"><?= $active ? 'Aktif' : 'Nonaktif' ?></span></td>
            <td data-label="Tagihan" data-usage-cell="bills">
              <?= $detailsReady ? '<span class="badge-count">'.(int)$item['jumlah_tagihan'].'</span>' : '<span aria-hidden="true">…</span>' ?>
            </td>
            <td data-label="Transaksi" data-usage-cell="transactions"><div class="fee-master-transactions">
              <?php if (!$detailsReady): ?><span aria-hidden="true">…</span>
              <?php else: ?>
                <?php if ($item['jumlah_pemakaian']): ?>
                  <span class="fee-transaction-caption"><?= number_format($item['jumlah_rincian'], 0, ',', '.') ?> rincian</span>
                  <a class="fee-transaction-link" href="pembayaran/lihat.php?<?= $e(http_build_query($historyQuery)) ?>" aria-label="Riwayat pembayaran <?= $e($item['nama']) ?>"><?= master_workspace_icon('document') ?><span>Pembayaran</span><strong><?= number_format($item['jumlah_pemakaian'], 0, ',', '.') ?></strong></a>
                  <div class="fee-transaction-amount"><span>Total</span><strong>Rp <?= number_format((float)$item['nominal_pemakaian'], 2, ',', '.') ?></strong></div>
                <?php else: ?><span class="fee-transaction-empty">Belum ada transaksi</span><?php endif; ?>
                <?php if ($item['jumlah_batal']): ?>
                  <a class="fee-transaction-link is-cancelled" href="pembayaran/lihat.php?<?= $e(http_build_query($historyQuery + ['view'=>'deleted'])) ?>" aria-label="Riwayat transaksi dibatalkan <?= $e($item['nama']) ?>"><?= master_workspace_icon('document') ?><span>Dibatalkan</span><strong><?= number_format($item['jumlah_batal'], 0, ',', '.') ?></strong></a>
                <?php endif; ?>
              <?php endif; ?>
            </div></td>
            <td data-label="Aksi" class="aksi-col">
              <?php if ($reserved): ?><span class="master-status">Pengesahan Legacy</span>
              <?php elseif (!unit_all_readonly()): ?>
                <a class="btn-tbl btn-tbl-edit" href="master_biaya_lain.php?<?= $e(http_build_query($actionQuery)) ?>">Edit</a>
                <form method="post" action="master_biaya_lain.php" class="fee-row-action">
                  <input type="hidden" name="_operational_unit" value="<?= unit_active_id() ?>">
                  <input type="hidden" name="master_tab" value="<?= $masterTab ?>">
                  <input type="hidden" name="page" value="<?= $masterPage ?>">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="aksi" value="toggle">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="target_active" value="<?= $active ? '0' : '1' ?>">
                  <button class="btn-tbl btn-tbl-toggle" type="submit"><?= $active ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <span data-usage-cell="delete">
                  <?php if ($detailsReady): ?>
                    <form method="post" action="master_biaya_lain.php" class="fee-row-action" data-dialog-confirm="Hapus master biaya <?= $e($item['nama']) ?>?" data-dialog-tone="danger">
                      <input type="hidden" name="_operational_unit" value="<?= unit_active_id() ?>">
                      <input type="hidden" name="master_tab" value="<?= $masterTab ?>">
                      <input type="hidden" name="page" value="<?= $masterPage ?>">
                      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                      <input type="hidden" name="aksi" value="hapus">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button class="btn-tbl btn-tbl-del" type="submit" <?= $deletable ? '' : 'disabled title="Sudah memiliki tagihan atau riwayat transaksi"' ?>>Hapus</button>
                    </form>
                    <?php if (!$deletable): ?><small class="fee-master-lock">Sudah memiliki tagihan atau riwayat transaksi.</small><?php endif; ?>
                  <?php endif; ?>
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php render_pagination('master_biaya_lain.php', ['master_tab'=>$masterTab], $masterPage, $masterPages, $masterTotal, $perPage, 'master biaya', fragment: '#daftar-master-biaya'); ?>
  <?php endif; ?>
</div>
