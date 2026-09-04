<?php

require_once __DIR__ . '/daftar_ulang.php';
require_once __DIR__ . '/tagihan_tahunan.php';

function class_label(array $class): string {
    $level = (int)($class['tingkat'] ?? 0);
    $code = strtoupper(trim((string)($class['kode_rombel'] ?? '')));
    if ($level === 0 || $code === 'PSB') {
        return 'PSB';
    }
    if ((int)($class['is_placeholder'] ?? 0) === 1) {
        return 'Kelas ' . $level . ' (Belum Ditentukan)';
    }
    return $level . $code;
}

function class_find(mysqli $db, int $classId, bool $activeOnly = false): ?array {
    $sql = 'SELECT id, tingkat, kode_rombel, is_placeholder, is_active FROM master_kelas WHERE id = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($class) $class['label'] = class_label($class);
    return $class;
}

function class_all(mysqli $db, bool $activeOnly = true, bool $includePlaceholder = true): array {
    $where = [];
    if ($activeOnly) $where[] = 'is_active = 1';
    if (!$includePlaceholder) $where[] = 'is_placeholder = 0';
    $sql = 'SELECT id, tingkat, kode_rombel, is_placeholder, is_active FROM master_kelas';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY CASE WHEN tingkat=0 THEN 0 ELSE 1 END, tingkat, is_placeholder, kode_rombel";
    $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['label'] = class_label($row);
    unset($row);
    return $rows;
}

function class_ensure_psb(mysqli $db): int {
    $stmt = $db->prepare("INSERT IGNORE INTO master_kelas (tingkat, kode_rombel, is_placeholder, is_active) VALUES (0, 'PSB', 0, 1)");
    $stmt->execute();
    $created = $stmt->affected_rows > 0 ? 1 : 0;
    $stmt->close();
    return $created;
}

function class_ensure_rombel_templates(mysqli $db): int {
    $created = class_ensure_psb($db);
    $stmt = $db->prepare("INSERT IGNORE INTO master_kelas (tingkat, kode_rombel, is_placeholder, is_active) VALUES (?, ?, 0, 1)");
    foreach (range(1, 6) as $level) {
        foreach (range('A', 'J') as $code) {
            $stmt->bind_param('is', $level, $code);
            $stmt->execute();
            $created += $stmt->affected_rows > 0 ? 1 : 0;
        }
    }
    $stmt->close();
    return $created;
}

