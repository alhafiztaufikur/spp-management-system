<?php

require_once __DIR__ . '/daftar_ulang.php';

class SppBillingOrderException extends RuntimeException {
    public function __construct(public readonly string $bulan, public readonly string $tahun) {
        parent::__construct('Lunasi dahulu SPP '.spp_month_label($bulan).' '.$tahun.'.');
    }
}

function spp_billing_schema_ready(mysqli $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $result = $db->query("SELECT COUNT(*) total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('master_spp_tahun','master_spp_tarif','tagihan_spp','spp_alokasi_batch','spp_alokasi')");
    $ready = $result && (int)$result->fetch_assoc()['total'] === 5;
    return $ready;
}

function spp_master_ensure_year(mysqli $db, string $label, bool $forUpdate = false): array {
    $label = du_normalize_academic_year($label);
    [$startDate, $endDate] = du_year_dates($label);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status) VALUES(?,?,?,'draft') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->bind_param('sss', $label, $startDate, $endDate);
    $stmt->execute();
    $yearId = (int)$db->insert_id;
    $stmt->close();
    if ($yearId <= 0) {
        $stmt = $db->prepare('SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1');
        $stmt->bind_param('s', $label); $stmt->execute();
        $yearId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
    }
    $stmt = $db->prepare("INSERT INTO master_spp_tahun(tahun_ajaran_id,status) VALUES(?,'draft') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->bind_param('i', $yearId); $stmt->execute();
    $masterId = (int)$db->insert_id; $stmt->close();
    if ($masterId <= 0) {
        $stmt = $db->prepare('SELECT id FROM master_spp_tahun WHERE tahun_ajaran_id=? LIMIT 1');
        $stmt->bind_param('i', $yearId); $stmt->execute();
        $masterId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
    }
    $sql = 'SELECT mst.*,ta.label,ta.id tahun_ajaran_id FROM master_spp_tahun mst JOIN tahun_ajaran ta ON ta.id=mst.tahun_ajaran_id WHERE mst.id=?';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $db->prepare($sql); $stmt->bind_param('i', $masterId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) throw new RuntimeException('Master SPP tahun ajaran tidak dapat dibuat.');
    return $row;
}

function spp_master_rates(mysqli $db, int $masterYearId, bool $forUpdate = false): array {
    [$firstLevel, $lastLevel] = unit_level_bounds();
    $rates = array_fill($firstLevel, $lastLevel - $firstLevel + 1, 0.0);
    $stmt = $db->prepare('SELECT tingkat,nominal_dasar FROM master_spp_tarif WHERE master_spp_tahun_id=? ORDER BY tingkat'.($forUpdate?' FOR UPDATE':''));
    $stmt->bind_param('i', $masterYearId); $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rates[(int)$row['tingkat']] = (float)$row['nominal_dasar'];
    $stmt->close();
    return $rates;
}

function spp_net_tariff(float $base, float $discountAmount): array {
    if (!is_finite($base) || !is_finite($discountAmount) || $base < 0 || $discountAmount < 0) throw new RuntimeException('Nominal tarif/potongan SPP tidak valid.');
    $discount = min($base, round($discountAmount, 2));
    return ['base'=>$base, 'discount_amount'=>$discountAmount, 'discount'=>$discount, 'net'=>max(0, round($base-$discount, 2))];
}

function spp_student_effective_year(mysqli $db, string $noInduk): string {
    $stmt=$db->prepare('SELECT ta.label FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk=? ORDER BY ta.label DESC LIMIT 1');
    $stmt->bind_param('s',$noInduk);$stmt->execute();
    $label=(string)($stmt->get_result()->fetch_assoc()['label']??'');$stmt->close();
    return $label!==''?$label:du_current_academic_year();
}

/** Tarif informasi untuk Master Siswa sesuai tahun penempatan yang diedit. */
function spp_current_effective_rate(mysqli $db, string $level, float $discountAmount, ?string $academicYear = null): array {
    $empty = ['base'=>0.0, 'discount_amount'=>$discountAmount, 'discount'=>0.0, 'net'=>0.0, 'year'=>'Belum disiapkan'];
    [$firstLevel, $lastLevel] = unit_level_bounds();
    if (!spp_billing_schema_ready($db) || !ctype_digit($level) || (int)$level < $firstLevel || (int)$level > $lastLevel) return $empty;
    $year = $academicYear ?? du_current_academic_year();
    $stmt = $db->prepare("SELECT ta.label,mst.id FROM master_spp_tahun mst JOIN tahun_ajaran ta ON ta.id=mst.tahun_ajaran_id WHERE ta.label=? AND mst.status IN ('published','draft') LIMIT 1");
    $stmt->bind_param('s', $year); $stmt->execute(); $master=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$master) return $empty;
    $stmt=$db->prepare('SELECT nominal_dasar FROM master_spp_tarif WHERE master_spp_tahun_id=? AND tingkat=? LIMIT 1');
    $masterId=(int)$master['id'];$levelInt=(int)$level;$stmt->bind_param('ii',$masterId,$levelInt);$stmt->execute();
    $base=(float)($stmt->get_result()->fetch_assoc()['nominal_dasar']??0);$stmt->close();
    $result=spp_net_tariff($base,$discountAmount);$result['year']=(string)$master['label'];return $result;
}

