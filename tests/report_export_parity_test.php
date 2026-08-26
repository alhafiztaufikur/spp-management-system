<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
require_once __DIR__ . '/../includes/reports.php';

test_require_audit_database($koneksi);

function parity_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function parity_amount(float $actual, float $expected, string $message): void
{
    parity_assert(abs($actual - $expected) < 0.01, $message . " (aktual=$actual, expected=$expected)");
}

function parity_rows_by_nis(array $rows): array
{
    $indexed = [];
    foreach ($rows as $row) $indexed[(string)$row['nis']] = $row;
    return $indexed;
}

function parity_insert_payment(mysqli $db, array $row): int
{
    $stmt = $db->prepare("INSERT INTO bayar
        (NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_PANGKAL,U_SPP,U_KOMITE,
         KETERANGAN,TGL_BYR,BULAN,user_id,sistem_pembayaran,TAHUN,potong_spp,total_jumlah,payment_link_version)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param(
        'ssisdddssssssddi',
        $row['nis'], $row['kelas'], $row['class_id'], $row['class_snapshot'],
        $row['pangkal'], $row['spp'], $row['komite'], $row['note'], $row['date'],
        $row['month'], $row['operator'], $row['method'], $row['year'], $row['discount'],
        $row['total'], $row['version']
    );
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

$koneksi->begin_transaction();
try {
    $operator = $koneksi->query("SELECT id,nama FROM admin ORDER BY id LIMIT 1")->fetch_assoc();
    parity_assert((bool)$operator, 'Fixture memerlukan minimal satu admin.');
    $operatorId = (int)$operator['id'];
    $operatorText = (string)$operatorId;

    $stmt = $koneksi->prepare("INSERT INTO master_kelas(tingkat,kode_rombel,is_placeholder,is_active) VALUES (6,?,0,1)");
    $code = 'W5A'; $stmt->bind_param('s', $code); $stmt->execute(); $classA = (int)$koneksi->insert_id;
    $code = 'W5B'; $stmt->execute(); $classB = (int)$koneksi->insert_id; $stmt->close();

    $years = [
        ['2098/2099', '2098-07-01', '2099-06-30', 'closed'],
        ['2099/2100', '2099-07-01', '2100-06-30', 'published'],
    ];
    $yearIds = [];
    $stmt = $koneksi->prepare('INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status,published_at,closed_at) VALUES (?,?,?,?,NOW(),?)');
    foreach ($years as [$label, $start, $end, $status]) {
        $closedAt = $status === 'closed' ? '2099-07-01 00:00:00' : null;
        $stmt->bind_param('sssss', $label, $start, $end, $status, $closedAt);
        $stmt->execute(); $yearIds[$label] = (int)$koneksi->insert_id;
    }
    $stmt->close();

    $students = [
        ['W500000001', '=2+3 Oracle A', $classA, 1, 500000.0],
        ['W500000002', 'Oracle B', $classB, 1, 600000.0],
        ['W500000003', 'Oracle Tanpa Bayar', $classA, 1, 700000.0],
        ['W500000004', 'Oracle Arsip', $classB, 0, 400000.0],
    ];
    $stmt = $koneksi->prepare("INSERT INTO siswa
        (NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,PANGKAL,PANGKAL_BAYAR,POMG,DAFTAR_ULANG,is_active)
        VALUES (?,?,6,?,900000,1000000,900000,99000,1000000,?)");
    foreach ($students as [$nis, $name, $classId, $active]) {
        $stmt->bind_param('ssii', $nis, $name, $classId, $active); $stmt->execute();
    }
    $stmt->close();

    $placementIds = [];
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status)
        VALUES (?,?,6,?,?,?,?,?)");
    foreach ($students as [$nis, $name, $classId, $active, $rate]) {
        $snapshot = $classId === $classA ? '6W5A' : '6W5B';
        $status = $active ? 'aktif' : 'lulus'; $committee = $classId === $classA ? 20000.0 : 30000.0;
        $stmt->bind_param('isisdds', $yearIds['2099/2100'], $nis, $classId, $snapshot, $rate, $committee, $status);
        $stmt->execute(); $placementIds[$nis] = (int)$koneksi->insert_id;
    }
    $previousStudents = [
        ['W500000001', $classA, '6W5A', 450000.0, 'aktif'],
        ['W500000004', $classB, '6W5B', 400000.0, 'lulus'],
    ];
    foreach ($previousStudents as [$nis, $classId, $snapshot, $rate, $status]) {
        $committee = 15000.0;
        $stmt->bind_param('isisdds', $yearIds['2098/2099'], $nis, $classId, $snapshot, $rate, $committee, $status);
        $stmt->execute();
    }
    $stmt->close();

    $label = '2099/2100'; $grade = '6'; $duAmount = 1000000.0;
    $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES (?,?,?,?)');
    $stmt->bind_param('issd', $yearIds[$label], $label, $grade, $duAmount); $stmt->execute();
    $duMaster = (int)$koneksi->insert_id; $stmt->close();
    $duBills = [];
    $stmt = $koneksi->prepare("INSERT INTO tagihan_daftar_ulang
        (tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan,status)
        VALUES (?,?,?,?,?,?,?,?,'open')");
    foreach (array_slice($students, 0, 3) as [$nis]) {
        $stmt->bind_param('iiisssdd', $yearIds[$label], $placementIds[$nis], $duMaster, $nis, $grade, $label, $duAmount, $duAmount);
        $stmt->execute(); $duBills[$nis] = (int)$koneksi->insert_id;
    }
    $stmt->close();

    $otherName = 'W5 Oracle Kegiatan'; $otherBill = 200000.0;
    $stmt = $koneksi->prepare('INSERT INTO master_biaya_lain(nama,nominal,is_active) VALUES (?,?,1)');
    $stmt->bind_param('sd', $otherName, $otherBill); $stmt->execute(); $otherMaster = (int)$koneksi->insert_id; $stmt->close();
    $otherBills = [];
    $stmt = $koneksi->prepare("INSERT INTO tagihan_biaya_lain
        (master_biaya_lain_id,no_induk,master_kelas_id,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot,status,created_by)
        VALUES (?,?,?,?,?,?,'open',?)");
    foreach (array_slice($students, 0, 3) as [$nis, $name, $classId]) {
        $snapshot = $classId === $classA ? '6W5A' : '6W5B';
        $stmt->bind_param('isisdsi', $otherMaster, $nis, $classId, $otherName, $otherBill, $snapshot, $operatorId);
        $stmt->execute(); $otherBills[$nis] = (int)$koneksi->insert_id;
    }
    $stmt->close();

    $base = ['kelas'=>'6','class_id'=>$classA,'class_snapshot'=>'6W5A','pangkal'=>0.0,'spp'=>0.0,'komite'=>0.0,'note'=>'W5 ORACLE','month'=>'08','operator'=>$operatorText,'method'=>'Tunai','year'=>'2099','discount'=>0.0,'total'=>0.0,'version'=>1];
    $p1 = parity_insert_payment($koneksi, array_merge($base, ['nis'=>'W500000001','pangkal'=>100000.0,'spp'=>300000.0,'komite'=>20000.0,'date'=>'2099-08-15 00:00:00','discount'=>25000.0,'total'=>845000.0]));
    $p2 = parity_insert_payment($koneksi, array_merge($base, ['nis'=>'W500000001','spp'=>200000.0,'date'=>'2099-08-15 23:59:59','method'=>'VA','total'=>325000.0]));
    $p3 = parity_insert_payment($koneksi, array_merge($base, ['nis'=>'W500000002','class_id'=>$classB,'class_snapshot'=>'6W5B','spp'=>500000.0,'date'=>'2099-08-16 00:00:00','method'=>'Qris','total'=>575000.0]));
    $overpay = parity_insert_payment($koneksi, array_merge($base, ['nis'=>'W500000001','spp'=>5500000.0,'date'=>'2099-07-20 10:00:00','month'=>'07','method'=>'VA','total'=>5500000.0]));
    parity_insert_payment($koneksi, array_merge($base, ['nis'=>'W500000004','class_id'=>$classB,'class_snapshot'=>'6W5B','spp'=>400000.0,'date'=>'2098-08-10 10:00:00','year'=>'2098','version'=>0,'total'=>400000.0]));

    $stmt = $koneksi->prepare('INSERT INTO bayar_spp_periode(bayar_id,no_induk,bulan,tahun) VALUES (?,?,?,?)');
    foreach ([[$p1,'W500000001','08','2099'],[$p2,'W500000001','08','2099'],[$p3,'W500000002','08','2099'],[$overpay,'W500000001','07','2099']] as [$paymentId,$nis,$month,$year]) {
        $stmt->bind_param('isss', $paymentId, $nis, $month, $year); $stmt->execute();
    }
    $stmt->close();

    $stmt = $koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES (?,?,?,?,?,?)');
    foreach ([[$p1,400000.0],[$p2,100000.0]] as [$paymentId,$amount]) {
        $nis = 'W500000001'; $stmt->bind_param('iisssd', $paymentId, $duBills[$nis], $nis, $grade, $label, $amount); $stmt->execute();
    }
    $stmt->close();

    $stmt = $koneksi->prepare("INSERT INTO bayar_biaya_lain
        (bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot,keterangan,urutan)
        VALUES (?,?,?,?,?,'W5 ORACLE',1)");
    foreach ([[$p1,'W500000001',50000.0],[$p2,'W500000001',25000.0],[$p3,'W500000002',75000.0]] as [$paymentId,$nis,$amount]) {
        $stmt->bind_param('iiisd', $paymentId, $otherMaster, $otherBills[$nis], $otherName, $amount); $stmt->execute();
    }
    $stmt->close();

    $stmt = $koneksi->prepare('INSERT INTO tabungan(NO_INDUK,SALDO) VALUES (?,?)');
    foreach ([['W500000001',130000.0],['W500000002',60000.0]] as [$nis,$balance]) {$stmt->bind_param('sd',$nis,$balance);$stmt->execute();}
    $stmt->close();
    $stmt = $koneksi->prepare('INSERT INTO transaksi_m(bayar_id,NO_INDUK,TANGGAL,MASUK,user_id) VALUES (?,?,?,?,?)');
    foreach ([[null,'W500000001','2099-07-31 23:59:59',100000.0], [null,'W500000001','2099-08-15 12:00:00',40000.0], [null,'W500000001','2099-08-16 00:00:00',20000.0], [null,'W500000002','2099-08-15 13:00:00',60000.0], [$p1,'W500000001','2099-08-15 14:00:00',999000.0]] as [$paymentId,$nis,$date,$amount]) {
        $stmt->bind_param('issds',$paymentId,$nis,$date,$amount,$operatorText);$stmt->execute();
    }
    $stmt->close();
    $stmt = $koneksi->prepare('INSERT INTO transaksi_k(NO_INDUK,TANGGAL,KELUAR,user_id) VALUES (?,?,?,?)');
    $nis='W500000001';$date='2099-08-15 12:00:00';$amount=30000.0;$stmt->bind_param('ssds',$nis,$date,$amount,$operatorText);$stmt->execute();$stmt->close();

    $filters = report_filters($koneksi, ['tanggal_awal'=>'2099-08-15','tanggal_akhir'=>'2099-08-15','tahun_ajaran'=>'2099/2100','tahun'=>2099,'bulan_awal'=>'08','bulan_akhir'=>'08','kategori'=>'semua','siswa_status'=>'active']);
    parity_assert($filters['tanggal_awal']==='2099-08-15' && $filters['tanggal_akhir']==='2099-08-15', 'Filter tanggal oracle berubah.');
    $classDefaults=report_template_defaults('per-item',[],array_merge($filters,['kelas'=>0]),[['id'=>91,'is_placeholder'=>1],['id'=>92,'is_placeholder'=>0]]);
    parity_assert($classDefaults['filters']['kelas']===92&&!$classDefaults['missing_rombel'],'Default rombel web/export tidak konsisten.');
    $allClassDefaults=report_template_defaults('per-item',['kelas'=>'0'],array_merge($filters,['kelas'=>0]),[['id'=>92,'is_placeholder'=>0]]);
    parity_assert($allClassDefaults['filters']['kelas']===0,'Filter eksplisit semua rombel berubah menjadi rombel pertama.');
    $reversed = report_filters($koneksi, ['tanggal_awal'=>'2099-08-16','tanggal_akhir'=>'2099-08-15']);
    parity_assert($reversed['tanggal_awal']==='2099-08-15' && $reversed['tanggal_akhir']==='2099-08-16', 'Rentang terbalik tidak dinormalisasi.');
    $invalid = report_filters($koneksi, ['tanggal_awal'=>'2099-02-31','tahun'=>999999]);
    parity_assert($invalid['tanggal_awal']!== '2099-02-31' && $invalid['tahun']===(int)date('Y'), 'Tanggal/tahun kalender invalid tidak ditolak.');

    $components = report_payment_components($koneksi, $filters);
    parity_assert(count($components)===9, 'Jumlah baris komponen satu hari tidak sesuai oracle.');
    parity_amount(array_sum(array_column($components,'nominal')),1170000.0,'Grand total komponen satu hari salah.');
    parity_assert(array_values(array_unique(array_column($components,'id')))===[$p1,$p2], 'Urutan pembayaran tidak stabil berdasarkan waktu lalu ID.');
    parity_assert($components[0]['operator']===(string)$operator['nama'], 'Operator tidak dipetakan ke nama admin.');

    $cashFilters=array_merge($filters,['metode'=>'Tunai']);$cashRows=report_payment_components($koneksi,$cashFilters);
    parity_amount(array_sum(array_column($cashRows,'nominal')),845000.0,'Filter metode Tunai salah.');
    $operatorFilters=array_merge($filters,['operator'=>$operatorText]);
    parity_assert(count(report_payment_components($koneksi,$operatorFilters))===9,'Filter operator exact match salah.');
    $searchReport=report_receipt_data($koneksi,array_merge($filters,['q'=>'Oracle A']));
    parity_assert(count($searchReport['rows'])===9,'Filter nama/NIS pada penerimaan salah.');
    $empty=report_receipt_data($koneksi,array_merge($filters,['tanggal_awal'=>'2097-01-01','tanggal_akhir'=>'2097-01-01']));
    parity_assert($empty['rows']===[],'Dataset kosong masih menghasilkan baris transaksi.');

    $status=parity_rows_by_nis(report_status_data($koneksi,array_merge($filters,['kategori'=>'spp']))['rows']);
    parity_amount((float)$status['W500000001']['tagihan'],500000.0,'Status SPP tidak memakai snapshot.');
    parity_amount((float)$status['W500000001']['terbayar'],500000.0,'Status SPP cicilan tidak menjumlah claim.');
    parity_assert($status['W500000001']['status']==='Lunas' && $status['W500000002']['status']==='Cicilan' && $status['W500000003']['status']==='Belum Bayar','Status SPP oracle salah.');
    parity_assert(!isset($status['W500000004']),'Siswa arsip bocor ke filter aktif.');
    $pangkal=parity_rows_by_nis(report_status_data($koneksi,array_merge($filters,['kategori'=>'pangkal']))['rows']);
    parity_amount((float)$pangkal['W500000001']['terbayar'],100000.0,'Status Uang Pangkal memakai cache siswa, bukan ledger bayar.');
    $du=parity_rows_by_nis(report_status_data($koneksi,array_merge($filters,['kategori'=>'daftar_ulang']))['rows']);
    parity_amount((float)$du['W500000001']['terbayar'],500000.0,'Status DU tidak menjumlah dua cicilan child.');
    $other=parity_rows_by_nis(report_status_data($koneksi,array_merge($filters,['kategori'=>'biaya_lain:'.$otherMaster]))['rows']);
    parity_amount((float)$other['W500000001']['terbayar'],75000.0,'Status Biaya Lain tidak menjumlah dua cicilan detail.');

    $annual=parity_rows_by_nis(report_spp_year_data($koneksi,array_merge($filters,['kategori'=>'spp']))['rows']);
    parity_amount((float)$annual['W500000001']['total_tagihan'],6000000.0,'Tagihan tahunan tidak memakai 12 snapshot.');
    parity_amount((float)$annual['W500000001']['total_bayar'],6000000.0,'Total bayar tahunan salah.');
    parity_amount((float)$annual['W500000001']['tunggakan'],5000000.0,'Kelebihan bulan Juli menutup tunggakan bulan lain.');
    parity_assert($annual['W500000001']['_status']==='Perlu Rekonsiliasi','Overpay bulanan tidak ditandai rekonsiliasi.');
    $legacyFilters=array_merge($filters,['tahun_ajaran'=>'2098/2099','siswa_status'=>'all']);
    $legacy=parity_rows_by_nis(report_spp_year_data($koneksi,$legacyFilters)['rows']);
    parity_amount((float)$legacy['W500000004']['total_bayar'],0.0,'Pembayaran legacy tanpa claim ditebak sebagai pembayaran periode.');

    $perItem=parity_rows_by_nis(report_item_data($koneksi,array_merge($filters,['kategori'=>'spp']))['rows']);
    parity_amount((float)$perItem['W500000001']['tagihan'],500000.0,'Per-item SPP memakai tarif aktif, bukan snapshot.');
    parity_amount((float)$perItem['W500000001']['terbayar'],500000.0,'Per-item SPP tidak memakai claim.');
    parity_assert($perItem['W500000002']['status']==='Cicilan','Per-item SPP status cicilan salah.');
    $committee=parity_rows_by_nis(report_item_data($koneksi,array_merge($filters,['kategori'=>'komite']))['rows']);
    parity_amount((float)$committee['W500000001']['tagihan'],20000.0,'Per-item Komite tidak memakai snapshot.');

    $savingsClass=parity_rows_by_nis(report_savings_class_data($koneksi,array_merge($filters,['kelas'=>$classA,'mode'=>'harian']))['rows']);
    parity_amount((float)$savingsClass['W500000001']['saldo_awal'],100000.0,'Saldo awal tabungan kelas salah.');
    parity_amount((float)$savingsClass['W500000001']['total_masuk'],60000.0,'Mutasi masuk bulan penuh salah atau linked saving ikut dihitung.');
    parity_amount((float)$savingsClass['W500000001']['total_keluar'],30000.0,'Mutasi keluar bulan penuh salah.');
    parity_amount((float)$savingsClass['W500000001']['saldo_akhir'],130000.0,'Saldo akhir tabungan kelas salah.');
    $savingsStudent=report_savings_student_data($koneksi,array_merge($filters,['kelas'=>0,'mode'=>'buku']));
    parity_assert(count($savingsStudent['rows'])===3,'Mutasi satu hari harus berisi tiga transaksi manual.');
    parity_assert($savingsStudent['rows'][0]['jenis']==='Masuk' && $savingsStudent['rows'][1]['jenis']==='Keluar','Tie timestamp tabungan tidak diurutkan deterministik Masuk lalu Keluar.');
    parity_amount((float)$savingsStudent['rows'][1]['saldo'],110000.0,'Saldo berjalan setelah masuk/keluar salah.');

    $settlement=report_settlement_data($koneksi,$filters);
    $settlementRows=array_column($settlement['rows'],'nominal','bagian');
    parity_amount((float)$settlementRows['Pendapatan pembayaran tunai'],845000.0,'Setoran Tunai salah.');
    parity_amount((float)$settlementRows['Penerimaan Virtual Account'],325000.0,'Setoran VA salah.');
    parity_amount((float)$settlementRows['Tabungan masuk tunai'],100000.0,'Setoran tabungan masuk salah.');
    parity_amount((float)$settlementRows['Tabungan keluar tunai'],-30000.0,'Setoran tabungan keluar salah.');
    parity_amount((float)$settlementRows['Kas fisik yang diserahkan'],915000.0,'Kas fisik salah.');
    parity_assert((int)$settlement['settlement']['payment_count']===2,'Jumlah kuitansi setoran salah.');

    foreach(array_keys(report_registry()) as $template){$templateFilters=$filters;if(in_array($template,['status','per-item'],true))$templateFilters['kategori']='spp';if(in_array($template,['penerimaan','setoran'],true))$templateFilters['kategori']='semua';if($template==='tabungan-siswa')$templateFilters['mode']='buku';$report=report_build($koneksi,$template,$templateFilters);parity_assert(isset($report['title'],$report['columns'],$report['rows']),'Kontrak template '.$template.' tidak lengkap.');}
    $tooLong=array_merge($filters,['tanggal_awal'=>'2098-01-01','tanggal_akhir'=>'2099-01-02']);$limited=false;try{report_build($koneksi,'penerimaan',$tooLong);}catch(LengthException){$limited=true;}parity_assert($limited,'Batas rentang 366 hari tidak ditegakkan.');
    $formulaPayloads = [
        '=2+3', '+SUM(A1:A2)', '-10+5', '@SUM(A1:A2)',
        " \t=HYPERLINK(\"https://example.test\")", "\r\n+CMD|/C calc!A0",
        "\0=cmd", "\u{00A0}@NOW()",
    ];
    foreach ($formulaPayloads as $formulaPayload) {
        $safeSpreadsheetText = report_spreadsheet_text($formulaPayload);
        parity_assert(!strpbrk($safeSpreadsheetText, "\0\r\n\t"), 'Control character spreadsheet tidak dinetralkan.');
        parity_assert(str_starts_with($safeSpreadsheetText, "'"), 'Prefix formula spreadsheet tidak dinetralkan: ' . bin2hex($formulaPayload));
    }
    parity_assert(report_spreadsheet_text('Siswa Aman')==='Siswa Aman','Teks spreadsheet aman berubah.');
    $page=report_paginate(array_map(fn($i)=>['nis'=>(string)$i],range(1,101)),array_merge($filters,['page'=>2,'per_page'=>50]),false);
    parity_assert($page['total']===101&&$page['pages']===3&&$page['rows'][0]['nis']==='51','Pagination modular tidak stabil.');

    $koneksi->rollback();
    echo "OK: oracle Wave 5 memvalidasi 7 template, snapshot/claim, cicilan, boundary tanggal, filter, ordering, tabungan, setoran, limit, dan formula export.\n";
} catch (Throwable $error) {
    $koneksi->rollback();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
