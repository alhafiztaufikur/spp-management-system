<?php
require_once __DIR__.'/legacy_billing.php';

/**
 * Komponen yang hanya ditagihkan satu kali sepanjang riwayat siswa.
 */
function one_time_fee_components(): array {
    return [
        'psb' => [
            'label' => 'Uang PSB',
            'payment' => 'U_PSB',
        ],
    ];
}

function one_time_fee_totals_from_student(array $student): array {
    return [
        'psb' => max(0, (float)($student['PSB'] ?? 0)-(float)($student['potongan_psb_nominal']??0)),
    ];
}

/**
 * @return array<string,array{label:string,total:float,paid:float,remaining:float}>
 */
function one_time_fee_status(mysqli $db, string $noInduk, int $excludePaymentId = 0, bool $forUpdate = false): array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $db->prepare('SELECT PSB,potongan_psb_nominal FROM siswa WHERE NO_INDUK=? LIMIT 1' . $lock);
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Data siswa tidak ditemukan.');

    if ($forUpdate) {
        // Locking read melihat pembayaran yang baru di-commit saat transaksi
        // ini menunggu kunci siswa. SUM biasa dapat membaca snapshot lama.
        $stmt = $db->prepare('SELECT U_PSB FROM bayar WHERE NO_INDUK=? AND id<>? FOR UPDATE');
        $stmt->bind_param('si', $noInduk, $excludePaymentId);
        $stmt->execute();
        $paid = ['psb'=>0.0];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $payment) {
            $paid['psb'] += (float)$payment['U_PSB'];
        }
        $stmt->close();
    } else {
        $stmt = $db->prepare('SELECT COALESCE(SUM(U_PSB),0) psb FROM bayar WHERE NO_INDUK=? AND id<>?');
        $stmt->bind_param('si', $noInduk, $excludePaymentId);
        $stmt->execute();
        $paid = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }

    $totals = one_time_fee_totals_from_student($student);
    $opening=lb_psb_source($db,$noInduk);if($opening&&$opening['known']){if((float)$student['PSB']<=0)$totals['psb']=$opening['total'];$paid['psb']=(float)($paid['psb']??0)+$opening['paid'];}
    $result = [];
    foreach (one_time_fee_components() as $key => $cfg) {
        $total = (float)$totals[$key];
        $paidAmount = (float)($paid[$key] ?? 0);
        $result[$key] = [
            'known'=>$opening===null||$opening['known'],'billing_state'=>$opening!==null&&!$opening['known']?'unknown':'known','basis'=>$opening['basis']??'system',
            'label' => $cfg['label'],
            'total' => $total,
            'paid' => $paidAmount,
            'remaining' => max(0, $total - $paidAmount),
        ];
    }
    return $result;
}

function one_time_fee_payload_for_options(mysqli $db, int $excludePaymentId = 0): array {
    $stmt = $db->prepare("SELECT s.NO_INDUK,s.PSB,s.potongan_psb_nominal,
        COALESCE(SUM(CASE WHEN b.id<>? THEN b.U_PSB ELSE 0 END),0) paid_psb
        FROM siswa s LEFT JOIN bayar b ON b.NO_INDUK=s.NO_INDUK AND b.unit_id=s.unit_id
        GROUP BY s.NO_INDUK,s.PSB,s.potongan_psb_nominal");
    $stmt->bind_param('i', $excludePaymentId);
    $stmt->execute();
    $rows = $stmt->get_result();
    $payload = [];
    while ($row = $rows->fetch_assoc()) {
        $totals = one_time_fee_totals_from_student($row);
        $opening=lb_psb_source($db,$row['NO_INDUK']);if($opening&&$opening['known']){if((float)$row['PSB']<=0)$totals['psb']=$opening['total'];$row['paid_psb']=(float)$row['paid_psb']+$opening['paid'];}
        foreach (one_time_fee_components() as $key => $cfg) {
            $payload[$row['NO_INDUK']][$key] = [
                'known'=>$opening===null||$opening['known'],
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
        if(empty($fee['known']))throw new RuntimeException('Sisa PSB belum dapat ditentukan dari bukti sumber. Lengkapi dasar PSB sebelum menerima pembayaran PSB.');
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
