<?php
// ============================================
// pembayaran/proses.php - Insert / Update / Delete
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/daftar_ulang.php';
require_once '../includes/biaya_lain.php';
require_once '../includes/tagihan_tahunan.php';
require_once '../includes/spp_payment_status.php';
requireRole(['admin', 'kasir']);

$aksi = $_POST['aksi'] ?? $_GET['aksi'] ?? '';

function parse_amount($value) {
    if ($value === null || $value === '') return 0.0;
    $normalized = str_replace(['.', ','], ['', '.'], trim((string)$value));
    return is_numeric($normalized) ? (float)$normalized : NAN;
}

function current_operator_id(): string {
    return (string)($_SESSION['admin_id'] ?? '');
}

function payment_failure_flash(Throwable $error, string $fallbackPrefix): array {
    if ($error instanceof SppPaymentException) {
        return [
            'type' => 'error',
            'scope' => 'spp',
            'msg' => $error->getMessage(),
            'spp_status' => $error->status(),
        ];
    }
    return ['type' => 'error', 'msg' => $fallbackPrefix . $error->getMessage()];
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
        SELECT s.PANGKAL, s.potong_pangkal, s.tot_pangkal, s.BANGUNAN, s.SERAGAM, s.KEGIATAN,
               MAKAN, SORGA, INFAQ, SPP_PERBULAN, POMG, DAFTAR_ULANG, potong_du, tot_du,
               PANGKAL_BAYAR, BANGUNAN_BAYAR, SERAGAM_BAYAR, KEGIATAN_BAYAR,
               COALESCE(mk.tingkat, CAST(s.KELAS AS UNSIGNED)) AS tingkat, mk.kode_rombel
        FROM siswa s
        LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
        WHERE s.NO_INDUK = ?
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
        SELECT COALESCE(SUM(CASE WHEN TAHUN = ? AND (BULAN = ? OR BULAN = ? OR BULAN = ?) THEN U_SPP ELSE 0 END), 0) AS spp
        FROM bayar
        WHERE NO_INDUK = ? AND id <> ?
    ');
    $stmtPaid->bind_param('sssssi', $tahun, $bulan, $monthLabel, $legacyMonth, $noInduk, $excludePaymentId);
    $stmtPaid->execute();
    $paid = $stmtPaid->get_result()->fetch_assoc() ?: [];
    $stmtPaid->close();

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
    $isPsb = (int)($student['tingkat'] ?? 0) === 0 || strtoupper((string)($student['kode_rombel'] ?? '')) === 'PSB';
    $annualInputTotal = 0.0;
    foreach (annual_fee_components() as $component => $cfg) {
        $annualInputTotal += (float)($components[$component] ?? 0);
    }
    if ($isPsb && $sppInput > 0.001) {
        throw new SppPaymentException(spp_payment_status_from_state(
            ['exists' => true, 'is_active' => 1, 'tingkat' => $student['tingkat'] ?? 0, 'kode_rombel' => $student['kode_rombel'] ?? 'PSB'],
            $bulan,
            $tahun,
            (float)($student['SPP_PERBULAN'] ?? 0),
            0,
            []
        ));
    }
    if ($isPsb && ($annualInputTotal > 0.001 || $uangDu > 0.001)) {
        throw new RuntimeException('Siswa PSB belum dapat membayar SPP, Daftar Ulang, atau tagihan tahunan. Pindahkan siswa ke rombel reguler terlebih dahulu.');
    }

    $sppTariff = spp_tariff_for_payment_period(
        $db,
        $noInduk,
        $bulan,
        $tahun,
        (float)$student['SPP_PERBULAN'],
        true
    );
    if ($sppInput > 0) {
        validate_spp_full_payment($db, $noInduk, $bulan, $tahun, $sppTariff, $sppInput, $excludePaymentId);
    }

    $limits = [
        'spp' => ['label' => 'Uang SPP', 'total' => $sppTariff, 'paid' => (float)($paid['spp'] ?? 0), 'input' => (float)($components['spp'] ?? 0)],
        'du' => ['label' => 'Daftar Ulang', 'total' => $duTotal, 'paid' => (float)($paid['du'] ?? 0), 'input' => $uangDu],
    ];
    foreach (annual_fee_components() as $component => $cfg) {
        $input = (float)($components[$component] ?? 0);
        if ($input <= 0) continue;
        $bill = annual_fee_require_bill($db, $noInduk, $component, (int)$bulan, (int)$tahun, true);
        $limits[$component] = [
            'label' => $cfg['label'],
            'total' => (float)$bill['nominal_tagihan'],
            'paid' => annual_fee_paid_for_bill($db, (int)$bill['id'], $excludePaymentId),
            'input' => $input,
        ];
    }

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

// ── INSERT ──────────────────────────────────
if ($aksi === 'input') {
    $no_induk        = trim($_POST['no_induk'] ?? '');
    // Transaksi baru selalu memakai waktu server Asia/Jakarta.
    $tanggal_bayar   = date('Y-m-d H:i:s');
    $bulan_bayar     = normalize_month_code($_POST['bulan_bayar'] ?? '');
    $tahun_bayar     = $_POST['tahun_bayar'] ?? date('Y');
    $sistem_pembayaran = $_POST['sistem_pembayaran'] ?? 'VA';
    
    $uang_pangkal    = parse_amount($_POST['uang_pangkal'] ?? 0);
    $uang_bangunan   = parse_amount($_POST['uang_bangunan'] ?? 0);
    $uang_seragam    = parse_amount($_POST['uang_seragam'] ?? 0);
    $uang_kegiatan   = parse_amount($_POST['uang_kegiatan'] ?? 0);
    $uang_spp        = parse_amount($_POST['uang_spp'] ?? 0);
    $uang_komite     = parse_amount($_POST['uang_komite'] ?? 0);
    $uang_makan      = parse_amount($_POST['uang_makan'] ?? 0);
    $uang_sorga      = parse_amount($_POST['uang_sorga'] ?? 0);
    $uang_infaq      = parse_amount($_POST['uang_infaq'] ?? 0);
    $uang_lain       = 0.0;
    $uang_du         = parse_amount($_POST['uang_du'] ?? 0);
    $ll_1_ket = $ll_2_ket = $ll_3_ket = $ll_4_ket = '';
    $ll_1_nom = $ll_2_nom = $ll_3_nom = $ll_4_nom = 0.0;
    
    $potongan_spp    = parse_amount($_POST['potongan_spp'] ?? 0);
    $legacy_tabungan_input = parse_amount($_POST['tabungan_wajib'] ?? 0);
    $total_jumlah    = 0.0;
    $catatan         = trim((string)($_POST['catatan'] ?? ''));
    if (mb_strlen($catatan) > 255) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Catatan maksimal 255 karakter.'];
        header('Location: form.php');
        exit;
    }
    $kelas_du        = $_POST['kelas_du'] ?? '';
    $tahun_ajaran_du = $_POST['tahun_ajaran_du'] ?? '';
    $payment_plan    = $_POST['payment_plan'] ?? 'monthly';

    if (empty($no_induk)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih siswa terlebih dahulu!'];
        header('Location: form.php');
        exit;
    }

    $koneksi->begin_transaction();

    try {
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
            annual_fee_sync_payment($koneksi, $bayar_id, $no_induk, $row_month, $row_year, [
                'pangkal' => $row_pangkal,
                'bangunan' => $row_bangunan,
                'seragam' => $row_seragam,
                'kegiatan' => $row_kegiatan,
                'komite' => $row_komite,
                'makan' => $row_makan,
                'sorga' => $row_sorga,
                'infaq' => $row_infaq,
            ]);

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
        $_SESSION['flash'] = payment_failure_flash($e, 'Gagal menyimpan: ');
        header('Location: form.php');
        exit;
    }
}

