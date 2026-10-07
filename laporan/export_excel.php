<?php
// ============================================
// laporan/export_excel.php — Export ke Excel
// ============================================
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);
$reportUnitId=unit_report_scope($koneksi,(string)($_GET['unit']??''));

require_once '../includes/general_multiple.php';
$generalChoices=general_choices($_GET);
$filter_bulan = (int)($_GET['bulan'] ?? date('m'));
$filter_tahun = (int)($_GET['tahun'] ?? date('Y'));
$filter_q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$dateParam = static function (string $key): string {
    $value = trim((string)($_GET[$key] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
};
$filter_tanggal = $dateParam('tanggal');
$filter_tanggal_awal = $dateParam('tanggal_awal') ?: $filter_tanggal;
$filter_tanggal_akhir = $dateParam('tanggal_akhir') ?: $filter_tanggal;
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir === '') $filter_tanggal_akhir = $filter_tanggal_awal;
if ($filter_tanggal_akhir !== '' && $filter_tanggal_awal === '') $filter_tanggal_awal = $filter_tanggal_akhir;
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '' && strtotime($filter_tanggal_awal) > strtotime($filter_tanggal_akhir)) {
    [$filter_tanggal_awal, $filter_tanggal_akhir] = [$filter_tanggal_akhir, $filter_tanggal_awal];
}
$download = isset($_GET['download']) && $_GET['download'] === '1';

$bln_names = ['1'=>'Januari','2'=>'Februari','3'=>'Maret','4'=>'April','5'=>'Mei','6'=>'Juni',
               '7'=>'Juli','8'=>'Agustus','9'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$bulan_label = $bln_names[$filter_bulan] ?? 'Unknown';
$period_start = $filter_tanggal_awal !== '' ? $filter_tanggal_awal . ' 00:00:00' : sprintf('%04d-%02d-01 00:00:00', $filter_tahun, $filter_bulan);
$period_end = $filter_tanggal_akhir !== ''
    ? date('Y-m-d H:i:s', strtotime($filter_tanggal_akhir . ' +1 day'))
    : date('Y-m-d H:i:s', strtotime($period_start . ' +1 month'));
$studentWhere = '';
$studentParams = [];
if ($filter_q !== '') {
    $studentWhere = ' AND (s.NO_INDUK LIKE ? OR s.NAMA LIKE ? OR s.NO_induk_diknas LIKE ?)';
    $studentLike = '%' . $filter_q . '%';
    $studentParams = [$studentLike, $studentLike, $studentLike];
}
$studentWhere .= unit_student_selection_where();
$bind = static function (mysqli_stmt $stmt, string $baseTypes, array $baseParams) use ($studentParams): void {
    $types = $baseTypes . str_repeat('s', count($studentParams));
    $params = array_merge($baseParams, $studentParams);
    $stmt->bind_param($types, ...$params);
};
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '') {
    $startTs = strtotime($filter_tanggal_awal);
    $endTs = strtotime($filter_tanggal_akhir);
    if ($filter_tanggal_awal === $filter_tanggal_akhir) {
        $period_label = spp_date_label($startTs);
    } elseif (date('Y-m', $startTs) === date('Y-m', $endTs)) {
        $period_label = spp_date_label($startTs) . ' - ' . spp_date_label($endTs);
    } else {
        $period_label = spp_date_label($startTs) . ' - ' . spp_date_label($endTs);
    }
} else {
    $period_label = $bulan_label . ' ' . $filter_tahun;
}

