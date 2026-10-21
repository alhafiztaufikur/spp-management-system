<?php
// ============================================
// laporan/export_pdf.php - Server-rendered payment slips PDF
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/spp_billing.php';
require_once '../includes/payment_permissions.php';
requireRole(['admin', 'bendahara', 'kasir']);
$reportUnitId=unit_report_scope($koneksi,(string)($_GET['unit']??''));

// Multi-filter recaps use the same three-sheet document as the XLSX export.
if(($_GET['mode']??'')!=='selected' && (is_array($_GET['jenis_laporan']??null)||($_GET['jenis_laporan']??'semua')!=='semua')){
    $sppGeneralPdf=true;require __DIR__.'/export_excel.php';
    $detailCount=0;foreach(array_slice($sheets,1) as $sheet)foreach($sheet['sections'] as $section)$detailCount+=count($section['rows']);
    $html=$detailCount>600?'':general_document_html($doc);
    if(!in_array($_GET['output']??'preview',['pdf','inline'],true)){
        require_once '../includes/report_preview.php';$downloadQuery=$_GET;$downloadQuery['output']='pdf';$inlineQuery=$_GET;$inlineQuery['output']='inline';
        render_report_pdf_preview($html,['title'=>$doc['title'],'subtitle'=>$doc['subtitle'],'orientation'=>'landscape','unit'=>$reportUnitId,'generated'=>$doc['generated'],'pdf_url'=>'export_pdf.php?'.filter_build_query($inlineQuery),'row_count'=>$detailCount,'download_url'=>'export_pdf.php?'.filter_build_query($downloadQuery),'back_url'=>'index.php?'.filter_build_query($_GET)]);
    }
    require_once '../includes/pdf.php';require_pdf_library();require_once '../includes/report_artifact.php';
    $stable=$doc;unset($stable['generated']);$query=$_GET;unset($query['output']);
    $artifact=report_artifact_get(['route'=>'recap','unit'=>$reportUnitId,'query'=>$query],$stable,'pdf',static function()use($html,$doc,$detailCount){
        if($detailCount>600){require_once __DIR__.'/../includes/transaction_report_pdf.php';require_once __DIR__.'/../includes/global_report_layout.php';return global_report_render_pdf_fragments(transaction_report_pdf_fragments($doc),'landscape');}
        $options=new \Dompdf\Options();$options->setIsRemoteEnabled(false);$options->setChroot(realpath(__DIR__.'/..'));
        $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','landscape');$pdf->render();
        $pdf->getCanvas()->page_text(705,571,'Halaman {PAGE_NUM} / {PAGE_COUNT}',$pdf->getFontMetrics()->getFont('DejaVu Sans'),8,[.3,.4,.35]);
        $bytes=$pdf->output();report_perf_mark('pdf');return $bytes;
    });
    report_artifact_stream($artifact,'rekap-laporan-transaksi.pdf','pdf',($_GET['output']??'pdf')!=='inline');
}

$output_mode = (string)($_GET['output'] ?? 'preview');
if (!in_array($output_mode, ['preview', 'pdf'], true)) {
    $output_mode = 'preview';
}

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
$preview_mode = isset($_GET['contoh']) && $_GET['contoh'] === '1';
$selected_mode = isset($_GET['mode']) && $_GET['mode'] === 'selected';
$selected_ids_raw = $_GET['ids'] ?? [];
if (!is_array($selected_ids_raw)) {
    $selected_ids_raw = preg_split('/[,\s]+/', (string)$selected_ids_raw, -1, PREG_SPLIT_NO_EMPTY);
}
$selected_ids = array_values(array_unique(array_filter(array_map('intval', $selected_ids_raw), fn($id) => $id !== 0)));

if ($selected_mode && !$selected_ids) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih minimal satu transaksi untuk dicetak.'];
    header('Location: index.php?bulan=' . urlencode((string)$filter_bulan) . '&tahun=' . urlencode((string)$filter_tahun) . '&tanggal_awal=' . urlencode($filter_tanggal_awal) . '&tanggal_akhir=' . urlencode($filter_tanggal_akhir) . '&q=' . urlencode($filter_q));
    exit;
}

