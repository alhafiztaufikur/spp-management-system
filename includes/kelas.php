<?php

require_once __DIR__ . '/daftar_ulang.php';
require_once __DIR__ . '/tagihan_tahunan.php';
require_once __DIR__ . '/komite_billing.php';
require_once __DIR__.'/promotion.php';

function class_label(array $class): string {
    if (($class['tingkat']??$class['KELAS']??'')==='LEGACY') return 'Legacy';
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

function class_find(mysqli $db, int $classId, bool $activeOnly = false, bool $forUpdate = false): ?array {
    $sql = 'SELECT id, unit_id, tingkat, kode_rombel, is_placeholder, is_active FROM master_kelas WHERE id = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
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
    $sql = 'SELECT id, unit_id, tingkat, kode_rombel, is_placeholder, is_active FROM master_kelas';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY CASE WHEN tingkat=0 THEN 0 ELSE 1 END, tingkat, is_placeholder, kode_rombel";
    $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['label'] = (($GLOBALS['app_unit_id']??1)===0?unit_label((int)$row['unit_id']).' · ':'') . class_label($row);
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
    foreach (range(...unit_level_bounds()) as $level) {
        foreach (range('A', 'J') as $code) {
            $stmt->bind_param('is', $level, $code);
            $stmt->execute();
            $created += $stmt->affected_rows > 0 ? 1 : 0;
        }
    }
    $stmt->close();
    return $created;
}

function class_highest_active_regular_level(mysqli $db, string $targetYear): int {
    $sourceYear = class_previous_academic_year_label($targetYear);
    $range = unit_level_between_sql();
    $stmt = $db->prepare("SELECT COALESCE(MAX(CAST(sta.kelas AS UNSIGNED)), 0) AS level
        FROM siswa_tahun_ajaran sta
        JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id
        JOIN siswa s ON s.NO_INDUK=sta.no_induk AND s.unit_id=sta.unit_id AND s.is_active=1
        LEFT JOIN siswa_tahun_ajaran next_sta ON next_sta.no_induk=sta.no_induk AND next_sta.unit_id=sta.unit_id
          AND next_sta.tahun_ajaran_id=(SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1)
        WHERE ta.label=? AND sta.status='aktif' AND next_sta.id IS NULL
          AND CAST(sta.kelas AS UNSIGNED) {$range}");
    $stmt->bind_param('ss', $targetYear, $sourceYear);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['level'] ?? 0);
}

function class_students_for_manual_step(mysqli $db, int $level, string $targetYear): array {
    $ctx=promotion_context_from_target($db,$targetYear);
    return array_values(array_filter(promotion_roster($db,$ctx['source_year_id'],$level),static fn($r)=>$r['eligible']));
}

function class_students_missing_source_year(mysqli $db, string $targetYear): array {
    $sourceYear = class_previous_academic_year_label($targetYear);
    $range = unit_level_between_sql();
    $stmt = $db->prepare("SELECT s.NO_INDUK,s.NAMA
        FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
        LEFT JOIN siswa_tahun_ajaran source_sta ON source_sta.no_induk=s.NO_INDUK AND source_sta.unit_id=s.unit_id
          AND source_sta.tahun_ajaran_id=(SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1)
        LEFT JOIN siswa_tahun_ajaran target_sta ON target_sta.no_induk=s.NO_INDUK AND target_sta.unit_id=s.unit_id
          AND target_sta.tahun_ajaran_id=(SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1)
        WHERE s.is_active=1
          AND COALESCE(mk.tingkat,CAST(s.KELAS AS UNSIGNED)) {$range}
          AND source_sta.id IS NULL AND target_sta.id IS NULL
          AND NOT EXISTS(SELECT 1 FROM siswa_tahun_ajaran later_sta JOIN tahun_ajaran later_ta ON later_ta.id=later_sta.tahun_ajaran_id AND later_ta.unit_id=later_sta.unit_id WHERE later_sta.unit_id=s.unit_id AND later_sta.no_induk=s.NO_INDUK AND later_ta.label>?)
        ORDER BY s.NAMA");
    $stmt->bind_param('sss', $sourceYear, $targetYear, $sourceYear);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function class_target_rombel_options(mysqli $db, int $level): array {
    [$first,$last] = unit_level_bounds();
    if ($level < $first || $level > $last) return [];
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

/** Internal compatibility: caller owns the transaction; source is explicit target minus one. */
function class_process_students_batch(mysqli $db,array $selectedStudents,array $targetClassIds,string $targetYear,int $expectedLevel):array {
    return promotion_batch($db,$selectedStudents,$targetClassIds,promotion_context_from_target($db,$targetYear),$expectedLevel);
}
function class_manual_graduate_student(mysqli $db,string $noInduk,string $targetYear):array {
    return promotion_apply_student($db,$noInduk,0,promotion_context_from_target($db,$targetYear),null,null,true);
}
function class_manual_promote_student(mysqli $db,string $noInduk,int $targetClassId,string $targetYear):array {
    return promotion_apply_student($db,$noInduk,$targetClassId,promotion_context_from_target($db,$targetYear));
}

function class_next_academic_year_label(string $label): string {
    $label = du_normalize_academic_year($label);
    $start = (int)substr($label, 0, 4) + 1;
    return $start . '/' . ($start + 1);
}

function class_previous_academic_year_label(string $label): string {
    $label = du_normalize_academic_year($label);
    $start = (int)substr($label, 0, 4) - 1;
    return $start . '/' . ($start + 1);
}

function class_ensure_academic_year(mysqli $db, string $label): int {
    [$startDate, $endDate] = du_year_dates($label);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran (label, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, 'draft') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->bind_param('sss', $label, $startDate, $endDate);
    $stmt->execute();
    $yearId = (int)$db->insert_id;
    $stmt->close();
    if ($yearId <= 0) {
        $stmt = $db->prepare('SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1');
        $stmt->bind_param('s', $label); $stmt->execute();
        $yearId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
    }
    if ($yearId <= 0) throw new RuntimeException('Tahun ajaran tidak dapat disiapkan.');
    return $yearId;
}

function class_process_year_promotion(mysqli $db,string $targetYear):array {
    $ctx=promotion_context_from_target($db,$targetYear);[$first,$last]=unit_level_bounds();
    $frozen=[];foreach(range($first,$last) as $level)foreach(promotion_roster($db,$ctx['source_year_id'],$level) as $student)if($student['eligible'])$frozen[]=$student;
    $promoted=0;$graduated=0;$skipped=0;
    foreach($frozen as $student){
        $level=(int)$student['KELAS'];$target=0;
        if($level!==$last){foreach(class_target_rombel_options($db,$level+1) as $class)if(strtoupper($class['kode_rombel'])===$student['promotion_kode_rombel']){$target=(int)$class['id'];break;}if(!$target){$skipped++;continue;}}
        $nis=$student['NO_INDUK'];$result=promotion_run_batch($db,[$nis],[$nis=>$target],$ctx['source_year_id'],$ctx['target_year'],$level,[$nis=>$student['source_placement_id']]);
        if(!$result['successes']){$skipped++;continue;}if($level===$last)$graduated++;else$promoted++;
    }
    return ['promoted'=>$promoted,'graduated'=>$graduated,'skipped'=>$skipped,'source_year'=>$ctx['source_year'],'target_year'=>$ctx['target_year']];
}

function class_disable_empty_rombel(mysqli $db): int {
    $db->begin_transaction();
    try {
        // Promotions and student edits lock this same class row before assigning it.
        $classes = $db->query('SELECT id FROM master_kelas
            WHERE is_placeholder=0 AND is_active=1 ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        $occupied = $db->prepare('SELECT COUNT(*) total FROM siswa WHERE master_kelas_id=? AND is_active=1');
        $disable = $db->prepare('UPDATE master_kelas SET is_active=0 WHERE id=? AND is_active=1');
        $affected = 0;
        foreach ($classes as $class) {
            $id = (int)$class['id'];
            $occupied->bind_param('i', $id); $occupied->execute();
            if ((int)$occupied->get_result()->fetch_assoc()['total'] !== 0) continue;
            $disable->bind_param('i', $id); $disable->execute();
            $affected += $disable->affected_rows;
        }
        $occupied->close();
        $disable->close();
        $db->commit();
        return $affected;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
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

function class_annual_component_paid_in_academic_year(mysqli $db, string $noInduk, string $academicYear, string $component): float {
    $stmt = $db->prepare('SELECT COALESCE(SUM(jumlah),0) AS paid FROM bayar_tahunan_siswa WHERE no_induk=? AND th_ajaran=? AND komponen=?');
    $stmt->bind_param('sss', $noInduk, $academicYear, $component);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function class_sync_student_current_year(
    mysqli $db,
    string $noInduk,
    int $classId,
    float $spp,
    float $komite,
    bool $active = true,
    ?array &$syncResult = null
): ?int {
    $syncResult = [
        'tahun_ajaran' => du_current_academic_year(),
        'kelas_dipertahankan' => false,
        'synced' => [],
        'locked' => [],
        'unchanged' => [],
    ];
    $class = class_find($db, $classId);
    if (!$class) throw new RuntimeException('Master kelas/rombel siswa tidak ditemukan.');
    if ((int)$class['tingkat'] === 0) {
        return null;
    }
    $academicLabel = du_current_academic_year();
    $stmt = $db->prepare('SELECT MAX(ta.label) AS latest_year FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk=?');
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $latestYear = (string)($stmt->get_result()->fetch_assoc()['latest_year'] ?? '');
    $stmt->close();
    if ($latestYear !== '' && strcmp($latestYear, $academicLabel) < 0) {
        throw new RuntimeException('Riwayat kelas tahun ajaran berjalan belum ada. Proses kenaikan kelas siswa terlebih dahulu.');
    }
    if ($latestYear !== '' && strcmp($latestYear, $academicLabel) > 0) {
        $academicLabel = $latestYear;
    }
    $stmt = $db->prepare('SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('s', $academicLabel);
    $stmt->execute();
    $yearId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    if (!$yearId) return null;
    $syncResult['tahun_ajaran'] = $academicLabel;
    $stmt = $db->prepare('SELECT id,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,spp_covered_by_psb,komite_snapshot,status FROM siswa_tahun_ajaran WHERE tahun_ajaran_id=? AND no_induk=? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('is', $yearId, $noInduk); $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($existing && $existing['status'] === 'lulus') {
        throw new RuntimeException('Riwayat lulusan tidak dapat diubah melalui Data Siswa.');
    }
    if ($existing && (int)$existing['kelas'] !== (int)$class['tingkat']) {
        throw new RuntimeException('Perubahan tingkat kelas harus melalui proses kenaikan kelas agar riwayat tahun ajaran tetap benar.');
    }
    $preserveClass = false;
    if ($existing && (int)$existing['master_kelas_id'] !== $classId) {
        $stmt = $db->prepare("SELECT EXISTS(SELECT 1 FROM bayar WHERE NO_INDUK=? AND th_ajaran=? AND total_jumlah>0)
          OR EXISTS(SELECT 1 FROM bayar_du WHERE no_induk=? AND th_ajaran=? AND jumlah>0) AS paid");
        $stmt->bind_param('ssss', $noInduk, $academicLabel, $noInduk, $academicLabel); $stmt->execute();
        $hasPayment = (int)$stmt->get_result()->fetch_assoc()['paid'] === 1; $stmt->close();
        $preserveClass = $hasPayment;
    }

    $stmt = $db->prepare('SELECT asal_psb FROM siswa WHERE NO_INDUK=? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('s', $noInduk); $stmt->execute();
    $origin = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $isGradeOne = (int)$class['tingkat'] === 1;
    $alreadyCovered = false;
    if ((int)($origin['asal_psb'] ?? 0) === 1) {
        $stmt = $db->prepare('SELECT EXISTS(SELECT 1 FROM siswa_tahun_ajaran WHERE no_induk=? AND spp_covered_by_psb=1) covered');
        $stmt->bind_param('s', $noInduk); $stmt->execute();
        $alreadyCovered = (int)($stmt->get_result()->fetch_assoc()['covered'] ?? 0) === 1; $stmt->close();
    }
    $coveredByPsb = (int)(
        (int)($existing['spp_covered_by_psb'] ?? 0) === 1
        || ((int)($origin['asal_psb'] ?? 0) === 1 && $isGradeOne && !$alreadyCovered)
    );

    $sppPaid = $existing ? class_component_paid_in_academic_year($db, $noInduk, $academicLabel, 'spp') : 0.0;
    $komitePaid = $existing ? max(
        class_component_paid_in_academic_year($db, $noInduk, $academicLabel, 'komite'),
        class_annual_component_paid_in_academic_year($db, $noInduk, $academicLabel, 'komite')
    ) : 0.0;
    $sppSnapshot = $coveredByPsb ? 0.0 : ($existing && $sppPaid > .001 ? (float)$existing['spp_perbulan_snapshot'] : $spp);
    $komiteSnapshot = $existing && $komitePaid > .001 ? (float)$existing['komite_snapshot'] : $komite;
    $level = $preserveClass ? (string)$existing['kelas'] : (string)$class['tingkat'];
    $label = $preserveClass ? (string)$existing['kelas_rombel_snapshot'] : (string)$class['label'];
    $placementClassId = $preserveClass ? (int)$existing['master_kelas_id'] : $classId;
    $status = $active ? 'aktif' : 'pindah';
    $stmt = $db->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id, no_induk, kelas, master_kelas_id, kelas_rombel_snapshot, spp_perbulan_snapshot, spp_covered_by_psb, komite_snapshot, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          id = LAST_INSERT_ID(id), kelas = VALUES(kelas), master_kelas_id = VALUES(master_kelas_id),
          kelas_rombel_snapshot = VALUES(kelas_rombel_snapshot),
          spp_perbulan_snapshot = VALUES(spp_perbulan_snapshot), spp_covered_by_psb = VALUES(spp_covered_by_psb), komite_snapshot = VALUES(komite_snapshot),
          status = VALUES(status)");
    $stmt->bind_param('issisdids', $yearId, $noInduk, $level, $placementClassId, $label, $sppSnapshot, $coveredByPsb, $komiteSnapshot, $status);
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
    $syncResult['kelas_dipertahankan'] = $preserveClass;
    if ($existing && abs((float)$existing['spp_perbulan_snapshot'] - $spp) > .001) {
        $syncResult[$sppPaid > .001 ? 'locked' : 'synced'][] = 'spp';
    } else {
        $syncResult['unchanged'][] = 'spp';
    }
    if ($placementId > 0 && $status === 'aktif') {
        komite_sync_placement($db, $placementId);
        $rateResult=komite_sync_student_rate($db,$noInduk,$komite,$placementId);
        $syncResult[$rateResult['updated']>0?'synced':'unchanged'][]='komite';
    }

    if ($placementId > 0) {
        $stmt = $db->prepare('SELECT spp_perbulan_snapshot,spp_covered_by_psb,komite_snapshot FROM siswa_tahun_ajaran WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $placementId);
        $stmt->execute();
        $verified = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$verified
            || ((int)$verified['spp_covered_by_psb'] !== $coveredByPsb)
            || (!$coveredByPsb && $sppPaid <= .001 && abs((float)$verified['spp_perbulan_snapshot'] - $spp) > .001)
            || ($coveredByPsb && abs((float)$verified['spp_perbulan_snapshot']) > .001)
            || ($komitePaid <= .001 && abs((float)$verified['komite_snapshot'] - $komite) > .001)) {
            throw new RuntimeException('Verifikasi sinkronisasi tarif tahun ajaran gagal. Tidak ada perubahan yang disimpan.');
        }
    }
    return $placementId > 0 ? $placementId : null;
}