function class_highest_active_regular_level(mysqli $db): int {
    $row = $db->query("SELECT COALESCE(MAX(COALESCE(mk.tingkat, CAST(s.KELAS AS UNSIGNED))), 0) AS level
        FROM siswa s
        LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
        WHERE s.is_active = 1
          AND COALESCE(mk.tingkat, CAST(s.KELAS AS UNSIGNED)) BETWEEN 1 AND 6")->fetch_assoc();
    return (int)($row['level'] ?? 0);
}

function class_students_for_manual_step(mysqli $db, int $level): array {
    if ($level < 1 || $level > 6) return [];
    $stmt = $db->prepare("SELECT s.NO_INDUK, s.NAMA, s.KELAS, s.master_kelas_id,
        s.SPP_PERBULAN, s.POMG, mk.tingkat, mk.kode_rombel, mk.is_placeholder
        FROM siswa s
        LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
        WHERE s.is_active = 1
          AND COALESCE(mk.tingkat, CAST(s.KELAS AS UNSIGNED)) = ?
        ORDER BY mk.kode_rombel, s.NAMA");
    $stmt->bind_param('i', $level);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) $row['kelas_label'] = class_label($row);
    unset($row);
    return $rows;
}

function class_target_rombel_options(mysqli $db, int $level): array {
    if ($level < 1 || $level > 6) return [];
    $stmt = $db->prepare("SELECT id, tingkat, kode_rombel, is_placeholder, is_active
        FROM master_kelas
        WHERE tingkat = ? AND is_placeholder = 0 AND is_active = 1
        ORDER BY kode_rombel");
    $stmt->bind_param('i', $level);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) $row['label'] = class_label($row);
    unset($row);
    return $rows;
}

function class_manual_graduate_student(mysqli $db, string $noInduk, string $targetYear): array {
    $targetYear = du_normalize_academic_year($targetYear);
    $currentStep = class_highest_active_regular_level($db);
    if ($currentStep !== 6) {
        throw new RuntimeException('Kelas 6 harus diselesaikan terlebih dahulu sebelum tahap kelas lain diproses.');
    }
    $yearId = class_ensure_academic_year($db, $targetYear);
    $stmt = $db->prepare("SELECT s.NO_INDUK, s.NAMA, s.KELAS, s.master_kelas_id, s.SPP_PERBULAN, s.POMG,
        mk.tingkat, mk.kode_rombel, mk.is_placeholder
        FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
        WHERE s.NO_INDUK=? AND s.is_active=1 FOR UPDATE");
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Siswa tidak ditemukan atau sudah tidak aktif.');
    $level = (int)($student['tingkat'] ?: $student['KELAS']);
    if ($level !== 6) throw new RuntimeException('Pada tahap ini hanya siswa kelas 6 yang boleh diluluskan.');

    $snapshot = class_label($student);
    $status = 'lulus';
    $classText = '6';
    $classId = (int)($student['master_kelas_id'] ?? 0);
    $spp = (float)$student['SPP_PERBULAN'];
    $komite = (float)$student['POMG'];
    $stmt = $db->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status)
        VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE kelas=VALUES(kelas),master_kelas_id=VALUES(master_kelas_id),
          kelas_rombel_snapshot=VALUES(kelas_rombel_snapshot),spp_perbulan_snapshot=VALUES(spp_perbulan_snapshot),
          komite_snapshot=VALUES(komite_snapshot),status=VALUES(status)");
    $stmt->bind_param('issisdds', $yearId, $noInduk, $classText, $classId, $snapshot, $spp, $komite, $status);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('UPDATE siswa SET is_active=0 WHERE NO_INDUK=?');
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $stmt->close();
    return ['student' => (string)$student['NAMA'], 'target_year' => $targetYear, 'action' => 'lulus'];
}

function class_manual_promote_student(mysqli $db, string $noInduk, int $targetClassId, string $targetYear): array {
    $targetYear = du_normalize_academic_year($targetYear);
    $currentStep = class_highest_active_regular_level($db);
    if ($currentStep < 1 || $currentStep > 5) {
        throw new RuntimeException($currentStep === 6
            ? 'Selesaikan kelulusan kelas 6 terlebih dahulu.'
            : 'Tidak ada siswa reguler aktif yang perlu dinaikkan.');
    }
    $target = class_find($db, $targetClassId, true);
    if (!$target || (int)$target['is_placeholder'] === 1) throw new RuntimeException('Pilih rombel target yang aktif.');
    if ((int)$target['tingkat'] !== $currentStep + 1) {
        throw new RuntimeException('Tahap saat ini adalah kelas ' . $currentStep . ', jadi target wajib kelas ' . ($currentStep + 1) . '.');
    }
    $stmt = $db->prepare("SELECT s.NO_INDUK, s.NAMA, s.KELAS, s.master_kelas_id, s.SPP_PERBULAN, s.POMG,
        mk.tingkat, mk.kode_rombel, mk.is_placeholder
        FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
        WHERE s.NO_INDUK=? AND s.is_active=1 FOR UPDATE");
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Siswa tidak ditemukan atau sudah tidak aktif.');
    $level = (int)($student['tingkat'] ?: $student['KELAS']);
    if ($level !== $currentStep) throw new RuntimeException('Siswa ini tidak berada pada tahap kenaikan kelas yang sedang aktif.');

    $yearId = class_ensure_academic_year($db, $targetYear);
    $newLevel = (string)$target['tingkat'];
    $snapshot = class_label($target);
    $spp = (float)$student['SPP_PERBULAN'];
    $komite = (float)$student['POMG'];
    $status = 'aktif';
    $stmt = $db->prepare('UPDATE siswa SET KELAS=?, master_kelas_id=?, is_active=1 WHERE NO_INDUK=?');
    $stmt->bind_param('sis', $newLevel, $targetClassId, $noInduk);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status)
        VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), kelas=VALUES(kelas),master_kelas_id=VALUES(master_kelas_id),
          kelas_rombel_snapshot=VALUES(kelas_rombel_snapshot),spp_perbulan_snapshot=VALUES(spp_perbulan_snapshot),
          komite_snapshot=VALUES(komite_snapshot),status=VALUES(status)");
    $stmt->bind_param('issisdds', $yearId, $noInduk, $newLevel, $targetClassId, $snapshot, $spp, $komite, $status);
    $stmt->execute();
    $placementId = (int)$db->insert_id;
    $stmt->close();
    if ($placementId > 0) annual_fee_sync_for_placement($db, $placementId, 'manual-promotion');
    return ['student' => (string)$student['NAMA'], 'target_year' => $targetYear, 'action' => 'naik', 'target' => $snapshot];
}