$bln_names = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$bulan_label = $bln_names[$filter_bulan] ?? '';
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '') {
    $startTs = strtotime($filter_tanggal_awal);
    $endTs = strtotime($filter_tanggal_akhir);
    if ($filter_tanggal_awal === $filter_tanggal_akhir) {
        $periode = spp_date_label($startTs);
    } elseif (date('Y-m', $startTs) === date('Y-m', $endTs)) {
        $periode = spp_date_label($startTs) . ' - ' . spp_date_label($endTs);
    } else {
        $periode = spp_date_label($startTs) . ' - ' . spp_date_label($endTs);
    }
} else {
    $periode = trim($bulan_label . ' ' . $filter_tahun);
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_plain($n): string {
    $amount = (float)$n;
    return $amount > 0 ? number_format($amount, 0, ',', '.') : '';
}

function money_total($n): string {
    $v=round((float)$n,2);return number_format($v,$v===floor($v)?0:2,',','.');
}

function month_code($value): string {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12'
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)$value, 2, '0', STR_PAD_LEFT);
}

function month_name_from_value($value, array $names): string {
    $code = (int)month_code($value);
    return $names[$code] ?? (string)$value;
}

function month_name_id($value): string {
    $names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $code = (int)month_code($value);
    return $names[$code] ?? (string)$value;
}

function terbilang_int(int $number): string {
    $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($number < 12) return $words[$number];
    if ($number < 20) return terbilang_int($number - 10) . ' belas';
    if ($number < 100) return terbilang_int(intdiv($number, 10)) . ' puluh' . ($number % 10 ? ' ' . terbilang_int($number % 10) : '');
    if ($number < 200) return 'seratus' . ($number - 100 ? ' ' . terbilang_int($number - 100) : '');
    if ($number < 1000) return terbilang_int(intdiv($number, 100)) . ' ratus' . ($number % 100 ? ' ' . terbilang_int($number % 100) : '');
    if ($number < 2000) return 'seribu' . ($number - 1000 ? ' ' . terbilang_int($number - 1000) : '');
    if ($number < 1000000) return terbilang_int(intdiv($number, 1000)) . ' ribu' . ($number % 1000 ? ' ' . terbilang_int($number % 1000) : '');
    if ($number < 1000000000) return terbilang_int(intdiv($number, 1000000)) . ' juta' . ($number % 1000000 ? ' ' . terbilang_int($number % 1000000) : '');
    return terbilang_int(intdiv($number, 1000000000)) . ' miliar' . ($number % 1000000000 ? ' ' . terbilang_int($number % 1000000000) : '');
}

function terbilang_rupiah($amount): string {
    $number = (int)round((float)$amount);
    if ($number <= 0) return 'nol rupiah';
    return ucfirst(terbilang_int($number)) . ' rupiah';
}

function payment_line(string $label, $amount): array {
    return ['label' => $label, 'amount' => (float)$amount];
}

$period_start = $filter_tanggal_awal !== '' ? $filter_tanggal_awal . ' 00:00:00' : sprintf('%04d-%02d-01 00:00:00', $filter_tahun, $filter_bulan);
$period_end = $filter_tanggal_akhir !== ''
    ? date('Y-m-d H:i:s', strtotime($filter_tanggal_akhir . ' +1 day'))
    : date('Y-m-d H:i:s', strtotime($period_start . ' +1 month'));
if($selected_mode&&min($selected_ids)<0){
    require_once __DIR__.'/../includes/payment_history.php';
    require_once __DIR__.'/../includes/receipt_balances.php';
    require_once __DIR__.'/../includes/receipt_print.php';
    $documents=[];$scope=(int)($GLOBALS['app_unit_id']??unit_active_id());
    try{foreach($selected_ids as $id){
        unit_set_context($koneksi,$scope);$model=payment_history_model($koneksi,$id,false);$p=$model['payment']??null;
        if(!$p||empty($model['capabilities']['can_print'])||!$p['TGL_BYR']||$p['TGL_BYR']<$period_start||$p['TGL_BYR']>=$period_end)throw new DomainException('Transaksi yang dipilih tidak tersedia atau tidak memiliki izin cetak.');
        unit_set_context($koneksi,(int)$p['unit_id']);$p['owner_type']=empty($p['student_id'])&&$id<0?'account':'student';
        $documents[]=['payment'=>$p,'school'=>unit_school_name((int)$p['unit_id']),'primary'=>$model['components'],'remaining'=>payment_receipt_remaining($koneksi,$p),'other'=>[],'period'=>payment_history_period($p),'words'=>receipt_money_words((float)$p['total_jumlah']),'date'=>spp_date_label($p['TGL_BYR'],true,$p['time_precision']??null),'signer'=>$p['source_operator']??$p['user_id']??'Tidak tercatat'];
    }}catch(Throwable $e){http_response_code(403);exit('Transaksi yang dipilih tidak tersedia atau tidak memiliki izin cetak.');}
    finally{unit_set_context($koneksi,$scope);}
    receipt_http_output($documents,'Slip Pembayaran','Struk siap dicetak');exit;
}
$where_sql = 'WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ?';
$where_sql .= unit_student_selection_where();
$types = 'ss';
$params = [$period_start, $period_end];
if ($filter_q !== '') {
    $where_sql .= ' AND (s.NO_INDUK LIKE ? OR s.NAMA LIKE ? OR s.NO_induk_diknas LIKE ?)';
    $types .= 'sss';
    $studentLike = '%' . $filter_q . '%';
    array_push($params, $studentLike, $studentLike, $studentLike);
}
if ($selected_ids) {
    $where_sql .= ' AND b.id IN (' . implode(',', array_fill(0, count($selected_ids), '?')) . ')';
    $types .= str_repeat('i', count($selected_ids));
    array_push($params, ...$selected_ids);
}

