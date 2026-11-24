<?php
require_once __DIR__.'/payment_period_schema.php';
require_once __DIR__.'/spp_billing.php';
require_once __DIR__.'/komite_billing.php';
require_once __DIR__.'/legacy_full_read.php';
require_once __DIR__.'/student_out.php';
require_once __DIR__.'/transaction_entry_date.php';
require_once __DIR__.'/payment_history_context.php';

function payment_period_key(string $period): bool {
    return (bool)preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $period);
}

function payment_period_label(string $period): string {
    return spp_month_label(substr($period, 5, 2)).' '.substr($period, 0, 4);
}

function payment_period_numeric_label(string $period): string {
    return substr($period,5,2).' '.substr($period,0,4);
}

function payment_period_range_label(array $range,bool $numeric=false): string {
    $format = $numeric ? 'payment_period_numeric_label' : 'payment_period_label';
    $start = $format($range['mulai']);
    return $range['mulai'] === $range['akhir'] ? $start : $start.' – '.$format($range['akhir']);
}

function payment_period_bounds(string $mode, string $choice, ?string $oldest, ?string $fixedStart = null): array {
    if ($mode === 'academic') {
        $periods = spp_academic_periods($choice);
        return [$periods[0]['tahun'].'-07', $periods[11]['tahun'].'-06'];
    }
    if ($mode !== 'range' || !payment_period_key($choice)) throw new DomainException('Pilih bulan terakhir pembayaran.');
    $start = $fixedStart ?? $oldest;
    if (!$start || $choice < $start) throw new DomainException('Bulan terakhir harus sama dengan atau sesudah bulan awal.');
    return [$start, $choice];
}

