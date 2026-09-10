<?php

require_once __DIR__ . '/daftar_ulang.php';

function annual_fee_components(): array {
    return [
        'pangkal' => ['label' => 'Uang Pangkal', 'bill' => 'PANGKAL', 'discount' => 'potong_pangkal', 'derived' => 'tot_pangkal', 'payment' => 'U_PANGKAL', 'mirror' => 'PANGKAL_BAYAR'],
        'bangunan' => ['label' => 'Uang Bangunan', 'bill' => 'BANGUNAN', 'discount' => null, 'derived' => null, 'payment' => 'U_BANGUNAN', 'mirror' => 'BANGUNAN_BAYAR'],
        'seragam' => ['label' => 'Uang Seragam', 'bill' => 'SERAGAM', 'discount' => null, 'derived' => null, 'payment' => 'U_SERAGAM', 'mirror' => 'SERAGAM_BAYAR'],
        'kegiatan' => ['label' => 'Uang Kegiatan', 'bill' => 'KEGIATAN', 'discount' => null, 'derived' => null, 'payment' => 'U_KEGIATAN', 'mirror' => 'KEGIATAN_BAYAR'],
        'komite' => ['label' => 'Uang Komite', 'bill' => 'POMG', 'discount' => null, 'derived' => null, 'payment' => 'U_KOMITE', 'mirror' => null],
        'makan' => ['label' => 'Uang Makan', 'bill' => 'MAKAN', 'discount' => null, 'derived' => null, 'payment' => 'U_MAKAN', 'mirror' => null],
        'sorga' => ['label' => 'Uang Sorga', 'bill' => 'SORGA', 'discount' => null, 'derived' => null, 'payment' => 'U_SORGA', 'mirror' => null],
        'infaq' => ['label' => 'Uang Infaq', 'bill' => 'INFAQ', 'discount' => null, 'derived' => null, 'payment' => 'U_INFAQ', 'mirror' => null],
    ];
}

function annual_fee_component(string $component): array {
    $components = annual_fee_components();
    if (!isset($components[$component])) {
        throw new RuntimeException('Komponen tagihan tahunan tidak dikenali.');
    }
    return $components[$component];
}

function annual_fee_academic_year_label(int $month, int $year): string {
    return du_academic_year_label($month, $year);
}