// ── UPDATE ──────────────────────────────────
if ($aksi === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { header('Location: lihat.php'); exit; }

    $no_induk        = trim($_POST['no_induk'] ?? '');
    $tanggal_bayar   = $_POST['tanggal_bayar'] ?? date('Y-m-d H:i:s');
    if (strlen($tanggal_bayar) === 10) {
        $tanggal_bayar .= ' ' . date('H:i:s');
    }
    $bulan_bayar     = normalize_month_code($_POST['bulan_bayar'] ?? '');
    $tahun_bayar     = $_POST['tahun_bayar'] ?? date('Y');
    $sistem_pembayaran = $_POST['sistem_pembayaran'] ?? 'VA';
    
    $uang_pangkal    = parse_amount($_POST['uang_pangkal'] ?? 0);
    $uang_bangunan   = parse_amount($_POST['uang_bangunan'] ?? 0);
    $uang_seragam    = parse_amount($_POST['uang_seragam'] ?? 0);
    $uang_kegiatan   = parse_amount($_POST['uang_kegiatan'] ?? 0);
    $uang_spp        = parse_amount($_POST['uang_spp'] ?? 0);
    $uang_komite     = parse_amount($_POST['uang_komite'] ?? 0);
    $uang_makan      = parse_amount($_POST['uang_makan'] ?? 0);
    $uang_sorga      = parse_amount($_POST['uang_sorga'] ?? 0);
    $uang_infaq      = parse_amount($_POST['uang_infaq'] ?? 0);
    $uang_lain       = 0.0;
    $uang_du         = parse_amount($_POST['uang_du'] ?? 0);
    $ll_1_ket = $ll_2_ket = $ll_3_ket = $ll_4_ket = '';
    $ll_1_nom = $ll_2_nom = $ll_3_nom = $ll_4_nom = 0.0;
    
    $potongan_spp    = parse_amount($_POST['potongan_spp'] ?? 0);
    $legacy_tabungan_input = parse_amount($_POST['tabungan_wajib'] ?? 0);
    $total_jumlah    = 0.0;
    $catatan         = trim((string)($_POST['catatan'] ?? ''));
    if (mb_strlen($catatan) > 255) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Catatan maksimal 255 karakter.'];
        header('Location: edit.php?id=' . $id);
        exit;
    }
    $kelas_du        = $_POST['kelas_du'] ?? '';
    $tahun_ajaran_du = $_POST['tahun_ajaran_du'] ?? '';

    if (empty($no_induk)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih siswa terlebih dahulu!'];
        header('Location: edit.php?id=' . $id);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        $old_bayar = find_linked_payment($koneksi, $id);
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
            $oldNis = (string)$old_bayar['NO_INDUK'];
            $oldBill = spp_tariff_for_payment_period(
                $koneksi,
                $oldNis,
                $oldSppMonth,
                $oldSppYear,
                spp_monthly_bill_for_student($koneksi, $oldNis),
                true
            );
            $oldPaidAfter = spp_paid_for_period($koneksi, $oldNis, $oldSppMonth, $oldSppYear, $id);
            if ($sameSppContext) $oldPaidAfter += $uang_spp;
            validate_spp_period_not_breaking_future(
                $koneksi,
                $oldNis,
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
            TGL_BYR=?, BULAN=?, TAHUN=?, user_id=?, sistem_pembayaran=?,
            LAIN_LAIN1=?, JUMLAH1=?, LAIN_LAIN2=?, JUMLAH2=?, LAIN_LAIN3=?, JUMLAH3=?, LAIN_LAIN4=?, JUMLAH4=?,
            th_ajaran=?, kelas_du=?, potong_spp=?, total_jumlah=?, payment_link_version=1
            WHERE id=?";

        $stmt = $koneksi->prepare($sql);
        $user_id = current_operator_id();
        
        $stmt->bind_param(
            'ssddddddddddsssssssdsdsdsdssddi',
            $no_induk, $kelas_siswa, $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_makan, $uang_sorga, $uang_infaq, $uang_komite, $uang_lain, $catatan,
            $tanggal_bayar, $bulan_bayar, $tahun_bayar, $user_id, $sistem_pembayaran,
            $ll_1_ket, $ll_1_nom, $ll_2_ket, $ll_2_nom, $ll_3_ket, $ll_3_nom, $ll_4_ket, $ll_4_nom,
            $tahun_ajaran_du, $kelas_du, $potongan_spp, $total_jumlah, $id
        );
        $stmt->execute();
        $stmt->close();
        $stmtClass = $koneksi->prepare('UPDATE bayar SET master_kelas_id=NULLIF(?,0), kelas_rombel_snapshot=? WHERE id=?');
        $stmtClass->bind_param('isi', $master_kelas_id, $kelas_rombel_snapshot, $id);
        $stmtClass->execute();
        $stmtClass->close();

        // Klaim periode ikut berpindah/dihapus ketika bulan, tahun, siswa,
        // atau nominal SPP pada transaksi diedit.
        sync_spp_period_claim($koneksi, $id, $no_induk, $bulan_bayar, (string)$tahun_bayar, $uang_spp);
        annual_fee_sync_payment($koneksi, $id, $no_induk, $bulan_bayar, (string)$tahun_bayar, [
            'pangkal' => $uang_pangkal,
            'bangunan' => $uang_bangunan,
            'seragam' => $uang_seragam,
            'kegiatan' => $uang_kegiatan,
            'komite' => $uang_komite,
            'makan' => $uang_makan,
            'sorga' => $uang_sorga,
            'infaq' => $uang_infaq,
        ]);

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
        $_SESSION['flash'] = payment_failure_flash($e, 'Gagal memperbarui: ');
        header('Location: edit.php?id=' . $id);
        exit;
    }
}

