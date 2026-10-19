<?php

function du_academic_year_label(int $month, int $year): string {
    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2200) {
        throw new RuntimeException('Periode pembayaran tidak valid untuk menentukan tahun ajaran.');
    }
    $start = $month >= 7 ? $year : $year - 1;
    return $start . '/' . ($start + 1);
}

function du_normalize_academic_year(string $value): string {
    $value = trim($value);
    if (!preg_match('/^(\d{4})\/(\d{4})$/', $value, $match) || (int)$match[2] !== (int)$match[1] + 1) {
        throw new RuntimeException('Tahun ajaran harus berformat YYYY/YYYY dan berurutan, contoh 2026/2027.');
    }
    return $value;
}

function du_year_dates(string $label): array {
    $label = du_normalize_academic_year($label);
    $start = (int)substr($label, 0, 4);
    return [$start . '-07-01', ($start + 1) . '-06-30'];
}

function du_current_academic_year(): string {
    // Simulasi pergantian tahun hanya untuk tes HTTP pada database disposable.
    $testYear = (string)($_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR'] ?? '');
    $testDatabase = (string)(getenv('SPP_DB_NAME') ?: '');
    if ($testYear !== '' && getenv('SPP_TEST_ALLOW_MUTATION') === '1'
        && preg_match('/^db_spp_audit_[a-z0-9_]+$/', $testDatabase)) {
        return du_normalize_academic_year($testYear);
    }
    return du_academic_year_label((int)date('n'), (int)date('Y'));
}

class DaftarUlangSelectionException extends RuntimeException {
    public function __construct(public string $reason, string $message) {
        parent::__construct($message);
    }
}

/**
 * Mengambil satu tagihan berdasarkan identitas permanennya. Pembayaran tertentu
 * dapat dikecualikan agar saldo saat edit dihitung seolah pembayaran lama sudah
 * dikembalikan ke tagihan asal.
 */
function du_find_bill_by_id(mysqli $db, int $billId, int $excludePaymentId = 0, bool $forUpdate = false): ?array {
    if ($billId <= 0) return null;

    $sql = "SELECT tdu.id,tdu.no_induk,tdu.kelas_snapshot AS kelas,
                   tdu.tahun_ajaran_snapshot AS tahun_ajaran,
                   tdu.nominal_awal,tdu.nominal_tagihan,tdu.status,
                   ta.id AS tahun_ajaran_id,ta.status AS tahun_status,
                   sta.id AS penempatan_id,sta.status AS penempatan_status
            FROM tagihan_daftar_ulang tdu
            JOIN tahun_ajaran ta ON ta.id=tdu.tahun_ajaran_id
            JOIN siswa_tahun_ajaran sta ON sta.id=tdu.penempatan_id
            WHERE tdu.id=? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $billId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$bill) return null;

    if ($forUpdate) {
        // Current read mencegah dua kasir memakai snapshot saldo yang sama.
        $stmt = $db->prepare('SELECT jumlah FROM bayar_du WHERE tagihan_daftar_ulang_id=? AND (bayar_id IS NULL OR bayar_id<>?) FOR UPDATE');
        $stmt->bind_param('ii', $billId, $excludePaymentId);
        $stmt->execute();
        $bill['terbayar'] = 0.0;
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $payment) $bill['terbayar'] += (float)$payment['jumlah'];
    } else {
        $stmt = $db->prepare('SELECT COALESCE(SUM(jumlah),0) AS terbayar FROM bayar_du WHERE tagihan_daftar_ulang_id=? AND (bayar_id IS NULL OR bayar_id<>?)');
        $stmt->bind_param('ii', $billId, $excludePaymentId);
        $stmt->execute();
        $bill['terbayar'] = (float)$stmt->get_result()->fetch_assoc()['terbayar'];
    }
    $stmt->close();
    $bill['nominal_awal'] = (float)$bill['nominal_awal'];
    $bill['nominal_tagihan'] = (float)$bill['nominal_tagihan'];
    $bill['sisa'] = max(0, $bill['nominal_tagihan'] - $bill['terbayar']);
    return $bill;
}