function payment_period_student(mysqli $db, string $nis, bool $lock = false, ?string $archivedAllowed = null): array {
    if (!in_array(unit_active_id(), [1,2,3], true) || $nis === '' || strlen($nis) > 10) throw new DomainException('Pilih siswa pada unit aktif.');
    sout_assert_student($db, $nis, $lock);
    $student = lf_one($db, "SELECT s.NO_INDUK,s.is_active,s.legacy_pending,s.KELAS,s.unit_id,s.master_kelas_id,
        m.tingkat,m.kode_rombel,m.is_placeholder,
        (m.legacy_kind='alumni' OR EXISTS(SELECT 1 FROM siswa_tahun_ajaran p
            WHERE p.no_induk=s.NO_INDUK AND p.unit_id=s.unit_id AND p.status='lulus')) graduate
        FROM siswa s LEFT JOIN master_kelas m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id
        WHERE s.NO_INDUK=?".($lock ? ' FOR UPDATE' : ''), [$nis]);
    if (!$student || (int)$student['legacy_pending'] || $student['KELAS'] === 'CUSTOM'
        || (!(int)$student['is_active'] && !(int)$student['graduate'] && $student['NO_INDUK'] !== $archivedAllowed)) {
        throw new DomainException('Siswa tidak dapat menerima pembayaran SPP dan Komite.');
    }
    return $student;
}

/** Lock physical bill and allocation rows separately so a waiting writer reads current balances. */
function payment_period_positions(mysqli $db, string $nis, int $exclude = 0, bool $lock = false): array {
    $suffix = $lock ? ' FOR UPDATE' : '';
    $result = [];
    foreach (['spp'=>'tagihan_spp', 'komite'=>'tagihan_komite'] as $kind=>$table) {
        $bills = lf_rows($db, "SELECT id,bulan,tahun,nominal_tagihan,status FROM $table
            WHERE no_induk=? ORDER BY tahun,bulan,id".$suffix, [$nis]);
        $paidSql = $kind === 'spp'
            ? "SELECT t.id,a.nominal_dari_bayar amount FROM tagihan_spp t
                JOIN spp_alokasi a ON a.tagihan_spp_id=t.id AND a.unit_id=t.unit_id
                JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id
                WHERE t.no_induk=? AND b.status='active' AND (b.bayar_id IS NULL OR b.bayar_id<>?)"
            : "SELECT t.id,b.nominal amount FROM tagihan_komite t
                JOIN bayar_komite b ON b.tagihan_komite_id=t.id AND b.unit_id=t.unit_id
                WHERE t.no_induk=? AND (b.bayar_id IS NULL OR b.bayar_id<>?)";
        $native = [];
        foreach (lf_rows($db, $paidSql.$suffix, [$nis,$exclude]) as $row) {
            $native[$row['id']] = ($native[$row['id']] ?? 0) + lb_result_cents($row['amount']);
        }
        foreach ($bills as $bill) {
            $key = sprintf('%04d-%02d', (int)$bill['tahun'], (int)$bill['bulan']);
            $credit = $kind === 'spp' ? lb_spp_credit($db,$nis,$bill['bulan'],$bill['tahun'])
                : lb_monthly_credit($db,$nis,'komite',$bill['bulan'],$bill['tahun']);
            $paid = ($native[$bill['id']] ?? 0) + lb_result_cents($credit);
            $remaining = max(0, lb_result_cents($bill['nominal_tagihan']) - $paid);
            $state = $bill['status'] !== 'open' || (float)$bill['nominal_tagihan'] <= 0
                ? 'exempt' : ($remaining > 0 ? 'due' : 'settled');
            $result[$key][$kind] = ['id'=>(int)$bill['id'], 'state'=>$state,
                'remaining'=>$state === 'due' ? $remaining : 0];
        }
    }
    ksort($result);
    return $result;
}

function payment_period_oldest_due(array $bills): ?string {
    foreach ($bills as $period=>$components) {
        if (in_array('due',array_column($components,'state'),true)) return $period;
    }
    return null;
}

function payment_period_plan(mysqli $db, string $nis, string $mode, string $choice = '', int $exclude = 0,
    bool $lock = false, ?string $fixedStart = null): array {
    if (!payment_period_schema_ready($db) || !spp_billing_schema_ready($db)) throw new DomainException('Fitur pembayaran Multi Bulan belum tersedia.');
    if ($mode !== 'range' && !($exclude > 0 && $mode === 'academic')) throw new DomainException('Pilihan periode tidak valid.');
    if ($lock) lb_scope_payment_student($db, $nis, unit_active_id());
    $bills = payment_period_positions($db,$nis,$exclude,$lock);
    $oldest = payment_period_oldest_due($bills);
    $last = array_key_last($bills);
    $result = ['mode'=>$mode,'oldest'=>$oldest,'years'=>[],'last'=>$last,
        'range'=>null,'rows'=>[],'total'=>0,'blocking'=>null,'version'=>''];
    if ($choice === '' && $exclude === 0) return $result;
    $placements = lf_rows($db, "SELECT y.label,p.komite_mulai_bulan FROM siswa_tahun_ajaran p
        JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id
        WHERE p.no_induk=? AND p.status IN ('aktif','pindah','lulus') ORDER BY p.id", [$nis]);
    $starts = array_column($placements, 'komite_mulai_bulan', 'label');
    $years = array_keys($starts);
    foreach (array_keys($bills) as $period) $years[] = lb_payment_year($period);
    $years = array_values(array_unique($years));
    rsort($years);
    if ($exclude > 0) $result['years'] = $years;
    if ($choice === '') return $result;
    if ($mode === 'range' && $oldest === null && $fixedStart === null) {
        $result['blocking'] = 'Tidak ada sisa tagihan SPP atau Komite.';
        return $result;
    }
    [$start,$end] = payment_period_bounds($mode,$choice,$oldest,$fixedStart);
    $result['range'] = ['mode'=>$mode,'mulai'=>$start,'akhir'=>$end];
    if (!$years || lb_payment_year($end) > $years[0]
        || ($mode === 'range' && $exclude === 0 && (!$last || $end > $last))
        || ($mode === 'academic' && !in_array($choice,$result['years'],true))) {
        $result['blocking'] = 'Tagihan '.payment_period_label($end).' belum terbit.';
        return $result;
    }
    if ($oldest && $oldest < $start) $result['blocking'] = 'Lunasi tagihan '.payment_period_label($oldest).' terlebih dahulu.';
    $spp = $komite = 0;
    $startYear = lb_payment_year($start);
    $startMonth = $starts[$startYear] ?? null;
    $inspectionStart = $start;
    if ($startMonth) {
        $calendar = (int)substr($startYear,0,4) + ($startMonth < '07' ? 1 : 0);
        $inspectionStart = min($start,$calendar.'-'.$startMonth);
    }
    $pointer = new DateTimeImmutable($inspectionStart.'-01');
    while (($period = $pointer->format('Y-m')) <= $end) {
        $year = lb_payment_year($period);
        $month = substr($period, 5);
        $placementStart = $starts[$year] ?? '';
        $beforePlacement = $placementStart && spp_sequence_period_order($month,substr($period,0,4))
            < spp_sequence_period_order($placementStart,substr($year,0,4) + ((int)$placementStart < 7 ? 1 : 0));
        $line = ['period'=>$period,'label'=>payment_period_label($period)];
        foreach (['spp','komite'] as $kind) {
            $bill = $bills[$period][$kind] ?? null;
            if (!$bill) {
                $coverage = lful_coverage_status($db,$nis,$kind,$period);
                $state = match($coverage['label'] ?? '') {
                    'Sudah dibayar (Legacy)', 'Lunas (Tunggakan Legacy)' => 'settled',
                    'Tidak ditagihkan (Legacy)', 'Dibatalkan (Legacy)' => 'exempt',
                    default => !$coverage && ($beforePlacement || sout_period_blocked($db,$nis,$kind,$period)) ? 'exempt' : 'missing',
                };
                $bill = ['id'=>null,'state'=>$state,'remaining'=>$state === 'missing' ? null : 0];
            }
            if ($bill['state'] === 'missing' && !$result['blocking']) {
                $result['blocking'] = 'Tagihan '.($kind === 'spp' ? 'SPP' : 'Komite').' '.$line['label'].' belum terbit atau perlu diperiksa.';
            }
            $line[$kind] = $bill;
        }
        if ($period >= $start) {
            $spp += $line['spp']['remaining'] ?? 0;
            $komite += $line['komite']['remaining'] ?? 0;
            $result['rows'][] = $line;
        }
        $pointer = $pointer->modify('+1 month');
    }
    $result['spp_total'] = $spp;
    $result['komite_total'] = $komite;
    $result['total'] = $spp + $komite;
    if (!$result['total'] && !$result['blocking']) $result['blocking'] = 'Tidak ada sisa tagihan yang dapat dibayar pada periode ini.';
    if (!$result['blocking']) $result['version'] = hash('sha256',json_encode([$nis,$exclude,$result['range'],$result['rows']],JSON_THROW_ON_ERROR));
    return $result;
}

function payment_period_meta(mysqli $db,int $id): ?array {
    if ($id <= 0 || !payment_period_schema_ready($db)) return null;
    return lf_one($db,'SELECT mode,mulai,akhir FROM pembayaran_rentang WHERE bayar_id=?',[$id]);
}

function payment_period_meta_for_rows(mysqli $db,array $rows,bool $includeNumericLabel=false): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows,'id')),static fn($id)=>$id>0)));
    if (!$ids || !payment_period_schema_ready($db)) return $rows;
    $meta = [];
    foreach (array_chunk($ids,1000) as $chunk) {
        foreach (lf_rows($db,'SELECT bayar_id,mode,mulai,akhir FROM pembayaran_rentang WHERE bayar_id IN ('.implode(',',$chunk).')') as $range) {
            $meta[(int)$range['bayar_id']] = $range;
        }
    }
    foreach ($rows as &$row) if (isset($meta[(int)$row['id']])) {
        $range=$meta[(int)$row['id']];
        $row['payment_period_label']=payment_period_range_label($range);
        if($includeNumericLabel)$row['payment_period_numeric_label']=payment_period_range_label($range,true);
    }
    return $rows;
}