/** Menyelaraskan potongan hanya pada penempatan yang sedang diedit. */
function spp_sync_student_discount(mysqli $db, string $noInduk, float $discountAmount, ?int $placementId): array {
    if (!spp_billing_schema_ready($db) || !$placementId) return ['updated'=>0,'locked'=>0];
    $stmt=$db->prepare("SELECT id FROM siswa_tahun_ajaran WHERE id=? AND no_induk=? AND status='aktif' LIMIT 1 FOR UPDATE");
    $stmt->bind_param('is',$placementId,$noInduk);$stmt->execute();$placement=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$placement)throw new RuntimeException('Penempatan aktif siswa untuk perubahan potongan SPP tidak ditemukan.');
    $discountAmount=max(0,$discountAmount);$updated=0;$locked=0;
    $stmt=$db->prepare("SELECT ts.id,ts.master_spp_tahun_id,ts.tarif_dasar_snapshot,ts.status,
      EXISTS(SELECT 1 FROM spp_alokasi a JOIN spp_alokasi_batch ab ON ab.id=a.batch_id AND ab.status='active' WHERE a.tagihan_spp_id=ts.id) allocated
      FROM tagihan_spp ts JOIN master_spp_tahun mst ON mst.id=ts.master_spp_tahun_id
      WHERE ts.no_induk=? AND ts.penempatan_id=? AND mst.status<>'closed' AND ts.status IN ('open','waived') FOR UPDATE");
    $stmt->bind_param('si',$noInduk,$placementId);$stmt->execute();$bills=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $update=$db->prepare('UPDATE tagihan_spp SET potongan_nominal_ditetapkan_snapshot=?,potongan_nominal_snapshot=?,nominal_tagihan=?,status=? WHERE id=?');
    foreach($bills as $bill){
        if((int)$bill['allocated']===1){$locked++;continue;}
        $net=spp_net_tariff((float)$bill['tarif_dasar_snapshot'],$discountAmount);$status=$net['net']<=.001?'waived':'open';$id=(int)$bill['id'];
        $update->bind_param('dddsi',$discountAmount,$net['discount'],$net['net'],$status,$id);$update->execute();$updated++;
    }
    $update->close();
    if($updated||$locked)spp_write_audit($db,null,$noInduk,'ubah_potongan_siswa',null,['penempatan_id'=>$placementId,'potongan_nominal'=>$discountAmount,'tagihan_diubah'=>$updated,'tagihan_terkunci'=>$locked],$updated+$locked);
    return ['updated'=>$updated,'locked'=>$locked];
}

function spp_write_audit(mysqli $db, ?int $masterYearId, ?string $noInduk, string $action, ?array $before, ?array $after, int $affected = 0): void {
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $adminName = (string)($_SESSION['admin_nama'] ?? $_SESSION['admin_username'] ?? 'system');
    $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('INSERT INTO spp_audit_log(master_spp_tahun_id,no_induk,aksi,before_data,after_data,affected_count,admin_id,admin_name) VALUES(?,?,?,?,?,?,?,?)');
    $stmt->bind_param('issssiis', $masterYearId, $noInduk, $action, $beforeJson, $afterJson, $affected, $adminId, $adminName);
    $stmt->execute(); $stmt->close();
}

