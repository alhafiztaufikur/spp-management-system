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
    return du_academic_year_label((int)date('n'), (int)date('Y'));
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
        JOIN tagihan_daftar_ulang tdu ON tdu.no_induk=s.NO_INDUK
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
        JOIN tagihan_daftar_ulang tdu ON tdu.no_induk=s.NO_INDUK
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
    $stmt = $db->prepare("SELECT tdu.id,tdu.tahun_ajaran_id,tdu.master_daftar_ulang_id,
            tdu.nominal_awal,tdu.nominal_tagihan,tdu.status,ta.status AS year_status,
            COALESCE(du.Jumlah,0) AS class_amount,s.DAFTAR_ULANG,s.potong_du,s.tot_du,
            COALESCE(SUM(bd.jumlah),0) AS paid
        FROM siswa s
        JOIN tagihan_daftar_ulang tdu ON tdu.no_induk=s.NO_INDUK
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
        JOIN siswa s ON s.NO_INDUK=tdu.no_induk
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
                         JOIN siswa s ON s.NO_INDUK=sta.no_induk
                         LEFT JOIN Daftar_ulang du ON du.tahun_ajaran_id = ta.id AND du.kelas = sta.kelas
                         WHERE sta.id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('i', $placementId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['placement_status'] !== 'aktif' || $row['year_status'] !== 'published') return null;
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

    $stmt = $db->prepare("SELECT COUNT(DISTINCT kelas) total FROM Daftar_ulang
        WHERE tahun_ajaran_id=? AND kelas IN ('1','2','3','4','5','6') AND Jumlah>0");
    $stmt->bind_param('i', $yearId); $stmt->execute();
    $masterCount = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    if ($masterCount !== 6) throw new RuntimeException('Lengkapi nominal Daftar Ulang kelas 1 sampai 6 sebelum menerbitkan.');

    $activeStudents = $db->query("SELECT NO_INDUK, KELAS FROM siswa WHERE is_active=1 AND KELAS IN ('1','2','3','4','5','6') FOR UPDATE");
    $activeCount = $activeStudents->num_rows;
    if ($activeCount === 0) throw new RuntimeException('Tidak ada siswa aktif kelas 1 sampai 6 yang dapat dibuatkan tagihan.');
    $invalidCount = 0;
    while ($activeStudent = $activeStudents->fetch_assoc()) {
        if (!in_array((string)$activeStudent['KELAS'], ['1','2','3','4','5','6'], true)) $invalidCount++;
    }
    if ($invalidCount > 0) throw new RuntimeException($invalidCount . ' siswa aktif memiliki kelas tidak valid (harus 1–6). Perbaiki Data Siswa terlebih dahulu.');

    $stmt = $db->prepare('SELECT COUNT(*) total FROM tagihan_daftar_ulang WHERE tahun_ajaran_id=?');
    $stmt->bind_param('i', $yearId); $stmt->execute();
    $existingBills = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    if ($existingBills > 0) throw new RuntimeException('Tahun ajaran draf memiliki tagihan lama yang tidak konsisten. Periksa database sebelum menerbitkan ulang.');

    $stmt = $db->prepare("DELETE sta FROM siswa_tahun_ajaran sta
        LEFT JOIN siswa s ON s.NO_INDUK=sta.no_induk
        WHERE sta.tahun_ajaran_id=? AND (s.NO_INDUK IS NULL OR s.is_active<>1)");
    $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
    $stmt = $db->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status)
        SELECT ?,s.NO_INDUK,s.KELAS,s.master_kelas_id,
               CASE WHEN mk.id IS NULL THEN CONCAT('Kelas ',s.KELAS,' (Belum Ditentukan)')
                    WHEN mk.is_placeholder=1 THEN CONCAT('Kelas ',s.KELAS,' (Belum Ditentukan)')
                    ELSE CONCAT(mk.tingkat,UPPER(mk.kode_rombel)) END,
               s.SPP_PERBULAN,s.POMG,'aktif'
        FROM siswa s
        LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
        WHERE s.is_active=1 AND s.KELAS IN ('1','2','3','4','5','6')
        ON DUPLICATE KEY UPDATE kelas=VALUES(kelas),master_kelas_id=VALUES(master_kelas_id),
          kelas_rombel_snapshot=VALUES(kelas_rombel_snapshot),
          spp_perbulan_snapshot=VALUES(spp_perbulan_snapshot),komite_snapshot=VALUES(komite_snapshot),status='aktif'");
    $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();

    $isCurrentYear = $label === du_current_academic_year() ? 1 : 0;
    $stmt = $db->prepare("INSERT IGNORE INTO tagihan_daftar_ulang
        (tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan)
        SELECT sta.tahun_ajaran_id,sta.id,du.id,sta.no_induk,sta.kelas,?,
               CASE WHEN ?=1 AND s.DAFTAR_ULANG>0 THEN s.DAFTAR_ULANG ELSE du.Jumlah END,
               CASE WHEN ?=1 AND s.DAFTAR_ULANG>0 THEN GREATEST(s.DAFTAR_ULANG-s.potong_du,0) ELSE du.Jumlah END
        FROM siswa_tahun_ajaran sta
        JOIN siswa s ON s.NO_INDUK=sta.no_induk
        JOIN Daftar_ulang du ON du.tahun_ajaran_id=sta.tahun_ajaran_id AND du.kelas=sta.kelas
        WHERE sta.tahun_ajaran_id=? AND sta.status='aktif'");
    $stmt->bind_param('siii', $label, $isCurrentYear, $isCurrentYear, $yearId); $stmt->execute();
    $created = $stmt->affected_rows; $stmt->close();
    if ($created !== $activeCount) throw new RuntimeException('Jumlah tagihan yang terbentuk tidak sesuai jumlah siswa aktif. Penerbitan dibatalkan.');

    if ($isCurrentYear === 1) {
        $stmt = $db->prepare("UPDATE siswa s
            JOIN tagihan_daftar_ulang tdu ON tdu.no_induk=s.NO_INDUK AND tdu.tahun_ajaran_id=?
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