function du_require_selectable_bill(
    mysqli $db,
    int $billId,
    string $noInduk,
    int $excludePaymentId = 0,
    bool $forUpdate = false
): array {
    if ($billId <= 0) {
        throw new DaftarUlangSelectionException('missing', 'Pilih tagihan Daftar Ulang yang akan dibayar.');
    }
    $bill = du_find_bill_by_id($db, $billId, $excludePaymentId, $forUpdate);
    if (!$bill) {
        throw new DaftarUlangSelectionException('not_found', 'Tagihan Daftar Ulang tidak ditemukan. Muat ulang halaman dan pilih tagihan lagi.');
    }
    if ((string)$bill['no_induk'] !== $noInduk) {
        throw new DaftarUlangSelectionException('wrong_student', 'Tagihan Daftar Ulang tidak cocok dengan siswa yang dipilih. Pilih tagihan siswa ini.');
    }
    if ($bill['status'] !== 'open') {
        throw new DaftarUlangSelectionException('cancelled', 'Tagihan Daftar Ulang TA ' . $bill['tahun_ajaran'] . ' sudah dibatalkan. Pilih tagihan lain yang masih terbuka.');
    }
    if (strcmp((string)$bill['tahun_ajaran'], du_current_academic_year()) > 0) {
        throw new DaftarUlangSelectionException('future', 'Tagihan Daftar Ulang TA ' . $bill['tahun_ajaran'] . ' belum dapat dibayar. Pilih tahun ajaran yang sudah berjalan.');
    }
    if ($bill['sisa'] <= .001) {
        throw new DaftarUlangSelectionException('settled', 'Daftar Ulang TA ' . $bill['tahun_ajaran'] . ' sudah lunas. Pilih tagihan lain yang masih bersisa.');
    }
    return $bill;
}

/** Snapshot browser hanya mendeteksi halaman usang; batas pembayaran tetap dari database. */
function du_assert_bill_snapshot(array $bill, $expectedTotal, $expectedPaid): void {
    if ($expectedTotal === null && $expectedPaid === null) return;
    if (!is_scalar($expectedTotal) || !is_scalar($expectedPaid)
        || !is_numeric((string)$expectedTotal) || !is_numeric((string)$expectedPaid)
        || abs((float)$expectedTotal - (float)$bill['nominal_tagihan']) > .001
        || abs((float)$expectedPaid - (float)$bill['terbayar']) > .001) {
        throw new DaftarUlangSelectionException('changed', 'Saldo Daftar Ulang berubah sejak halaman dibuka. Periksa sisa yang sekarang tampil, lalu sesuaikan nominal pembayaran.');
    }
}

function du_assert_payment_amount(array $bill, float $amount): void {
    if ($amount <= .001) return;
    $total = (float)$bill['nominal_tagihan'];
    $paid = (float)$bill['terbayar'];
    if ($paid > $total + .001) {
        throw new DaftarUlangSelectionException('overpaid', 'Pembayaran Daftar Ulang sebelumnya sudah melebihi tagihan. Minta admin memeriksa riwayat transaksi siswa ini.');
    }
    $remaining = max(0, $total - $paid);
    if ($amount > $remaining + .001) {
        throw new DaftarUlangSelectionException('over_limit', 'Sisa Daftar Ulang Rp ' . number_format($remaining, 0, ',', '.')
            . ', tetapi yang diisi Rp ' . number_format($amount, 0, ',', '.') . '. Kurangi nominalnya sebelum menyimpan.');
    }
}