/** Keep an unpaid active placement and the student's displayed rate in step with its year master. */
function spp_sync_active_placement_rates(mysqli $db, int $yearId, array $rates, ?array $selectedNis = null): void {
    if ($selectedNis !== null && !$selectedNis) return;
    $studentFilter = $selectedNis === null ? '' : ' AND sta.no_induk IN (' . implode(',', array_fill(0, count($selectedNis), '?')) . ')';
    $stmt = $db->prepare("SELECT sta.id,sta.no_induk,sta.kelas,sta.spp_covered_by_psb,
          sta.spp_perbulan_snapshot,s.potongan_spp_nominal,s.SPP_PERBULAN,s.KELAS AS active_class,
          NOT EXISTS(SELECT 1 FROM siswa_tahun_ajaran newer JOIN tahun_ajaran newer_year
            ON newer_year.id=newer.tahun_ajaran_id
            WHERE newer.no_induk=sta.no_induk AND newer.unit_id=sta.unit_id AND newer_year.label>ta.label) AS latest
        FROM siswa_tahun_ajaran sta
        JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id
        JOIN siswa s ON s.NO_INDUK=sta.no_induk AND s.unit_id=sta.unit_id
        WHERE sta.tahun_ajaran_id=? AND sta.status='aktif' AND s.is_active=1
          AND CAST(sta.kelas AS UNSIGNED) " . unit_level_between_sql() . $studentFilter . "
        ORDER BY sta.no_induk FOR UPDATE");
    $params = array_merge([$yearId], $selectedNis ?? []);
    $stmt->bind_param('i' . str_repeat('s', count($selectedNis ?? [])), ...$params);
    $stmt->execute();
    $placements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$placements) return;

    $year = $db->prepare('SELECT CAST(LEFT(label,4) AS UNSIGNED) AS start_year FROM tahun_ajaran WHERE id=?');
    $year->bind_param('i', $yearId); $year->execute();
    $startYear = (int)($year->get_result()->fetch_assoc()['start_year'] ?? 0); $year->close();
    $endYear = $startYear + 1;

    $monthSql = "CASE LOWER(b.BULAN)
      WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4
      WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8
      WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12
      ELSE CAST(b.BULAN AS UNSIGNED) END";
    $paid = $db->prepare("SELECT
        EXISTS(SELECT 1 FROM bayar b WHERE b.NO_INDUK=? AND b.U_SPP>0
          AND ((CAST(b.TAHUN AS UNSIGNED)=? AND {$monthSql} BETWEEN 7 AND 12)
            OR (CAST(b.TAHUN AS UNSIGNED)=? AND {$monthSql} BETWEEN 1 AND 6)))
        OR EXISTS(SELECT 1 FROM spp_alokasi a JOIN spp_alokasi_batch ab ON ab.id=a.batch_id
          JOIN tagihan_spp ts ON ts.id=a.tagihan_spp_id
          WHERE ts.penempatan_id=? AND ab.status='active') AS has_payment");
    $updatePlacement = $db->prepare("UPDATE siswa_tahun_ajaran SET spp_perbulan_snapshot=? WHERE id=? AND status='aktif'");
    $updateStudent = $db->prepare('UPDATE siswa SET SPP_PERBULAN=? WHERE NO_INDUK=? AND is_active=1');
    foreach ($placements as $placement) {
        $level = (int)$placement['kelas'];
        $base = (float)($rates[$level] ?? 0);
        if ($base <= .001) continue;
        $net = spp_net_tariff($base, (float)$placement['potongan_spp_nominal'])['net'];
        $snapshot = (int)$placement['spp_covered_by_psb'] === 1 ? 0.0 : $net;
        $placementId = (int)$placement['id'];
        $nis = (string)$placement['no_induk'];
        $paid->bind_param('siii', $nis, $startYear, $endYear, $placementId);
        $paid->execute();
        $hasPayment = (int)($paid->get_result()->fetch_assoc()['has_payment'] ?? 0) === 1;
        if (!$hasPayment && abs((float)$placement['spp_perbulan_snapshot'] - $snapshot) > .001) {
            $updatePlacement->bind_param('di', $snapshot, $placementId);
            $updatePlacement->execute();
        }
        if ((int)$placement['latest'] === 1 && (int)$placement['active_class'] === $level
            && abs((float)$placement['SPP_PERBULAN'] - $net) > .001) {
            $updateStudent->bind_param('ds', $net, $nis);
            $updateStudent->execute();
        }
    }
    $paid->close();
    $updatePlacement->close();
    $updateStudent->close();
}

/** Published rates are read-only by default; corrections use an explicit confirmed action. */
function spp_master_rates_editable(array $master): bool {
    return ($master['status'] ?? '') === 'draft'
        && empty($master['published_at']) && empty($master['has_bills']);
}

function spp_master_state(mysqli $db, int $id, bool $lock = false): array {
    $s=$db->prepare('SELECT mst.*,ta.label,
        EXISTS(SELECT 1 FROM tagihan_spp ts WHERE ts.master_spp_tahun_id=mst.id) has_bills,
        COALESCE((SELECT MAX(a.id) FROM spp_audit_log a WHERE a.master_spp_tahun_id=mst.id),0) audit_version
        FROM master_spp_tahun mst JOIN tahun_ajaran ta ON ta.id=mst.tahun_ajaran_id WHERE mst.id=?'.($lock?' FOR UPDATE':''));
    $s->bind_param('i',$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if (!$row) throw new RuntimeException('Master SPP tidak tersedia pada unit ini.');
    return $row;
}

function spp_master_rate_version(array $master, array $rates): string {
    ksort($rates,SORT_NUMERIC);
    $normalized=array_map(static fn($amount)=>number_format((float)$amount,2,'.',''),$rates);
    return hash('sha256',json_encode([unit_active_id(),$master['label']??'',
        $master['status']??'draft',$master['published_at']??null,$master['closed_at']??null,
        (int)($master['has_bills']??0),(int)($master['audit_version']??0),$normalized],JSON_THROW_ON_ERROR));
}

function spp_assert_rate_version(string $expected, array $master, array $rates): void {
    if (!preg_match('/^[a-f0-9]{64}$/D',$expected)
        || !hash_equals(spp_master_rate_version($master,$rates),$expected))
        throw new RuntimeException('Tarif atau status tahun sudah berubah. Muat ulang halaman sebelum menyimpan.');
}

/** Caller owns transaction. Student-before-bill locks agree with the cashier path. */
function spp_master_correct_published_rates(mysqli $db, int $id, array $rates, string $expected, bool $confirmed): array {
    if (!$confirmed) throw new RuntimeException('Konfirmasi perubahan tarif diperlukan.');
    if (!in_array(unit_active_id(),[1,2,3],true)) throw new RuntimeException('Pilih satu unit untuk mengubah tarif.');
    $actor=(int)($_SESSION['admin_id']??0);
    $s=$db->prepare('SELECT role,unit_id,is_active FROM admin WHERE id=? FOR UPDATE');$s->bind_param('i',$actor);$s->execute();$account=$s->get_result()->fetch_assoc();$s->close();
    if (!$account || !(int)$account['is_active'] || $account['role']!==($_SESSION['admin_role']??'')
        || !in_array($account['role'],['super_admin','admin','kasir'],true)
        || ($account['role']!=='super_admin' && (int)$account['unit_id']!==unit_active_id()))
        throw new RuntimeException('Akun tidak diizinkan mengubah tarif SPP.');
    $master=spp_master_state($db,$id,true);$oldRates=spp_master_rates($db,$id,true);
    spp_assert_rate_version($expected,$master,$oldRates);
    if ($master['status']!=='published') throw new RuntimeException('Koreksi tarif hanya tersedia untuk tahun SPP yang terbuka dan sudah terbit.');
    $changed=[];[$first,$last]=unit_level_bounds();
    foreach (range($first,$last) as $level) {
        $new=(float)($rates[$level]??0);
        if (!is_finite($new) || $new<=0 || $new>9999999999999.99) throw new RuntimeException('Tarif dasar kelas '.$level.' harus berupa nominal positif.');
        if (abs($new-$oldRates[$level])>.001) $changed[$level]=$new;
    }
    if (!$changed) throw new RuntimeException('Belum ada perubahan tarif.');
    $yearId=(int)$master['tahun_ajaran_id'];
    $s=$db->prepare('SELECT s.NO_INDUK FROM siswa s JOIN siswa_tahun_ajaran sta ON sta.no_induk=s.NO_INDUK AND sta.unit_id=s.unit_id WHERE sta.tahun_ajaran_id=? ORDER BY s.id');
    $s->bind_param('i',$yearId);$s->execute();$students=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    // Use the cashier's exact lookup shape. A join can lock a different NIS index
    // before the primary record, causing a cycle with payment foreign-key checks.
    $s=$db->prepare('SELECT id FROM siswa WHERE NO_INDUK=? FOR UPDATE');
    foreach($students as $student){$nis=(string)$student['NO_INDUK'];$s->bind_param('s',$nis);$s->execute();$s->get_result()->fetch_all();}$s->close();
    $s=$db->prepare('SELECT id,tingkat_snapshot,status,potongan_nominal_ditetapkan_snapshot FROM tagihan_spp WHERE master_spp_tahun_id=? ORDER BY id FOR UPDATE');
    $s->bind_param('i',$id);$s->execute();$bills=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $paid=$db->prepare("SELECT a.id FROM spp_alokasi a JOIN spp_alokasi_batch ab ON ab.id=a.batch_id WHERE a.tagihan_spp_id=? AND ab.status='active' LIMIT 1 FOR UPDATE");
    $update=$db->prepare('UPDATE tagihan_spp SET tarif_dasar_snapshot=?,potongan_nominal_snapshot=?,nominal_tagihan=?,status=? WHERE id=?');
    $updated=0;$locked=0;
    foreach ($bills as $bill) {
        $level=(int)$bill['tingkat_snapshot'];if (!isset($changed[$level])) continue;
        $billId=(int)$bill['id'];$paid->bind_param('i',$billId);$paid->execute();$allocated=$paid->get_result()->num_rows>0;
        if ($allocated || !in_array($bill['status'],['open','waived'],true)) { $locked++;continue; }
        $base=$changed[$level];$net=spp_net_tariff($base,(float)$bill['potongan_nominal_ditetapkan_snapshot']);$status=$net['net']<=.001?'waived':'open';
        $update->bind_param('dddsi',$base,$net['discount'],$net['net'],$status,$billId);$update->execute();$updated++;
    }
    $paid->close();$update->close();
    $s=$db->prepare('INSERT INTO master_spp_tarif(master_spp_tahun_id,tingkat,nominal_dasar) VALUES(?,?,?) ON DUPLICATE KEY UPDATE nominal_dasar=VALUES(nominal_dasar)');
    foreach ($changed as $level=>$new) { $s->bind_param('iid',$id,$level,$new);$s->execute(); }
    $s->close();spp_sync_active_placement_rates($db,$yearId,$rates);
    spp_write_audit($db,$id,null,'koreksi_tarif_terbit',['tarif'=>$oldRates],['tarif'=>$rates,'tagihan_diubah'=>$updated,'tagihan_dipertahankan'=>$locked],$updated);
    return ['rates_changed'=>count($changed),'bills_updated'=>$updated,'bills_locked'=>$locked];
}

/** Caller owns the transaction. Saving and publication lock the same master row. */
function spp_master_save_rates(mysqli $db, int $masterYearId, array $rates): array {
    $stmt = $db->prepare('SELECT mst.status,mst.published_at,mst.tahun_ajaran_id,
        EXISTS(SELECT 1 FROM tagihan_spp ts WHERE ts.master_spp_tahun_id=mst.id) AS has_bills
        FROM master_spp_tahun mst WHERE mst.id=? FOR UPDATE');
    $stmt->bind_param('i', $masterYearId); $stmt->execute();
    $master = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$master) throw new RuntimeException('Tahun SPP tidak tersedia.');
    if (!spp_master_rates_editable($master)) throw new RuntimeException('Tarif terkunci karena SPP sudah diterbitkan atau tahun SPP ditutup.');
    $changed = 0;
    [$firstLevel, $lastLevel] = unit_level_bounds();
    for ($level=$firstLevel; $level<=$lastLevel; $level++) {
        $new = (float)($rates[$level] ?? 0);
        if ($new <= 0) throw new RuntimeException('Tarif dasar kelas '.$level.' harus lebih dari Rp0.');
        $stmt = $db->prepare('SELECT id,nominal_dasar FROM master_spp_tarif WHERE master_spp_tahun_id=? AND tingkat=? FOR UPDATE');
        $stmt->bind_param('ii', $masterYearId, $level); $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$old) {
            $stmt = $db->prepare('INSERT INTO master_spp_tarif(master_spp_tahun_id,tingkat,nominal_dasar) VALUES(?,?,?)');
            $stmt->bind_param('iid', $masterYearId, $level, $new); $stmt->execute(); $stmt->close();
            $changed++;
            continue;
        }
        if (abs((float)$old['nominal_dasar']-$new) < .001) continue;
        $stmt = $db->prepare('UPDATE master_spp_tarif SET nominal_dasar=? WHERE id=?');
        $rateId = (int)$old['id']; $stmt->bind_param('di', $new, $rateId); $stmt->execute(); $stmt->close();
        $changed++;
        spp_write_audit($db, $masterYearId, null, 'ubah_tarif', ['tingkat'=>$level,'nominal'=>(float)$old['nominal_dasar']], ['tingkat'=>$level,'nominal'=>$new,'tagihan_diubah'=>0,'tagihan_terkunci'=>0], 0);
    }
    spp_sync_active_placement_rates($db, (int)$master['tahun_ajaran_id'], $rates);
    return ['rates_changed'=>$changed,'bills_updated'=>0,'bills_locked'=>0];
}

/** @return array<int,array{bulan:string,tahun:string,label:string,order:int}> */
function spp_academic_periods(string $label): array {
    $label = du_normalize_academic_year($label);
    [$start,$end] = array_map('intval', explode('/', $label));
    $names=[1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $periods=[]; $order=0;
    foreach ([[7,12,$start],[1,6,$end]] as [$first,$last,$year]) {
        for($m=$first;$m<=$last;$m++) $periods[]=['bulan'=>str_pad((string)$m,2,'0',STR_PAD_LEFT),'tahun'=>(string)$year,'label'=>$names[$m].' '.$year,'order'=>$order++];
    }
    return $periods;
}
function spp_month_label(string $month): string {
    $names=['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    return $names[str_pad((string)(int)$month,2,'0',STR_PAD_LEFT)]??$month;
}

function spp_publish_students(mysqli $db, int $masterYearId, array $students, array $startMonths = []): array {
    $stmt=$db->prepare('SELECT mst.status,ta.id tahun_ajaran_id,ta.label FROM master_spp_tahun mst JOIN tahun_ajaran ta ON ta.id=mst.tahun_ajaran_id WHERE mst.id=? FOR UPDATE');
    $stmt->bind_param('i',$masterYearId);$stmt->execute();$master=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$master || $master['status']==='closed') throw new RuntimeException('Tahun SPP sudah ditutup atau tidak tersedia.');
    $rates=spp_master_rates($db,$masterYearId);
    foreach($rates as $level=>$rate) if($rate<=0) throw new RuntimeException('Lengkapi tarif dasar kelas '.$level.' sebelum menerbitkan.');
    $selected=[]; foreach($students as $nis){$nis=trim((string)$nis);if($nis!=='')$selected[$nis]=true;}
    if(!$selected) throw new RuntimeException('Pilih minimal satu siswa untuk penerbitan SPP.');
    spp_sync_active_placement_rates($db, (int)$master['tahun_ajaran_id'], $rates, array_keys($selected));
    $periods=spp_academic_periods((string)$master['label']);
    $created=0;$skipped=0;$ineligible=[];
    $find=$db->prepare("SELECT sta.id,sta.no_induk,sta.kelas,sta.master_kelas_id,sta.kelas_rombel_snapshot,sta.spp_covered_by_psb,sta.komite_mulai_bulan,s.potongan_spp_nominal,s.is_active
      FROM siswa_tahun_ajaran sta JOIN siswa s ON s.NO_INDUK=sta.no_induk AND s.unit_id=sta.unit_id
      WHERE sta.tahun_ajaran_id=? AND sta.no_induk=? AND CAST(sta.kelas AS UNSIGNED) " . unit_level_between_sql() . " LIMIT 1 FOR UPDATE");
    $insert=$db->prepare("INSERT IGNORE INTO tagihan_spp(master_spp_tahun_id,tahun_ajaran_id,penempatan_id,no_induk,tingkat_snapshot,master_kelas_id,kelas_rombel_snapshot,bulan,tahun,tarif_dasar_snapshot,potongan_nominal_ditetapkan_snapshot,potongan_nominal_snapshot,nominal_tagihan,status)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach(array_keys($selected) as $nis){
        $yearId=(int)$master['tahun_ajaran_id'];$find->bind_param('is',$yearId,$nis);$find->execute();$placement=$find->get_result()->fetch_assoc();
        if(!$placement || (int)$placement['is_active']!==1){$ineligible[]=$nis;continue;}
        // Manual Legacy activation creates only a placement. Explicit publication prepares
        // its Komite pair too; INSERT IGNORE preserves existing bills and paid snapshots.
        require_once __DIR__.'/komite_billing.php';
        komite_sync_placement($db,(int)$placement['id']);
        $level=(int)$placement['kelas'];$base=(float)$rates[$level];$discount=(float)$placement['potongan_spp_nominal'];
        $net=spp_net_tariff($base,$discount);$covered=(int)$placement['spp_covered_by_psb']===1;
        $start=(string)($startMonths[$nis]??$placement['komite_mulai_bulan']??'07');$startIndex=0;
        foreach($periods as $idx=>$period) if($period['bulan']===$start){$startIndex=$idx;break;}
        foreach($periods as $idx=>$period){
            if($idx<$startIndex)continue;
            $billBase=$covered?0:$base;$billDiscount=$covered?0:$net['discount'];$billNet=$covered?0:$net['net'];
            $status=$covered?'covered_psb':($billNet<=.001?'waived':'open');
            $placementId=(int)$placement['id'];$classId=(int)($placement['master_kelas_id']??0);$classIdParam=$classId?:null;
            $classLabel=(string)$placement['kelas_rombel_snapshot'];$month=$period['bulan'];$year=$period['tahun'];
            $insert->bind_param('iiisiisssdddds',$masterYearId,$yearId,$placementId,$nis,$level,$classIdParam,$classLabel,$month,$year,$billBase,$discount,$billDiscount,$billNet,$status);
            $insert->execute(); if($insert->affected_rows===1)$created++;else$skipped++;
        }
    }
    $find->close();$insert->close();
    if($created>0){$stmt=$db->prepare("UPDATE master_spp_tahun SET status='published',published_at=COALESCE(published_at,NOW()) WHERE id=? AND status='draft'");$stmt->bind_param('i',$masterYearId);$stmt->execute();$stmt->close();}
    spp_write_audit($db,$masterYearId,null,'terbitkan_tagihan',null,['siswa'=>count($selected),'tagihan_baru'=>$created,'tidak_memenuhi'=>$ineligible],$created);
    return ['created'=>$created,'existing'=>$skipped,'ineligible'=>$ineligible];
}



function spp_open_bills(mysqli $db, string $noInduk, bool $forUpdate=false): array {
    $sql="SELECT ts.*,COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) paid
      FROM tagihan_spp ts LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id
      WHERE ts.no_induk=? AND ts.status='open'
      GROUP BY ts.id HAVING ts.nominal_tagihan-paid>.001 ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED),ts.id";
    if($forUpdate)$sql.=' FOR UPDATE';
    $stmt=$db->prepare($sql);$stmt->bind_param('s',$noInduk);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    foreach($rows as &$row){$row['paid']=(float)$row['paid'];$row['remaining']=max(0,(float)$row['nominal_tagihan']-$row['paid']);}unset($row);
    return $rows;
}

function spp_published_period_status(mysqli $db, string $noInduk, string $month, string $year, int $excludePaymentId=0): array {
    $stmt=$db->prepare("SELECT ts.id,ts.bulan,ts.tahun,ts.nominal_tagihan,ts.status,COALESCE(SUM(CASE WHEN ab.status='active' AND (ab.bayar_id IS NULL OR ab.bayar_id<>?) THEN a.nominal_dari_bayar ELSE 0 END),0) paid FROM tagihan_spp ts LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id WHERE ts.no_induk=? GROUP BY ts.id ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED)");
    $stmt->bind_param('is',$excludePaymentId,$noInduk);$stmt->execute();$bills=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $selected=null;$older=null;
    $target=(int)$year*100+(int)$month;
    foreach ($bills as $bill) {
        $period=(int)$bill['tahun']*100+(int)$bill['bulan'];
        $remaining=max(0,(float)$bill['nominal_tagihan']-(float)$bill['paid']);
        if ($period===$target) $selected=$bill;
        if ($period<$target && $bill['status']==='open' && $remaining>.001 && !$older) $older=$bill;
    }
    $label=spp_month_label($month).' '.$year;
    $paid=(float)($selected['paid']??0);$total=(float)($selected['nominal_tagihan']??0);
    $status=['ok'=>true,'status'=>'payable','code'=>'payable','lock_spp'=>false,'title'=>'','message'=>'','amount_label'=>'','selected'=>['bulan'=>$month,'tahun'=>$year,'label'=>$label,'tariff'=>$total,'paid'=>$paid,'remaining'=>max(0,$total-$paid)],'blocking_period'=>null];
    if (!$selected) return array_merge($status,['status'=>'not_published','code'=>'not_published','lock_spp'=>true,'title'=>'Tagihan SPP belum terbit','message'=>'SPP '.$label.' belum tersedia.','amount_label'=>'Pilih bulan lain atau hubungi admin.']);
    if ($selected['status']!=='open' || $total<=.001) return array_merge($status,['status'=>'not_payable','code'=>'not_payable','lock_spp'=>true,'title'=>'SPP tidak perlu dibayar','message'=>'SPP '.$label.' tidak memiliki tagihan terbuka.']);
    if ($paid+.001>=$total) return array_merge($status,['status'=>'already_paid','code'=>'already_paid','lock_spp'=>true,'title'=>'SPP sudah lunas','message'=>'SPP '.$label.' sudah lunas.']);
    if ($older) {
        $oldLabel=spp_month_label((string)$older['bulan']).' '.$older['tahun'];
        return array_merge($status,['status'=>'prior_unpaid','code'=>'prior_unpaid','lock_spp'=>true,'title'=>'Ada SPP yang lebih lama','message'=>'SPP '.$oldLabel.' masih tersisa Rp '.number_format((float)$older['nominal_tagihan']-(float)$older['paid'],0,',','.').'. Lunasi bulan itu lebih dulu.','amount_label'=>'Sisa Rp '.number_format((float)$older['nominal_tagihan']-(float)$older['paid'],0,',','.'),'blocking_period'=>['bulan'=>$older['bulan'],'tahun'=>$older['tahun'],'label'=>$oldLabel]]);
    }
    return $status;
}

function spp_payment_payload(mysqli $db, int $excludePaymentId = 0): array {
    $payload=[];
    $result=$db->query('SELECT NO_INDUK FROM siswa');
    while($row=$result->fetch_assoc())$payload[$row['NO_INDUK']]=['tagihan'=>[]];
    $excludeAllocation=$excludePaymentId>0?' AND (ab.bayar_id IS NULL OR ab.bayar_id<>'.(int)$excludePaymentId.')':'';
    $result=$db->query("SELECT ts.*,ta.label tahun_ajaran,COALESCE(SUM(CASE WHEN ab.status='active'".$excludeAllocation." THEN a.nominal_dari_bayar ELSE 0 END),0) paid
      FROM tagihan_spp ts JOIN tahun_ajaran ta ON ta.id=ts.tahun_ajaran_id LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id
      WHERE ts.status='open' GROUP BY ts.id ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED)");
    while($row=$result->fetch_assoc()){
        $remaining=max(0,(float)$row['nominal_tagihan']-(float)$row['paid']);if($remaining<=.001)continue;
        $payload[$row['no_induk']]['tagihan'][]=['id'=>(int)$row['id'],'bulan'=>$row['bulan'],'tahun'=>$row['tahun'],'tahun_ajaran'=>$row['tahun_ajaran'],'kelas'=>$row['kelas_rombel_snapshot'],'total'=>(float)$row['nominal_tagihan'],'paid'=>(float)$row['paid'],'remaining'=>$remaining];
    }
    return $payload;
}

/** Satu transaksi SPP melunasi tepat satu tagihan terbit yang dipilih kasir. */
function spp_allocate_payment(mysqli $db, string $noInduk, ?int $bayarId, string $month, string $year, float $newMoney, string $date, string $method, string $userId): array {
    if (!in_array($month, ['01','02','03','04','05','06','07','08','09','10','11','12'], true) || !preg_match('/^\d{4}$/', $year)) throw new RuntimeException('Periode SPP tidak valid.');
    if ($newMoney <= .001 || !is_finite($newMoney)) throw new RuntimeException('Nominal SPP tidak valid.');
    $bills=spp_open_bills($db,$noInduk,true);
    $selected=null;
    foreach ($bills as $bill) if ($bill['bulan']===$month && $bill['tahun']===$year) { $selected=$bill; break; }
    if (!$selected) throw new RuntimeException('Tagihan SPP '.spp_month_label($month).' '.$year.' belum terbit atau sudah lunas.');
    $oldest=$bills[0];
    if ((int)$oldest['id'] !== (int)$selected['id']) throw new SppBillingOrderException((string)$oldest['bulan'], (string)$oldest['tahun']);
    $need=(float)$selected['remaining'];
    if (abs($newMoney-$need)>.001) throw new RuntimeException('SPP '.spp_month_label($month).' '.$year.' harus dilunasi tepat Rp '.number_format($need,0,',','.').'.');
    $required=1;
    $stmt=$db->prepare('INSERT INTO spp_alokasi_batch(no_induk,bayar_id,tanggal,user_id,komite_required,uang_baru) VALUES(?,?,?,?,?,?)');
    $stmt->bind_param('sissid',$noInduk,$bayarId,$date,$userId,$required,$newMoney);$stmt->execute();$batchId=(int)$db->insert_id;$stmt->close();
    $billId=(int)$selected['id'];
    $stmt=$db->prepare('INSERT INTO spp_alokasi(batch_id,tagihan_spp_id,nominal_dari_bayar) VALUES(?,?,?)');
    $stmt->bind_param('iid',$batchId,$billId,$newMoney);$stmt->execute();$stmt->close();
    return ['batch_id'=>$batchId,'cash_received'=>$newMoney,'cash_allocated'=>$newMoney,'periods'=>[spp_month_label($month).' '.$year],'bill_count'=>1];
}

function spp_reverse_payment_allocation(mysqli $db, int $bayarId): void {
    $stmt=$db->prepare("SELECT id,no_induk FROM spp_alokasi_batch WHERE bayar_id=? AND status='active' FOR UPDATE");$stmt->bind_param('i',$bayarId);$stmt->execute();$batch=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$batch)return;
    $stmt=$db->prepare("UPDATE spp_alokasi_batch SET status='reversed' WHERE id=?");$id=(int)$batch['id'];$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
}

function spp_assert_paid_order(mysqli $db, string $noInduk): void {
    $stmt=$db->prepare("SELECT ts.bulan,ts.tahun,ts.nominal_tagihan,COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) paid,MAX(CASE WHEN ab.status='active' AND ab.komite_required=1 THEN 1 ELSE 0 END) protected_payment FROM tagihan_spp ts LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id WHERE ts.no_induk=? AND ts.status='open' GROUP BY ts.id ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED)");
    $stmt->bind_param('s',$noInduk);$stmt->execute();$firstUnpaid=null;
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $bill) {
        if ((float)$bill['nominal_tagihan']-(float)$bill['paid']>.001 && !$firstUnpaid) $firstUnpaid=$bill;
        if ($firstUnpaid && (int)$bill['protected_payment']===1 && ((int)$bill['tahun']*100+(int)$bill['bulan']) > ((int)$firstUnpaid['tahun']*100+(int)$firstUnpaid['bulan'])) {
            $stmt->close();
            throw new RuntimeException('SPP '.spp_month_label((string)$firstUnpaid['bulan']).' '.$firstUnpaid['tahun'].' tidak boleh menjadi tunggakan karena pembayaran bulan berikutnya sudah tersimpan.');
        }
    }
    $stmt->close();
}

function spp_payment_allocation_summary(mysqli $db, int $bayarId): ?array {
    $stmt=$db->prepare("SELECT ab.* FROM spp_alokasi_batch ab WHERE ab.bayar_id=? AND ab.status='active' LIMIT 1");
    $stmt->bind_param('i',$bayarId);$stmt->execute();$batch=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$batch)return null;
    $stmt=$db->prepare("SELECT ts.bulan,ts.tahun,ts.kelas_rombel_snapshot,a.nominal_dari_bayar FROM spp_alokasi a JOIN tagihan_spp ts ON ts.id=a.tagihan_spp_id WHERE a.batch_id=? ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED)");$id=(int)$batch['id'];$stmt->bind_param('i',$id);$stmt->execute();$batch['allocations']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $batch;
}