// ── DELETE ──────────────────────────────────
if ($aksi === 'hapus') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { header('Location: lihat.php'); exit; }

    $koneksi->begin_transaction();

    try {
        $old_bayar = find_linked_payment($koneksi, $id);

        if ((float)$old_bayar['U_SPP'] > 0) {
            $oldSppMonth = normalize_month_code((string)$old_bayar['BULAN']);
            $oldSppYear = (string)$old_bayar['TAHUN'];
            $oldNis = (string)$old_bayar['NO_INDUK'];
            validate_spp_period_not_breaking_future(
                $koneksi,
                $oldNis,
                $oldSppMonth,
                $oldSppYear,
                spp_tariff_for_payment_period(
                    $koneksi,
                    $oldNis,
                    $oldSppMonth,
                    $oldSppYear,
                    spp_monthly_bill_for_student($koneksi, $oldNis),
                    true
                ),
                spp_paid_for_period($koneksi, $oldNis, $oldSppMonth, $oldSppYear, $id),
                $id,
                'dihapus'
            );
        }

        $legacyMirrorStudent = (string)$old_bayar['NO_INDUK'];

        // Hapus header; FK cascade hanya akan menghapus child dengan bayar_id ini.
        $stmt_del = $koneksi->prepare("DELETE FROM bayar WHERE id = ?");
        $stmt_del->bind_param('i', $id);
        $stmt_del->execute();
        $stmt_del->close();
        annual_fee_sync_legacy_paid_mirror($koneksi, $legacyMirrorStudent);

        $koneksi->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Data pembayaran berhasil dihapus!'];
    } catch (Exception $e) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal menghapus data: ' . $e->getMessage()];
    }

    header('Location: lihat.php');
    exit;
}

header('Location: lihat.php');
exit;
?>
