<?php
require_once __DIR__.'/excel_test_helpers.php';
/** Read-only HTTP checks. Point SPP_HTTP_BASE to a server using the same disposable DB. */
if (PHP_SAPI !== 'cli' || !preg_match('/^db_spp_audit_[a-z0-9_]+$/i', (string)getenv('SPP_DB_NAME'))
    || !preg_match('#^http://127\.0\.0\.1:\d+/?$#', (string)getenv('SPP_HTTP_BASE'))) {
    fwrite(STDERR, "SKIPPED: use an audit database and local SPP_HTTP_BASE.\n");
    exit(0);
}
require_once __DIR__ . '/../koneksi.php';

function finance_http_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function finance_http_get(string $url, string $cookie): array {
    $context = stream_context_create(['http'=>[
        'method'=>'GET', 'ignore_errors'=>true, 'follow_location'=>0, 'timeout'=>30,
        'header'=>'Cookie: '.session_name().'='.$cookie."\r\n",
    ]]);
    $body = file_get_contents($url, false, $context);
    return [$http_response_header ?? [], $body === false ? '' : $body];
}
function finance_http_ok(array $response, string $label): string {
    finance_http_assert(str_contains($response[0][0] ?? '', '200'), $label.' tidak menghasilkan HTTP 200.');
    return $response[1];
}
function finance_http_pdf_text(string $pdf): string {
    $pdfFile = tempnam(sys_get_temp_dir(), 'spp_du_pdf_');
    $textFile = tempnam(sys_get_temp_dir(), 'spp_du_text_');
    finance_http_assert($pdfFile !== false && $textFile !== false, 'Berkas sementara PDF tidak dapat dibuat.');
    try {
        finance_http_assert(file_put_contents($pdfFile, $pdf) === strlen($pdf), 'PDF tidak dapat disimpan untuk pemeriksaan.');
        $process = proc_open([getenv('SPP_PDFTOTEXT') ?: 'pdftotext', '-layout', $pdfFile, $textFile],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        finance_http_assert(is_resource($process), 'pdftotext tidak tersedia untuk memeriksa PDF biner.');
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        finance_http_assert(proc_close($process) === 0, 'Ekstraksi PDF gagal: '.trim($error));
        return (string)file_get_contents($textFile);
    } finally {
        if ($pdfFile !== false) unlink($pdfFile);
        if ($textFile !== false) unlink($textFile);
    }
}

$failure = null;
$sessionId = 'financereport'.bin2hex(random_bytes(8));
$fixtureNis = '';
$fixturePaymentId = 0;
$duFixtureNis = '';
$duFixturePaymentIds = [];
$duFixtureBillIds = [];
$duFixturePlacementIds = [];
try {
    $_SESSION['active_unit_id'] = 1;
    unit_set_context($koneksi, 1);
    $account = $koneksi->query("SELECT id FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    finance_http_assert((bool)$account, 'Akun admin SD tidak tersedia.');
    session_id($sessionId);session_start();
    $_SESSION = ['admin_id'=>(int)$account['id'],'admin_role'=>'admin','admin_nama'=>'Uji Rekap'];
    session_write_close();

    $start = '2026-07-01';$end = '2027-02-28';
    $query = http_build_query(['tanggal_awal'=>$start,'tanggal_akhir'=>$end,'bulan'=>'02','tahun'=>'2027']);
    $stats = $koneksi->query("SELECT COALESCE(SUM(total_jumlah),0) total FROM bayar WHERE TGL_BYR >= '$start 00:00:00' AND TGL_BYR < '2027-03-01 00:00:00'")->fetch_assoc();
    $du = $koneksi->query("SELECT COALESCE(SUM(d.jumlah),0) total FROM bayar_du d JOIN bayar b ON b.id=d.bayar_id WHERE b.TGL_BYR >= '$start 00:00:00' AND b.TGL_BYR < '2027-03-01 00:00:00'")->fetch_assoc();
    finance_http_assert((float)$du['total'] > 0, 'Fixture clone tidak memuat Daftar Ulang.');
    $totalText = number_format((float)$stats['total'],0,',','.');
    $duText = number_format((float)$du['total'],0,',','.');
    $base = rtrim((string)getenv('SPP_HTTP_BASE'), '/').'/';

    $index = finance_http_ok(finance_http_get($base.'laporan/index.php?'.$query,$sessionId),'Laporan Umum');
    finance_http_assert(str_contains($index, 'Daftar Ulang') && str_contains($index, $duText)
        && !str_contains($index, 'Titipan SPP')
        && str_contains($index, $totalText), 'Komponen Laporan Umum tidak cocok dengan total transaksi.');
    $excel = finance_http_ok(finance_http_get($base.'laporan/export_excel.php?download=1&'.$query,$sessionId),'Excel Laporan Umum');
    $excel=test_excel_html($excel,true);
    finance_http_assert(str_contains($excel, '<td>Daftar Ulang</td><td>'.$duText.'</td>')
        && !str_contains($excel, 'Titipan SPP')
        && str_contains($excel, $totalText), 'Rincian Excel tidak cocok dengan komponen dan total transaksi.');
    $unpaid = finance_http_ok(finance_http_get($base.'laporan/index.php?'.http_build_query(['jenis_laporan'=>'belum_biaya_lain','tanggal_awal'=>$start,'tanggal_akhir'=>$end]),$sessionId),'Biaya Lain belum lunas');
    $count = $koneksi->query("SELECT COUNT(*) total FROM (SELECT t.id FROM tagihan_biaya_lain t JOIN siswa s ON s.NO_INDUK=t.no_induk LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id WHERE t.status='open' AND s.is_active=1 GROUP BY t.id,t.nominal_tagihan HAVING t.nominal_tagihan>COALESCE(SUM(d.nominal_snapshot),0)) x")->fetch_assoc();
    finance_http_assert(str_contains($unpaid, number_format((int)$count['total']).' siswa/tagihan'),
        'Jumlah Biaya Lain belum lunas tidak mengikuti tagihan siswa yang terbit.');
    foreach (['belum_spp','belum_komite','belum_du'] as $type) {
        $unpaidPage=finance_http_ok(finance_http_get($base.'laporan/index.php?'.http_build_query(['jenis_laporan'=>$type,'bulan'=>'08','tahun'=>'2026','tanggal_awal'=>'2026-08-01','tanggal_akhir'=>'2026-08-31']),$sessionId),$type);
        finance_http_assert(str_contains($unpaidPage,'siswa/tagihan') && !str_contains($unpaidPage,'Fatal error'),
            'Laporan '.$type.' tidak merender data tagihan.');
    }

    $payment = $koneksi->query("SELECT b.id,b.NO_INDUK,DATE(b.TGL_BYR) tanggal,b.kelas_rombel_snapshot FROM bayar b JOIN siswa s ON s.NO_INDUK=b.NO_INDUK WHERE b.kelas_rombel_snapshot IS NOT NULL AND b.kelas_rombel_snapshot<>'' AND b.kelas_rombel_snapshot<>s.KELAS ORDER BY b.id LIMIT 1")->fetch_assoc();
    finance_http_assert((bool)$payment, 'Transaksi dengan snapshot rombel tidak tersedia.');
    $single = http_build_query(['q'=>$payment['NO_INDUK'],'tanggal_awal'=>$payment['tanggal'],'tanggal_akhir'=>$payment['tanggal']]);
    $history = finance_http_ok(finance_http_get($base.'pembayaran/lihat.php?'.http_build_query(['search'=>$payment['NO_INDUK'],'tanggal_awal'=>$payment['tanggal'],'tanggal_akhir'=>$payment['tanggal'],'per_page'=>50]),$sessionId),'Riwayat Pembayaran');
    finance_http_assert(str_contains($history,'Kelas '.htmlspecialchars($payment['kelas_rombel_snapshot'],ENT_QUOTES,'UTF-8')),
        'Riwayat Pembayaran tidak menampilkan rombel snapshot transaksi.');
    $singleIndex = finance_http_ok(finance_http_get($base.'laporan/index.php?'.$single,$sessionId),'Detail Laporan Umum');
    finance_http_assert(str_contains($singleIndex,'Kelas '.htmlspecialchars($payment['kelas_rombel_snapshot'],ENT_QUOTES,'UTF-8')),
        'Detail Laporan Umum tidak menampilkan rombel transaksi.');
    $singleExcel = finance_http_ok(finance_http_get($base.'laporan/export_excel.php?download=1&'.$single,$sessionId),'Excel satu siswa');
    $singleExcel=test_excel_html($singleExcel,true);
    finance_http_assert(str_contains($singleExcel,'<td>'.htmlspecialchars($payment['kelas_rombel_snapshot'],ENT_QUOTES,'UTF-8').'</td>'),
        'Excel satu siswa tidak menampilkan rombel transaksi.');
    $receipt = finance_http_ok(finance_http_get($base.'laporan/export_pdf.php?'.http_build_query(['output'=>'preview','mode'=>'selected','ids'=>[$payment['id']],'tanggal_awal'=>$payment['tanggal'],'tanggal_akhir'=>$payment['tanggal']]),$sessionId),'Pratinjau struk PDF massal');
    finance_http_assert(str_contains($receipt,htmlspecialchars($payment['kelas_rombel_snapshot'],ENT_QUOTES,'UTF-8')),
        'Pratinjau struk PDF massal tidak memakai rombel transaksi.');
    $singleReceipt = finance_http_ok(finance_http_get($base.'laporan/cetak_struk.php?id='.(int)$payment['id'],$sessionId),'Struk tunggal');
    finance_http_assert(preg_match('/Kelas<\/td>.*?'.preg_quote(htmlspecialchars($payment['kelas_rombel_snapshot'],ENT_QUOTES,'UTF-8'),'/').'<\/td>/s',$singleReceipt)===1,
        'Struk tunggal tidak memakai rombel transaksi.');
    $pdf = finance_http_ok(finance_http_get($base.'laporan/export_pdf.php?'.http_build_query(['output'=>'pdf','mode'=>'selected','ids'=>[$payment['id']],'tanggal_awal'=>$payment['tanggal'],'tanggal_akhir'=>$payment['tanggal']]),$sessionId),'PDF struk massal');
    finance_http_assert(str_starts_with($pdf,'%PDF-'),'Struk massal tidak menghasilkan PDF.');

    if (getenv('SPP_TEST_ALLOW_MUTATION') === '1') {
        // Reporting fixture only. It has no real bill and is removed immediately after the HTTP checks.
        $fixtureNis = (string)random_int(9800000000, 9899999999);
        $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active) VALUES(?,'UJI POTONGAN REKAP','1',1)");
        $stmt->bind_param('s',$fixtureNis);$stmt->execute();$stmt->close();
        $date = '2099-12-30 08:00:00';$operator=(string)$account['id'];
        $stmt = $koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,kelas_rombel_snapshot,U_SPP,potong_spp,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran) VALUES(?,'1','1A',100,20,80,?,'12','2099',?,'Tunai')");
        $stmt->bind_param('sss',$fixtureNis,$date,$operator);$stmt->execute();$fixturePaymentId=(int)$koneksi->insert_id;$stmt->close();
        $discountQuery = http_build_query(['q'=>$fixtureNis,'tanggal_awal'=>'2099-12-30','tanggal_akhir'=>'2099-12-30']);
        $discountIndex = finance_http_ok(finance_http_get($base.'laporan/index.php?'.$discountQuery,$sessionId),'Laporan potongan');
        finance_http_assert(str_contains($discountIndex,'Potongan SPP') && str_contains($discountIndex,'Rp -20')
            && str_contains($discountIndex,'Rp 80'),'Laporan Umum tidak merekonsiliasi potongan ke kas Rp 80.');
        $discountExcel = finance_http_ok(finance_http_get($base.'laporan/export_excel.php?download=1&'.$discountQuery,$sessionId),'Excel potongan');
    $discountExcel=test_excel_html($discountExcel,true);
        finance_http_assert(str_contains($discountExcel,'<td>Potongan SPP</td><td>-20</td>')
            && str_contains($discountExcel,'<td>Uang SPP</td><td>100</td>')
            && str_contains($discountExcel,'<td>80</td>'),'Excel tidak menjumlahkan SPP dan potongan menjadi kas Rp 80.');

        $duFixtureNis = (string)random_int(9800000000, 9899999999);
        $classes = [];
        foreach ([1,2] as $level) {
            $stmt = $koneksi->prepare("SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel='A' AND is_placeholder=0 LIMIT 1");
            $stmt->bind_param('i',$level);$stmt->execute();
            $classes[$level]=(int)($stmt->get_result()->fetch_assoc()['id']??0);$stmt->close();
        }
        finance_http_assert($classes[1]>0 && $classes[2]>0,'Rombel 1A/2A tidak tersedia untuk fixture DU.');
        $stmt=$koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,DAFTAR_ULANG,tot_du,is_active) VALUES(?,'UJI STRUK DU HISTORIS','2',?,2000,2000,1)");
        $stmt->bind_param('si',$duFixtureNis,$classes[2]);$stmt->execute();$stmt->close();
        $years=[];
        foreach (['2026/2027','2027/2028'] as $yearLabel) {
            $stmt=$koneksi->prepare('SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1');
            $stmt->bind_param('s',$yearLabel);$stmt->execute();
            $years[$yearLabel]=(int)($stmt->get_result()->fetch_assoc()['id']??0);$stmt->close();
        }
        finance_http_assert($years['2026/2027']>0 && $years['2027/2028']>0,'Dua tahun ajaran fixture DU tidak tersedia.');
        $batchToken=bin2hex(random_bytes(16));
        foreach ([['2026/2027',1,'1A',1000.0,400.0,'2026-08-01 08:00:00'],['2027/2028',2,'2A',2000.0,500.0,'2027-08-01 08:00:00']] as $index=>[$yearLabel,$level,$classLabel,$bill,$amount,$paymentAt]) {
            $yearId=$years[$yearLabel];$classId=$classes[$level];$levelText=(string)$level;
            $stmt=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,'aktif')");
            $stmt->bind_param('issis',$yearId,$duFixtureNis,$levelText,$classId,$classLabel);
            $stmt->execute();$placementId=(int)$koneksi->insert_id;$duFixturePlacementIds[]=$placementId;$stmt->close();
            $stmt=$koneksi->prepare('INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,?)');
            $stmt->bind_param('iisssdd',$yearId,$placementId,$duFixtureNis,$levelText,$yearLabel,$bill,$bill);
            $stmt->execute();$billId=(int)$koneksi->insert_id;$duFixtureBillIds[]=$billId;$stmt->close();
            $stmt=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran) VALUES(?,?,?,?,?,?,'08',?,?, 'Tunai')");
            $paymentYear=substr($paymentAt,0,4);
            $stmt->bind_param('ssisdsss',$duFixtureNis,$levelText,$classId,$classLabel,$amount,$paymentAt,$paymentYear,$operator);
            $stmt->execute();$paymentId=(int)$koneksi->insert_id;$duFixturePaymentIds[]=$paymentId;$stmt->close();
            $sequence=$index+1;
            $stmt=$koneksi->prepare('UPDATE bayar SET payment_batch_token=?,payment_batch_sequence=?,payment_batch_count=2 WHERE id=?');
            $stmt->bind_param('sii',$batchToken,$sequence,$paymentId);$stmt->execute();$stmt->close();
            $stmt=$koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,?,?)');
            $stmt->bind_param('iisssd',$paymentId,$billId,$duFixtureNis,$levelText,$yearLabel,$amount);
            $stmt->execute();$stmt->close();
        }
        foreach ([['2026/2027','1A','Rp 1.000','Rp 400','Rp 600'],['2027/2028','2A','Rp 2.000','Rp 500','Rp 1.500']] as [$yearLabel,$classLabel,$billText,$paidText,$dueText]) {
            $sourceStmt = $koneksi->prepare('SELECT t.nominal_tagihan tagihan,sta.kelas_rombel_snapshot kelas,t.tahun_ajaran_snapshot tahun,COALESCE(SUM(d.jumlah),0) terbayar FROM tagihan_daftar_ulang t JOIN siswa_tahun_ajaran sta ON sta.id=t.penempatan_id LEFT JOIN bayar_du d ON d.tagihan_daftar_ulang_id=t.id WHERE t.no_induk=? AND t.tahun_ajaran_snapshot=? GROUP BY t.id,t.nominal_tagihan,sta.kelas_rombel_snapshot,t.tahun_ajaran_snapshot');
            $sourceStmt->bind_param('ss',$duFixtureNis,$yearLabel);$sourceStmt->execute();
            $sourceRows=$sourceStmt->get_result()->fetch_all(MYSQLI_ASSOC);$sourceStmt->close();
            finance_http_assert(count($sourceRows)===1,'Sumber tagihan DU '.$yearLabel.' harus tepat satu baris.');
            $source=$sourceRows[0];$sourceDue=max(0,(float)$source['tagihan']-(float)$source['terbayar']);
            finance_http_assert($source['kelas']===$classLabel && $source['tahun']===$yearLabel
                && ('Rp '.number_format((float)$source['tagihan'],0,',','.'))===$billText
                && ('Rp '.number_format((float)$source['terbayar'],0,',','.'))===$paidText
                && ('Rp '.number_format($sourceDue,0,',','.'))===$dueText,
                'Fixture sumber DU '.$yearLabel.' tidak cocok dengan nominal/kelas yang diuji.');
            $itemQuery = http_build_query(['template'=>'per-item','kategori'=>'daftar_ulang',
                'tahun_ajaran'=>$yearLabel,'siswa_status'=>'all','q'=>$duFixtureNis,
                'tanggal_awal'=>'2099-12-30','tanggal_akhir'=>'2099-12-30']);
            $screen = finance_http_ok(finance_http_get($base.'laporan/template.php?'.$itemQuery,$sessionId),'Per Item DU '.$yearLabel);
            $excelItem = finance_http_ok(finance_http_get($base.'laporan/export_global.php?'.$itemQuery.'&format=excel&download=1',$sessionId),'Excel Per Item DU '.$yearLabel);
    $excelItem=test_excel_html($excelItem,true);
            $previewItem = finance_http_ok(finance_http_get($base.'laporan/export_global.php?'.$itemQuery.'&format=preview',$sessionId),'Pratinjau PDF Per Item DU '.$yearLabel);
            foreach ([$screen,$excelItem,$previewItem] as $body) {
                finance_http_assert(str_contains($body,'UJI STRUK DU HISTORIS')
                    && str_contains($body,$classLabel)
                    && str_contains($body,$billText)
                    && str_contains($body,$paidText)
                    && str_contains($body,$dueText),
                    'Layar/Excel/pratinjau Per Item DU tidak cocok dengan tagihan '.$yearLabel.'.');
            }
            $pdfResponse = finance_http_get($base.'laporan/export_global.php?'.$itemQuery.'&format=pdf&download=1',$sessionId);
            $pdfItem = finance_http_ok($pdfResponse,'PDF Per Item DU '.$yearLabel);
            finance_http_assert(str_contains(strtolower(implode("\n",$pdfResponse[0])), 'application/pdf')
                && str_starts_with($pdfItem,'%PDF-'),
                'Unduhan Per Item DU '.$yearLabel.' bukan PDF biner.');
            $pdfText = finance_http_pdf_text($pdfItem);
            foreach (['UJI STRUK DU HISTORIS',$duFixtureNis,$yearLabel,$classLabel,$billText,$paidText,$dueText] as $expected) {
                finance_http_assert(str_contains($pdfText,$expected),
                    'Teks PDF Per Item DU '.$yearLabel.' tidak memuat '.$expected.'.');
            }
            $otherYear=$yearLabel==='2026/2027'?'2027/2028':'2026/2027';
            $otherClass=$yearLabel==='2026/2027'?'2A':'1A';
            finance_http_assert(!str_contains($pdfText,$otherYear)
                && preg_match('/\b'.preg_quote($otherClass,'/').'\b/',$pdfText)!==1,
                'PDF Per Item DU '.$yearLabel.' mencampur tahun/kelas tagihan lain.');
        }
        $oldReceipt = finance_http_ok(finance_http_get($base.'laporan/export_pdf.php?'.http_build_query(['output'=>'preview','mode'=>'selected','ids'=>[$duFixturePaymentIds[0]],'tanggal_awal'=>'2026-08-01','tanggal_akhir'=>'2026-08-01']),$sessionId),'Struk DU tahun asal');
        $oldReceipt=html_entity_decode($oldReceipt,ENT_QUOTES|ENT_HTML5,'UTF-8');
        finance_http_assert(str_contains($oldReceipt,'Uang Daftar Ulang (TA 2026/2027)')
            && str_contains($oldReceipt,'>1A</td>')
            && preg_match('/Sisa DU<\/td>.*?pay-amount">600<\/td>/s',$oldReceipt)===1,
            'Struk DU tahun asal tidak memakai kelas dan sisa tagihan DU tahun asal.');
        $oldSingleReceipt=finance_http_ok(finance_http_get($base.'laporan/cetak_struk.php?id='.$duFixturePaymentIds[0],$sessionId),'Struk tunggal DU lama');
        finance_http_assert(str_contains($oldSingleReceipt,'1A') && str_contains($oldSingleReceipt,'Sisa DU (TA 2026/2027)')
            && str_contains($oldSingleReceipt,'600'),'Struk tunggal DU lama tidak cocok dengan tagihan asal.');
        $annualReceipts=finance_http_ok(finance_http_get($base.'laporan/cetak_struk_tahunan.php?batch='.$batchToken,$sessionId),'Struk batch');
        finance_http_assert(str_contains($annualReceipts,'>1A</td>') && str_contains($annualReceipts,'>2A</td>'),
            'Struk batch menampilkan kelas siswa saat ini untuk kedua transaksi.');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($fixturePaymentId > 0) $koneksi->query('DELETE FROM bayar WHERE id='.$fixturePaymentId);
    if ($fixtureNis !== '') {
        $stmt=$koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
        $stmt->bind_param('s',$fixtureNis);$stmt->execute();$stmt->close();
    }
    foreach ($duFixturePaymentIds as $id) $koneksi->query('DELETE FROM bayar WHERE id='.(int)$id);
    foreach ($duFixtureBillIds as $id) $koneksi->query('DELETE FROM tagihan_daftar_ulang WHERE id='.(int)$id);
    foreach ($duFixturePlacementIds as $id) $koneksi->query('DELETE FROM siswa_tahun_ajaran WHERE id='.(int)$id);
    if ($duFixtureNis !== '') {
        $stmt=$koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
        $stmt->bind_param('s',$duFixtureNis);$stmt->execute();$stmt->close();
    }
    session_id($sessionId);session_start();$_SESSION=[];session_destroy();session_write_close();
}
if ($failure) {fwrite(STDERR,'FAILED: '.$failure->getMessage().PHP_EOL);exit(1);}
echo "OK: komponen Laporan Umum/Excel, tagihan Biaya Lain, kelas historis detail, riwayat, dan struk PDF"
    .(getenv('SPP_TEST_ALLOW_MUTATION')==='1'?', termasuk potongan SPP, DU lintas tahun, dan Per Item DU pada layar/Excel/PDF biner':'').".\n";