function class_next_academic_year_label(string $label): string {
    $label = du_normalize_academic_year($label);
    $start = (int)substr($label, 0, 4) + 1;
    return $start . '/' . ($start + 1);
}

function class_ensure_academic_year(mysqli $db, string $label): int {
    [$startDate, $endDate] = du_year_dates($label);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran (label, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, 'draft') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->bind_param('sss', $label, $startDate, $endDate);
    $stmt->execute();
    $yearId = (int)$db->insert_id;
    $stmt->close();
    return $yearId;
}

function class_process_year_promotion(mysqli $db, string $targetYear): array {
    $targetYear = du_normalize_academic_year($targetYear);
    $yearId = class_ensure_academic_year($db, $targetYear);
    class_ensure_rombel_templates($db);
    $students = $db->query("SELECT s.NO_INDUK,s.NAMA,s.KELAS,s.master_kelas_id,s.SPP_PERBULAN,s.POMG,
        mk.tingkat,mk.kode_rombel,mk.is_placeholder
        FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
        WHERE s.is_active=1
        ORDER BY COALESCE(mk.tingkat,s.KELAS),mk.kode_rombel,s.NAMA")->fetch_all(MYSQLI_ASSOC);
    $promoted = 0; $graduated = 0; $skipped = 0;
    $selectNext = $db->prepare("SELECT id, tingkat, kode_rombel, is_placeholder, is_active FROM master_kelas WHERE tingkat=? AND kode_rombel=? AND is_placeholder=0 LIMIT 1");
    $updateStudent = $db->prepare("UPDATE siswa SET KELAS=?, master_kelas_id=?, is_active=1 WHERE NO_INDUK=?");
    $archiveStudent = $db->prepare("UPDATE siswa SET is_active=0 WHERE NO_INDUK=?");
    $insertPlacement = $db->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status)
        VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE kelas=VALUES(kelas),master_kelas_id=VALUES(master_kelas_id),
          kelas_rombel_snapshot=VALUES(kelas_rombel_snapshot),spp_perbulan_snapshot=VALUES(spp_perbulan_snapshot),
          komite_snapshot=VALUES(komite_snapshot),status=VALUES(status),id=LAST_INSERT_ID(id)");
    foreach ($students as $student) {
        $level = (int)($student['tingkat'] ?: $student['KELAS']);
        $code = strtoupper(trim((string)($student['kode_rombel'] ?? '')));
        if ($level < 1 || $level > 6 || $code === '' || (int)($student['is_placeholder'] ?? 1) === 1) {
            $skipped++;
            continue;
        }
        if ($level >= 6) {
            $noInduk = (string)$student['NO_INDUK'];
            $classId = (int)($student['master_kelas_id'] ?? 0);
            $snapshot = class_label($student);
            $status = 'lulus';
            $classText = (string)$level;
            $spp = (float)$student['SPP_PERBULAN'];
            $komite = (float)$student['POMG'];
            $insertPlacement->bind_param('issisdds', $yearId, $noInduk, $classText, $classId, $snapshot, $spp, $komite, $status);
            $insertPlacement->execute();
            $archiveStudent->bind_param('s', $noInduk);
            $archiveStudent->execute();
            $graduated++;
            continue;
        }
        $nextLevel = $level + 1;
        $selectNext->bind_param('is', $nextLevel, $code);
        $selectNext->execute();
        $nextClass = $selectNext->get_result()->fetch_assoc();
        if (!$nextClass) {
            $skipped++;
            continue;
        }
        $noInduk = (string)$student['NO_INDUK'];
        $nextClassId = (int)$nextClass['id'];
        $classText = (string)$nextLevel;
        $snapshot = class_label($nextClass);
        $spp = (float)$student['SPP_PERBULAN'];
        $komite = (float)$student['POMG'];
        $status = 'aktif';
        $updateStudent->bind_param('sis', $classText, $nextClassId, $noInduk);
        $updateStudent->execute();
        $insertPlacement->bind_param('issisdds', $yearId, $noInduk, $classText, $nextClassId, $snapshot, $spp, $komite, $status);
        $insertPlacement->execute();
        $placementId = (int)$db->insert_id;
        if ($placementId > 0) {
            annual_fee_sync_for_placement($db, $placementId, 'promotion');
        }
        $promoted++;
    }
    $selectNext->close(); $updateStudent->close(); $archiveStudent->close(); $insertPlacement->close();
    return ['promoted'=>$promoted,'graduated'=>$graduated,'skipped'=>$skipped,'target_year'=>$targetYear];
}