$stmt = $koneksi->prepare("
    SELECT
        b.*,
        s.NAMA,
        s.NO_induk_diknas,
        s.KELAS AS KELAS_SISWA,
        s.PSB,
        s.DAFTAR_ULANG,
        s.potong_du,
        s.tot_du,
        COALESCE(du_current.jumlah, 0) AS uang_du,
        du_current.th_ajaran AS du_tahun_ajaran,
        du_bill.id AS du_bill_id,
        du_bill.nominal_tagihan AS du_nominal_tagihan,
        COALESCE((SELECT SUM(bd.jumlah) FROM bayar_du bd WHERE bd.tagihan_daftar_ulang_id=du_bill.id),0) AS du_paid_this_bill,
        COALESCE(one_paid.total_psb_bayar, 0) AS total_psb_bayar,
        COALESCE(op.nama, NULLIF(b.user_id, '')) AS operator_name
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
    LEFT JOIN admin op ON op.id = CAST(b.user_id AS UNSIGNED)
    LEFT JOIN bayar_du du_current ON du_current.bayar_id=b.id
    LEFT JOIN tagihan_daftar_ulang du_bill ON du_bill.id=du_current.tagihan_daftar_ulang_id
    LEFT JOIN (
        SELECT unit_id,NO_INDUK, SUM(U_PSB) AS total_psb_bayar
        FROM bayar
        GROUP BY unit_id,NO_INDUK
    ) one_paid ON one_paid.NO_INDUK = b.NO_INDUK AND one_paid.unit_id=b.unit_id
    $where_sql
    ORDER BY b.TGL_BYR DESC, b.id DESC
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
if(!$selected_mode){
    require_once __DIR__.'/../includes/general_history.php';
    $rows=general_payment_rows($koneksi,$period_start,$period_end,$filter_q,(string)($_GET['urut']??'terbaru'));
}

// Selected slips use the same print permissions as the report's row actions.
if ($selected_mode) {
    $events = payment_activity_for_payments($koneksi, array_column($rows, 'id'));
    $allowed = count($rows) === count($selected_ids);
    foreach ($rows as $row) {
        if (!payment_capabilities($koneksi, $row, $events[(int)$row['id']] ?? [], false, [])['can_print']) $allowed = false;
    }
    if (!$allowed) {
        http_response_code(403);
        exit('Transaksi yang dipilih tidak tersedia atau tidak memiliki izin cetak.');
    }
}

if ($selected_mode && !$rows) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Transaksi yang dipilih tidak ditemukan pada periode ini.'];
    header('Location: index.php?bulan=' . urlencode((string)$filter_bulan) . '&tahun=' . urlencode((string)$filter_tahun) . '&tanggal_awal=' . urlencode($filter_tanggal_awal) . '&tanggal_akhir=' . urlencode($filter_tanggal_akhir) . '&q=' . urlencode($filter_q));
    exit;
}

