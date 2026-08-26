<?php
// ============================================
// pembayaran/proses.php - Insert / Update / Delete
// ============================================
require_once __DIR__ . '/../includes/security.php';
security_bootstrap_session();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/audit.php';
require_once '../includes/daftar_ulang.php';
require_once '../includes/biaya_lain.php';
require_once '../includes/idempotency.php';
requireRole(['admin', 'kasir']);
security_require_post();
security_require_csrf('payment');

$aksi = security_input_scalar($_POST, 'aksi');
$paymentIdempotencyKey = trim((string)security_input_scalar($_POST, 'idempotency_key'));

function parse_amount($value) {
    if ($value === null || $value === '') return 0.0;
    $normalized = str_replace(['.', ','], ['', '.'], trim((string)$value));
    return is_numeric($normalized) ? (float)$normalized : NAN;
}

function current_operator_id(): string {
    return (string)($_SESSION['admin_id'] ?? '');
}

function validate_payment_amounts(array $amounts): void {
    foreach ($amounts as $label => $amount) {
        if (!is_finite($amount) || $amount < 0) {
            throw new RuntimeException("Nominal $label harus berupa angka positif atau nol.");
        }
    }
}

function reject_disabled_payment_savings(float $amount): void {
    if (!is_finite($amount) || $amount < 0) {
        throw new RuntimeException('Nominal Tabungan harus berupa angka positif atau nol.');
    }
    if ($amount > 0.001) {
        throw new RuntimeException('Input tabungan lewat pembayaran sudah dinonaktifkan. Gunakan menu Tabungan Masuk.');
    }
}

function validate_payment_context(string $tanggal, string $bulan, string $tahun): void {
    if (!in_array($bulan, ['01','02','03','04','05','06','07','08','09','10','11','12'], true)) {
        throw new RuntimeException('Bulan pembayaran tidak valid.');
    }
    if (!preg_match('/^\d{4}$/', $tahun)) throw new RuntimeException('Tahun pembayaran tidak valid.');
    $datePart = substr($tanggal, 0, 10);
    $parsedDate = DateTime::createFromFormat('!Y-m-d', $datePart);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $datePart) {
        throw new RuntimeException('Tanggal pembayaran tidak valid.');
    }
    $paymentYear = (int)$parsedDate->format('Y');
    $periodYear = (int)$tahun;
    if ($periodYear > $paymentYear + 10) {
        throw new RuntimeException('Tahun pembayaran maksimal 10 tahun dari tanggal bayar, yaitu ' . ($paymentYear + 10) . '.');
    }
}

function payment_month_label(string $bulan): string {
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];
    return $months[$bulan] ?? $bulan;
}

function spp_prior_periods_in_academic_year(string $bulan, string $tahun): array {
    $month = (int)$bulan;
    $year = (int)$tahun;
    if ($month < 1 || $month > 12 || $year < 1) return [];

    $periods = [];
    if ($month >= 7) {
        for ($m = 7; $m < $month; $m++) {
            $periods[] = ['bulan' => str_pad((string)$m, 2, '0', STR_PAD_LEFT), 'tahun' => (string)$year];
        }
        return $periods;
    }

    $previousYear = $year - 1;
    for ($m = 7; $m <= 12; $m++) {
        $periods[] = ['bulan' => str_pad((string)$m, 2, '0', STR_PAD_LEFT), 'tahun' => (string)$previousYear];
    }
    for ($m = 1; $m < $month; $m++) {
        $periods[] = ['bulan' => str_pad((string)$m, 2, '0', STR_PAD_LEFT), 'tahun' => (string)$year];
    }
    return $periods;
}

function spp_following_periods_in_academic_year(string $bulan, string $tahun): array {
    $month = (int)$bulan;
    $year = (int)$tahun;
    if ($month < 1 || $month > 12 || $year < 1) return [];

    $periods = [];
    if ($month >= 7) {
        for ($m = $month + 1; $m <= 12; $m++) {
            $periods[] = ['bulan' => str_pad((string)$m, 2, '0', STR_PAD_LEFT), 'tahun' => (string)$year];
        }
        for ($m = 1; $m <= 6; $m++) {
            $periods[] = ['bulan' => str_pad((string)$m, 2, '0', STR_PAD_LEFT), 'tahun' => (string)($year + 1)];
        }
        return $periods;
    }

    for ($m = $month + 1; $m <= 6; $m++) {
        $periods[] = ['bulan' => str_pad((string)$m, 2, '0', STR_PAD_LEFT), 'tahun' => (string)$year];
    }
    return $periods;
}

function spp_paid_for_period(mysqli $db, string $noInduk, string $bulan, string $tahun, int $excludePaymentId = 0): float {
    $monthLabel = payment_month_label($bulan);
    $legacyMonth = (string)(int)$bulan;
    $stmt = $db->prepare('
        SELECT U_SPP
        FROM bayar
        WHERE NO_INDUK = ?
          AND TAHUN = ?
          AND (BULAN = ? OR BULAN = ? OR BULAN = ?)
          AND id <> ?
        FOR UPDATE
    ');
    $stmt->bind_param('sssssi', $noInduk, $tahun, $bulan, $monthLabel, $legacyMonth, $excludePaymentId);
    $stmt->execute();
    $paid = 0.0;
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $paid += (float)$row['U_SPP'];
    }
    $stmt->close();
    return $paid;
}

function spp_monthly_bill_for_student(mysqli $db, string $noInduk): float {
    $stmt = $db->prepare('SELECT SPP_PERBULAN FROM siswa WHERE NO_INDUK = ? FOR UPDATE');
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['SPP_PERBULAN'] ?? 0);
}

function validate_spp_sequence(mysqli $db, string $noInduk, string $bulan, string $tahun, float $monthlyBill, int $excludePaymentId = 0): void {
    if ($monthlyBill <= 0) return;

    foreach (spp_prior_periods_in_academic_year($bulan, $tahun) as $period) {
        $paid = spp_paid_for_period($db, $noInduk, $period['bulan'], $period['tahun'], $excludePaymentId);
        if ($paid + 0.001 >= $monthlyBill) continue;

        $selectedLabel = payment_month_label($bulan) . ' ' . $tahun;
        $missingLabel = payment_month_label($period['bulan']) . ' ' . $period['tahun'];
        $remaining = max(0, $monthlyBill - $paid);
        throw new RuntimeException(
            'SPP ' . $selectedLabel . ' belum bisa dibayar karena ' . $missingLabel .
            ' belum lunas. Sisa ' . $missingLabel . ': Rp ' . number_format($remaining, 0, ',', '.') . '.'
        );
    }
}

function first_paid_spp_following_period(mysqli $db, string $noInduk, string $bulan, string $tahun, int $excludePaymentId = 0): ?array {
    foreach (spp_following_periods_in_academic_year($bulan, $tahun) as $period) {
        $paid = spp_paid_for_period($db, $noInduk, $period['bulan'], $period['tahun'], $excludePaymentId);
        if ($paid > 0.001) {
            return ['bulan' => $period['bulan'], 'tahun' => $period['tahun'], 'paid' => $paid];
        }
    }
    return null;
}