function payment_period_receipt_rows(mysqli $db,int $id): array {
    $out = [];
    $parts = [
        'spp'=>"SELECT t.tahun,t.bulan,a.nominal_dari_bayar amount FROM spp_alokasi_batch b
            JOIN spp_alokasi a ON a.batch_id=b.id AND a.unit_id=b.unit_id
            JOIN tagihan_spp t ON t.id=a.tagihan_spp_id AND t.unit_id=a.unit_id WHERE b.bayar_id=? AND b.status='active'",
        'komite'=>"SELECT t.tahun,t.bulan,b.nominal amount FROM bayar_komite b
            JOIN tagihan_komite t ON t.id=b.tagihan_komite_id AND t.unit_id=b.unit_id WHERE b.bayar_id=?",
    ];
    foreach ($parts as $kind=>$sql) foreach (lf_rows($db,$sql,[$id]) as $line) {
        $period = sprintf('%04d-%02d',(int)$line['tahun'],(int)$line['bulan']);
        $out[$period] ??= ['period'=>$period,'label'=>payment_period_label($period),'spp'=>0,'komite'=>0];
        $out[$period][$kind] += lb_result_cents($line['amount']);
    }
    ksort($out);
    return array_values($out);
}

/** Older forms read paid amounts by month from the receipt headers. Range headers
 * carry the cash total, so use their allocations in that monthly projection. */
