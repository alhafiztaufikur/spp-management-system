<?php

/**
 * Komponen yang hanya ditagihkan satu kali sepanjang riwayat siswa.
 */
function one_time_fee_components(): array {
    return [
        'pangkal' => [
            'label' => 'Uang Pangkal',
            'payment' => 'U_PANGKAL',
        ],
        'psb' => [
            'label' => 'Uang PSB',
            'payment' => 'U_PSB',
        ],
    ];
}

function one_time_fee_totals_from_student(array $student): array {
    // PANGKAL dan potong_pangkal adalah nilai master. tot_pangkal hanya
    // kolom kompatibilitas/hasil hitung lama, jadi tidak boleh menentukan
    // tagihan saat nilainya tertinggal dari data master.
    $pangkal = max(0, (float)($student['PANGKAL'] ?? 0) - (float)($student['potong_pangkal'] ?? 0));
    return [
        'pangkal' => $pangkal,
        'psb' => max(0, (float)($student['PSB'] ?? 0)),
    ];
}

/**
 * @return array<string,array{label:string,total:float,paid:float,remaining:float}>
 */
function one_time_fee_status(mysqli $db, string $noInduk, int $excludePaymentId = 0, bool $forUpdate = false): array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $db->prepare('SELECT PANGKAL,potong_pangkal,tot_pangkal,PSB FROM siswa WHERE NO_INDUK=? LIMIT 1' . $lock);
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Data siswa tidak ditemukan.');

    $stmt = $db->prepare('SELECT COALESCE(SUM(U_PANGKAL),0) pangkal,COALESCE(SUM(U_PSB),0) psb FROM bayar WHERE NO_INDUK=? AND id<>?');
    $stmt->bind_param('si', $noInduk, $excludePaymentId);
    $stmt->execute();
    $paid = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $totals = one_time_fee_totals_from_student($student);
    $result = [];
    foreach (one_time_fee_components() as $key => $cfg) {
        $total = (float)$totals[$key];
        $paidAmount = (float)($paid[$key] ?? 0);
        $result[$key] = [
            'label' => $cfg['label'],
            'total' => $total,
            'paid' => $paidAmount,
            'remaining' => max(0, $total - $paidAmount),
        ];
    }
    return $result;
}

function one_time_fee_payload_for_options(mysqli $db, int $excludePaymentId = 0): array {
    $stmt = $db->prepare("SELECT s.NO_INDUK,s.PANGKAL,s.potong_pangkal,s.tot_pangkal,s.PSB,
        COALESCE(SUM(CASE WHEN b.id<>? THEN b.U_PANGKAL ELSE 0 END),0) paid_pangkal,
        COALESCE(SUM(CASE WHEN b.id<>? THEN b.U_PSB ELSE 0 END),0) paid_psb
        FROM siswa s LEFT JOIN bayar b ON b.NO_INDUK=s.NO_INDUK
        GROUP BY s.NO_INDUK,s.PANGKAL,s.potong_pangkal,s.tot_pangkal,s.PSB");
    $stmt->bind_param('ii', $excludePaymentId, $excludePaymentId);
    $stmt->execute();
    $rows = $stmt->get_result();
    $payload = [];
    while ($row = $rows->fetch_assoc()) {
        $totals = one_time_fee_totals_from_student($row);
        foreach (one_time_fee_components() as $key => $cfg) {
            $payload[$row['NO_INDUK']][$key] = [
                'total' => (float)$totals[$key],
                'paid' => (float)($row['paid_' . $key] ?? 0),
            ];
        }
    }
    $stmt->close();
    return $payload;
}

function validate_one_time_fee_payments(
    mysqli $db,
    string $noInduk,
    array $amounts,
    int $excludePaymentId = 0
): void {
    $status = one_time_fee_status($db, $noInduk, $excludePaymentId, true);
    foreach (one_time_fee_components() as $key => $cfg) {
        $input = (float)($amounts[$key] ?? 0);
        if ($input <= 0) continue;
        $fee = $status[$key];
        if ($fee['total'] <= 0) {
            throw new RuntimeException($cfg['label'] . ' belum diatur pada Master Siswa.');
        }
        if ($fee['paid'] > $fee['total'] + 0.001) {
            throw new RuntimeException($cfg['label'] . ' sudah melebihi nominal Master Siswa. Periksa histori pembayaran.');
        }
        if ($input > $fee['remaining'] + 0.001) {
            throw new RuntimeException('Pembayaran ' . $cfg['label'] . ' melebihi sisa tagihan. Sisa: Rp ' . number_format($fee['remaining'], 0, ',', '.') . '.');
        }
    }
}