$details_by_payment = [];
$spp_allocations_by_payment = [];
if ($rows && $selected_mode) {
    $ids = array_map(fn($row) => (int)$row['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt_details = $koneksi->prepare("
        SELECT bayar_id, nama_biaya_snapshot, nominal_snapshot, keterangan
        FROM bayar_biaya_lain
        WHERE bayar_id IN ($placeholders)
        ORDER BY bayar_id ASC, urutan ASC, id ASC
    ");
    $stmt_details->bind_param($types, ...$ids);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    while ($detail = $result_details->fetch_assoc()) {
        $details_by_payment[(int)$detail['bayar_id']][] = $detail;
    }
    $stmt_details->close();
    if(spp_billing_schema_ready($koneksi))foreach($ids as $paymentId){$summary=spp_payment_allocation_summary($koneksi,$paymentId);if($summary)$spp_allocations_by_payment[$paymentId]=$summary;}
}

if (!$selected_mode) {
    $totalRows = count($rows);
    $grandTotal = array_sum(array_map(static fn($row) => (float)($row['total_jumlah'] ?? 0), $rows));
    $htmlRows = '';
    foreach ($rows as $index => $row) {
        $htmlRows .= '<tr>'
            . '<td>' . ($index + 1) . '</td>'
            . '<td>' . e($row['NO_INDUK'] ?? '') . (!empty($row['NO_induk_diknas']) ? '<br><small>Diknas ' . e($row['NO_induk_diknas']) . '</small>' : '') . '</td>'
            . '<td>' . e($row['NAMA'] ?? '') . '</td>'
            . '<td>' . e($row['kelas_rombel_snapshot'] ?: ($row['KELAS_SISWA'] ?? '')) . '</td>'
            . '<td>' . e(trim(($row['BULAN'] ?? '') . ' ' . ($row['TAHUN'] ?? ''))) . '</td>'
            . '<td>' . e($row['sistem_pembayaran'] ?? '') . '</td>'
            . '<td>' . e(spp_date_label($row['TGL_BYR'] ?? null,true,$row['time_precision']??null)) . '</td>'
            . '<td class="num">Rp ' . money_total($row['total_jumlah'] ?? 0) . '</td>'
            . '</tr>';
    }
    if ($htmlRows === '') {
        $htmlRows = '<tr><td colspan="8" class="empty">Tidak ada data pembayaran pada filter ini.</td></tr>';
    }
    $logoPath = realpath(__DIR__ . '/../assets/img/school-logo.png');
    $logoData = $logoPath ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath)) : '';
    $generated = spp_date_label(new DateTimeImmutable('now'),true);
    $operator = (string)($_SESSION['admin_nama'] ?? $_SESSION['admin_username'] ?? 'Pengguna');
    $html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>'
        . '@page{margin:14mm;size:A4 landscape}*{box-sizing:border-box}body{font-family:DejaVu Sans,Arial,sans-serif;color:#18281f;font-size:9pt;margin:0}.kop{width:100%;border-bottom:3px double #15543c;margin-bottom:10px}.kop td{border:0;padding:0 0 8px}.kop img{width:54px;height:54px;object-fit:contain}.kop h1{font-size:15px;margin:0;text-align:center}.kop p{text-align:center;margin:3px 0;color:#52645b}.title{text-align:center;margin:10px 0}.title h2{font-size:15px;margin:0 0 4px}.title p{margin:0;color:#66766d}.meta{width:100%;margin-bottom:8px}.meta td{border:0;padding:2px}.summary{display:table;width:100%;margin:10px 0 12px;border:1px solid #cfe9dc}.summary div{display:table-cell;padding:9px;border-right:1px solid #e1f1e8}.summary div:last-child{border-right:0}.summary span{display:block;color:#708078;font-size:9pt;text-transform:uppercase;font-weight:700}.summary strong{display:block;margin-top:3px;font-size:12px}table{width:100%;border-collapse:collapse}thead{display:table-header-group}tr{page-break-inside:avoid}th{background:#15543c;color:#fff;text-align:left;font-size:9pt;text-transform:uppercase;padding:7px;border:1px solid #8fb3a2}td{padding:6px;border:1px solid #d9e9e1;vertical-align:top}tbody tr:nth-child(even){background:#f5faf7}.num{text-align:right;font-weight:700;color:#0b8d4b;white-space:nowrap}.empty{text-align:center;color:#718078;padding:24px}small{color:#708078}.footer{position:fixed;bottom:-8mm;left:0;right:0;border-top:1px solid #b9cec3;padding-top:3px;color:#697970;font-size:9pt}.footer:after{content:"SistemSPP - Halaman " counter(page)}'
        . 'body{font-size:9pt;line-height:1.38;background:#fff}.kop{margin-bottom:12px}.kop img{width:60px;height:60px}.kop h1{font-size:16px;letter-spacing:.15px}.title{margin:12px 0 9px;padding:9px 12px;border:1px solid #d3e7dc;background:#f3faf6}.title h2{font-size:16px;color:#123f2f}.meta{color:#61736a;margin-bottom:10px}.summary{border-radius:4px;background:#fbfdfc}.summary div{padding:10px 12px}.summary strong{color:#143d2f;font-size:13px}body>table:last-of-type{table-layout:fixed}body>table:last-of-type th:nth-child(1){width:4%}body>table:last-of-type th:nth-child(2){width:14%}body>table:last-of-type th:nth-child(3){width:19%}body>table:last-of-type th:nth-child(4){width:8%}body>table:last-of-type th:nth-child(5){width:13%}body>table:last-of-type th:nth-child(6){width:10%}body>table:last-of-type th:nth-child(7){width:17%}body>table:last-of-type th:nth-child(8){width:15%}th{padding:7px 8px}td{padding:6px 8px;word-break:break-word}@media screen{body{padding:28px 30px;font-size:9pt}.kop h1{font-size:18px}.kop p{font-size:9pt}.title h2{font-size:17px}.title p{font-size:9pt}.meta{font-size:9pt}.summary span{font-size:9pt}.summary strong{font-size:14px}th{font-size:9pt;padding:8px}td{padding:7px 8px}.footer{position:static;margin-top:18px}}'
        . '</style></head><body><table class="kop"><tr><td style="width:72px">' . ($logoData !== '' ? '<img src="' . $logoData . '" alt="Logo sekolah">' : '') . '</td><td><h1>' . e(unit_school_name($reportUnitId)) . '</h1><p>Perum Bekasi Griya Asri II, Tambun Selatan - Telp. 021-88363466</p></td><td style="width:72px"></td></tr></table><div class="title"><h2>REKAP LAPORAN TRANSAKSI</h2><p>Periode ' . e($periode) . '</p></div><table class="meta"><tr><td>Dibuat: ' . e($generated) . '</td><td style="text-align:right">Petugas: ' . e($operator) . '</td></tr></table><div class="summary"><div><span>Total Transaksi</span><strong>' . number_format($totalRows) . '</strong></div><div><span>Total Pembayaran</span><strong>Rp ' . money_total($grandTotal) . '</strong></div></div><table><thead><tr><th>No</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>Periode Bayar</th><th>Sistem</th><th>Tanggal</th><th>Total</th></tr></thead><tbody>'
        . $htmlRows
        . '</tbody></table><div class="footer"></div></body></html>';

    if ($output_mode === 'preview') {
        require_once __DIR__ . '/../includes/report_preview.php';
        $downloadQuery = $_GET;
        $downloadQuery['output'] = 'pdf';
        $backQuery = $_GET;
        unset($backQuery['output'], $backQuery['mode'], $backQuery['ids'], $backQuery['contoh']);
        render_report_pdf_preview($html, [
            'title' => 'Rekap Laporan Transaksi',
            'subtitle' => 'Periode ' . $periode,
            'generated' => $generated,
            'row_count' => $totalRows,
            'orientation' => 'landscape',
            'download_url' => 'export_pdf.php?' . http_build_query($downloadQuery),
            'back_url' => 'index.php?' . http_build_query($backQuery),
        ]);
    }

    require_once __DIR__ . '/../includes/pdf.php';
    require_pdf_library();
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->setDefaultMediaType('print');
$options->setFontHeightRatio(1.0);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('rekap-laporan-keuangan.pdf', ['Attachment' => true]);
    exit;
}