/** @return array<string,array<int,array<string,mixed>>> */
function du_selectable_bills_payload(mysqli $db, int $excludePaymentId = 0, int $alwaysIncludeBillId = 0): array {
    $current = du_current_academic_year();
    $stmt = $db->prepare("SELECT tdu.id,tdu.no_induk,tdu.kelas_snapshot,tdu.tahun_ajaran_snapshot,
            tdu.nominal_tagihan,tdu.status,ta.status AS tahun_status,
            COALESCE(SUM(CASE WHEN bd.bayar_id IS NULL OR bd.bayar_id<>? THEN bd.jumlah ELSE 0 END),0) AS terbayar
        FROM tagihan_daftar_ulang tdu
        JOIN tahun_ajaran ta ON ta.id=tdu.tahun_ajaran_id
        LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id=tdu.id
        WHERE tdu.status='open' AND tdu.tahun_ajaran_snapshot<=?
        GROUP BY tdu.id,tdu.no_induk,tdu.kelas_snapshot,tdu.tahun_ajaran_snapshot,
                 tdu.nominal_tagihan,tdu.status,ta.status
        ORDER BY tdu.tahun_ajaran_snapshot,tdu.id");
    $stmt->bind_param('is', $excludePaymentId, $current);
    $stmt->execute();
    $result = $stmt->get_result();
    $payload = [];
    while ($bill = $result->fetch_assoc()) {
        $total = (float)$bill['nominal_tagihan'];
        $paid = (float)$bill['terbayar'];
        $remaining = max(0, $total - $paid);
        $isCurrent = (string)$bill['tahun_ajaran_snapshot'] === $current;
        if (!$isCurrent && $remaining <= .001 && (int)$bill['id'] !== $alwaysIncludeBillId) continue;
        $payload[(string)$bill['no_induk']][] = [
            'id' => (int)$bill['id'],
            'tahun_ajaran' => (string)$bill['tahun_ajaran_snapshot'],
            'kelas' => (string)$bill['kelas_snapshot'],
            'total' => $total,
            'terbayar' => $paid,
            'sisa' => $remaining,
            'status' => (string)$bill['status'],
            'is_current' => $isCurrent,
            'is_arrear' => !$isCurrent && $remaining > .001,
        ];
    }
    $stmt->close();
    return $payload;
}

function du_find_bill(mysqli $db, string $noInduk, int $month, int $year, bool $forUpdate = false): ?array {
    $label = du_academic_year_label($month, $year);
    $sql = "
        SELECT tdu.id, tdu.no_induk, tdu.kelas_snapshot AS kelas,
               tdu.tahun_ajaran_snapshot AS tahun_ajaran,
               tdu.nominal_awal, tdu.nominal_tagihan, tdu.status,
               ta.id AS tahun_ajaran_id, ta.status AS tahun_status,
               sta.id AS penempatan_id, sta.status AS penempatan_status,
               COALESCE(SUM(CASE WHEN bd.id IS NOT NULL THEN bd.jumlah ELSE 0 END), 0) AS terbayar
        FROM tagihan_daftar_ulang tdu
        JOIN tahun_ajaran ta ON ta.id = tdu.tahun_ajaran_id
        JOIN siswa_tahun_ajaran sta ON sta.id = tdu.penempatan_id
        LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id = tdu.id
        WHERE tdu.no_induk = ? AND ta.label = ?
        GROUP BY tdu.id, tdu.no_induk, tdu.kelas_snapshot, tdu.tahun_ajaran_snapshot,
                 tdu.nominal_awal, tdu.nominal_tagihan, tdu.status,
                 ta.id, ta.status, sta.id, sta.status
        LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ss', $noInduk, $label);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($bill) {
        $bill['nominal_awal'] = (float)$bill['nominal_awal'];
        $bill['nominal_tagihan'] = (float)$bill['nominal_tagihan'];
        $bill['terbayar'] = (float)$bill['terbayar'];
        $bill['sisa'] = max(0, $bill['nominal_tagihan'] - $bill['terbayar']);
    }
    return $bill;
}

function du_require_bill(mysqli $db, string $noInduk, int $month, int $year, bool $forUpdate = false): array {
    $label = du_academic_year_label($month, $year);
    $bill = du_find_bill($db, $noInduk, $month, $year, $forUpdate);
    if (!$bill) {
        throw new RuntimeException('Tagihan Daftar Ulang siswa untuk tahun ajaran ' . $label . ' belum diterbitkan.');
    }
    if ($bill['status'] !== 'open') {
        throw new RuntimeException('Tagihan Daftar Ulang tahun ajaran ' . $label . ' sudah dibatalkan.');
    }
    return $bill;
}

function du_write_audit(
    mysqli $db,
    ?int $yearId,
    ?int $masterId,
    string $action,
    ?array $before,
    ?array $after,
    int $affectedCount = 0
): void {
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $adminName = (string)($_SESSION['admin_nama'] ?? $_SESSION['admin_username'] ?? 'system');
    $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('INSERT INTO daftar_ulang_audit_log (tahun_ajaran_id, master_id, aksi, before_data, after_data, affected_count, admin_id, admin_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iisssiis', $yearId, $masterId, $action, $beforeJson, $afterJson, $affectedCount, $adminId, $adminName);
    $stmt->execute();
    $stmt->close();
}

function du_student_legacy_amounts(array $student, float $classAmount): array {
    $legacyAmount = (float)($student['DAFTAR_ULANG'] ?? 0);
    $legacyDiscount = (float)($student['potong_du'] ?? 0);
    if ($legacyAmount <= 0) {
        return ['initial' => $classAmount, 'discount' => 0.0, 'total' => $classAmount, 'custom' => false];
    }
    $total = max(0, $legacyAmount - $legacyDiscount);
    return ['initial' => $legacyAmount, 'discount' => $legacyDiscount, 'total' => $total, 'custom' => true];
}

