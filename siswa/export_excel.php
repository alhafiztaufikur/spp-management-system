<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/kelas.php';
requireRole(['admin', 'kasir']);

$query = trim((string)($_GET['q'] ?? ''));
require_once '../includes/student_filters.php';
[$filterClass,$filterStatus,$classOptions,$studentFilterSql]=student_list_filters($koneksi,$_GET);
$sql = "
    SELECT s.*, mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder
    FROM siswa s
    LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
    WHERE (? = '' OR s.NO_INDUK LIKE CONCAT('%', ?, '%') OR s.NAMA LIKE CONCAT('%', ?, '%') OR s.NO_induk_diknas LIKE CONCAT('%', ?, '%'))
      AND (? = 0 OR s.master_kelas_id = ?)
      AND (? = 'all' OR CASE ? WHEN 'legacy' THEN s.legacy_pending=1 WHEN 'archived' THEN s.legacy_pending=0 AND s.is_active=0 ELSE s.legacy_pending=0 AND s.is_active=1 END) $studentFilterSql
    ORDER BY s.is_active DESC, COALESCE(mk.tingkat, s.KELAS), mk.kode_rombel, s.NAMA
";
$stmt = $koneksi->prepare($sql);
$types = 'ssssiiss';
$stmt->bind_param($types, $query, $query, $query, $query, $filterClass, $filterClass, $filterStatus, $filterStatus);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$download = ($_GET['download'] ?? '') === '1';
$statusLabels = ['legacy'=>'Legacy', 'active'=>'Aktif', 'archived'=>'Arsip/Lulus', 'all'=>'Semua'];
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

require_once __DIR__.'/../includes/excel.php';
$columns=[spp_excel_column('no','No','number')];
if(unit_all_readonly())$columns[]=spp_excel_column('export_unit','Unit');
$columns=array_merge($columns,[spp_excel_column('NO_INDUK','NIS'),spp_excel_column('NO_induk_diknas','NIS Diknas'),spp_excel_column('NAMA','Nama Siswa'),spp_excel_column('export_class','Kelas/Rombel'),spp_excel_column('SPP_PERBULAN','SPP Per Bulan','money'),spp_excel_column('export_status','Status')]);
foreach($rows as $i=>&$row){$row['no']=$i+1;$row['export_unit']=unit_label((int)$row['unit_id']);$row['export_class']=class_label(['tingkat'=>$row['master_tingkat']?:$row['KELAS'],'kode_rombel'=>$row['kode_rombel']??'BELUM','is_placeholder'=>$row['is_placeholder']??1]);$row['export_status']=!empty($row['legacy_pending'])?'Legacy':((int)$row['is_active']===1?'Aktif':'Arsip/Lulus');}unset($row);
$doc=spp_excel_document('Data Siswa',$filterLabel,unit_active_id(),[['name'=>'Data Siswa','sections'=>[spp_excel_section('Daftar Siswa - '.count($rows).' siswa',$columns,$rows)]]]);
$downloadQuery=$_GET;$downloadQuery['download']='1';$backQuery=array_intersect_key($_GET,array_flip(['q','kelas','status','per_page','page']));
spp_excel_respond($doc,$download,'data-siswa-'.strtolower(unit_label(unit_active_id())).'-'.date('Ymd-His'),'export_excel.php?'.filter_build_query($downloadQuery),'daftar.php?'.filter_build_query($backQuery),count($rows));