function class_disable_empty_rombel(mysqli $db): int {
    $stmt = $db->prepare("UPDATE master_kelas mk
        SET mk.is_active=0
        WHERE mk.is_placeholder=0
          AND mk.is_active=1
          AND NOT EXISTS (SELECT 1 FROM siswa s WHERE s.master_kelas_id=mk.id AND s.is_active=1)");
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return max(0, $affected);
}

function class_enable_all_rombel(mysqli $db): int {
    $stmt = $db->prepare("UPDATE master_kelas
        SET is_active = 1
        WHERE is_placeholder = 0
          AND is_active = 0");
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return max(0, $affected);
}

function class_current_academic_year_id(mysqli $db, bool $forUpdate = false): ?int {
    $label = du_current_academic_year();
    $stmt = $db->prepare('SELECT id FROM tahun_ajaran WHERE label = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('s', $label);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function class_component_paid_in_academic_year(mysqli $db, string $noInduk, string $academicYear, string $component): float {
    $academicYear = du_normalize_academic_year($academicYear);
    $startYear = (int)substr($academicYear, 0, 4);
    $column = $component === 'komite' ? 'U_KOMITE' : 'U_SPP';
    $monthSql = "CASE LOWER(BULAN)
      WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4
      WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8
      WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12
      ELSE CAST(BULAN AS UNSIGNED) END";
    $stmt = $db->prepare("SELECT COALESCE(SUM($column), 0) AS paid
        FROM bayar
        WHERE NO_INDUK = ?
          AND ((CAST(TAHUN AS UNSIGNED) = ? AND $monthSql BETWEEN 7 AND 12)
            OR (CAST(TAHUN AS UNSIGNED) = ? AND $monthSql BETWEEN 1 AND 6))");
    $endYear = $startYear + 1;
    $stmt->bind_param('sii', $noInduk, $startYear, $endYear);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function class_validate_tariff_snapshot_change(
    mysqli $db,
    string $noInduk,
    float $oldSpp,
    float $newSpp,
    float $oldKomite,
    float $newKomite
): void {
    $label = du_current_academic_year();
    if (abs($oldSpp - $newSpp) > .001 && class_component_paid_in_academic_year($db, $noInduk, $label, 'spp') > 0) {
        throw new RuntimeException('Tarif SPP tahun ajaran ' . $label . ' sudah memiliki pembayaran dan tidak dapat diubah. Koreksi transaksi terlebih dahulu.');
    }
    $paidKomite = 0.0;
    $yearId = annual_fee_year_id($db, $label, false);
    if ($yearId) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(bts.jumlah), 0) AS paid
            FROM tagihan_tahunan_siswa t
            JOIN bayar_tahunan_siswa bts ON bts.tagihan_tahunan_id = t.id
            WHERE t.tahun_ajaran_id = ? AND t.no_induk = ? AND t.komponen = 'komite'");
        $stmt->bind_param('is', $yearId, $noInduk);
        $stmt->execute();
        $paidKomite = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
        $stmt->close();
    }
    if (abs($oldKomite - $newKomite) > .001 && $newKomite + .001 < $paidKomite) {
        throw new RuntimeException('Tarif Komite tahun ajaran ' . $label . ' tidak boleh lebih kecil dari yang sudah dibayar, yaitu Rp ' . number_format($paidKomite, 0, ',', '.') . '.');
    }
}

function class_sync_student_current_year(
    mysqli $db,
    string $noInduk,
    int $classId,
    float $spp,
    float $komite,
    bool $active = true
): ?int {
    $class = class_find($db, $classId);
    if (!$class) throw new RuntimeException('Master kelas/rombel siswa tidak ditemukan.');
    if ((int)$class['tingkat'] === 0) {
        return null;
    }
    $yearId = class_current_academic_year_id($db, true);
    if (!$yearId) return null;

    $academicLabel = du_current_academic_year();
    $stmt = $db->prepare('SELECT id, master_kelas_id FROM siswa_tahun_ajaran WHERE tahun_ajaran_id=? AND no_induk=? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('is', $yearId, $noInduk); $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($existing && (int)$existing['master_kelas_id'] !== $classId) {
        $stmt = $db->prepare("SELECT EXISTS(SELECT 1 FROM bayar WHERE NO_INDUK=? AND th_ajaran=? AND total_jumlah>0)
          OR EXISTS(SELECT 1 FROM bayar_du WHERE no_induk=? AND th_ajaran=? AND jumlah>0) AS paid");
        $stmt->bind_param('ssss', $noInduk, $academicLabel, $noInduk, $academicLabel); $stmt->execute();
        $hasPayment = (int)$stmt->get_result()->fetch_assoc()['paid'] === 1; $stmt->close();
        if ($hasPayment) {
            $status = $active ? 'aktif' : 'pindah';
            $stmt = $db->prepare('UPDATE siswa_tahun_ajaran SET status=? WHERE id=?');
            $existingId=(int)$existing['id'];$stmt->bind_param('si',$status,$existingId);$stmt->execute();$stmt->close();
            return $existingId;
        }
    }

    $level = (string)$class['tingkat'];
    $label = $class['label'];
    $status = $active ? 'aktif' : 'pindah';
    $stmt = $db->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id, no_induk, kelas, master_kelas_id, kelas_rombel_snapshot, spp_perbulan_snapshot, komite_snapshot, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          id = LAST_INSERT_ID(id), kelas = VALUES(kelas), master_kelas_id = VALUES(master_kelas_id),
          kelas_rombel_snapshot = VALUES(kelas_rombel_snapshot),
          spp_perbulan_snapshot = VALUES(spp_perbulan_snapshot), komite_snapshot = VALUES(komite_snapshot),
          status = VALUES(status)");
    $stmt->bind_param('issisdds', $yearId, $noInduk, $level, $classId, $label, $spp, $komite, $status);
    $stmt->execute();
    $placementId = (int)$db->insert_id;
    $stmt->close();
    if ($placementId <= 0) {
        $stmt = $db->prepare('SELECT id FROM siswa_tahun_ajaran WHERE tahun_ajaran_id = ? AND no_induk = ? LIMIT 1');
        $stmt->bind_param('is', $yearId, $noInduk);
        $stmt->execute();
        $placementId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
    }
    if ($placementId > 0 && $status === 'aktif') {
        annual_fee_sync_for_placement($db, $placementId, 'system');
    }
    return $placementId > 0 ? $placementId : null;
}
