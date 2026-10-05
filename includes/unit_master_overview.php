<?php
$isSpp = basename((string)$_SERVER['SCRIPT_NAME']) === 'master_spp.php';
$title = $isSpp ? 'Master Penerbitan SPP' : 'Master Daftar Ulang';
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
if ($isSpp) {
    $rows = $koneksi->query('SELECT ta.unit_id,ta.label tahun_ajaran,mst.status,mt.tingkat,mt.nominal_dasar nominal
        FROM tahun_ajaran ta LEFT JOIN master_spp_tahun mst ON mst.tahun_ajaran_id=ta.id AND mst.unit_id=ta.unit_id
        LEFT JOIN master_spp_tarif mt ON mt.master_spp_tahun_id=mst.id AND mt.unit_id=ta.unit_id
        ORDER BY ta.unit_id,ta.label DESC,mt.tingkat')->fetch_all(MYSQLI_ASSOC);
} else {
    $rows = $koneksi->query('SELECT unit_id,th_ajaran tahun_ajaran,kelas tingkat,Jumlah nominal FROM daftar_ulang ORDER BY unit_id,th_ajaran DESC,CAST(kelas AS UNSIGNED)')->fetch_all(MYSQLI_ASSOC);
}
?>
<!doctype html><html lang="id" data-palette="super"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $escape($title) ?> | SistemSPP</title><link rel="stylesheet" href="assets/css/style.css?mtime=<?= filemtime(__DIR__.'/../assets/css/style.css') ?>"><link rel="stylesheet" href="assets/css/date_controls.css?mtime=<?= filemtime(__DIR__.'/../assets/css/date_controls.css') ?>"></head><body>
<div class="layout"><?php include __DIR__.'/sidebar.php'; ?><main class="main-content"><div class="topbar"><button class="sidebar-toggle" id="btn-sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka menu">☰</button><div class="topbar-title"><h2><?= $escape($title) ?></h2><span class="breadcrumb">SistemSPP / Data Master</span></div></div>
<div class="page-content"><section class="main-card"><h1><?= $escape($title) ?> · Semua Unit</h1><p>Tarif per unit dan tahun ajaran. Pilih satu unit untuk mengubah tarif atau menerbitkan tagihan.</p><div class="table-container"><table class="payment-table"><thead><tr><th>Unit</th><th>Tahun Ajaran</th><th>Tingkat</th><th>Tarif</th><?php if ($isSpp): ?><th>Status</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?= $escape(unit_label((int)$row['unit_id'])) ?></td><td><?= $escape($row['tahun_ajaran']) ?></td><td><?= $row['tingkat'] === null ? 'Belum diatur' : (int)$row['tingkat'] ?></td><td><?= $row['nominal'] === null ? 'Belum diatur' : 'Rp '.number_format((float)$row['nominal'],0,',','.') ?></td><?php if ($isSpp): ?><td><?= $escape($row['status'] ?? 'Belum diterbitkan') ?></td><?php endif; ?></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="<?= $isSpp?5:4 ?>">Belum ada tarif.</td></tr><?php endif; ?>
</tbody></table></div></section></div></main></div><script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script></body></html>
