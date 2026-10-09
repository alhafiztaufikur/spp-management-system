<?php
require_once __DIR__.'/kelas.php';

/** Initial admission uses the same billing/snapshot helpers as Data Siswa. */
function psb_placement_apply(mysqli $db, string $nis, int $targetId, string $year, array $actor): array {
    $student = promotion_lock_student($db, $nis);
    if (!in_array((string)$student['KELAS'], ['0','PSB'], true)) {
        throw new RuntimeException('Siswa sudah ditempatkan atau bukan siswa PSB. Muat ulang daftar.');
    }
    if ($student['master_kelas_id'] !== null) {
        $source = class_find($db, (int)$student['master_kelas_id'], false, true);
        if (!$source || (int)$source['tingkat'] !== 0) throw new RuntimeException('Rombel asal siswa tidak sesuai PSB.');
    }
    [$first] = unit_level_bounds();
    $target = class_find($db, $targetId, true, true);
    if (!$target || (int)$target['is_placeholder'] || (int)$target['tingkat'] !== $first) {
        throw new RuntimeException('Pilih rombel aktif pada kelas '.$first.' di unit ini.');
    }
    $unit = unit_active_id();
    $check = $db->prepare('SELECT id FROM siswa_tahun_ajaran WHERE unit_id=? AND no_induk=? FOR UPDATE');
    $check->bind_param('is', $unit, $nis); $check->execute();
    $hasHistory = $check->get_result()->num_rows > 0; $check->close();
    if ($hasHistory) throw new RuntimeException('Siswa sudah memiliki penempatan tahun ajaran. Periksa riwayat siswa.');

    class_ensure_academic_year($db, $year);
    $level = (string)$first;
    $rate = spp_current_effective_rate($db, $level, (float)$student['potongan_spp_nominal'], $year);
    $spp = $rate['year'] !== 'Belum disiapkan' ? (float)$rate['net'] : (float)$student['SPP_PERBULAN'];
    $update = $db->prepare('UPDATE siswa SET KELAS=?,master_kelas_id=?,SPP_PERBULAN=? WHERE id=? AND unit_id=?');
    $id = (int)$student['id'];
    $update->bind_param('sidii', $level, $targetId, $spp, $id, $unit); $update->execute(); $update->close();
    $sync = null;
    $placement = class_sync_student_current_year($db, $nis, $targetId, $spp, (float)$student['POMG'], true, $sync);
    if (!$placement) throw new RuntimeException('Penempatan tahun berjalan tidak berhasil disimpan.');
    spp_sync_student_discount($db, $nis, (float)$student['potongan_spp_nominal'], $placement);
    du_create_bill_for_placement($db, $placement, false);
    $duSync = du_reconcile_current_student_override($db, $nis);
    $after = promotion_lock_student($db, $nis);
    $beforeJson = json_encode($student, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $after['_psb_placement'] = ['tahun_ajaran'=>$year,'rombel'=>$target['label'],'penempatan_id'=>$placement,'tariff_sync'=>$sync,'du_sync'=>$duSync];
    $afterJson = json_encode($after, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $audit = $db->prepare("INSERT INTO siswa_audit_log(siswa_id,no_induk_snapshot,aksi,before_data,after_data,admin_id,admin_name) VALUES(?,?,'update',?,?,?,?)");
    $audit->bind_param('isssis', $id, $nis, $beforeJson, $afterJson, $actor['id'], $actor['nama']); $audit->execute(); $audit->close();
    return ['student'=>$student['NAMA'],'no_induk'=>$nis,'action'=>'penempatan_psb','target'=>$target['label'],'target_year'=>$year,'target_placement_id'=>$placement];
}

/** Caller owns transaction; actor, students, then classes are locked in stable order. */
function psb_placement_batch(mysqli $db, array $selected, array $targets, string $year): array {
    if (du_normalize_academic_year($year) !== du_current_academic_year()) throw new RuntimeException('Tahun penempatan sudah berubah. Muat ulang halaman.');
    $actor = promotion_actor_lock($db);
    foreach ($selected as $nis) if ((!is_string($nis) && !is_int($nis)) || (string)$nis === '') throw new RuntimeException('Pilihan siswa tidak valid.');
    $selected = array_values(array_unique(array_map('strval', $selected)));
    if (!$selected) throw new RuntimeException('Pilih minimal satu siswa PSB.');
    $unit = unit_active_id();
    $lock = $db->prepare('SELECT NO_INDUK,NAMA FROM siswa WHERE unit_id=? AND NO_INDUK IN ('.implode(',',array_fill(0,count($selected),'?')).') ORDER BY id FOR UPDATE');
    $args = array_merge([$unit], $selected); $lock->bind_param('i'.str_repeat('s',count($selected)), ...$args); $lock->execute();
    $rows = $lock->get_result()->fetch_all(MYSQLI_ASSOC); $lock->close();
    $order = array_column($rows, 'NO_INDUK'); $names = array_column($rows, 'NAMA', 'NO_INDUK');
    foreach ($selected as $nis) if (!in_array($nis, $order, true)) $order[] = $nis;
    $targetIds = [];
    foreach ($selected as $nis) if (isset($targets[$nis]) && is_scalar($targets[$nis]) && ctype_digit((string)$targets[$nis])) $targetIds[] = (int)$targets[$nis];
    sort($targetIds, SORT_NUMERIC);
    foreach (array_unique($targetIds) as $targetId) if ($targetId > 0) class_find($db, $targetId, false, true);
    $success = []; $fail = [];
    foreach ($order as $nis) {
        $db->query('SAVEPOINT psb_student');
        try {
            if (!isset($targets[$nis]) || !is_scalar($targets[$nis]) || !ctype_digit((string)$targets[$nis]) || (int)$targets[$nis] <= 0) throw new RuntimeException('Pilih rombel tujuan untuk siswa ini.');
            $success[] = psb_placement_apply($db, $nis, (int)$targets[$nis], $year, $actor);
            $db->query('RELEASE SAVEPOINT psb_student');
        } catch (mysqli_sql_exception $error) {
            if (in_array($error->getCode(), [1205,1213], true)) throw $error;
            $db->query('ROLLBACK TO SAVEPOINT psb_student'); $db->query('RELEASE SAVEPOINT psb_student');
            $fail[] = ['no_induk'=>$nis,'student'=>$names[$nis]??$nis,'reason'=>'Relasi siswa berubah; penempatan dibatalkan.'];
        } catch (Throwable $error) {
            $db->query('ROLLBACK TO SAVEPOINT psb_student'); $db->query('RELEASE SAVEPOINT psb_student');
            $fail[] = ['no_induk'=>$nis,'student'=>$names[$nis]??$nis,'reason'=>$error->getMessage()];
        }
    }
    return ['action'=>'penempatan_psb','target_year'=>$year,'attempted'=>count($selected),'successes'=>$success,'failures'=>$fail];
}

function psb_placement_run(mysqli $db, array $selected, array $targets, string $year): array {
    for ($attempt=0; $attempt<3; $attempt++) {
        $db->begin_transaction();
        try { $result = psb_placement_batch($db, $selected, $targets, $year); $db->commit(); return $result; }
        catch (Throwable $error) {
            $db->rollback();
            if ($error instanceof mysqli_sql_exception && in_array($error->getCode(), [1205,1213], true)) {
                if ($attempt<2) { usleep(100000*($attempt+1)); continue; }
                throw new RuntimeException('Penempatan bersamaan belum selesai. Muat ulang halaman dan coba lagi.');
            }
            throw $error;
        }
    }
    throw new RuntimeException('Penempatan tidak dapat diselesaikan.');
}