function validate_spp_period_not_breaking_future(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    float $monthlyBill,
    float $paidAfterChange,
    int $excludePaymentId = 0,
    string $action = 'diubah'
): void {
    if ($monthlyBill <= 0 || $paidAfterChange + 0.001 >= $monthlyBill) return;

    $future = first_paid_spp_following_period($db, $noInduk, $bulan, $tahun, $excludePaymentId);
    if (!$future) return;

    $currentLabel = payment_month_label($bulan) . ' ' . $tahun;
    $futureLabel = payment_month_label($future['bulan']) . ' ' . $future['tahun'];
    throw new RuntimeException(
        'SPP ' . $currentLabel . ' tidak bisa ' . $action . ' karena ' . $futureLabel .
        ' sudah memiliki pembayaran. Lunaskan kembali ' . $currentLabel . ' atau koreksi transaksi bulan setelahnya terlebih dahulu.'
    );
}

function split_payment_amount(float $amount, int $parts): array {
    $totalCents = (int)round($amount * 100);
    $baseCents = intdiv($totalCents, $parts);
    $remainder = $totalCents % $parts;
    $result = [];
    for ($index = 0; $index < $parts; $index++) {
        $result[] = ($baseCents + ($index < $remainder ? 1 : 0)) / 100;
    }
    return $result;
}