// Ambil data pembayaran
$paymentOrder=match((string)($_GET['urut']??'terbaru')){
    'nama'=>'s.NAMA ASC,b.TGL_BYR DESC',
    'kelas'=>'CAST(b.KELAS AS UNSIGNED) ASC,b.kelas_rombel_snapshot ASC,s.NAMA ASC,b.TGL_BYR DESC',
    'nominal_terbesar'=>'b.total_jumlah DESC,b.TGL_BYR DESC',
    default=>'b.TGL_BYR DESC,b.id DESC',
};
$stmt = $koneksi->prepare("
    SELECT b.unit_id,s.id AS student_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA,
           COALESCE(NULLIF(b.kelas_rombel_snapshot,''),NULLIF(b.KELAS,''),s.KELAS) AS KELAS,
           b.BULAN, b.TAHUN,
           b.U_PSB, b.U_SPP, b.U_KOMITE,
           b.sistem_pembayaran, b.total_jumlah, b.TGL_BYR
    FROM bayar b JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ? $studentWhere
    ORDER BY $paymentOrder
");
$bind($stmt, 'ss', [$period_start, $period_end]);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmtKomponen = $koneksi->prepare("
    SELECT SUM(U_PSB) AS psb,
           SUM(U_SPP) AS spp,
           SUM(U_KOMITE) AS komite, SUM(potong_spp) AS potongan_spp
    FROM bayar b JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ? $studentWhere
");
$bind($stmtKomponen, 'ss', [$period_start, $period_end]);
$stmtKomponen->execute();
$komponenTetap = $stmtKomponen->get_result()->fetch_assoc();
$stmtKomponen->close();

$komponen_rows = [];
$komponenMap = [
    'Uang PSB' => 'psb',
    'Uang SPP' => 'spp', 'Uang Komite' => 'komite'
];
foreach ($komponenMap as $nama => $key) {
    if ((float)($komponenTetap[$key] ?? 0) > 0) {
        $komponen_rows[] = ['nama' => $nama, 'total' => $komponenTetap[$key]];
    }
}

$stmtBiayaLain = $koneksi->prepare("
    SELECT d.nama_biaya_snapshot AS nama, SUM(d.nominal_snapshot) AS total
    FROM bayar_biaya_lain d JOIN bayar b ON b.id = d.bayar_id
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ? $studentWhere
    GROUP BY d.nama_biaya_snapshot ORDER BY d.nama_biaya_snapshot ASC
");
$bind($stmtBiayaLain, 'ss', [$period_start, $period_end]);
$stmtBiayaLain->execute();
$komponen_rows = array_merge($komponen_rows, $stmtBiayaLain->get_result()->fetch_all(MYSQLI_ASSOC));
$stmtBiayaLain->close();

$stmtDu = $koneksi->prepare("
    SELECT COALESCE(SUM(d.jumlah),0) AS total
    FROM bayar_du d JOIN bayar b ON b.id=d.bayar_id
    JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ? $studentWhere
");
$bind($stmtDu, 'ss', [$period_start, $period_end]);
$stmtDu->execute();
$totalDu = (float)($stmtDu->get_result()->fetch_assoc()['total'] ?? 0);
$stmtDu->close();
if ($totalDu > 0.001) $komponen_rows[] = ['nama'=>'Daftar Ulang','total'=>$totalDu];
$totalDiscount = (float)($komponenTetap['potongan_spp'] ?? 0);
if ($totalDiscount > 0.001) $komponen_rows[] = ['nama'=>'Potongan SPP','total'=>-$totalDiscount];

// Ambil data tabungan periode ini
$stmt2 = $koneksi->prepare("
    SELECT tm.unit_id,tm.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, tm.TANGGAL, tm.MASUK as nominal, 'masuk' as jenis, tm.keterangan
    FROM transaksi_m tm JOIN siswa s ON s.NO_INDUK = tm.NO_INDUK AND s.unit_id=tm.unit_id
    WHERE tm.TANGGAL >= ? AND tm.TANGGAL < ? $studentWhere
    UNION ALL
    SELECT tk.unit_id,tk.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, tk.TANGGAL, tk.KELUAR as nominal, 'keluar' as jenis, tk.keterangan
    FROM transaksi_k tk JOIN siswa s ON s.NO_INDUK = tk.NO_INDUK AND s.unit_id=tk.unit_id
    WHERE tk.TANGGAL >= ? AND tk.TANGGAL < ? $studentWhere
    ORDER BY TANGGAL DESC
");
$tabTypes = 'ssss' . str_repeat('s', count($studentParams) * 2);
$tabParams = array_merge([$period_start, $period_end], $studentParams, [$period_start, $period_end], $studentParams);
$stmt2->bind_param($tabTypes, ...$tabParams);
$stmt2->execute();
$tab_rows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

$preview_total_pembayaran = 0.0;
foreach ($rows as $row) {
    $preview_total_pembayaran += (float)$row['total_jumlah'];
}
$preview_total_tab_masuk = 0.0;
$preview_total_tab_keluar = 0.0;
foreach ($tab_rows as $tab) {
    if ($tab['jenis'] === 'masuk') {
        $preview_total_tab_masuk += (float)$tab['nominal'];
    } else {
        $preview_total_tab_keluar += (float)$tab['nominal'];
    }
}

require_once __DIR__.'/../includes/excel.php';
$paymentColumns=[spp_excel_column('no','No','number')];$savingColumns=$paymentColumns;
if($reportUnitId===0){$paymentColumns[]=spp_excel_column('export_unit','Unit');$savingColumns[]=spp_excel_column('export_unit','Unit');}
$identities=[spp_excel_column('NO_INDUK','NIS'),spp_excel_column('NO_induk_diknas','NIS Diknas'),spp_excel_column('NAMA','Nama Siswa'),spp_excel_column('KELAS','Kelas')];
$paymentColumns=array_merge($paymentColumns,$identities,[spp_excel_column('export_period','Bulan Tagihan'),spp_excel_column('sistem_pembayaran','Sistem Pembayaran'),spp_excel_column('total_jumlah','Total Bayar','money'),spp_excel_column('TGL_BYR','Tanggal Bayar','datetime')]);
$savingColumns=array_merge($savingColumns,$identities,[spp_excel_column('TANGGAL','Tanggal','datetime'),spp_excel_column('jenis','Jenis'),spp_excel_column('nominal','Nominal','money'),spp_excel_column('keterangan','Keterangan')]);
foreach($rows as $i=>&$row){$row['no']=$i+1;$row['export_unit']=unit_label((int)$row['unit_id']);$row['export_period']=$row['BULAN'].' '.$row['TAHUN'];}unset($row);
foreach($tab_rows as $i=>&$row){$row['no']=$i+1;$row['export_unit']=unit_label((int)$row['unit_id']);$row['jenis']=$row['jenis']==='masuk'?'Masuk':'Keluar';}unset($row);
$totals=[['label'=>'Total Pembayaran','value'=>$preview_total_pembayaran]];
$tabTotals=[['label'=>'Total Masuk','value'=>$preview_total_tab_masuk],['label'=>'Total Keluar','value'=>$preview_total_tab_keluar]];
$summary=[['label'=>'Pembayaran','value'=>$preview_total_pembayaran],['label'=>'Tabungan Masuk','value'=>$preview_total_tab_masuk],['label'=>'Tabungan Keluar','value'=>$preview_total_tab_keluar]];
$sheets=[['name'=>'Ringkasan','sections'=>[spp_excel_section('Ringkasan Keuangan',[spp_excel_column('label','Ringkasan'),spp_excel_column('value','Nominal','money')],$summary,[],false),spp_excel_section('Rincian Komponen Pembayaran',[spp_excel_column('nama','Komponen'),spp_excel_column('total','Nominal','money')],$komponen_rows,$totals,false)]],['name'=>'Pembayaran','sections'=>[spp_excel_section('Transaksi Pembayaran',$paymentColumns,$rows,$totals)]],['name'=>'Tabungan','sections'=>[spp_excel_section('Transaksi Tabungan',$savingColumns,$tab_rows,$tabTotals)]]];
if(is_array($_GET['jenis_laporan']??null)||($_GET['jenis_laporan']??'semua')!=='semua'){
    $generalSections=general_sections($koneksi,$generalChoices,$period_start,$period_end,$filter_q,(string)($_GET['urut']??'terbaru'));
    $sheets[1]['sections']=general_excel_sections($generalSections);
    foreach($generalSections as $section)foreach($section['totals'] as $total)$summary[]=['label'=>$section['title'].' - '.$total['label'],'value'=>$total['value']];
    $sheets[0]['sections'][0]['rows']=$summary;
}
$doc=spp_excel_document('Rekap Laporan Keuangan','Periode '.$period_label.($filter_q!==''?' | Pencarian: '.$filter_q:''),$reportUnitId,$sheets);
$downloadQuery=$_GET;$downloadQuery['download']='1';$backQuery=$_GET;unset($backQuery['download']);
if(!empty($sppGeneralPdf))return;
$detailCount=0;
foreach(array_slice($sheets,1) as $sheet)foreach($sheet['sections'] as $section)$detailCount+=count($section['rows']);
spp_excel_respond($doc,$download,'laporan-keuangan-'.date('Ymd-His'),'export_excel.php?'.filter_build_query($downloadQuery),'index.php?'.filter_build_query($backQuery),$detailCount);