if (!$rows && !$selected_mode) {
    $preview_mode = true;
    $rows[] = [
        'id' => 0,
        'NO_INDUK' => '000000001',
        'NAMA' => 'Contoh Siswa',
        'KELAS_SISWA' => '2B',
        'BULAN' => str_pad((string)$filter_bulan, 2, '0', STR_PAD_LEFT),
        'TAHUN' => (string)$filter_tahun,
        'U_PSB' => 0,
        'U_SPP' => 495000,
        'U_KOMITE' => 15000,
        'sistem_pembayaran' => 'VA',
        'potong_spp' => 0,
        'total_jumlah' => 510000,
        'TGL_BYR' => date('Y-m-d H:i:s'),
        'PSB' => 0,
        'DAFTAR_ULANG' => 0,
        'potong_du' => 0,
        'tot_du' => 0,
        'uang_du' => 0,
        'total_psb_bayar' => 0,
        'total_du_bayar' => 0,
    ];
}

function primary_lines(array $row, ?array $sppAllocation=null): array {
    $lines=[
        payment_line('Uang PSB', $row['U_PSB']),
        payment_line('Uang Daftar Ulang' . (!empty($row['du_tahun_ajaran']) ? ' (TA ' . $row['du_tahun_ajaran'] . ')' : ''), $row['uang_du']),
        payment_line('Komite Sekolah ('.month_name_id($row['BULAN']).' '.$row['TAHUN'].')', $row['U_KOMITE']),
    ];
    $lines[]=payment_line($sppAllocation?'Uang SPP Diterima Sekarang':'Uang SPP',$sppAllocation?(float)$sppAllocation['uang_baru']:$row['U_SPP']);return $lines;
}