function sync_spp_period_claim(mysqli $db, int $bayarId, string $noInduk, string $bulan, string $tahun, float $uangSpp): void {
    $stmt = $db->prepare('DELETE FROM bayar_spp_periode WHERE bayar_id = ?');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $stmt->close();
    if ($uangSpp <= 0) return;

    $stmt = $db->prepare('INSERT INTO bayar_spp_periode (bayar_id, no_induk, bulan, tahun) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $bayarId, $noInduk, $bulan, $tahun);
    $stmt->execute();
    $stmt->close();
}

function normalize_student_class_for_du(array $student): string {
    $kelas = preg_replace('/\D+/', '', (string)($student['KELAS'] ?? ''));
    if (!in_array($kelas, ['1','2','3','4','5','6'], true)) {
        throw new RuntimeException('Kelas siswa tidak valid untuk Daftar Ulang.');
    }
    return $kelas;
}

function normalize_payment_method($value): string {
    $method = trim((string)$value);
    $allowed = ['Tunai', 'VA', 'Qris'];
    if (!in_array($method, $allowed, true)) {
        throw new RuntimeException('Sistem pembayaran tidak valid.');
    }
    return $method;
}

function validate_student_and_komite(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    float $uangKomite,
    int $excludePaymentId = 0,
    ?string $archivedStudentAllowed = null
): array {
    $stmt = $db->prepare('SELECT s.KELAS, s.master_kelas_id, s.POMG, s.SPP_PERBULAN, s.is_active,
        mk.tingkat, mk.kode_rombel, mk.is_placeholder
        FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
        WHERE s.NO_INDUK = ? FOR UPDATE');
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Data siswa tidak ditemukan.');
    if ((int)$student['is_active'] !== 1 && $noInduk !== $archivedStudentAllowed) {
        throw new RuntimeException('Siswa yang diarsipkan tidak dapat dipakai untuk transaksi baru.');
    }

    $monthLabel = payment_month_label($bulan);
    $legacyMonth = (string)(int)$bulan;
    $stmt = $db->prepare('SELECT U_KOMITE FROM bayar WHERE NO_INDUK = ? AND TAHUN = ? AND (BULAN = ? OR BULAN = ? OR BULAN = ?) AND id <> ? FOR UPDATE');
    $stmt->bind_param('sssssi', $noInduk, $tahun, $bulan, $monthLabel, $legacyMonth, $excludePaymentId);
    $stmt->execute();
    $paid = 0.0;
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $paid += (float)$row['U_KOMITE'];
    $stmt->close();

    $remaining = max(0, (float)$student['POMG'] - $paid);
    if ($uangKomite > $remaining + 0.001) {
        throw new RuntimeException('Pembayaran Uang Komite melebihi sisa periode. Sisa: Rp ' . number_format($remaining, 0, ',', '.'));
    }
    return $student;
}

function payable_total(float $total, float $discount = 0, float $derivedTotal = 0): float {
    return $derivedTotal > 0 ? $derivedTotal : max(0, $total - $discount);
}

function validate_component_remaining(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    array $components,
    float $uangDu,
    string $kelasDu = '',
    string $tahunAjaran = '',
    int $excludePaymentId = 0
): void {
    $stmt = $db->prepare('
        SELECT PANGKAL, potong_pangkal, tot_pangkal, BANGUNAN, SERAGAM, KEGIATAN,
               MAKAN, SORGA, INFAQ, SPP_PERBULAN, POMG, DAFTAR_ULANG, potong_du, tot_du,
               PANGKAL_BAYAR, BANGUNAN_BAYAR, SERAGAM_BAYAR, KEGIATAN_BAYAR
        FROM siswa
        WHERE NO_INDUK = ?
        FOR UPDATE
    ');
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Data siswa tidak ditemukan.');

    $monthLabel = payment_month_label($bulan);
    $legacyMonth = (string)(int)$bulan;
    $stmtPaid = $db->prepare('
        SELECT
            COALESCE(SUM(CASE WHEN TAHUN = ? AND (BULAN = ? OR BULAN = ? OR BULAN = ?) THEN U_SPP ELSE 0 END), 0) AS spp,
            COALESCE(SUM(CASE WHEN TAHUN = ? AND (BULAN = ? OR BULAN = ? OR BULAN = ?) THEN U_KOMITE ELSE 0 END), 0) AS komite,
            COALESCE(SUM(U_MAKAN), 0) AS makan,
            COALESCE(SUM(U_SORGA), 0) AS sorga,
            COALESCE(SUM(U_INFAQ), 0) AS infaq
        FROM bayar
        WHERE NO_INDUK = ? AND id <> ?
    ');
    $stmtPaid->bind_param(
        'sssssssssi',
        $tahun, $bulan, $monthLabel, $legacyMonth,
        $tahun, $bulan, $monthLabel, $legacyMonth,
        $noInduk, $excludePaymentId
    );
    $stmtPaid->execute();
    $paid = $stmtPaid->get_result()->fetch_assoc() ?: [];
    $stmtPaid->close();

    $excludedInitialPaid = ['pangkal' => 0.0, 'bangunan' => 0.0, 'seragam' => 0.0, 'kegiatan' => 0.0];
    if ($excludePaymentId > 0) {
        $stmtExcluded = $db->prepare('
            SELECT U_PANGKAL, U_BANGUNAN, U_SERAGAM, U_KEGIATAN
            FROM bayar
            WHERE id = ? AND NO_INDUK = ?
            LIMIT 1
            FOR UPDATE
        ');
        $stmtExcluded->bind_param('is', $excludePaymentId, $noInduk);
        $stmtExcluded->execute();
        $excluded = $stmtExcluded->get_result()->fetch_assoc() ?: [];
        $stmtExcluded->close();
        $excludedInitialPaid = [
            'pangkal' => (float)($excluded['U_PANGKAL'] ?? 0),
            'bangunan' => (float)($excluded['U_BANGUNAN'] ?? 0),
            'seragam' => (float)($excluded['U_SERAGAM'] ?? 0),
            'kegiatan' => (float)($excluded['U_KEGIATAN'] ?? 0),
        ];
    }

    $initialPaid = [
        'pangkal' => max(0, (float)$student['PANGKAL_BAYAR'] - $excludedInitialPaid['pangkal']),
        'bangunan' => max(0, (float)$student['BANGUNAN_BAYAR'] - $excludedInitialPaid['bangunan']),
        'seragam' => max(0, (float)$student['SERAGAM_BAYAR'] - $excludedInitialPaid['seragam']),
        'kegiatan' => max(0, (float)$student['KEGIATAN_BAYAR'] - $excludedInitialPaid['kegiatan']),
    ];

    $duTotal = 0.0;
    $paid['du'] = 0.0;
    if ($uangDu > 0) {
        $bill = du_require_bill($db, $noInduk, (int)$bulan, (int)$tahun, true);
        $duTotal = (float)$bill['nominal_tagihan'];
        $billId = (int)$bill['id'];
        $stmtDu = $db->prepare('SELECT COALESCE(SUM(jumlah), 0) AS du FROM bayar_du WHERE tagihan_daftar_ulang_id = ? AND (bayar_id IS NULL OR bayar_id <> ?)');
        $stmtDu->bind_param('ii', $billId, $excludePaymentId);
        $stmtDu->execute();
        $paid['du'] = (float)($stmtDu->get_result()->fetch_assoc()['du'] ?? 0);
        $stmtDu->close();
    }

    $sppInput = (float)($components['spp'] ?? 0);
    if ($sppInput > 0) {
        validate_spp_sequence($db, $noInduk, $bulan, $tahun, (float)$student['SPP_PERBULAN'], $excludePaymentId);
        validate_spp_period_not_breaking_future(
            $db,
            $noInduk,
            $bulan,
            $tahun,
            (float)$student['SPP_PERBULAN'],
            (float)($paid['spp'] ?? 0) + $sppInput,
            $excludePaymentId,
            'dicicil sebagian'
        );
    }

    $limits = [
        'pangkal' => ['label' => 'Uang Pangkal', 'total' => payable_total((float)$student['PANGKAL'], (float)$student['potong_pangkal'], (float)$student['tot_pangkal']), 'paid' => $initialPaid['pangkal'], 'input' => (float)($components['pangkal'] ?? 0)],
        'bangunan' => ['label' => 'Uang Bangunan', 'total' => (float)$student['BANGUNAN'], 'paid' => $initialPaid['bangunan'], 'input' => (float)($components['bangunan'] ?? 0)],
        'seragam' => ['label' => 'Uang Seragam', 'total' => (float)$student['SERAGAM'], 'paid' => $initialPaid['seragam'], 'input' => (float)($components['seragam'] ?? 0)],
        'kegiatan' => ['label' => 'Uang Kegiatan', 'total' => (float)$student['KEGIATAN'], 'paid' => $initialPaid['kegiatan'], 'input' => (float)($components['kegiatan'] ?? 0)],
        'spp' => ['label' => 'Uang SPP', 'total' => (float)$student['SPP_PERBULAN'], 'paid' => (float)($paid['spp'] ?? 0), 'input' => (float)($components['spp'] ?? 0)],
        'komite' => ['label' => 'Uang Komite', 'total' => (float)$student['POMG'], 'paid' => (float)($paid['komite'] ?? 0), 'input' => (float)($components['komite'] ?? 0)],
        'makan' => ['label' => 'Uang Makan', 'total' => (float)$student['MAKAN'], 'paid' => (float)($paid['makan'] ?? 0), 'input' => (float)($components['makan'] ?? 0)],
        'sorga' => ['label' => 'Uang Sorga', 'total' => (float)$student['SORGA'], 'paid' => (float)($paid['sorga'] ?? 0), 'input' => (float)($components['sorga'] ?? 0)],
        'infaq' => ['label' => 'Uang Infaq', 'total' => (float)$student['INFAQ'], 'paid' => (float)($paid['infaq'] ?? 0), 'input' => (float)($components['infaq'] ?? 0)],
        'du' => ['label' => 'Daftar Ulang', 'total' => $duTotal, 'paid' => (float)($paid['du'] ?? 0), 'input' => $uangDu],
    ];

    foreach ($limits as $limit) {
        if ($limit['input'] <= 0) continue;
        if ($limit['total'] <= 0) {
            throw new RuntimeException($limit['label'] . ' belum memiliki total tagihan. Lengkapi master atau data tagihan terlebih dahulu.');
        }
        $remaining = max(0, $limit['total'] - $limit['paid']);
        if ($limit['paid'] > $limit['total'] + 0.001) {
            throw new RuntimeException($limit['label'] . ' sudah melebihi total tagihan. Total: Rp ' . number_format($limit['total'], 0, ',', '.') . ', sudah terbayar: Rp ' . number_format($limit['paid'], 0, ',', '.') . '. Cek ulang transaksi sebelumnya.');
        }
        if ($limit['input'] > $remaining + 0.001) {
            throw new RuntimeException('Pembayaran ' . $limit['label'] . ' melebihi sisa tagihan. Sisa: Rp ' . number_format($remaining, 0, ',', '.') . '.');
        }
    }
}

function normalize_month_code($value) {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12'
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)$value, 2, '0', STR_PAD_LEFT);
}

function collect_biaya_lain(mysqli $koneksi, string $noInduk, int $bayarId = 0): array {
    $detailIds = $_POST['biaya_lain_detail_id'] ?? [];
    $billIds = $_POST['biaya_lain_tagihan_id'] ?? [];
    $nominals = $_POST['biaya_lain_nominal'] ?? [];
    $notes = $_POST['biaya_lain_keterangan'] ?? [];
    if (!is_array($detailIds) || !is_array($billIds) || !is_array($nominals) || !is_array($notes)) {
        throw new RuntimeException('Format biaya lain tidak valid.');
    }
    $legacyMasterIds = $_POST['biaya_lain_master_id'] ?? [];
    if (!$billIds && is_array($legacyMasterIds) && array_filter(array_map('intval', $legacyMasterIds))) {
        throw new RuntimeException('Form Biaya Lain sudah diperbarui. Muat ulang halaman dan pilih tagihan siswa.');
    }

    $existing = [];
    if ($bayarId > 0) {
        $stmt = $koneksi->prepare('SELECT * FROM bayar_biaya_lain WHERE bayar_id = ?');
        $stmt->bind_param('i', $bayarId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $existing[(int)$row['id']] = $row;
        $stmt->close();
    }

    $lines = [];
    $submittedByBill = [];
    $rowCount = max(count($detailIds), count($billIds), count($nominals), count($notes));
    for ($index = 0; $index < $rowCount; $index++) {
        $detailId = (int)($detailIds[$index] ?? 0);
        $billId = (int)($billIds[$index] ?? 0);
        $nominalInput = parse_amount($nominals[$index] ?? null);
        $note = trim((string)($notes[$index] ?? ''));
        if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

        $oldLine = $detailId > 0 && isset($existing[$detailId]) ? $existing[$detailId] : null;
        if (!is_finite($nominalInput) || $nominalInput < 0) {
            throw new RuntimeException('Nominal biaya lain harus berupa angka positif atau nol.');
        }

        if ($billId <= 0) {
            // Detail hasil migrasi tidak memiliki master, tetapi snapshot-nya tetap sah.
            if ($oldLine && $oldLine['tagihan_biaya_lain_id'] === null) {
                if ($nominalInput <= 0) continue;
                $lines[] = [
                    'bill_id' => null,
                    'master_id' => null,
                    'nama' => $oldLine['nama_biaya_snapshot'],
                    'nominal' => $nominalInput,
                    'keterangan' => $note,
                ];
            }
            continue;
        }

        if ($nominalInput <= 0) continue;

        $sameBillAsOldLine = $oldLine && (int)$oldLine['tagihan_biaya_lain_id'] === $billId;
        $bill = other_fee_bill_find($koneksi, $billId, $noInduk, true);
        if (!$bill || ($bill['status'] !== 'open' && !$sameBillAsOldLine)) {
            throw new RuntimeException('Tagihan Biaya Lain tidak tersedia untuk siswa ini.');
        }
        $masterTotal = (float)$bill['nominal_tagihan'];
        $stmtPaid = $koneksi->prepare('SELECT COALESCE(SUM(d.nominal_snapshot),0) paid FROM bayar_biaya_lain d WHERE d.tagihan_biaya_lain_id=? AND d.bayar_id<>?');
        $stmtPaid->bind_param('ii', $billId, $bayarId); $stmtPaid->execute();
        $paidBefore = (float)($stmtPaid->get_result()->fetch_assoc()['paid'] ?? 0); $stmtPaid->close();
        if ($paidBefore > $masterTotal + 0.001) {
            throw new RuntimeException($bill['nama_snapshot'] . ' sudah melebihi total tagihan. Periksa transaksi sebelumnya.');
        }
        if ($paidBefore >= $masterTotal - 0.001 && !$sameBillAsOldLine) {
            throw new RuntimeException($bill['nama_snapshot'] . ' sudah lunas dan tidak dapat ditambahkan lagi.');
        }
        if (array_key_exists($billId, $submittedByBill)) throw new RuntimeException($bill['nama_snapshot'] . ' hanya boleh dipilih satu kali dalam satu transaksi.');
        $submittedBefore = (float)($submittedByBill[$billId] ?? 0);
        $remaining = max(0, $masterTotal - $paidBefore - $submittedBefore);
        if ($nominalInput > $remaining + 0.001) {
            throw new RuntimeException('Pembayaran ' . $bill['nama_snapshot'] . ' melebihi sisa tagihan. Sisa: Rp ' . number_format($remaining, 0, ',', '.') . '.');
        }
        $submittedByBill[$billId] = $submittedBefore + $nominalInput;

        $lines[] = [
            'bill_id' => $billId,
            'master_id' => (int)$bill['master_biaya_lain_id'],
            'nama' => $sameBillAsOldLine ? $oldLine['nama_biaya_snapshot'] : $bill['nama_snapshot'],
            'nominal' => $nominalInput,
            'keterangan' => $note,
        ];
    }
    return $lines;
}

function save_biaya_lain(mysqli $koneksi, int $bayarId, array $lines): void {
    $stmtDelete = $koneksi->prepare('DELETE FROM bayar_biaya_lain WHERE bayar_id = ?');
    $stmtDelete->bind_param('i', $bayarId);
    $stmtDelete->execute();
    $stmtDelete->close();

    if (!$lines) return;
    $stmt = $koneksi->prepare("
        INSERT INTO bayar_biaya_lain
            (bayar_id, master_biaya_lain_id, tagihan_biaya_lain_id, nama_biaya_snapshot, nominal_snapshot, keterangan, urutan)
        VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), ?)
    ");
    foreach ($lines as $index => $line) {
        $masterId = $line['master_id'];
        $billId = $line['bill_id'] ?? null;
        $nama = $line['nama'];
        $nominal = $line['nominal'];
        $keterangan = $line['keterangan'];
        $urutan = $index + 1;
        $stmt->bind_param('iiisdsi', $bayarId, $masterId, $billId, $nama, $nominal, $keterangan, $urutan);
        $stmt->execute();
    }
    $stmt->close();
}

function calculate_payment_total(array $components, float $uangDu, float $potonganSpp, array $biayaLain): float {
    $total = array_sum($components) + $uangDu;
    foreach ($biayaLain as $line) $total += (float)$line['nominal'];
    return max(0, $total - $potonganSpp);
}

function legacy_biaya_lain_values(array $lines): array {
    $values = [
        'total' => 0.0,
        'names' => [null, null, null, null],
        'amounts' => [0.0, 0.0, 0.0, 0.0],
    ];
    foreach ($lines as $index => $line) {
        $values['total'] += (float)$line['nominal'];
        if ($index >= 4) continue;
        $values['names'][$index] = mb_substr((string)$line['nama'], 0, 100);
        $values['amounts'][$index] = (float)$line['nominal'];
    }
    return $values;
}

function sync_student_initial_fee_paid(
    mysqli $db,
    string $noInduk,
    float $pangkalDelta,
    float $bangunanDelta,
    float $seragamDelta,
    float $kegiatanDelta
): void {
    if (
        abs($pangkalDelta) < 0.001 &&
        abs($bangunanDelta) < 0.001 &&
        abs($seragamDelta) < 0.001 &&
        abs($kegiatanDelta) < 0.001
    ) {
        return;
    }

    $stmtLock = $db->prepare('
        SELECT PANGKAL_BAYAR, BANGUNAN_BAYAR, SERAGAM_BAYAR, KEGIATAN_BAYAR
        FROM siswa
        WHERE NO_INDUK = ?
        FOR UPDATE
    ');
    $stmtLock->bind_param('s', $noInduk);
    $stmtLock->execute();
    $student = $stmtLock->get_result()->fetch_assoc();
    $stmtLock->close();
    if (!$student) throw new RuntimeException('Data siswa tidak ditemukan untuk sinkron pembayaran awal.');

    $pangkalBayar = max(0, (float)$student['PANGKAL_BAYAR'] + $pangkalDelta);
    $bangunanBayar = max(0, (float)$student['BANGUNAN_BAYAR'] + $bangunanDelta);
    $seragamBayar = max(0, (float)$student['SERAGAM_BAYAR'] + $seragamDelta);
    $kegiatanBayar = max(0, (float)$student['KEGIATAN_BAYAR'] + $kegiatanDelta);

    $stmtUpdate = $db->prepare('
        UPDATE siswa
        SET PANGKAL_BAYAR = ?, BANGUNAN_BAYAR = ?, SERAGAM_BAYAR = ?, KEGIATAN_BAYAR = ?
        WHERE NO_INDUK = ?
    ');
    $stmtUpdate->bind_param('dddds', $pangkalBayar, $bangunanBayar, $seragamBayar, $kegiatanBayar, $noInduk);
    $stmtUpdate->execute();
    $stmtUpdate->close();
}

/**
 * Pastikan pembayaran bukan histori legacy dan kunci header sebelum dimutasi.
 */
function find_linked_payment(mysqli $db, int $bayarId): array {
    $stmt = $db->prepare('SELECT * FROM bayar WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payment) throw new RuntimeException('Data pembayaran tidak ditemukan.');
    if ((int)($payment['payment_link_version'] ?? 0) !== 1) {
        throw new RuntimeException('Pembayaran legacy tidak dapat diubah atau dihapus. Rekonsiliasi manual diperlukan terlebih dahulu.');
    }
    return $payment;
}

/**
 * Snapshot finansial terpilih untuk audit. Snapshot sengaja tidak memuat data
 * siswa selain NIS dan tidak memuat data autentikasi apa pun.
 */
function payment_audit_snapshot(mysqli $db, int $bayarId): array {
    $stmt = $db->prepare('SELECT
        id, NO_INDUK, KELAS, master_kelas_id, kelas_rombel_snapshot,
        U_PANGKAL, U_BANGUNAN, U_SERAGAM, U_KEGIATAN, U_SPP,
        U_MAKAN, U_SORGA, U_INFAQ, U_KOMITE, U_LAIN,
        KETERANGAN, TGL_BYR, BULAN, TAHUN, user_id, sistem_pembayaran,
        th_ajaran, kelas_du, potong_spp, total_jumlah,
        payment_link_version, payment_batch_token, payment_batch_sequence,
        payment_batch_count, created_at, updated_at
      FROM bayar WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment) {
        return [];
    }

    $stmt = $db->prepare('SELECT bayar_id, no_induk, bulan, tahun, created_at
      FROM bayar_spp_periode WHERE bayar_id = ? ORDER BY tahun, bulan');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $sppPeriods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $db->prepare('SELECT id, tagihan_daftar_ulang_id, no_induk, kelas, th_ajaran, jumlah
      FROM bayar_du WHERE bayar_id = ? ORDER BY id');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $registration = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $db->prepare('SELECT id, master_biaya_lain_id, tagihan_biaya_lain_id,
        nama_biaya_snapshot, nominal_snapshot, keterangan, urutan, legacy_key
      FROM bayar_biaya_lain WHERE bayar_id = ? ORDER BY urutan, id');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $otherFees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'payment' => $payment,
        'spp_periods' => $sppPeriods,
        'registration' => $registration,
        'other_fees' => $otherFees,
    ];
}

// ── INSERT ──────────────────────────────────
if ($aksi === 'input') {
    $no_induk        = trim((string)security_input_scalar($_POST, 'no_induk'));
    // Transaksi baru selalu memakai waktu server Asia/Jakarta.
    $tanggal_bayar   = date('Y-m-d H:i:s');
    $bulan_bayar     = normalize_month_code(security_input_scalar($_POST, 'bulan_bayar'));
    $tahun_bayar     = security_input_scalar($_POST, 'tahun_bayar', date('Y'));
    $sistem_pembayaran = security_input_scalar($_POST, 'sistem_pembayaran', 'VA');
    
    $uang_pangkal    = parse_amount(security_input_scalar($_POST, 'uang_pangkal', 0));
    $uang_bangunan   = parse_amount(security_input_scalar($_POST, 'uang_bangunan', 0));
    $uang_seragam    = parse_amount(security_input_scalar($_POST, 'uang_seragam', 0));
    $uang_kegiatan   = parse_amount(security_input_scalar($_POST, 'uang_kegiatan', 0));
    $uang_spp        = parse_amount(security_input_scalar($_POST, 'uang_spp', 0));
    $uang_komite     = parse_amount(security_input_scalar($_POST, 'uang_komite', 0));
    $uang_makan      = parse_amount(security_input_scalar($_POST, 'uang_makan', 0));
    $uang_sorga      = parse_amount(security_input_scalar($_POST, 'uang_sorga', 0));
    $uang_infaq      = parse_amount(security_input_scalar($_POST, 'uang_infaq', 0));
    $uang_lain       = 0.0;
    $uang_du         = parse_amount(security_input_scalar($_POST, 'uang_du', 0));
    $ll_1_ket = $ll_2_ket = $ll_3_ket = $ll_4_ket = '';
    $ll_1_nom = $ll_2_nom = $ll_3_nom = $ll_4_nom = 0.0;
    
    $potongan_spp    = parse_amount(security_input_scalar($_POST, 'potongan_spp', 0));
    $legacy_tabungan_input = parse_amount(security_input_scalar($_POST, 'tabungan_wajib', 0));
    $total_jumlah    = 0.0;
    $catatan         = trim((string)security_input_scalar($_POST, 'catatan'));
    if (mb_strlen($catatan) > 255) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Catatan maksimal 255 karakter.'];
        header('Location: form.php');
        exit;
    }
    $kelas_du        = security_input_scalar($_POST, 'kelas_du');
    $tahun_ajaran_du = security_input_scalar($_POST, 'tahun_ajaran_du');
    $payment_plan    = security_input_scalar($_POST, 'payment_plan', 'monthly');

    if (empty($no_induk)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih siswa terlebih dahulu!'];
        header('Location: form.php');
        exit;
    }

    $koneksi->begin_transaction();

    try {
        idempotency_claim($koneksi, 'payment', $paymentIdempotencyKey, (int)current_operator_id());
        if ($payment_plan !== 'monthly') throw new RuntimeException('Pembayaran banyak bulan sedang ditangguhkan. Gunakan transaksi bulanan.');
        $sistem_pembayaran = normalize_payment_method($sistem_pembayaran);
        validate_payment_amounts([
            'Pangkal' => $uang_pangkal, 'Bangunan' => $uang_bangunan,
            'Seragam' => $uang_seragam, 'Kegiatan' => $uang_kegiatan,
            'SPP' => $uang_spp, 'Komite' => $uang_komite, 'Makan' => $uang_makan,
            'Sorga' => $uang_sorga, 'Infaq' => $uang_infaq, 'Daftar Ulang' => $uang_du,
            'Potongan SPP' => $potongan_spp
        ]);
        reject_disabled_payment_savings($legacy_tabungan_input);
        validate_payment_context($tanggal_bayar, $bulan_bayar, (string)$tahun_bayar);
        if ($potongan_spp > $uang_spp) throw new RuntimeException('Potongan SPP tidak boleh melebihi pembayaran SPP.');
        $siswa_data = validate_student_and_komite($koneksi, $no_induk, $bulan_bayar, $tahun_bayar, $uang_komite);
        $kelas_siswa = $siswa_data['KELAS'];
        $master_kelas_id = (int)($siswa_data['master_kelas_id'] ?? 0);
        $kelas_rombel_snapshot = class_label([
            'tingkat' => $siswa_data['tingkat'] ?? $kelas_siswa,
            'kode_rombel' => $siswa_data['kode_rombel'] ?? 'BELUM',
            'is_placeholder' => $siswa_data['is_placeholder'] ?? 1,
        ]);
        $du_bill_id = null;
        $tahun_ajaran_du = du_academic_year_label((int)$bulan_bayar, (int)$tahun_bayar);
        $kelas_du = '';
        if ($uang_du > 0) {
            $du_bill = du_require_bill($koneksi, $no_induk, (int)$bulan_bayar, (int)$tahun_bayar, true);
            $du_bill_id = (int)$du_bill['id'];
            $kelas_du = (string)$du_bill['kelas'];
            $tahun_ajaran_du = (string)$du_bill['tahun_ajaran'];
        }

        $periods = [['bulan' => $bulan_bayar, 'tahun' => (string)$tahun_bayar]];
        $spp_parts = [$uang_spp];
        $discount_parts = [$potongan_spp];
        $batch_token = null;
        $batch_count = 1;
        if ($payment_plan === 'annual') {
            $monthlyBill = (float)$siswa_data['SPP_PERBULAN'];
            $annualBill = $monthlyBill * 12;
            if ($monthlyBill <= 0) throw new RuntimeException('Tagihan SPP bulanan siswa belum diatur.');
            if (abs($uang_spp - $annualBill) > 0.001) {
                throw new RuntimeException('Pembayaran tahunan harus senilai 12 bulan penuh, yaitu Rp ' . number_format($annualBill, 0, ',', '.') . '.');
            }
            $periods = [];
            for ($month = 1; $month <= 12; $month++) {
                $periods[] = ['bulan' => str_pad((string)$month, 2, '0', STR_PAD_LEFT), 'tahun' => (string)$tahun_bayar];
            }
            $spp_parts = split_payment_amount($uang_spp, 12);
            $discount_parts = split_payment_amount($potongan_spp, 12);
            $batch_token = bin2hex(random_bytes(16));
            $batch_count = 12;
        }

        foreach ($periods as $index => $period) {
            $isFirst = $index === 0;
            validate_component_remaining($koneksi, $no_induk, $period['bulan'], $period['tahun'], [
                'pangkal' => $isFirst ? $uang_pangkal : 0,
                'bangunan' => $isFirst ? $uang_bangunan : 0,
                'seragam' => $isFirst ? $uang_seragam : 0,
                'kegiatan' => $isFirst ? $uang_kegiatan : 0,
                'spp' => $spp_parts[$index],
                'komite' => $isFirst ? $uang_komite : 0,
                'makan' => $isFirst ? $uang_makan : 0,
                'sorga' => $isFirst ? $uang_sorga : 0,
                'infaq' => $isFirst ? $uang_infaq : 0,
            ], $isFirst ? $uang_du : 0, $kelas_du, $tahun_ajaran_du);
        }

        $biaya_lain = collect_biaya_lain($koneksi, $no_induk);
        $legacy_biaya_lain = legacy_biaya_lain_values($biaya_lain);

        // Satu pembayaran tahunan disimpan sebagai 12 header transaksi agar
        // setiap bulan memiliki nomor dan halaman struk sendiri.
        $sql = "INSERT INTO bayar (
            NO_INDUK, KELAS, U_PANGKAL, U_BANGUNAN, U_SERAGAM, U_KEGIATAN,
            U_SPP, U_MAKAN, U_SORGA, U_INFAQ, U_KOMITE, U_LAIN, KETERANGAN,
            TGL_BYR, BULAN, TAHUN, user_id, sistem_pembayaran,
            LAIN_LAIN1, JUMLAH1, LAIN_LAIN2, JUMLAH2, LAIN_LAIN3, JUMLAH3, LAIN_LAIN4, JUMLAH4,
            th_ajaran, kelas_du, potong_spp, total_jumlah, payment_link_version,
            payment_batch_token, payment_batch_sequence, payment_batch_count
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)";

        $stmt = $koneksi->prepare($sql);
        $user_id = current_operator_id();
        $receipt_ids = [];
        foreach ($periods as $index => $period) {
            $isFirst = $index === 0;
            $row_pangkal = $isFirst ? $uang_pangkal : 0.0;
            $row_bangunan = $isFirst ? $uang_bangunan : 0.0;
            $row_seragam = $isFirst ? $uang_seragam : 0.0;
            $row_kegiatan = $isFirst ? $uang_kegiatan : 0.0;
            $row_spp = $spp_parts[$index];
            $row_komite = $isFirst ? $uang_komite : 0.0;
            $row_makan = $isFirst ? $uang_makan : 0.0;
            $row_sorga = $isFirst ? $uang_sorga : 0.0;
            $row_infaq = $isFirst ? $uang_infaq : 0.0;
            $row_du = $isFirst ? $uang_du : 0.0;
            $row_discount = $discount_parts[$index];
            $row_other = $isFirst ? $biaya_lain : [];
            $row_legacy_other = $isFirst ? $legacy_biaya_lain : legacy_biaya_lain_values([]);
            $uang_lain = $row_legacy_other['total'];
            [$ll_1_ket, $ll_2_ket, $ll_3_ket, $ll_4_ket] = $row_legacy_other['names'];
            [$ll_1_nom, $ll_2_nom, $ll_3_nom, $ll_4_nom] = $row_legacy_other['amounts'];
            $row_total = calculate_payment_total([
                $row_pangkal, $row_bangunan, $row_seragam, $row_kegiatan, $row_spp,
                $row_komite, $row_makan, $row_sorga, $row_infaq
            ], $row_du, $row_discount, $row_other);
            $row_month = $period['bulan'];
            $row_year = $period['tahun'];
            $batch_sequence = $index + 1;

            $stmt->bind_param(
                'ssddddddddddsssssssdsdsdsdssddsii',
                $no_induk, $kelas_siswa, $row_pangkal, $row_bangunan, $row_seragam, $row_kegiatan,
                $row_spp, $row_makan, $row_sorga, $row_infaq, $row_komite, $uang_lain, $catatan,
                $tanggal_bayar, $row_month, $row_year, $user_id, $sistem_pembayaran,
                $ll_1_ket, $ll_1_nom, $ll_2_ket, $ll_2_nom, $ll_3_ket, $ll_3_nom, $ll_4_ket, $ll_4_nom,
                $tahun_ajaran_du, $kelas_du, $row_discount, $row_total,
                $batch_token, $batch_sequence, $batch_count
            );
            $stmt->execute();
            $bayar_id = $koneksi->insert_id;
            $stmtClass = $koneksi->prepare('UPDATE bayar SET master_kelas_id=NULLIF(?,0), kelas_rombel_snapshot=? WHERE id=?');
            $stmtClass->bind_param('isi', $master_kelas_id, $kelas_rombel_snapshot, $bayar_id);
            $stmtClass->execute();
            $stmtClass->close();
            $receipt_ids[] = $bayar_id;
            sync_spp_period_claim($koneksi, $bayar_id, $no_induk, $row_month, $row_year, $row_spp);
            sync_student_initial_fee_paid($koneksi, $no_induk, $row_pangkal, $row_bangunan, $row_seragam, $row_kegiatan);

            if (!$isFirst) continue;
            save_biaya_lain($koneksi, $bayar_id, $biaya_lain);
            if ($uang_du > 0) {
                $stmt_du = $koneksi->prepare("INSERT INTO bayar_du (bayar_id, tagihan_daftar_ulang_id, no_induk, kelas, th_ajaran, jumlah) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_du->bind_param('iisssd', $bayar_id, $du_bill_id, $no_induk, $kelas_du, $tahun_ajaran_du, $uang_du);
                $stmt_du->execute();
                $stmt_du->close();
            }
        }
        $stmt->close();

        foreach ($receipt_ids as $auditPaymentId) {
            audit_event_write(
                $koneksi,
                'payment.created',
                'bayar',
                $auditPaymentId,
                'create',
                null,
                payment_audit_snapshot($koneksi, $auditPaymentId),
                null,
                [
                    'result' => 'committed',
                    'source' => 'pembayaran/proses.php',
                    'batch_count' => $batch_count,
                ]
            );
        }

        $koneksi->commit();
        $_SESSION['flash'] = [
            'type' => 'success',
            'msg' => $payment_plan === 'annual'
                ? 'Pembayaran tahunan berhasil dibagi menjadi 12 transaksi dan 12 struk!'
                : 'Data pembayaran berhasil disimpan!',
            'print_payment' => [
                'id' => $receipt_ids[0],
                'batch' => $batch_token,
                'count' => $batch_count,
                'bulan' => $payment_plan === 'annual' ? '01' : $bulan_bayar,
                'tahun' => (string)$tahun_bayar,
                'source' => 'input',
            ],
        ];
        header('Location: lihat.php');
        exit;
    } catch (Throwable $e) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => security_exception_message($e, 'Pembayaran gagal disimpan.', 'payment-create')];
        header('Location: form.php');
        exit;
    }
}

// ── UPDATE ──────────────────────────────────
if ($aksi === 'update') {
    $id = (int)security_input_scalar($_POST, 'id', 0);
    if ($id <= 0) { header('Location: lihat.php'); exit; }

    $no_induk        = trim((string)security_input_scalar($_POST, 'no_induk'));
    $tanggal_bayar   = security_input_scalar($_POST, 'tanggal_bayar', date('Y-m-d H:i:s'));
    if (strlen($tanggal_bayar) === 10) {
        $tanggal_bayar .= ' ' . date('H:i:s');
    }
    $bulan_bayar     = normalize_month_code(security_input_scalar($_POST, 'bulan_bayar'));
    $tahun_bayar     = security_input_scalar($_POST, 'tahun_bayar', date('Y'));
    $sistem_pembayaran = security_input_scalar($_POST, 'sistem_pembayaran', 'VA');
    
    $uang_pangkal    = parse_amount(security_input_scalar($_POST, 'uang_pangkal', 0));
    $uang_bangunan   = parse_amount(security_input_scalar($_POST, 'uang_bangunan', 0));
    $uang_seragam    = parse_amount(security_input_scalar($_POST, 'uang_seragam', 0));
    $uang_kegiatan   = parse_amount(security_input_scalar($_POST, 'uang_kegiatan', 0));
    $uang_spp        = parse_amount(security_input_scalar($_POST, 'uang_spp', 0));
    $uang_komite     = parse_amount(security_input_scalar($_POST, 'uang_komite', 0));
    $uang_makan      = parse_amount(security_input_scalar($_POST, 'uang_makan', 0));
    $uang_sorga      = parse_amount(security_input_scalar($_POST, 'uang_sorga', 0));
    $uang_infaq      = parse_amount(security_input_scalar($_POST, 'uang_infaq', 0));
    $uang_lain       = 0.0;
    $uang_du         = parse_amount(security_input_scalar($_POST, 'uang_du', 0));
    $ll_1_ket = $ll_2_ket = $ll_3_ket = $ll_4_ket = '';
    $ll_1_nom = $ll_2_nom = $ll_3_nom = $ll_4_nom = 0.0;
    
    $potongan_spp    = parse_amount(security_input_scalar($_POST, 'potongan_spp', 0));
    $legacy_tabungan_input = parse_amount(security_input_scalar($_POST, 'tabungan_wajib', 0));
    $total_jumlah    = 0.0;
    $catatan         = trim((string)security_input_scalar($_POST, 'catatan'));
    if (mb_strlen($catatan) > 255) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Catatan maksimal 255 karakter.'];
        header('Location: edit.php?id=' . $id);
        exit;
    }
    $kelas_du        = security_input_scalar($_POST, 'kelas_du');
    $tahun_ajaran_du = security_input_scalar($_POST, 'tahun_ajaran_du');
    $audit_reason = security_input_scalar($_POST, 'audit_reason');

    if (empty($no_induk)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih siswa terlebih dahulu!'];
        header('Location: edit.php?id=' . $id);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        idempotency_claim($koneksi, 'payment', $paymentIdempotencyKey, (int)current_operator_id());
        $audit_reason = audit_require_reason($audit_reason, 'Alasan perubahan pembayaran');
        $old_bayar = find_linked_payment($koneksi, $id);
        $before_audit = payment_audit_snapshot($koneksi, $id);
        $sistem_pembayaran = normalize_payment_method($sistem_pembayaran);
        validate_payment_amounts([
            'Pangkal' => $uang_pangkal, 'Bangunan' => $uang_bangunan,
            'Seragam' => $uang_seragam, 'Kegiatan' => $uang_kegiatan,
            'SPP' => $uang_spp, 'Komite' => $uang_komite, 'Makan' => $uang_makan,
            'Sorga' => $uang_sorga, 'Infaq' => $uang_infaq, 'Daftar Ulang' => $uang_du,
            'Potongan SPP' => $potongan_spp
        ]);
        reject_disabled_payment_savings($legacy_tabungan_input);
        validate_payment_context($tanggal_bayar, $bulan_bayar, (string)$tahun_bayar);
        if ($potongan_spp > $uang_spp) throw new RuntimeException('Potongan SPP tidak boleh melebihi pembayaran SPP.');
        $allowedArchived = $no_induk === $old_bayar['NO_INDUK'] ? $old_bayar['NO_INDUK'] : null;
        $siswa_data = validate_student_and_komite($koneksi, $no_induk, $bulan_bayar, $tahun_bayar, $uang_komite, $id, $allowedArchived);
        $du_bill_id = null;
        $tahun_ajaran_du = du_academic_year_label((int)$bulan_bayar, (int)$tahun_bayar);
        $kelas_du = '';
        if ($uang_du > 0) {
            $du_bill = du_require_bill($koneksi, $no_induk, (int)$bulan_bayar, (int)$tahun_bayar, true);
            $du_bill_id = (int)$du_bill['id'];
            $kelas_du = (string)$du_bill['kelas'];
            $tahun_ajaran_du = (string)$du_bill['tahun_ajaran'];
        }
        validate_component_remaining($koneksi, $no_induk, $bulan_bayar, (string)$tahun_bayar, [
            'pangkal' => $uang_pangkal,
            'bangunan' => $uang_bangunan,
            'seragam' => $uang_seragam,
            'kegiatan' => $uang_kegiatan,
            'spp' => $uang_spp,
            'komite' => $uang_komite,
            'makan' => $uang_makan,
            'sorga' => $uang_sorga,
            'infaq' => $uang_infaq,
        ], $uang_du, $kelas_du, $tahun_ajaran_du, $id);

        $oldSppMonth = normalize_month_code((string)$old_bayar['BULAN']);
        $oldSppYear = (string)$old_bayar['TAHUN'];
        $sameSppContext = (string)$old_bayar['NO_INDUK'] === $no_induk
            && $oldSppMonth === $bulan_bayar
            && $oldSppYear === (string)$tahun_bayar;
        if ((float)$old_bayar['U_SPP'] > 0 && (!$sameSppContext || $uang_spp <= 0)) {
            $oldBill = $sameSppContext ? (float)$siswa_data['SPP_PERBULAN'] : spp_monthly_bill_for_student($koneksi, (string)$old_bayar['NO_INDUK']);
            $oldPaidAfter = spp_paid_for_period($koneksi, (string)$old_bayar['NO_INDUK'], $oldSppMonth, $oldSppYear, $id);
            if ($sameSppContext) $oldPaidAfter += $uang_spp;
            validate_spp_period_not_breaking_future(
                $koneksi,
                (string)$old_bayar['NO_INDUK'],
                $oldSppMonth,
                $oldSppYear,
                $oldBill,
                $oldPaidAfter,
                $id,
                $sameSppContext ? 'dikosongkan' : 'dipindahkan'
            );
        }

        $kelas_siswa = $siswa_data['KELAS'];
        $master_kelas_id = (int)($siswa_data['master_kelas_id'] ?? 0);
        $kelas_rombel_snapshot = class_label([
            'tingkat' => $siswa_data['tingkat'] ?? $kelas_siswa,
            'kode_rombel' => $siswa_data['kode_rombel'] ?? 'BELUM',
            'is_placeholder' => $siswa_data['is_placeholder'] ?? 1,
        ]);
        $biaya_lain = collect_biaya_lain($koneksi, $no_induk, $id);
        $legacy_biaya_lain = legacy_biaya_lain_values($biaya_lain);
        $uang_lain = $legacy_biaya_lain['total'];
        [$ll_1_ket, $ll_2_ket, $ll_3_ket, $ll_4_ket] = $legacy_biaya_lain['names'];
        [$ll_1_nom, $ll_2_nom, $ll_3_nom, $ll_4_nom] = $legacy_biaya_lain['amounts'];
        $total_jumlah = calculate_payment_total([
            $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_komite, $uang_makan, $uang_sorga, $uang_infaq
        ], $uang_du, $potongan_spp, $biaya_lain);

        // 1. Update data utama ke tabel bayar
        $sql = "UPDATE bayar SET
            NO_INDUK=?, KELAS=?, U_PANGKAL=?, U_BANGUNAN=?, U_SERAGAM=?, U_KEGIATAN=?,
            U_SPP=?, U_MAKAN=?, U_SORGA=?, U_INFAQ=?, U_KOMITE=?, U_LAIN=?, KETERANGAN=?,
            TGL_BYR=?, BULAN=?, TAHUN=?, sistem_pembayaran=?,
            LAIN_LAIN1=?, JUMLAH1=?, LAIN_LAIN2=?, JUMLAH2=?, LAIN_LAIN3=?, JUMLAH3=?, LAIN_LAIN4=?, JUMLAH4=?,
            th_ajaran=?, kelas_du=?, potong_spp=?, total_jumlah=?, payment_link_version=1
            WHERE id=?";

        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param(
            'ssddddddddddssssssdsdsdsdssddi',
            $no_induk, $kelas_siswa, $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_makan, $uang_sorga, $uang_infaq, $uang_komite, $uang_lain, $catatan,
            $tanggal_bayar, $bulan_bayar, $tahun_bayar, $sistem_pembayaran,
            $ll_1_ket, $ll_1_nom, $ll_2_ket, $ll_2_nom, $ll_3_ket, $ll_3_nom, $ll_4_ket, $ll_4_nom,
            $tahun_ajaran_du, $kelas_du, $potongan_spp, $total_jumlah, $id
        );
        $stmt->execute();
        $stmt->close();
        $stmtClass = $koneksi->prepare('UPDATE bayar SET master_kelas_id=NULLIF(?,0), kelas_rombel_snapshot=? WHERE id=?');
        $stmtClass->bind_param('isi', $master_kelas_id, $kelas_rombel_snapshot, $id);
        $stmtClass->execute();
        $stmtClass->close();

        if ((string)$old_bayar['NO_INDUK'] === $no_induk) {
            sync_student_initial_fee_paid(
                $koneksi,
                $no_induk,
                $uang_pangkal - (float)$old_bayar['U_PANGKAL'],
                $uang_bangunan - (float)$old_bayar['U_BANGUNAN'],
                $uang_seragam - (float)$old_bayar['U_SERAGAM'],
                $uang_kegiatan - (float)$old_bayar['U_KEGIATAN']
            );
        } else {
            sync_student_initial_fee_paid(
                $koneksi,
                (string)$old_bayar['NO_INDUK'],
                -(float)$old_bayar['U_PANGKAL'],
                -(float)$old_bayar['U_BANGUNAN'],
                -(float)$old_bayar['U_SERAGAM'],
                -(float)$old_bayar['U_KEGIATAN']
            );
            sync_student_initial_fee_paid($koneksi, $no_induk, $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan);
        }

        // Klaim periode ikut berpindah/dihapus ketika bulan, tahun, siswa,
        // atau nominal SPP pada transaksi diedit.
        sync_spp_period_claim($koneksi, $id, $no_induk, $bulan_bayar, (string)$tahun_bayar, $uang_spp);

        save_biaya_lain($koneksi, $id, $biaya_lain);

        // 2. Hapus hanya Daftar Ulang yang dimiliki pembayaran ini, lalu simpan nilai baru.
        $stmt_del_du = $koneksi->prepare("DELETE FROM bayar_du WHERE bayar_id = ?");
        $stmt_del_du->bind_param('i', $id);
        $stmt_del_du->execute();
        $stmt_del_du->close();

        if ($uang_du > 0) {
            $stmt_ins_du = $koneksi->prepare("INSERT INTO bayar_du (bayar_id, tagihan_daftar_ulang_id, no_induk, kelas, th_ajaran, jumlah) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins_du->bind_param('iisssd', $id, $du_bill_id, $no_induk, $kelas_du, $tahun_ajaran_du, $uang_du);
            $stmt_ins_du->execute();
            $stmt_ins_du->close();
        }

        audit_event_write(
            $koneksi,
            'payment.updated',
            'bayar',
            $id,
            'update',
            $before_audit,
            payment_audit_snapshot($koneksi, $id),
            $audit_reason,
            [
                'result' => 'committed',
                'source' => 'pembayaran/proses.php',
                'original_operator_id' => $old_bayar['user_id'] ?? null,
                'corrector_id' => current_operator_id(),
            ]
        );

        $koneksi->commit();
        $_SESSION['flash'] = [
            'type' => 'success',
            'msg' => 'Data pembayaran berhasil diperbarui!',
            'print_payment' => [
                'id' => $id,
                'bulan' => $bulan_bayar,
                'tahun' => (string)$tahun_bayar,
                'source' => 'update',
            ],
        ];
        header('Location: lihat.php');
        exit;
    } catch (Throwable $e) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => security_exception_message($e, 'Pembayaran gagal diperbarui.', 'payment-update')];
        header('Location: edit.php?id=' . $id);
        exit;
    }
}

// ── DELETE ──────────────────────────────────
if ($aksi === 'hapus') {
    $id = (int)security_input_scalar($_POST, 'id', 0);
    $audit_reason = security_input_scalar($_POST, 'audit_reason');
    if ($id <= 0) { header('Location: lihat.php'); exit; }

    $koneksi->begin_transaction();

    try {
        idempotency_claim($koneksi, 'payment', $paymentIdempotencyKey, (int)current_operator_id());
        $audit_reason = audit_require_reason($audit_reason, 'Alasan penghapusan pembayaran');
        $old_bayar = find_linked_payment($koneksi, $id);
        $before_audit = payment_audit_snapshot($koneksi, $id);

        if ((float)$old_bayar['U_SPP'] > 0) {
            $oldSppMonth = normalize_month_code((string)$old_bayar['BULAN']);
            $oldSppYear = (string)$old_bayar['TAHUN'];
            validate_spp_period_not_breaking_future(
                $koneksi,
                (string)$old_bayar['NO_INDUK'],
                $oldSppMonth,
                $oldSppYear,
                spp_monthly_bill_for_student($koneksi, (string)$old_bayar['NO_INDUK']),
                spp_paid_for_period($koneksi, (string)$old_bayar['NO_INDUK'], $oldSppMonth, $oldSppYear, $id),
                $id,
                'dihapus'
            );
        }

        sync_student_initial_fee_paid(
            $koneksi,
            (string)$old_bayar['NO_INDUK'],
            -(float)$old_bayar['U_PANGKAL'],
            -(float)$old_bayar['U_BANGUNAN'],
            -(float)$old_bayar['U_SERAGAM'],
            -(float)$old_bayar['U_KEGIATAN']
        );

        // Hapus header; FK cascade hanya akan menghapus child dengan bayar_id ini.
        $stmt_del = $koneksi->prepare("DELETE FROM bayar WHERE id = ?");
        $stmt_del->bind_param('i', $id);
        $stmt_del->execute();
        $stmt_del->close();

        audit_event_write(
            $koneksi,
            'payment.deleted',
            'bayar',
            $id,
            'delete',
            $before_audit,
            null,
            $audit_reason,
            [
                'result' => 'committed',
                'source' => 'pembayaran/proses.php',
                'original_operator_id' => $old_bayar['user_id'] ?? null,
                'corrector_id' => current_operator_id(),
            ]
        );

        $koneksi->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Data pembayaran berhasil dihapus!'];
    } catch (Throwable $e) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => security_exception_message($e, 'Pembayaran gagal dihapus.', 'payment-delete')];
    }

    header('Location: lihat.php');
    exit;
}

header('Location: lihat.php');
exit;
?>