function du_sync_student_legacy_from_bill(mysqli $db, int $billId): void {
    $stmt = $db->prepare("UPDATE siswa s
        JOIN tagihan_daftar_ulang tdu ON tdu.no_induk=s.NO_INDUK AND tdu.unit_id=s.unit_id
        SET s.DAFTAR_ULANG=tdu.nominal_awal,
            s.potong_du=GREATEST(tdu.nominal_awal-tdu.nominal_tagihan,0),
            s.tot_du=tdu.nominal_tagihan
        WHERE tdu.id=?");
    $stmt->bind_param('i', $billId);
    $stmt->execute();
    $stmt->close();
}

function du_apply_current_student_override(mysqli $db, string $noInduk): void {
    $label = du_current_academic_year();
    $stmt = $db->prepare("SELECT tdu.id,tdu.tahun_ajaran_id,tdu.master_daftar_ulang_id,
            tdu.nominal_awal,tdu.nominal_tagihan,tdu.status,ta.status AS year_status,
            COALESCE(du.Jumlah,0) AS class_amount,
            s.DAFTAR_ULANG,s.potong_du,s.tot_du,
            COALESCE(SUM(bd.jumlah),0) AS paid
        FROM siswa s
        JOIN tagihan_daftar_ulang tdu ON tdu.no_induk=s.NO_INDUK AND tdu.unit_id=s.unit_id
        JOIN tahun_ajaran ta ON ta.id=tdu.tahun_ajaran_id AND ta.label=?
        LEFT JOIN Daftar_ulang du ON du.id=tdu.master_daftar_ulang_id
        LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id=tdu.id
        WHERE s.NO_INDUK=?
        GROUP BY tdu.id,tdu.tahun_ajaran_id,tdu.master_daftar_ulang_id,
                 tdu.nominal_awal,tdu.nominal_tagihan,tdu.status,ta.status,du.Jumlah,
                 s.DAFTAR_ULANG,s.potong_du,s.tot_du
        LIMIT 1 FOR UPDATE");
    $stmt->bind_param('ss', $label, $noInduk);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$bill) return;
    if ($bill['year_status'] === 'closed') {
        throw new RuntimeException('Daftar Ulang tahun ajaran ' . $label . ' sudah ditutup dan tidak dapat diubah dari Data Siswa.');
    }
    if ($bill['status'] !== 'open') {
        throw new RuntimeException('Tagihan Daftar Ulang siswa sudah dibatalkan dan tidak dapat diubah.');
    }

    $amounts = du_student_legacy_amounts($bill, (float)$bill['class_amount']);
    if ($amounts['initial'] <= 0) {
        throw new RuntimeException('Tarif kelas Daftar Ulang belum tersedia untuk menghapus override siswa.');
    }
    if ($amounts['total'] + 0.001 < (float)$bill['paid']) {
        throw new RuntimeException('Total Daftar Ulang tidak boleh lebih kecil dari cicilan yang sudah dibayar, yaitu Rp ' . number_format((float)$bill['paid'], 0, ',', '.') . '.');
    }

    $billId = (int)$bill['id'];
    $initial = $amounts['initial'];
    $total = $amounts['total'];
    $stmt = $db->prepare('UPDATE tagihan_daftar_ulang SET nominal_awal=?,nominal_tagihan=? WHERE id=?');
    $stmt->bind_param('ddi', $initial, $total, $billId);
    $stmt->execute();
    $stmt->close();
    du_sync_student_legacy_from_bill($db, $billId);
    du_write_audit(
        $db,
        (int)$bill['tahun_ajaran_id'],
        $bill['master_daftar_ulang_id'] === null ? null : (int)$bill['master_daftar_ulang_id'],
        'override_siswa',
        ['no_induk'=>$noInduk,'nominal_awal'=>(float)$bill['nominal_awal'],'nominal_tagihan'=>(float)$bill['nominal_tagihan']],
        ['no_induk'=>$noInduk,'nominal_awal'=>$initial,'nominal_tagihan'=>$total]
    );
}

/**
 * Menyelaraskan tagihan Daftar Ulang tahun berjalan dengan tarif siswa hanya
 * ketika tagihan tersebut belum pernah dibayar.
 *
 * @return array{tahun_ajaran:string,status:string,label:string}
 */
function du_reconcile_current_student_override(mysqli $db, string $noInduk): array {
    $label = du_current_academic_year();
    $result = ['tahun_ajaran' => $label, 'status' => 'unchanged', 'label' => 'Daftar Ulang'];
    $stmt = $db->prepare('SELECT MAX(ta.label) AS latest_year FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk=?');
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $latestYear = (string)($stmt->get_result()->fetch_assoc()['latest_year'] ?? '');
    $stmt->close();
    if ($latestYear !== '' && strcmp($latestYear, $label) > 0) return $result;
    $stmt = $db->prepare("SELECT tdu.id,tdu.tahun_ajaran_id,tdu.master_daftar_ulang_id,
            tdu.nominal_awal,tdu.nominal_tagihan,tdu.status,ta.status AS year_status,
            COALESCE(du.Jumlah,0) AS class_amount,s.DAFTAR_ULANG,s.potong_du,s.tot_du,
            COALESCE(SUM(bd.jumlah),0) AS paid
        FROM siswa s
        JOIN tagihan_daftar_ulang tdu ON tdu.no_induk=s.NO_INDUK AND tdu.unit_id=s.unit_id
        JOIN tahun_ajaran ta ON ta.id=tdu.tahun_ajaran_id AND ta.label=?
        LEFT JOIN Daftar_ulang du ON du.id=tdu.master_daftar_ulang_id
        LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id=tdu.id
        WHERE s.NO_INDUK=?
        GROUP BY tdu.id,tdu.tahun_ajaran_id,tdu.master_daftar_ulang_id,
                 tdu.nominal_awal,tdu.nominal_tagihan,tdu.status,ta.status,du.Jumlah,
                 s.DAFTAR_ULANG,s.potong_du,s.tot_du
        LIMIT 1 FOR UPDATE");
    $stmt->bind_param('ss', $label, $noInduk);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$bill) return $result;

    $amounts = du_student_legacy_amounts($bill, (float)$bill['class_amount']);
    if ($amounts['initial'] <= 0) return $result;
    $different = abs((float)$bill['nominal_awal'] - $amounts['initial']) > .001
        || abs((float)$bill['nominal_tagihan'] - $amounts['total']) > .001;
    if (!$different) return $result;
    if ((float)$bill['paid'] > .001 || $bill['status'] !== 'open' || $bill['year_status'] === 'closed') {
        $result['status'] = 'locked';
        return $result;
    }

    $billId = (int)$bill['id'];
    $initial = (float)$amounts['initial'];
    $total = (float)$amounts['total'];
    $stmt = $db->prepare('UPDATE tagihan_daftar_ulang SET nominal_awal=?,nominal_tagihan=? WHERE id=?');
    $stmt->bind_param('ddi', $initial, $total, $billId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('SELECT nominal_awal,nominal_tagihan FROM tagihan_daftar_ulang WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $billId);
    $stmt->execute();
    $verified = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$verified
        || abs((float)$verified['nominal_awal'] - $initial) > .001
        || abs((float)$verified['nominal_tagihan'] - $total) > .001) {
        throw new RuntimeException('Verifikasi sinkronisasi Daftar Ulang gagal. Tidak ada perubahan yang disimpan.');
    }

    du_write_audit(
        $db,
        (int)$bill['tahun_ajaran_id'],
        $bill['master_daftar_ulang_id'] === null ? null : (int)$bill['master_daftar_ulang_id'],
        'sinkronisasi_siswa',
        ['no_induk'=>$noInduk,'nominal_awal'=>(float)$bill['nominal_awal'],'nominal_tagihan'=>(float)$bill['nominal_tagihan']],
        ['no_induk'=>$noInduk,'nominal_awal'=>$initial,'nominal_tagihan'=>$total]
    );
    $result['status'] = 'synced';
    return $result;
}

function du_sync_open_bills_for_master_rate(
    mysqli $db,
    int $masterId,
    string $yearLabel,
    float $oldAmount,
    float $newAmount
): int {
    $isCurrentYear = $yearLabel === du_current_academic_year();
    $stmt = $db->prepare("SELECT tdu.id,tdu.no_induk,tdu.nominal_awal,tdu.nominal_tagihan,
            s.DAFTAR_ULANG,s.potong_du,s.tot_du,COALESCE(SUM(bd.jumlah),0) paid
        FROM tagihan_daftar_ulang tdu
        JOIN siswa s ON s.NO_INDUK=tdu.no_induk AND s.unit_id=tdu.unit_id
        LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id=tdu.id
        WHERE tdu.master_daftar_ulang_id=? AND tdu.status='open'
        GROUP BY tdu.id,tdu.no_induk,tdu.nominal_awal,tdu.nominal_tagihan,
                 s.DAFTAR_ULANG,s.potong_du,s.tot_du FOR UPDATE");
    $stmt->bind_param('i', $masterId);
    $stmt->execute();
    $bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $affected = 0;
    foreach ($bills as $bill) {
        if ($isCurrentYear) {
            $legacyUnset = (float)$bill['DAFTAR_ULANG'] <= 0
                && (float)$bill['potong_du'] <= 0
                && (float)$bill['tot_du'] <= 0;
            $usesOldClassRate =
                abs((float)$bill['DAFTAR_ULANG'] - $oldAmount) < .001
                && abs((float)$bill['potong_du']) < .001
                && abs((float)$bill['tot_du'] - $oldAmount) < .001;
            if (!$legacyUnset && !$usesOldClassRate) continue;
        } else {
            $usesOldClassRate =
                abs((float)$bill['nominal_awal'] - $oldAmount) < .001
                && abs((float)$bill['nominal_tagihan'] - $oldAmount) < .001;
            if (!$usesOldClassRate) continue;
        }

        $paid = (float)$bill['paid'];
        if ($paid > $newAmount + .001) {
            throw new RuntimeException('Nominal baru lebih kecil daripada cicilan siswa yang sudah masuk.');
        }
        $billId = (int)$bill['id'];
        $stmt = $db->prepare('UPDATE tagihan_daftar_ulang SET nominal_awal=?,nominal_tagihan=? WHERE id=?');
        $stmt->bind_param('ddi', $newAmount, $newAmount, $billId);
        $stmt->execute();
        $stmt->close();
        if ($isCurrentYear) du_sync_student_legacy_from_bill($db, $billId);
        $affected++;
    }
    return $affected;
}

function du_create_bill_for_placement(mysqli $db, int $placementId, bool $syncLegacy = true): ?int {
    $stmt = $db->prepare("SELECT sta.id, sta.tahun_ajaran_id, sta.no_induk, sta.kelas, sta.status AS placement_status,
                                ta.label, ta.status AS year_status, du.id AS master_id, du.Jumlah,
                                s.DAFTAR_ULANG,s.potong_du,s.tot_du
                         FROM siswa_tahun_ajaran sta
                         JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
                         JOIN siswa s ON s.NO_INDUK=sta.no_induk AND s.unit_id=sta.unit_id
                         LEFT JOIN Daftar_ulang du ON du.tahun_ajaran_id = ta.id AND du.kelas = sta.kelas
                         WHERE sta.id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('i', $placementId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['placement_status'] !== 'aktif' || $row['year_status'] !== 'published') return null;
    require_once __DIR__.'/legacy_full_read.php';if(lful_is_covered($db,(string)$row['no_induk'],'daftar_ulang',(string)$row['label']))return null;
    if (!$row['master_id'] || (float)$row['Jumlah'] <= 0) {
        throw new RuntimeException('Tarif Daftar Ulang untuk kelas penempatan siswa belum tersedia.');
    }
    $yearId = (int)$row['tahun_ajaran_id'];
    $rowPlacementId = (int)$row['id'];
    $masterId = (int)$row['master_id'];
    $studentNumber = (string)$row['no_induk'];
    $class = (string)$row['kelas'];
    $label = (string)$row['label'];
    $amounts = $label === du_current_academic_year()
        ? du_student_legacy_amounts($row, (float)$row['Jumlah'])
        : ['initial'=>(float)$row['Jumlah'], 'discount'=>0.0, 'total'=>(float)$row['Jumlah'], 'custom'=>false];
    $stmt = $db->prepare("INSERT INTO tagihan_daftar_ulang
        (tahun_ajaran_id, penempatan_id, master_daftar_ulang_id, no_induk, kelas_snapshot, tahun_ajaran_snapshot, nominal_awal, nominal_tagihan)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $initial = $amounts['initial'];
    $total = $amounts['total'];
    $stmt->bind_param('iiisssdd', $yearId, $rowPlacementId, $masterId, $studentNumber, $class, $label, $initial, $total);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    if ($id <= 0) {
        $stmt = $db->prepare('SELECT id FROM tagihan_daftar_ulang WHERE penempatan_id=? LIMIT 1');
        $stmt->bind_param('i', $rowPlacementId); $stmt->execute();
        $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
    }
    if($id>0){$restore=$db->prepare("UPDATE tagihan_daftar_ulang SET status='open',cancel_reason=NULL,master_daftar_ulang_id=?,kelas_snapshot=?,nominal_awal=?,nominal_tagihan=? WHERE id=? AND status='cancelled' AND cancel_reason LIKE 'Koreksi pendaftaran awal PSB:%' AND NOT EXISTS(SELECT 1 FROM bayar_du WHERE tagihan_daftar_ulang_id=?)");$restore->bind_param('isddii',$masterId,$class,$initial,$total,$id,$id);$restore->execute();$restore->close();}
    if ($syncLegacy && $id > 0 && $label === du_current_academic_year()) du_sync_student_legacy_from_bill($db, $id);
    return $id ?: null;
}

function du_publish_year_from_active_students(mysqli $db, int $yearId, string $label): int {
    $label = du_normalize_academic_year($label);
    $stmt = $db->prepare('SELECT id, label, status FROM tahun_ajaran WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $yearId); $stmt->execute();
    $year = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$year || $year['label'] !== $label) throw new RuntimeException('Tahun ajaran penerbitan tidak valid.');
    if ($year['status'] === 'closed') throw new RuntimeException('Tahun ajaran yang sudah ditutup tidak dapat diterbitkan ulang.');
    if ($year['status'] === 'published') {
        $stmt = $db->prepare('SELECT COUNT(*) total FROM tagihan_daftar_ulang WHERE tahun_ajaran_id = ?');
        $stmt->bind_param('i', $yearId); $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
        return $total;
    }

    [$unitFirst,$unitLast]=unit_level_bounds();
    $regularClasses=unit_level_in_sql();
    $stmt = $db->prepare("SELECT COUNT(DISTINCT kelas) total FROM Daftar_ulang
        WHERE tahun_ajaran_id=? AND kelas IN {$regularClasses} AND Jumlah>0");
    $stmt->bind_param('i', $yearId); $stmt->execute();
    $masterCount = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    if ($masterCount !== $unitLast-$unitFirst+1) throw new RuntimeException('Lengkapi nominal Daftar Ulang kelas '.$unitFirst.' sampai '.$unitLast.' sebelum menerbitkan.');

    $currentYear = du_current_academic_year();
    if ($label === $currentYear) {
        // Tahun pertama yang diketahui boleh dimulai dari kelas siswa saat ini.
        $stmt = $db->prepare("INSERT INTO siswa_tahun_ajaran
            (tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status)
            SELECT ?,s.NO_INDUK,s.KELAS,s.master_kelas_id,
                CASE WHEN mk.id IS NULL OR mk.is_placeholder=1
                    THEN CONCAT('Kelas ',s.KELAS,' (Belum Ditentukan)')
                    ELSE CONCAT(mk.tingkat,UPPER(mk.kode_rombel)) END,
                s.SPP_PERBULAN,s.POMG,'aktif'
            FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
            WHERE s.is_active=1 AND s.KELAS IN {$regularClasses}
              AND NOT EXISTS(SELECT 1 FROM siswa_tahun_ajaran prior WHERE prior.no_induk=s.NO_INDUK AND prior.unit_id=s.unit_id)");
        $stmt->bind_param('i', $yearId);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $db->prepare('SELECT COUNT(*) total FROM tagihan_daftar_ulang WHERE tahun_ajaran_id=?');
    $stmt->bind_param('i', $yearId); $stmt->execute();
    $existingBills = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    if ($existingBills > 0) throw new RuntimeException('Tahun ajaran draf memiliki tagihan lama yang tidak konsisten. Periksa database sebelum menerbitkan ulang.');

    if (strcmp($label, $currentYear) >= 0) {
        $stmt = $db->prepare("SELECT COUNT(*) AS missing FROM siswa s
            LEFT JOIN siswa_tahun_ajaran sta ON sta.no_induk=s.NO_INDUK AND sta.unit_id=s.unit_id AND sta.tahun_ajaran_id=?
            WHERE s.is_active=1 AND s.KELAS IN {$regularClasses} AND sta.id IS NULL");
        $stmt->bind_param('i', $yearId);
        $stmt->execute();
        $missing = (int)$stmt->get_result()->fetch_assoc()['missing'];
        $stmt->close();
        if ($missing > 0) throw new RuntimeException($missing . ' siswa belum memiliki riwayat kelas tahun ajaran ini. Selesaikan kenaikan kelas sebelum menerbitkan tagihan.');
    }
    $stmt = $db->prepare("SELECT COUNT(*) AS total FROM siswa_tahun_ajaran
        WHERE tahun_ajaran_id=? AND kelas IN {$regularClasses}");
    $stmt->bind_param('i', $yearId);
    $stmt->execute();
    $placementCount = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    if ($placementCount === 0) throw new RuntimeException('Belum ada riwayat kelas yang dapat diterbitkan pada tahun ajaran ini.');
    require_once __DIR__ . '/komite_billing.php';
    $stmtPlacement=$db->prepare("SELECT id FROM siswa_tahun_ajaran WHERE tahun_ajaran_id=? AND kelas IN {$regularClasses}");
    $stmtPlacement->bind_param('i',$yearId);$stmtPlacement->execute();
    foreach ($stmtPlacement->get_result()->fetch_all(MYSQLI_ASSOC) as $placement) komite_sync_placement($db,(int)$placement['id']);
    $stmtPlacement->close();

    $isCurrentYear = $label === du_current_academic_year() ? 1 : 0;
    require_once __DIR__.'/legacy_full_read.php';$legacyExclude='';$expectedCount=$placementCount;
    if(lful_read_ready($db)){
        $coveredSql="EXISTS(SELECT 1 FROM legacy_full_coverage c JOIN siswa covered_student ON covered_student.id=c.student_id AND covered_student.unit_id=c.unit_id WHERE covered_student.NO_INDUK=sta.no_induk AND covered_student.unit_id=sta.unit_id AND c.category='daftar_ulang' AND c.period=(SELECT label FROM tahun_ajaran WHERE id=sta.tahun_ajaran_id))";
        $stmt=$db->prepare("SELECT COUNT(*) n FROM siswa_tahun_ajaran sta WHERE sta.tahun_ajaran_id=? AND sta.kelas IN {$regularClasses} AND $coveredSql");$stmt->bind_param('i',$yearId);$stmt->execute();$expectedCount-=(int)$stmt->get_result()->fetch_assoc()['n'];$stmt->close();$legacyExclude=' AND NOT '.$coveredSql;
    }
    $stmt = $db->prepare("INSERT IGNORE INTO tagihan_daftar_ulang
        (tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan)
        SELECT sta.tahun_ajaran_id,sta.id,du.id,sta.no_induk,sta.kelas,?,
               CASE WHEN ?=1 AND s.DAFTAR_ULANG>0 THEN s.DAFTAR_ULANG ELSE du.Jumlah END,
               CASE WHEN ?=1 AND s.DAFTAR_ULANG>0 THEN GREATEST(s.DAFTAR_ULANG-s.potong_du,0) ELSE du.Jumlah END
        FROM siswa_tahun_ajaran sta
        JOIN siswa s ON s.NO_INDUK=sta.no_induk AND s.unit_id=sta.unit_id
        JOIN Daftar_ulang du ON du.tahun_ajaran_id=sta.tahun_ajaran_id AND du.kelas=sta.kelas
        WHERE sta.tahun_ajaran_id=? AND sta.kelas IN {$regularClasses}{$legacyExclude}");
    $stmt->bind_param('siii', $label, $isCurrentYear, $isCurrentYear, $yearId); $stmt->execute();
    $created = $stmt->affected_rows; $stmt->close();
    if ($created !== $expectedCount) throw new RuntimeException('Jumlah tagihan tidak sesuai riwayat kelas tahun ajaran. Penerbitan dibatalkan.');

    if ($isCurrentYear === 1) {
        $stmt = $db->prepare("UPDATE siswa s
            JOIN tagihan_daftar_ulang tdu ON tdu.no_induk=s.NO_INDUK AND tdu.unit_id=s.unit_id AND tdu.tahun_ajaran_id=?
            SET s.DAFTAR_ULANG=tdu.nominal_awal,
                s.potong_du=GREATEST(tdu.nominal_awal-tdu.nominal_tagihan,0),
                s.tot_du=tdu.nominal_tagihan");
        $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
    }

    $stmt = $db->prepare("UPDATE tahun_ajaran SET status='published',published_at=NOW() WHERE id=? AND status='draft'");
    $stmt->bind_param('i', $yearId); $stmt->execute();
    if ($stmt->affected_rows !== 1) { $stmt->close(); throw new RuntimeException('Status tahun ajaran gagal diterbitkan.'); }
    $stmt->close();
    return $created;
}