function payment_period_paid_components(mysqli $db,int $exclude = 0,?string $nis = null): array {
    $scoped = $nis !== null;
    $args = $scoped ? [$exclude,$nis] : [$exclude];
    $ranges = payment_period_schema_ready($db);
    $rows = hp_rows($db,"SELECT b.NO_INDUK,b.BULAN,b.TAHUN,SUM(b.U_SPP) spp,SUM(b.U_KOMITE) komite FROM bayar b"
        .($ranges ? ' LEFT JOIN pembayaran_rentang r ON r.bayar_id=b.id AND r.unit_id=b.unit_id' : '')
        .' WHERE b.id<>?'.($scoped ? ' AND b.NO_INDUK=?' : '').($ranges ? ' AND r.bayar_id IS NULL' : '')
        .' GROUP BY b.NO_INDUK,b.BULAN,b.TAHUN',$args);
    if (!$ranges) return $rows;
    $group = ' WHERE r.bayar_id<>?'.($scoped ? ' AND t.no_induk=?' : '').' GROUP BY t.no_induk,t.bulan,t.tahun';
    $rows = array_merge($rows,hp_rows($db,"SELECT t.no_induk NO_INDUK,t.bulan BULAN,t.tahun TAHUN,SUM(a.nominal_dari_bayar) spp,0 komite
        FROM tagihan_spp t JOIN spp_alokasi a ON a.tagihan_spp_id=t.id AND a.unit_id=t.unit_id
        JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id AND b.status='active'
        JOIN pembayaran_rentang r ON r.bayar_id=b.bayar_id AND r.unit_id=b.unit_id".$group,$args));
    return array_merge($rows,hp_rows($db,"SELECT t.no_induk NO_INDUK,t.bulan BULAN,t.tahun TAHUN,0 spp,SUM(b.nominal) komite
        FROM tagihan_komite t JOIN bayar_komite b ON b.tagihan_komite_id=t.id AND b.unit_id=t.unit_id
        JOIN pembayaran_rentang r ON r.bayar_id=b.bayar_id AND r.unit_id=b.unit_id".$group,$args));
}

function payment_period_receipt_document(mysqli $db,array $payment,string $label,string $signer,bool $singleStudent=false): array {
    require_once __DIR__.'/receipt_balances.php';
    require_once __DIR__.'/receipt_print.php';
    $id = (int)$payment['id'];
    $du = lf_one($db,'SELECT tagihan_daftar_ulang_id,th_ajaran du_tahun_ajaran,kelas du_kelas,jumlah uang_du FROM bayar_du WHERE bayar_id=?',[$id]);
    if ($du) $payment = array_replace($payment,$du);
    return [
        'payment'=>$payment,'school'=>unit_school_name((int)$payment['unit_id']),
        'period_range'=>true,'primary'=>receipt_school_primary($payment),'other'=>receipt_other_fee_lines($db,$id),
        'remaining'=>payment_receipt_remaining($db,$payment,singleStudent:$singleStudent),
        'period'=>$label,
        'words'=>receipt_money_words((float)$payment['total_jumlah']),
        'date'=>spp_date_label($payment['TGL_BYR'],true),'signer'=>$signer,
    ];
}

function payment_period_additional_total(array $snapshot): float {
    return (float)($snapshot['payment']['U_PSB'] ?? 0) + (float)($snapshot['payment']['U_LAIN'] ?? 0)
        + array_sum(array_column($snapshot['details']['bayar_du'] ?? [],'jumlah'));
}

function payment_period_update(mysqli $db,array $student,array $plan,string $date,string $method,string $operator,string $note,int $id,float $additionalTotal): void {
    $nis = $student['NO_INDUK'];
    $grade = $student['KELAS'];
    $class = class_label(['tingkat'=>$student['tingkat'] ?? $grade,
        'kode_rombel'=>$student['kode_rombel'] ?? 'BELUM','is_placeholder'=>$student['is_placeholder'] ?? 1]);
    $classId = (int)$student['master_kelas_id'];
    $range = $plan['range'];
    $spp = $plan['spp_total']/100;
    $komite = $plan['komite_total']/100;
    $total = $plan['total']/100 + $additionalTotal;
    $first = $range['mulai'];
    foreach ($plan['rows'] as $row) {
        if ($row['spp']['remaining'] || $row['komite']['remaining']) {$first = $row['period'];break;}
    }
    [$year,$month] = explode('-',$first);
    $stmt = $db->prepare("UPDATE bayar SET NO_INDUK=?,KELAS=?,master_kelas_id=NULLIF(?,0),kelas_rombel_snapshot=?,
        U_SPP=?,U_KOMITE=?,total_jumlah=?,TGL_BYR=?,BULAN=?,TAHUN=?,user_id=?,sistem_pembayaran=?,KETERANGAN=? WHERE id=?");
    $stmt->bind_param('ssisdddssssssi',$nis,$grade,$classId,$class,$spp,$komite,$total,$date,$month,$year,$operator,$method,$note,$id);
    $stmt->execute();
    $stmt->close();
    payment_period_save_allocations($db,$student,$plan,$date,$operator,$id);
}

function payment_period_save_allocations(mysqli $db,array $student,array $plan,string $date,string $operator,int $id): void {
    $nis = $student['NO_INDUK'];
    $unit = (int)$student['unit_id'];
    $range = $plan['range'];
    $spp = $plan['spp_total']/100;
    $stmt = $db->prepare('INSERT INTO pembayaran_rentang (unit_id,bayar_id,mode,mulai,akhir) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE mode=?,mulai=?,akhir=?');
    $stmt->bind_param('iissssss',$unit,$id,$range['mode'],$range['mulai'],$range['akhir'],$range['mode'],$range['mulai'],$range['akhir']);
    $stmt->execute();
    $stmt->close();
    if ($spp > 0) {
        $stmt = $db->prepare('INSERT INTO spp_alokasi_batch(no_induk,bayar_id,tanggal,user_id,komite_required,uang_baru) VALUES(?,?,?,?,1,?)');
        $stmt->bind_param('sissd',$nis,$id,$date,$operator,$spp);
        $stmt->execute();
        $batchId = (int)$db->insert_id;
        $stmt->close();
        $allocation = $db->prepare('INSERT INTO spp_alokasi(batch_id,tagihan_spp_id,nominal_dari_bayar) VALUES(?,?,?)');
    }
    foreach ($plan['rows'] as $row) {
        if ($row['spp']['remaining']) {
            $amount = $row['spp']['remaining']/100;
            $allocation->bind_param('iid',$batchId,$row['spp']['id'],$amount);
            $allocation->execute();
        }
        if ($row['komite']['remaining']) komite_save_payment($db,$id,$row['komite'],$row['komite']['remaining']/100);
    }
    if (isset($allocation)) $allocation->close();
}
