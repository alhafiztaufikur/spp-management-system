<?php

require_once __DIR__ . '/spp_sequence.php';

class SppPaymentException extends RuntimeException {
    private array $status;

    public function __construct(array $status) {
        $this->status = $status;
        parent::__construct((string)($status['message'] ?? 'Pembayaran SPP belum dapat diproses.'));
    }

    public function status(): array {
        return $this->status;
    }
}

function payment_month_label(string $bulan): string {
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    return $months[spp_sequence_month_code($bulan)] ?? $bulan;
}

function spp_payment_period_label(string $bulan, string $tahun): string {
    return trim(payment_month_label($bulan) . ' ' . $tahun);
}

function spp_payment_money(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/**
 * Membentuk status SPP murni dari data yang sudah dibaca.
 * Fungsi ini bebas database agar pesan dan prioritas status dapat diuji langsung.
 */
function spp_payment_status_from_state(
    array $student,
    string $bulan,
    string $tahun,
    float $tariff,
    float $selectedPaid,
    array $priorPeriods
): array {
    $month = spp_sequence_month_code($bulan);
    $selectedLabel = spp_payment_period_label($month, $tahun);
    $selected = [
        'bulan' => $month,
        'tahun' => $tahun,
        'label' => $selectedLabel,
        'tariff' => $tariff,
        'paid' => $selectedPaid,
        'remaining' => max(0, $tariff - $selectedPaid),
    ];

    $base = [
        'ok' => true,
        'status' => 'payable',
        'code' => 'payable',
        'lock_spp' => false,
        'title' => '',
        'message' => '',
        'amount_label' => '',
        'selected' => $selected,
        'blocking_period' => null,
    ];

    $studentExists = (bool)($student['exists'] ?? true);
    $studentActive = (int)($student['is_active'] ?? 1) === 1;
    $allowInactive = (bool)($student['allow_inactive'] ?? false);
    $isPsb = (int)($student['tingkat'] ?? -1) === 0
        || strtoupper(trim((string)($student['kode_rombel'] ?? ''))) === 'PSB';

    if (!$studentExists || (!$studentActive && !$allowInactive) || $isPsb) {
        return array_merge($base, [
            'status' => 'student_ineligible',
            'code' => 'student_ineligible',
            'lock_spp' => true,
            'title' => 'SPP belum tersedia',
            'message' => 'Hubungi admin untuk memeriksa kelas siswa.',
        ]);
    }

    if ($tariff <= 0.001) {
        return array_merge($base, [
            'status' => 'tariff_missing',
            'code' => 'tariff_missing',
            'lock_spp' => true,
            'title' => 'Tarif SPP belum tersedia',
            'message' => 'Hubungi admin sebelum melanjutkan.',
        ]);
    }

    if ($selectedPaid > 0.001) {
        if ($selectedPaid + 0.001 >= $tariff) {
            return array_merge($base, [
                'status' => 'already_paid',
                'code' => 'already_paid',
                'lock_spp' => true,
                'title' => 'SPP sudah lunas',
                'message' => 'SPP ' . $selectedLabel . ' sudah lunas. Pilih bulan lain.',
            ]);
        }

        return array_merge($base, [
            'status' => 'partial_history',
            'code' => 'partial_history',
            'lock_spp' => true,
            'title' => 'SPP sudah memiliki pembayaran',
            'message' => 'SPP ' . $selectedLabel . ' sudah dibayar ' . spp_payment_money($selectedPaid) . '. Koreksi transaksi lama sebelum melanjutkan.',
            'amount_label' => 'Sudah dibayar ' . spp_payment_money($selectedPaid),
        ]);
    }

    foreach ($priorPeriods as $period) {
        $periodTariff = (float)($period['tarif'] ?? 0);
        $periodPaid = (float)($period['paid'] ?? 0);
        if ($periodTariff <= 0.001 || $periodPaid + 0.001 >= $periodTariff) continue;

        $remaining = max(0, $periodTariff - $periodPaid);
        $blockingLabel = (string)($period['label'] ?? spp_payment_period_label((string)$period['bulan'], (string)$period['tahun']));
        return array_merge($base, [
            'status' => 'arrears',
            'code' => 'arrears',
            'lock_spp' => true,
            'title' => 'SPP belum bisa dibayar',
            'message' => 'Masih ada tunggakan ' . $blockingLabel . '. Lunasi bulan tersebut sebelum membayar ' . $selectedLabel . '.',
            'amount_label' => 'Sisa ' . spp_payment_money($remaining),
            'blocking_period' => [
                'bulan' => (string)$period['bulan'],
                'tahun' => (string)$period['tahun'],
                'label' => $blockingLabel,
                'tariff' => $periodTariff,
                'paid' => $periodPaid,
                'remaining' => $remaining,
            ],
        ]);
    }

    return $base;
}

function spp_payment_amount_status(string $bulan, string $tahun, float $tariff): array {
    $label = spp_payment_period_label($bulan, $tahun);
    return [
        'ok' => true,
        'status' => 'amount_mismatch',
        'code' => 'amount_mismatch',
        'lock_spp' => false,
        'title' => 'Nominal SPP belum sesuai',
        'message' => 'SPP ' . $label . ' harus dibayar penuh ' . spp_payment_money($tariff) . '.',
        'amount_label' => 'Tagihan ' . spp_payment_money($tariff),
        'selected' => [
            'bulan' => spp_sequence_month_code($bulan),
            'tahun' => $tahun,
            'label' => $label,
            'tariff' => $tariff,
        ],
        'blocking_period' => null,
    ];
}

function spp_active_placements(mysqli $db, string $noInduk, bool $forUpdate = false): array {
    $sql = 'SELECT ta.label AS tahun_ajaran, sta.spp_perbulan_snapshot, sta.status
        FROM siswa_tahun_ajaran sta
        JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
        WHERE sta.no_induk = ? AND sta.status = \'aktif\'
        ORDER BY ta.label ASC';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $placements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $placements;
}

function spp_tariff_for_payment_period(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    float $fallback,
    bool $forUpdate = false
): float {
    return spp_sequence_tariff_for_period(
        spp_active_placements($db, $noInduk, $forUpdate),
        $bulan,
        $tahun,
        $fallback
    );
}

function spp_paid_for_period(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    int $excludePaymentId = 0,
    bool $forUpdate = true
): float {
    $month = spp_sequence_month_code($bulan);
    $monthLabel = payment_month_label($month);
    $legacyMonth = (string)(int)$month;
    $sql = 'SELECT U_SPP FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = ?
          AND (BULAN = ? OR BULAN = ? OR BULAN = ?)
          AND id <> ?';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('sssssi', $noInduk, $tahun, $month, $monthLabel, $legacyMonth, $excludePaymentId);
    $stmt->execute();
    $paid = 0.0;
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $paid += (float)$row['U_SPP'];
    $stmt->close();
    return $paid;
}

/** @return array<string,float> */
function spp_paid_period_map(mysqli $db, string $noInduk, int $excludePaymentId = 0): array {
    $stmt = $db->prepare('SELECT BULAN, TAHUN, U_SPP FROM bayar WHERE NO_INDUK = ? AND id <> ?');
    $stmt->bind_param('si', $noInduk, $excludePaymentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $periods = [];
    while ($row = $result->fetch_assoc()) {
        $key = spp_sequence_period_key((string)$row['BULAN'], (string)$row['TAHUN']);
        if ($key === '') continue;
        $periods[$key] = ($periods[$key] ?? 0) + (float)$row['U_SPP'];
    }
    $stmt->close();
    return $periods;
}

function spp_monthly_bill_for_student(mysqli $db, string $noInduk, bool $forUpdate = true): float {
    $sql = 'SELECT SPP_PERBULAN FROM siswa WHERE NO_INDUK = ?';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['SPP_PERBULAN'] ?? 0);
}

function spp_payment_status(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    int $excludePaymentId = 0,
    bool $forUpdate = false,
    bool $allowInactive = false
): array {
    $month = spp_sequence_month_code($bulan);
    if ($noInduk === '' || $month === '' || !preg_match('/^\d{4}$/', $tahun)) {
        throw new InvalidArgumentException('Data siswa dan periode SPP belum lengkap.');
    }

    $sql = 'SELECT s.NO_INDUK, s.SPP_PERBULAN, s.is_active,
        COALESCE(mk.tingkat, CAST(s.KELAS AS UNSIGNED)) AS tingkat,
        COALESCE(mk.kode_rombel, \'\') AS kode_rombel
        FROM siswa s
        LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
        WHERE s.NO_INDUK = ?';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        return spp_payment_status_from_state(['exists' => false], $month, $tahun, 0, 0, []);
    }
    $student['exists'] = true;
    $student['allow_inactive'] = $allowInactive;

    $placements = spp_active_placements($db, $noInduk, $forUpdate);
    $tariff = spp_sequence_tariff_for_period($placements, $month, $tahun, (float)$student['SPP_PERBULAN']);
    $paidPeriods = $forUpdate ? null : spp_paid_period_map($db, $noInduk, $excludePaymentId);
    $selectedKey = spp_sequence_period_key($month, $tahun);
    $selectedPaid = $forUpdate
        ? spp_paid_for_period($db, $noInduk, $month, $tahun, $excludePaymentId, true)
        : (float)($paidPeriods[$selectedKey] ?? 0);
    $priorPeriods = [];
    foreach (spp_sequence_prior_periods($placements, $month, $tahun) as $period) {
        $period['paid'] = $forUpdate
            ? spp_paid_for_period(
                $db,
                $noInduk,
                (string)$period['bulan'],
                (string)$period['tahun'],
                $excludePaymentId,
                true
            )
            : (float)($paidPeriods[$period['key']] ?? 0);
        $period['label'] = spp_payment_period_label((string)$period['bulan'], (string)$period['tahun']);
        $priorPeriods[] = $period;
    }

    return spp_payment_status_from_state($student, $month, $tahun, $tariff, $selectedPaid, $priorPeriods);
}

function first_paid_spp_following_period(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    int $excludePaymentId = 0,
    bool $forUpdate = true
): ?array {
    $placements = spp_active_placements($db, $noInduk, $forUpdate);
    $paidPeriods = $forUpdate ? null : spp_paid_period_map($db, $noInduk, $excludePaymentId);
    foreach (spp_sequence_following_periods($placements, $bulan, $tahun) as $period) {
        $paid = $forUpdate
            ? spp_paid_for_period(
                $db,
                $noInduk,
                (string)$period['bulan'],
                (string)$period['tahun'],
                $excludePaymentId,
                true
            )
            : (float)($paidPeriods[$period['key']] ?? 0);
        if ($paid > 0.001) {
            return [
                'bulan' => (string)$period['bulan'],
                'tahun' => (string)$period['tahun'],
                'label' => spp_payment_period_label((string)$period['bulan'], (string)$period['tahun']),
                'paid' => $paid,
            ];
        }
    }
    return null;
}

function spp_edit_dependency(mysqli $db, int $paymentId, bool $forUpdate = false): ?array {
    if ($paymentId <= 0) return null;
    $sql = 'SELECT id, NO_INDUK, BULAN, TAHUN, U_SPP FROM bayar WHERE id = ?';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment || (float)$payment['U_SPP'] <= 0.001) return null;

    $month = spp_sequence_month_code((string)$payment['BULAN']);
    $year = (string)$payment['TAHUN'];
    if ($month === '' || !preg_match('/^\d{4}$/', $year)) return null;

    $fallback = spp_monthly_bill_for_student($db, (string)$payment['NO_INDUK'], $forUpdate);
    $tariff = spp_tariff_for_payment_period($db, (string)$payment['NO_INDUK'], $month, $year, $fallback, $forUpdate);
    $paidWithoutCurrent = spp_paid_for_period($db, (string)$payment['NO_INDUK'], $month, $year, $paymentId, $forUpdate);
    if ($tariff <= 0.001 || $paidWithoutCurrent + 0.001 >= $tariff) return null;

    $future = first_paid_spp_following_period($db, (string)$payment['NO_INDUK'], $month, $year, $paymentId, $forUpdate);
    if (!$future) return null;

    $oldLabel = spp_payment_period_label($month, $year);
    return [
        'code' => 'edit_dependency',
        'title' => 'SPP tidak dapat diubah',
        'message' => 'SPP ' . $oldLabel . ' tidak dapat dipindahkan atau dikosongkan karena ' . $future['label'] . ' sudah dibayar.',
        'amount_label' => 'Koreksi bulan setelahnya terlebih dahulu',
        'original' => [
            'no_induk' => (string)$payment['NO_INDUK'],
            'bulan' => $month,
            'tahun' => $year,
            'label' => $oldLabel,
            'amount' => (float)$payment['U_SPP'],
            'tariff' => $tariff,
        ],
        'future_period' => $future,
    ];
}