function other_lines(array $row, array $details, ?array $sppAllocation=null): array {
    $lines = [];

    if($sppAllocation){foreach($sppAllocation['allocations'] as $allocation)$lines[]=payment_line('SPP '.month_name_id($allocation['bulan']).' '.$allocation['tahun'],(float)$allocation['nominal_dari_bayar']);}

    foreach ($details as $detail) {
        $label = $detail['nama_biaya_snapshot'];
        if (trim((string)$detail['keterangan']) !== '') {
            $label .= ' - ' . $detail['keterangan'];
        }
        $lines[] = payment_line($label, $detail['nominal_snapshot']);
    }

    if ((float)$row['potong_spp'] > 0) {
        $lines[] = payment_line('Potongan SPP', -1 * (float)$row['potong_spp']);
    }

    return array_values(array_filter($lines, fn($line) => abs((float)$line['amount']) > 0.001));
}


function total_psb_bill(array $row): float {
    return max(0, (float)($row['PSB'] ?? 0));
}

require_once __DIR__.'/../includes/receipt_print.php';
$documents=[];
foreach($rows as $row){
    $allocation=$spp_allocations_by_payment[(int)$row['id']]??null;
    $sisaDu=(float)$row['uang_du']>.001 && (int)($row['du_bill_id']??0)>0
        ? max(0,(float)$row['du_nominal_tagihan']-(float)$row['du_paid_this_bill']) : null;
    $documents[]=[
        'payment'=>$row,'school'=>unit_school_name((int)$row['unit_id']),
        'primary'=>array_map(static fn($line)=>[$line['label'],$line['amount']],primary_lines($row,$allocation)),
        'remaining'=>[['Sisa PSB',max(0,total_psb_bill($row)-(float)$row['total_psb_bayar'])],['Sisa DU',$sisaDu]],
        'other'=>array_map(static fn($line)=>[$line['label'],$line['amount']],other_lines($row,$details_by_payment[(int)$row['id']]??[],$allocation)),
        'period'=>month_name_from_value($row['BULAN'],$bln_names).' '.$row['TAHUN'],
        'words'=>terbilang_rupiah($row['total_jumlah']),'date'=>spp_date_label($row['TGL_BYR'],true),
        'signer'=>$row['operator_name'] ?: ($_SESSION['admin_nama']??'Bagian Keuangan'),
    ];
}
ob_start();
receipt_print_document($documents,'Slip Pembayaran','Struk siap dicetak',true);
$html = ob_get_clean();

if ($output_mode === 'preview') {
    require_once __DIR__ . '/../includes/report_preview.php';
    $downloadQuery = $_GET;
    $downloadQuery['output'] = 'pdf';
    $backQuery = $_GET;
    unset($backQuery['output'], $backQuery['mode'], $backQuery['ids'], $backQuery['contoh']);
    render_report_pdf_preview($html, [
        'title' => 'Slip Pembayaran',
        'document_label' => 'Letter separo · 215,9 × 139,7 mm',
        'stage_width' => '980px',
        'subtitle' => 'Periode ' . $periode . ' - ' . number_format(count($rows)) . ' transaksi dipilih',
        'generated' => spp_date_label(new DateTimeImmutable('now'),true),
        'row_count' => count($rows),
        'orientation' => 'landscape',
        'download_url' => 'export_pdf.php?' . http_build_query($downloadQuery),
        'back_url' => 'index.php?' . http_build_query($backQuery),
    ]);
}

require_once __DIR__ . '/../includes/pdf.php';
require_pdf_library();

$options = new \Dompdf\Options();
$options->setDefaultMediaType('print');
$options->setFontHeightRatio(1.0);
$options->setIsHtml5ParserEnabled(true);
$options->setIsRemoteEnabled(false);
$options->setChroot(realpath(__DIR__ . '/..'));

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper([0, 0, 612, 396]);
$dompdf->render();
$dompdf->stream(sprintf('slip-pembayaran-%04d-%02d.pdf', $filter_tahun, $filter_bulan), ['Attachment' => true]);
exit;
?>
