<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/kelas.php';
requireRole(['admin']);

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

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="data-siswa.xls"');
echo "\xEF\xBB\xBF";
?>
<table border="1">
  <thead>
    <tr>
      <th>No</th><th>NIS</th><th>NIS Diknas</th><th>Nama</th><th>Kelas/Rombel</th><th>SPP Per Bulan</th><th>Status</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $index => $row): ?>
    <tr>
      <td><?= $index + 1 ?></td>
      <td><?= htmlspecialchars($row['NO_INDUK']) ?></td>
      <td><?= htmlspecialchars((string)($row['NO_induk_diknas'] ?? '')) ?></td>
      <td><?= htmlspecialchars($row['NAMA']) ?></td>
      <td><?= htmlspecialchars(class_label([
          'tingkat' => $row['master_tingkat'] ?: $row['KELAS'],
          'kode_rombel' => $row['kode_rombel'] ?? 'BELUM',
          'is_placeholder' => $row['is_placeholder'] ?? 1,
      ])) ?></td>
      <td><?= number_format((float)$row['SPP_PERBULAN'], 0, ',', '.') ?></td>
      <td><?= ((int)$row['is_active'] === 1) ? 'Aktif' : 'Diarsipkan' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