function annual_fee_year_id(mysqli $db, string $academicYear, bool $create = true, bool $forUpdate = false): ?int {
    $academicYear = du_normalize_academic_year($academicYear);
    $stmt = $db->prepare('SELECT id FROM tahun_ajaran WHERE label = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('s', $academicYear);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['id'];
    if (!$create) return null;

    [$startDate, $endDate] = du_year_dates($academicYear);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran (label, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, 'draft') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->bind_param('sss', $academicYear, $startDate, $endDate);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id > 0 ? $id : null;
}

function annual_fee_amount_from_student(array $student, string $component): array {
    $cfg = annual_fee_component($component);
    $nominalAwal = (float)($student[$cfg['bill']] ?? 0);
    $potongan = $cfg['discount'] ? (float)($student[$cfg['discount']] ?? 0) : 0.0;
    $derived = $cfg['derived'] ? (float)($student[$cfg['derived']] ?? 0) : 0.0;
    $tagihan = $derived > 0 ? $derived : max(0, $nominalAwal - $potongan);
    return [$nominalAwal, $potongan, $tagihan];
}

function annual_fee_paid_for_bill(mysqli $db, int $billId, int $excludePaymentId = 0): float {
    $stmt = $db->prepare('SELECT COALESCE(SUM(jumlah), 0) AS paid FROM bayar_tahunan_siswa WHERE tagihan_tahunan_id = ? AND bayar_id <> ?');
    $stmt->bind_param('ii', $billId, $excludePaymentId);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function annual_fee_historical_paid_for_bill(mysqli $db, int $billId, int $excludePaymentId = 0): float {
    $stmt = $db->prepare('SELECT no_induk,komponen,tahun_ajaran_snapshot FROM tagihan_tahunan_siswa WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $billId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$bill) return 0.0;

    $cfg = annual_fee_component((string)$bill['komponen']);
    $column = $cfg['payment'];
    $academicYear = du_normalize_academic_year((string)$bill['tahun_ajaran_snapshot']);
    $startYear = (int)substr($academicYear, 0, 4);
    $endYear = $startYear + 1;
    $monthSql = "CASE LOWER(BULAN)
      WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4
      WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8
      WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12
      ELSE CAST(BULAN AS UNSIGNED) END";
    $stmt = $db->prepare("SELECT COALESCE(SUM($column),0) AS paid FROM bayar
        WHERE NO_INDUK=? AND id<>? AND (
          th_ajaran=? OR ((th_ajaran IS NULL OR th_ajaran='') AND (
            (CAST(TAHUN AS UNSIGNED)=? AND $monthSql BETWEEN 7 AND 12)
            OR (CAST(TAHUN AS UNSIGNED)=? AND $monthSql BETWEEN 1 AND 6)
          ))
        )");
    $stmt->bind_param('sisii', $bill['no_induk'], $excludePaymentId, $academicYear, $startYear, $endYear);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function annual_fee_find_or_create_placement(mysqli $db, string $noInduk, string $academicYear, bool $forUpdate = false): array {
    $yearId = annual_fee_year_id($db, $academicYear, true, $forUpdate);
    if (!$yearId) throw new RuntimeException('Tahun ajaran tidak ditemukan.');

    $stmt = $db->prepare('SELECT sta.* FROM siswa_tahun_ajaran sta WHERE sta.tahun_ajaran_id = ? AND sta.no_induk = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('is', $yearId, $noInduk);
    $stmt->execute();
    $placement = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($placement) return $placement;

    $stmt = $db->prepare("SELECT s.NO_INDUK, s.KELAS, s.master_kelas_id, s.SPP_PERBULAN, s.POMG, s.is_active,
        COALESCE(mk.tingkat, CAST(s.KELAS AS UNSIGNED)) AS tingkat, mk.kode_rombel, COALESCE(mk.is_placeholder, 1) AS is_placeholder
        FROM siswa s LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
        WHERE s.NO_INDUK = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Data siswa tidak ditemukan.');
    if ((int)$student['is_active'] !== 1) throw new RuntimeException('Siswa yang diarsipkan tidak dapat dibuatkan tagihan tahun ajaran baru.');
    if ((int)($student['tingkat'] ?? 0) === 0 || strtoupper((string)($student['kode_rombel'] ?? '')) === 'PSB') {
        throw new RuntimeException('Siswa PSB belum memiliki tagihan tahunan. Pindahkan siswa ke rombel reguler terlebih dahulu.');
    }

    $level = (string)($student['tingkat'] ?: $student['KELAS']);
    $classId = (int)($student['master_kelas_id'] ?? 0);
    $code = strtoupper(trim((string)($student['kode_rombel'] ?? '')));
    $snapshot = ((int)($student['is_placeholder'] ?? 1) === 1 || $code === '')
        ? 'Kelas ' . $level . ' (Belum Ditentukan)'
        : $level . $code;
    $spp = (float)$student['SPP_PERBULAN'];
    $komite = (float)$student['POMG'];
    $status = 'aktif';
    $stmt = $db->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id, no_induk, kelas, master_kelas_id, kelas_rombel_snapshot, spp_perbulan_snapshot, komite_snapshot, status)
        VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->bind_param('issisdds', $yearId, $noInduk, $level, $classId, $snapshot, $spp, $komite, $status);
    $stmt->execute();
    $placementId = (int)$db->insert_id;
    $stmt->close();

    $stmt = $db->prepare('SELECT * FROM siswa_tahun_ajaran WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $placementId);
    $stmt->execute();
    $placement = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$placement) throw new RuntimeException('Gagal membuat penempatan tahun ajaran siswa.');
    return $placement;
}

function annual_fee_sync_for_placement(mysqli $db, int $placementId, string $createdBy = ''): void {
    $stmt = $db->prepare("SELECT sta.*, ta.label AS tahun_ajaran, s.*
        FROM siswa_tahun_ajaran sta
        JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
        JOIN siswa s ON s.NO_INDUK = sta.no_induk
        WHERE sta.id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('i', $placementId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || ($row['status'] ?? '') === 'lulus') return;
    if ((string)($row['kelas'] ?? '') === '0' || strtoupper((string)($row['kelas_rombel_snapshot'] ?? '')) === 'PSB') return;

    foreach (annual_fee_components() as $component => $cfg) {
        [$nominalAwal, $potongan, $tagihan] = annual_fee_amount_from_student($row, $component);
        $paid = 0.0;
        $stmt = $db->prepare('SELECT id FROM tagihan_tahunan_siswa WHERE tahun_ajaran_id = ? AND no_induk = ? AND komponen = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('iss', $row['tahun_ajaran_id'], $row['no_induk'], $component);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            $paid = annual_fee_paid_for_bill($db, (int)$existing['id']);
            if ($tagihan + 0.001 < $paid) {
                throw new RuntimeException($cfg['label'] . ' tahun ajaran ' . $row['tahun_ajaran'] . ' tidak boleh lebih kecil dari yang sudah dibayar, yaitu Rp ' . number_format($paid, 0, ',', '.') . '.');
            }
        }
        $stmt = $db->prepare("INSERT INTO tagihan_tahunan_siswa
            (tahun_ajaran_id, penempatan_id, no_induk, komponen, nama_snapshot, kelas_snapshot, kelas_rombel_snapshot,
             tahun_ajaran_snapshot, nominal_awal, potongan, nominal_tagihan, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)
            ON DUPLICATE KEY UPDATE
              penempatan_id = VALUES(penempatan_id), nama_snapshot = VALUES(nama_snapshot),
              kelas_snapshot = VALUES(kelas_snapshot), kelas_rombel_snapshot = VALUES(kelas_rombel_snapshot),
              nominal_awal = VALUES(nominal_awal), potongan = VALUES(potongan),
              nominal_tagihan = VALUES(nominal_tagihan), status = 'open'");
        $stmt->bind_param(
            'iissssssddds',
            $row['tahun_ajaran_id'],
            $placementId,
            $row['no_induk'],
            $component,
            $row['NAMA'],
            $row['kelas'],
            $row['kelas_rombel_snapshot'],
            $row['tahun_ajaran'],
            $nominalAwal,
            $potongan,
            $tagihan,
            $createdBy
        );
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Menyelaraskan tagihan tahunan pada satu penempatan tanpa mengubah komponen
 * yang sudah memiliki pembayaran. Fungsi ini harus dipanggil di dalam
 * transaksi yang juga mengunci data siswa/penempatan terkait.
 *
 * @return array{tahun_ajaran:string,synced:array<int,string>,locked:array<int,string>,unchanged:array<int,string>,metadata_synced:array<int,string>,metadata_locked:array<int,string>}
 */
function annual_fee_reconcile_for_placement(mysqli $db, int $placementId, string $createdBy = ''): array {
    $result = [
        'tahun_ajaran' => '', 'synced' => [], 'locked' => [], 'unchanged' => [],
        'metadata_synced' => [], 'metadata_locked' => [],
    ];
    $stmt = $db->prepare("SELECT sta.id AS penempatan_id,sta.tahun_ajaran_id,sta.no_induk,sta.kelas,
            sta.kelas_rombel_snapshot,sta.status AS penempatan_status,ta.label AS tahun_ajaran,
            s.NAMA,s.PANGKAL,s.potong_pangkal,s.tot_pangkal,s.BANGUNAN,s.SERAGAM,s.KEGIATAN,
            s.POMG,s.MAKAN,s.SORGA,s.INFAQ
        FROM siswa_tahun_ajaran sta
        JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id
        JOIN siswa s ON s.NO_INDUK=sta.no_induk
        WHERE sta.id=? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('i', $placementId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['penempatan_status'] !== 'aktif') return $result;
    if ((string)$row['kelas'] === '0' || strtoupper((string)$row['kelas_rombel_snapshot']) === 'PSB') return $result;

    $result['tahun_ajaran'] = (string)$row['tahun_ajaran'];
    foreach (annual_fee_components() as $component => $cfg) {
        [$nominalAwal, $potongan, $tagihan] = annual_fee_amount_from_student($row, $component);
        $stmt = $db->prepare('SELECT id,penempatan_id,nama_snapshot,kelas_snapshot,kelas_rombel_snapshot,nominal_awal,potongan,nominal_tagihan,status FROM tagihan_tahunan_siswa WHERE tahun_ajaran_id=? AND no_induk=? AND komponen=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('iss', $row['tahun_ajaran_id'], $row['no_induk'], $component);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($bill) {
            $metadataDifferent = (int)$bill['penempatan_id'] !== $placementId
                || (string)$bill['nama_snapshot'] !== (string)$row['NAMA']
                || (string)$bill['kelas_snapshot'] !== (string)$row['kelas']
                || (string)$bill['kelas_rombel_snapshot'] !== (string)$row['kelas_rombel_snapshot'];
            $amountDifferent = abs((float)$bill['nominal_awal'] - $nominalAwal) > .001
                || abs((float)$bill['potongan'] - $potongan) > .001
                || abs((float)$bill['nominal_tagihan'] - $tagihan) > .001;
            if (!$metadataDifferent && !$amountDifferent) {
                $result['unchanged'][] = $component;
                continue;
            }
            $paid = max(
                annual_fee_paid_for_bill($db, (int)$bill['id']),
                annual_fee_historical_paid_for_bill($db, (int)$bill['id'])
            );
            if ($paid > .001 || $bill['status'] !== 'open') {
                $result[$amountDifferent ? 'locked' : 'metadata_locked'][] = $component;
                continue;
            }
            $billId = (int)$bill['id'];
            $stmt = $db->prepare('UPDATE tagihan_tahunan_siswa SET penempatan_id=?,nama_snapshot=?,kelas_snapshot=?,kelas_rombel_snapshot=?,nominal_awal=?,potongan=?,nominal_tagihan=? WHERE id=?');
            $stmt->bind_param('isssdddi', $placementId, $row['NAMA'], $row['kelas'], $row['kelas_rombel_snapshot'], $nominalAwal, $potongan, $tagihan, $billId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $db->prepare("INSERT INTO tagihan_tahunan_siswa
                (tahun_ajaran_id,penempatan_id,no_induk,komponen,nama_snapshot,kelas_snapshot,kelas_rombel_snapshot,
                 tahun_ajaran_snapshot,nominal_awal,potongan,nominal_tagihan,status,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'open',?)");
            $stmt->bind_param(
                'iissssssddds',
                $row['tahun_ajaran_id'],
                $placementId,
                $row['no_induk'],
                $component,
                $row['NAMA'],
                $row['kelas'],
                $row['kelas_rombel_snapshot'],
                $row['tahun_ajaran'],
                $nominalAwal,
                $potongan,
                $tagihan,
                $createdBy
            );
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $db->prepare('SELECT penempatan_id,nama_snapshot,kelas_snapshot,kelas_rombel_snapshot,nominal_awal,potongan,nominal_tagihan FROM tagihan_tahunan_siswa WHERE tahun_ajaran_id=? AND no_induk=? AND komponen=? LIMIT 1');
        $stmt->bind_param('iss', $row['tahun_ajaran_id'], $row['no_induk'], $component);
        $stmt->execute();
        $verified = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$verified
            || (int)$verified['penempatan_id'] !== $placementId
            || (string)$verified['nama_snapshot'] !== (string)$row['NAMA']
            || (string)$verified['kelas_snapshot'] !== (string)$row['kelas']
            || (string)$verified['kelas_rombel_snapshot'] !== (string)$row['kelas_rombel_snapshot']
            || abs((float)$verified['nominal_awal'] - $nominalAwal) > .001
            || abs((float)$verified['potongan'] - $potongan) > .001
            || abs((float)$verified['nominal_tagihan'] - $tagihan) > .001) {
            throw new RuntimeException('Verifikasi sinkronisasi ' . $cfg['label'] . ' gagal. Tidak ada perubahan yang disimpan.');
        }
        $result[!$bill || $amountDifferent ? 'synced' : 'metadata_synced'][] = $component;
    }
    return $result;
}

function annual_fee_require_bill(mysqli $db, string $noInduk, string $component, int $month, int $year, bool $forUpdate = false): array {
    annual_fee_component($component);
    $academicYear = annual_fee_academic_year_label($month, $year);
    $yearId = annual_fee_year_id($db, $academicYear, true, $forUpdate);
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $db->prepare("SELECT * FROM tagihan_tahunan_siswa WHERE tahun_ajaran_id = ? AND no_induk = ? AND komponen = ? AND status = 'open' LIMIT 1$lock");
    $stmt->bind_param('iss', $yearId, $noInduk, $component);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$bill) {
        $placement = annual_fee_find_or_create_placement($db, $noInduk, $academicYear, true);
        annual_fee_sync_for_placement($db, (int)$placement['id'], 'system');
        $stmt = $db->prepare("SELECT * FROM tagihan_tahunan_siswa WHERE tahun_ajaran_id = ? AND no_induk = ? AND komponen = ? AND status = 'open' LIMIT 1$lock");
        $stmt->bind_param('iss', $yearId, $noInduk, $component);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$bill) throw new RuntimeException('Tagihan tahunan tidak ditemukan.');
    return $bill;
}

function annual_fee_payment_components_from_values(array $values): array {
    $result = [];
    foreach (annual_fee_components() as $component => $cfg) {
        $result[$component] = (float)($values[$component] ?? $values[$cfg['payment']] ?? 0);
    }
    return $result;
}

function annual_fee_sync_payment(mysqli $db, int $bayarId, string $noInduk, string $bulan, string $tahun, array $components): void {
    $oldStudents = [];
    $stmt = $db->prepare('SELECT DISTINCT no_induk FROM bayar_tahunan_siswa WHERE bayar_id = ?');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $oldStudents[] = (string)$row['no_induk'];
    $stmt->close();

    $stmt = $db->prepare('DELETE FROM bayar_tahunan_siswa WHERE bayar_id = ?');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $stmt->close();

    $month = (int)$bulan;
    $year = (int)$tahun;
    foreach (annual_fee_components() as $component => $cfg) {
        $amount = (float)($components[$component] ?? 0);
        if ($amount <= 0.001) continue;
        $bill = annual_fee_require_bill($db, $noInduk, $component, $month, $year, true);
        $paid = annual_fee_paid_for_bill($db, (int)$bill['id'], $bayarId);
        $remaining = max(0, (float)$bill['nominal_tagihan'] - $paid);
        if ((float)$bill['nominal_tagihan'] <= 0) {
            throw new RuntimeException($cfg['label'] . ' belum memiliki total tagihan untuk tahun ajaran ' . $bill['tahun_ajaran_snapshot'] . '.');
        }
        if ($amount > $remaining + 0.001) {
            throw new RuntimeException('Pembayaran ' . $cfg['label'] . ' melebihi sisa tagihan tahun ajaran ' . $bill['tahun_ajaran_snapshot'] . '. Sisa: Rp ' . number_format($remaining, 0, ',', '.') . '.');
        }
        $academicYear = (string)$bill['tahun_ajaran_snapshot'];
        $billId = (int)$bill['id'];
        $stmt = $db->prepare('INSERT INTO bayar_tahunan_siswa (bayar_id, tagihan_tahunan_id, no_induk, komponen, th_ajaran, jumlah) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iisssd', $bayarId, $billId, $noInduk, $component, $academicYear, $amount);
        $stmt->execute();
        $stmt->close();
    }

    $oldStudents[] = $noInduk;
    foreach (array_unique($oldStudents) as $affectedNoInduk) {
        annual_fee_sync_legacy_paid_mirror($db, $affectedNoInduk);
    }
}

function annual_fee_sync_legacy_paid_mirror(mysqli $db, ?string $noInduk = null): void {
    $currentYear = du_current_academic_year();
    $whereStudent = $noInduk !== null ? ' AND s.NO_INDUK = ?' : '';
    $sql = "UPDATE siswa s
        LEFT JOIN (
          SELECT bts.no_induk,
            SUM(CASE WHEN bts.komponen='pangkal' THEN bts.jumlah ELSE 0 END) AS pangkal,
            SUM(CASE WHEN bts.komponen='bangunan' THEN bts.jumlah ELSE 0 END) AS bangunan,
            SUM(CASE WHEN bts.komponen='seragam' THEN bts.jumlah ELSE 0 END) AS seragam,
            SUM(CASE WHEN bts.komponen='kegiatan' THEN bts.jumlah ELSE 0 END) AS kegiatan
          FROM bayar_tahunan_siswa bts
          WHERE bts.th_ajaran = ?
          GROUP BY bts.no_induk
        ) paid ON paid.no_induk = s.NO_INDUK
        SET s.PANGKAL_BAYAR = COALESCE(paid.pangkal, 0),
            s.BANGUNAN_BAYAR = COALESCE(paid.bangunan, 0),
            s.SERAGAM_BAYAR = COALESCE(paid.seragam, 0),
            s.KEGIATAN_BAYAR = COALESCE(paid.kegiatan, 0)
        WHERE 1=1$whereStudent";
    $stmt = $db->prepare($sql);
    if ($noInduk !== null) {
        $stmt->bind_param('ss', $currentYear, $noInduk);
    } else {
        $stmt->bind_param('s', $currentYear);
    }
    $stmt->execute();
    $stmt->close();
}

function annual_fee_payload_for_options(mysqli $db, int $excludePaymentId = 0): array {
    $payload = [];
    $stmt = $db->prepare("SELECT t.no_induk, t.tahun_ajaran_snapshot, t.komponen, t.nominal_tagihan,
        COALESCE(SUM(CASE WHEN d.bayar_id IS NULL OR d.bayar_id <> ? THEN d.jumlah ELSE 0 END), 0) AS paid
        FROM tagihan_tahunan_siswa t
        LEFT JOIN bayar_tahunan_siswa d ON d.tagihan_tahunan_id = t.id
        WHERE t.status = 'open'
        GROUP BY t.id, t.no_induk, t.tahun_ajaran_snapshot, t.komponen, t.nominal_tagihan");
    $stmt->bind_param('i', $excludePaymentId);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        $payload[$row['no_induk']][$row['komponen']][$row['tahun_ajaran_snapshot']] = [
            'total' => (float)$row['nominal_tagihan'],
            'paid' => (float)$row['paid'],
        ];
    }
    $stmt->close();
    return $payload;
}

function annual_fee_remaining_for_payment(mysqli $db, int $bayarId): array {
    $remaining = [];
    $stmt = $db->prepare("SELECT bts.komponen, bts.jumlah AS current_amount, t.nominal_tagihan,
        COALESCE(SUM(all_paid.jumlah), 0) AS paid
        FROM bayar_tahunan_siswa bts
        JOIN tagihan_tahunan_siswa t ON t.id = bts.tagihan_tahunan_id
        LEFT JOIN bayar_tahunan_siswa all_paid ON all_paid.tagihan_tahunan_id = t.id
        WHERE bts.bayar_id = ?
        GROUP BY bts.id, bts.komponen, bts.jumlah, t.nominal_tagihan");
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        $remaining[$row['komponen']] = max(0, (float)$row['nominal_tagihan'] - (float)$row['paid']);
    }
    $stmt->close();
    return $remaining;
}
