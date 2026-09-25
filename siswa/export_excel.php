<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/kelas.php';
requireRole(['admin', 'kasir']);

$query = trim((string)($_GET['q'] ?? ''));
$filterClass = (int)($_GET['kelas'] ?? 0);
$filterStatus = (string)($_GET['status'] ?? 'active');
if (!in_array($filterStatus, ['active', 'archived', 'all'], true)) $filterStatus = 'active';

$sql = "
    SELECT s.*, mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder
    FROM siswa s
    LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
    WHERE (? = '' OR s.NO_INDUK LIKE CONCAT('%', ?, '%') OR s.NAMA LIKE CONCAT('%', ?, '%') OR s.NO_induk_diknas LIKE CONCAT('%', ?, '%'))
      AND (? = 0 OR s.master_kelas_id = ?)
      AND (? = 'all' OR s.is_active = IF(? = 'archived', 0, 1))
    ORDER BY s.is_active DESC, COALESCE(mk.tingkat, s.KELAS), mk.kode_rombel, s.NAMA
";
$stmt = $koneksi->prepare($sql);
$types = 'ssssiiss';
$stmt->bind_param($types, $query, $query, $query, $query, $filterClass, $filterClass, $filterStatus, $filterStatus);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$statusLabels = ['active'=>'Aktif', 'archived'=>'Arsip/Lulus', 'all'=>'Semua'];
$filterParts = [];
if ($query !== '') $filterParts[] = 'Pencarian: ' . $query;
if ($filterClass > 0) {
    foreach ($rows as $row) {
        if ((int)($row['master_kelas_id'] ?? 0) !== $filterClass) continue;
        $filterParts[] = 'Kelas: ' . class_label([
            'tingkat'=>$row['master_tingkat'] ?: $row['KELAS'],
            'kode_rombel'=>$row['kode_rombel'] ?? 'BELUM',
            'is_placeholder'=>$row['is_placeholder'] ?? 1,
        ]);
        break;
    }
}
$filterParts[] = 'Status: ' . ($statusLabels[$filterStatus] ?? 'Aktif');
$filterLabel = implode(' | ', $filterParts);
$generated = date('d-m-Y H:i:s');

ob_start();
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Data Siswa</title><style>
@page{margin:12mm;size:A4 landscape}*{box-sizing:border-box}body{margin:0;padding:26px;font-family:Arial,sans-serif;color:#17231d;font-size:10px;background:#fff}.heading{padding-bottom:12px;border-bottom:3px double #15543c;text-align:center}.heading h1{margin:0;color:#123d2e;font-size:18px}.heading p{margin:5px 0 0;color:#52645b}.meta{display:flex;justify-content:space-between;gap:20px;margin:12px 0;color:#52645b}.summary{display:flex;gap:10px;margin-bottom:12px}.summary div{min-width:170px;padding:9px 12px;border:1px solid #c9e0d4;background:#f1f8f4}.summary span{display:block;color:#607269;font-size:8px;font-weight:bold;text-transform:uppercase}.summary strong{display:block;margin-top:4px;color:#0c7042;font-size:13px}table{width:100%;border-collapse:collapse}th,td{padding:6px 7px;border:1px solid #b9d4c7;text-align:left;vertical-align:top}th{background:#12503a;color:#fff;font-size:8px;text-transform:uppercase}tbody tr:nth-child(even){background:#f5faf7}.money{text-align:right;white-space:nowrap}.center{text-align:center}.footer{margin-top:14px;padding-top:7px;border-top:1px solid #c9d9d0;color:#687970;font-size:8px}
</style></head><body>
<header class="heading"><h1>DATA SISWA</h1><p>Sekolah Dasar Al-Qur'an (SDA) Mutiara Hikmah</p></header>
<div class="meta"><span><?= $escape($filterLabel) ?></span><span>Dibuat: <?= $escape($generated) ?></span></div>
<div class="summary"><div><span>Jumlah Siswa</span><strong><?= number_format(count($rows)) ?></strong></div></div>
<table><thead><tr><th>No</th><th>NIS</th><th>NIS Diknas</th><th>Nama</th><th>Kelas/Rombel</th><th>SPP Per Bulan</th><th>Status</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="center">Tidak ada siswa sesuai filter.</td></tr><?php else: foreach ($rows as $index => $row): ?>
<tr><td class="center"><?= $index + 1 ?></td><td><?= $escape($row['NO_INDUK']) ?></td><td><?= $escape((string)($row['NO_induk_diknas'] ?? '')) ?></td><td><?= $escape($row['NAMA']) ?></td><td><?= $escape(class_label(['tingkat'=>$row['master_tingkat'] ?: $row['KELAS'],'kode_rombel'=>$row['kode_rombel'] ?? 'BELUM','is_placeholder'=>$row['is_placeholder'] ?? 1])) ?></td><td class="money">Rp <?= number_format((float)$row['SPP_PERBULAN'], 0, ',', '.') ?></td><td><?= ((int)$row['is_active'] === 1) ? 'Aktif' : 'Arsip/Lulus' ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table><div class="footer">SistemSPP | Data mengikuti filter aktif pada saat laporan dibuat.</div></body></html>
<?php
$documentHtml = ob_get_clean();

if (($_GET['download'] ?? '') === '1') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="data-siswa-' . date('Ymd-His') . '.xls"');
    echo "\xEF\xBB\xBF" . $documentHtml;
    exit;
}

require_once '../includes/report_preview.php';
$downloadQuery = $_GET;
$downloadQuery['download'] = '1';
$backQuery = array_intersect_key($_GET, array_flip(['q','kelas','status','per_page','page']));
render_report_export_preview($documentHtml, [
    'title'=>'Data Siswa',
    'subtitle'=>$filterLabel,
    'generated'=>$generated,
    'row_count'=>count($rows),
    'orientation'=>'landscape',
    'file_type'=>'EXCEL',
    'show_print'=>false,
    'download_url'=>'export_excel.php?' . http_build_query($downloadQuery),
    'back_url'=>'daftar.php?' . http_build_query($backQuery),
]);