function validate_spp_full_payment(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    float $monthlyBill,
    float $uangSpp,
    int $excludePaymentId = 0
): void {
    if ($uangSpp <= 0.001) return;

    $status = spp_payment_status($db, $noInduk, $bulan, $tahun, $excludePaymentId, true, true);
    $tariff = (float)($status['selected']['tariff'] ?? $monthlyBill);
    if ($tariff <= 0.001 && $monthlyBill > 0.001) $tariff = $monthlyBill;
    if (($status['status'] ?? 'status_unavailable') !== 'payable') {
        throw new SppPaymentException($status);
    }
    if (abs($uangSpp - $tariff) > 0.001) {
        throw new SppPaymentException(spp_payment_amount_status($bulan, $tahun, $tariff));
    }
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
    $future = first_paid_spp_following_period($db, $noInduk, $bulan, $tahun, $excludePaymentId, true);
    if (!$future) return;

    $currentLabel = spp_payment_period_label($bulan, $tahun);
    throw new SppPaymentException([
        'ok' => true,
        'status' => 'edit_dependency',
        'code' => 'edit_dependency',
        'lock_spp' => true,
        'title' => 'SPP tidak dapat diubah',
        'message' => 'SPP ' . $currentLabel . ' tidak bisa ' . $action . ' karena ' . $future['label'] . ' sudah dibayar.',
        'amount_label' => 'Koreksi bulan setelahnya terlebih dahulu',
        'selected' => [
            'bulan' => spp_sequence_month_code($bulan),
            'tahun' => $tahun,
            'label' => $currentLabel,
            'tariff' => $monthlyBill,
            'paid' => $paidAfterChange,
            'remaining' => max(0, $monthlyBill - $paidAfterChange),
        ],
        'blocking_period' => null,
        'future_period' => $future,
    ]);
}
