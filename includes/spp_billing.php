<?php

require_once __DIR__ . '/daftar_ulang.php';

function spp_billing_schema_ready(mysqli $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $result = $db->query("SELECT COUNT(*) total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('master_spp_tahun','master_spp_tarif','tagihan_spp','spp_alokasi_batch','spp_alokasi','titipan_spp_mutasi')");
    $ready = $result && (int)$result->fetch_assoc()['total'] === 6;
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

function spp_master_rates(mysqli $db, int $masterYearId): array {
    $rates = array_fill(1, 6, 0.0);
    $stmt = $db->prepare('SELECT tingkat,nominal_dasar FROM master_spp_tarif WHERE master_spp_tahun_id=? ORDER BY tingkat');
    $stmt->bind_param('i', $masterYearId); $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rates[(int)$row['tingkat']] = (float)$row['nominal_dasar'];
    $stmt->close();
    return $rates;
}

function spp_net_tariff(float $base, float $discountPercent): array {
    $discountPercent = min(100, max(0, $discountPercent));
    $discount = round($base * $discountPercent / 100, 0);
    return ['base'=>$base, 'discount_percent'=>$discountPercent, 'discount'=>$discount, 'net'=>max(0, round($base-$discount, 0))];
}

/** Tarif informasi untuk Master Siswa. Tagihan baru tetap hanya berasal dari proses penerbitan. */
function spp_current_effective_rate(mysqli $db, string $level, float $discountPercent): array {
    $empty = ['base'=>0.0, 'discount_percent'=>$discountPercent, 'discount'=>0.0, 'net'=>0.0, 'year'=>'Belum disiapkan'];
    if (!spp_billing_schema_ready($db) || !preg_match('/^[1-6]$/', $level)) return $empty;
    $currentYear = du_current_academic_year();
    $stmt = $db->prepare("SELECT ta.label,mst.id FROM master_spp_tahun mst JOIN tahun_ajaran ta ON ta.id=mst.tahun_ajaran_id WHERE mst.status IN ('published','draft') ORDER BY (ta.label=?) DESC,(mst.status='published') DESC,ta.tanggal_mulai DESC,mst.id DESC LIMIT 1");
    $stmt->bind_param('s', $currentYear); $stmt->execute(); $master=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$master) return $empty;
    $stmt=$db->prepare('SELECT nominal_dasar FROM master_spp_tarif WHERE master_spp_tahun_id=? AND tingkat=? LIMIT 1');
    $masterId=(int)$master['id'];$levelInt=(int)$level;$stmt->bind_param('ii',$masterId,$levelInt);$stmt->execute();
    $base=(float)($stmt->get_result()->fetch_assoc()['nominal_dasar']??0);$stmt->close();
    $result=spp_net_tariff($base,$discountPercent);$result['year']=(string)$master['label'];return $result;
}

/** Menyelaraskan potongan siswa hanya ke tagihan yang belum pernah menerima alokasi. */
function spp_sync_student_discount(mysqli $db, string $noInduk, float $discountPercent): array {
    if (!spp_billing_schema_ready($db)) return ['updated'=>0,'locked'=>0];
    $discountPercent=min(100,max(0,$discountPercent));$updated=0;$locked=0;
    $stmt=$db->prepare("SELECT ts.id,ts.master_spp_tahun_id,ts.tarif_dasar_snapshot,ts.status,
      EXISTS(SELECT 1 FROM spp_alokasi a JOIN spp_alokasi_batch ab ON ab.id=a.batch_id AND ab.status='active' WHERE a.tagihan_spp_id=ts.id) allocated
      FROM tagihan_spp ts JOIN master_spp_tahun mst ON mst.id=ts.master_spp_tahun_id
      WHERE ts.no_induk=? AND mst.status<>'closed' AND ts.status IN ('open','waived') FOR UPDATE");
    $stmt->bind_param('s',$noInduk);$stmt->execute();$bills=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $update=$db->prepare('UPDATE tagihan_spp SET potongan_persen_snapshot=?,potongan_nominal_snapshot=?,nominal_tagihan=?,status=? WHERE id=?');
    foreach($bills as $bill){
        if((int)$bill['allocated']===1){$locked++;continue;}
        $net=spp_net_tariff((float)$bill['tarif_dasar_snapshot'],$discountPercent);$status=$net['net']<=.001?'waived':'open';$id=(int)$bill['id'];
        $update->bind_param('dddsi',$discountPercent,$net['discount'],$net['net'],$status,$id);$update->execute();$updated++;
    }
    $update->close();
    if($updated||$locked)spp_write_audit($db,null,$noInduk,'ubah_potongan_siswa',null,['potongan_persen'=>$discountPercent,'tagihan_diubah'=>$updated,'tagihan_terkunci'=>$locked],$updated+$locked);
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

/** Menyimpan tarif dan menyelaraskan hanya tagihan yang belum pernah dialokasikan. */
function spp_master_save_rates(mysqli $db, int $masterYearId, array $rates): array {
    $stmt = $db->prepare('SELECT status FROM master_spp_tahun WHERE id=? FOR UPDATE');
    $stmt->bind_param('i', $masterYearId); $stmt->execute();
    $status = (string)($stmt->get_result()->fetch_assoc()['status'] ?? ''); $stmt->close();
    if ($status === '' || $status === 'closed') throw new RuntimeException('Tahun SPP sudah ditutup atau tidak tersedia.');
    $changed = 0; $updatedBills = 0; $lockedBills = 0;
    for ($level=1; $level<=6; $level++) {
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
        $stmt = $db->prepare("SELECT ts.id,ts.potongan_persen_snapshot,
             EXISTS(SELECT 1 FROM spp_alokasi a JOIN spp_alokasi_batch ab ON ab.id=a.batch_id AND ab.status='active' WHERE a.tagihan_spp_id=ts.id) allocated
             FROM tagihan_spp ts WHERE ts.master_spp_tahun_id=? AND ts.tingkat_snapshot=? AND ts.status IN ('open','waived') FOR UPDATE");
        $stmt->bind_param('ii', $masterYearId, $level); $stmt->execute();
        $bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $levelUpdated = 0;
        $levelLocked = 0;
        foreach ($bills as $bill) {
            if ((int)$bill['allocated'] === 1) { $lockedBills++; $levelLocked++; continue; }
            $net = spp_net_tariff($new, (float)$bill['potongan_persen_snapshot']);
            $newStatus = $net['net'] <= .001 ? 'waived' : 'open';
            $stmt = $db->prepare('UPDATE tagihan_spp SET tarif_dasar_snapshot=?,potongan_nominal_snapshot=?,nominal_tagihan=?,status=? WHERE id=?');
            $billId=(int)$bill['id']; $stmt->bind_param('dddsi', $new, $net['discount'], $net['net'], $newStatus, $billId); $stmt->execute(); $stmt->close();
            $updatedBills++; $levelUpdated++;
        }
        $changed++;
        spp_write_audit($db, $masterYearId, null, 'ubah_tarif', ['tingkat'=>$level,'nominal'=>(float)$old['nominal_dasar']], ['tingkat'=>$level,'nominal'=>$new,'tagihan_diubah'=>$levelUpdated,'tagihan_terkunci'=>$levelLocked], count($bills));
    }
    return ['rates_changed'=>$changed,'bills_updated'=>$updatedBills,'bills_locked'=>$lockedBills];
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
    $periods=spp_academic_periods((string)$master['label']);
    $created=0;$skipped=0;$ineligible=[];
    $find=$db->prepare("SELECT sta.id,sta.no_induk,sta.kelas,sta.master_kelas_id,sta.kelas_rombel_snapshot,sta.spp_covered_by_psb,sta.komite_mulai_bulan,s.potongan_spp_persen,s.is_active
      FROM siswa_tahun_ajaran sta JOIN siswa s ON s.NO_INDUK=sta.no_induk
      WHERE sta.tahun_ajaran_id=? AND sta.no_induk=? AND sta.kelas IN ('1','2','3','4','5','6') LIMIT 1 FOR UPDATE");
    $insert=$db->prepare("INSERT IGNORE INTO tagihan_spp(master_spp_tahun_id,tahun_ajaran_id,penempatan_id,no_induk,tingkat_snapshot,master_kelas_id,kelas_rombel_snapshot,bulan,tahun,tarif_dasar_snapshot,potongan_persen_snapshot,potongan_nominal_snapshot,nominal_tagihan,status)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach(array_keys($selected) as $nis){
        $yearId=(int)$master['tahun_ajaran_id'];$find->bind_param('is',$yearId,$nis);$find->execute();$placement=$find->get_result()->fetch_assoc();
        if(!$placement || (int)$placement['is_active']!==1){$ineligible[]=$nis;continue;}
        $level=(int)$placement['kelas'];$base=(float)$rates[$level];$discount=(float)$placement['potongan_spp_persen'];
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

function spp_deposit_balance(mysqli $db, string $noInduk, bool $forUpdate=false, int $excludeBatch=0): float {
    $sql="SELECT COALESCE(SUM(CASE WHEN jenis IN ('masuk','koreksi_masuk') THEN nominal ELSE -nominal END),0) saldo FROM titipan_spp_mutasi WHERE no_induk=?";
    if($excludeBatch>0)$sql.=' AND (batch_id IS NULL OR batch_id<>?)';
    if($forUpdate)$sql.=' FOR UPDATE';
    $stmt=$db->prepare($sql);
    if($excludeBatch>0)$stmt->bind_param('si',$noInduk,$excludeBatch);else$stmt->bind_param('s',$noInduk);
    $stmt->execute();$balance=(float)($stmt->get_result()->fetch_assoc()['saldo']??0);$stmt->close();
    return $balance;
}

function spp_open_bills(mysqli $db, string $noInduk, bool $forUpdate=false): array {
    $sql="SELECT ts.*,COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar+a.nominal_dari_titipan ELSE 0 END),0) paid
      FROM tagihan_spp ts LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id
      WHERE ts.no_induk=? AND ts.status='open'
      GROUP BY ts.id HAVING ts.nominal_tagihan-paid>.001 ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED),ts.id";
    if($forUpdate)$sql.=' FOR UPDATE';
    $stmt=$db->prepare($sql);$stmt->bind_param('s',$noInduk);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    foreach($rows as &$row){$row['paid']=(float)$row['paid'];$row['remaining']=max(0,(float)$row['nominal_tagihan']-$row['paid']);}unset($row);
    return $rows;
}

function spp_published_period_status(mysqli $db, string $noInduk, string $month, string $year, int $excludePaymentId=0): array {
    $stmt=$db->prepare("SELECT ts.id,ts.bulan,ts.tahun,ts.nominal_tagihan,ts.status,COALESCE(SUM(CASE WHEN ab.status='active' AND (ab.bayar_id IS NULL OR ab.bayar_id<>?) THEN a.nominal_dari_bayar+a.nominal_dari_titipan ELSE 0 END),0) paid FROM tagihan_spp ts LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id WHERE ts.no_induk=? GROUP BY ts.id ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED)");
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
        return array_merge($status,['status'=>'prior_unpaid','code'=>'prior_unpaid','lock_spp'=>true,'title'=>'Ada tunggakan SPP','message'=>'Lunasi dahulu SPP '.$oldLabel.'.','amount_label'=>'Sisa Rp '.number_format((float)$older['nominal_tagihan']-(float)$older['paid'],0,',','.'),'blocking_period'=>['bulan'=>$older['bulan'],'tahun'=>$older['tahun'],'label'=>$oldLabel]]);
    }
    return $status;
}

function spp_payment_payload(mysqli $db, int $excludePaymentId = 0): array {
    $payload=[];
    $excludeLedger=$excludePaymentId>0?' AND (m.bayar_id IS NULL OR m.bayar_id<>'.(int)$excludePaymentId.')':'';
    $result=$db->query("SELECT s.NO_INDUK,COALESCE(SUM(CASE WHEN m.id IS NOT NULL".$excludeLedger." THEN CASE WHEN m.jenis IN ('masuk','koreksi_masuk') THEN m.nominal ELSE -m.nominal END ELSE 0 END),0) saldo FROM siswa s LEFT JOIN titipan_spp_mutasi m ON m.no_induk=s.NO_INDUK GROUP BY s.NO_INDUK");
    while($row=$result->fetch_assoc())$payload[$row['NO_INDUK']]=['saldo'=>(float)$row['saldo'],'tagihan'=>[]];
    $excludeAllocation=$excludePaymentId>0?' AND (ab.bayar_id IS NULL OR ab.bayar_id<>'.(int)$excludePaymentId.')':'';
    $result=$db->query("SELECT ts.*,ta.label tahun_ajaran,COALESCE(SUM(CASE WHEN ab.status='active'".$excludeAllocation." THEN a.nominal_dari_bayar+a.nominal_dari_titipan ELSE 0 END),0) paid
      FROM tagihan_spp ts JOIN tahun_ajaran ta ON ta.id=ts.tahun_ajaran_id LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id
      WHERE ts.status='open' GROUP BY ts.id ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED)");
    while($row=$result->fetch_assoc()){
        $remaining=max(0,(float)$row['nominal_tagihan']-(float)$row['paid']);if($remaining<=.001)continue;
        $payload[$row['no_induk']]['tagihan'][]=['id'=>(int)$row['id'],'bulan'=>$row['bulan'],'tahun'=>$row['tahun'],'tahun_ajaran'=>$row['tahun_ajaran'],'kelas'=>$row['kelas_rombel_snapshot'],'total'=>(float)$row['nominal_tagihan'],'paid'=>(float)$row['paid'],'remaining'=>$remaining];
    }
    return $payload;
}

/** Satu transaksi SPP melunasi tepat satu tagihan terbit yang dipilih kasir. */
function spp_allocate_payment(mysqli $db, string $noInduk, ?int $bayarId, string $month, string $year, float $newMoney, bool $useDeposit, string $date, string $method, string $userId): array {
    if (!in_array($month, ['01','02','03','04','05','06','07','08','09','10','11','12'], true) || !preg_match('/^\d{4}$/', $year)) throw new RuntimeException('Periode SPP tidak valid.');
    if ($newMoney < 0 || !is_finite($newMoney)) throw new RuntimeException('Nominal SPP tidak valid.');
    $balance=spp_deposit_balance($db,$noInduk,true);
    $bills=spp_open_bills($db,$noInduk,true);
    $selected=null;
    foreach ($bills as $bill) if ($bill['bulan']===$month && $bill['tahun']===$year) { $selected=$bill; break; }
    if (!$selected) throw new RuntimeException('Tagihan SPP '.spp_month_label($month).' '.$year.' belum terbit atau sudah lunas.');
    $oldest=$bills[0];
    if ((int)$oldest['id'] !== (int)$selected['id']) throw new RuntimeException('Lunasi dahulu SPP '.spp_month_label((string)$oldest['bulan']).' '.$oldest['tahun'].'.');
    $need=(float)$selected['remaining'];
    $depositUsed=$useDeposit?min($balance,$need):0.0;
    if ($useDeposit && $balance <= .001) throw new RuntimeException('Saldo Titipan SPP tidak tersedia.');
    if (abs($newMoney+$depositUsed-$need)>.001) throw new RuntimeException('SPP '.spp_month_label($month).' '.$year.' harus dilunasi tepat Rp '.number_format($need,0,',','.').($depositUsed>.001?' termasuk Titipan SPP Rp '.number_format($depositUsed,0,',','.'):'').'.');
    $useFlag=$depositUsed>.001?1:0;$required=1;
    $stmt=$db->prepare('INSERT INTO spp_alokasi_batch(no_induk,bayar_id,tanggal,user_id,gunakan_titipan,komite_required,uang_baru,titipan_digunakan,titipan_baru) VALUES(?,?,?,?,?,?,?,?,0)');
    $stmt->bind_param('sissiidd',$noInduk,$bayarId,$date,$userId,$useFlag,$required,$newMoney,$depositUsed);$stmt->execute();$batchId=(int)$db->insert_id;$stmt->close();
    $billId=(int)$selected['id'];
    $stmt=$db->prepare('INSERT INTO spp_alokasi(batch_id,tagihan_spp_id,nominal_dari_bayar,nominal_dari_titipan) VALUES(?,?,?,?)');
    $stmt->bind_param('iidd',$batchId,$billId,$newMoney,$depositUsed);$stmt->execute();$stmt->close();
    if ($depositUsed>.001) {
        $kind='pakai';$note='SPP '.spp_month_label($month).' '.$year;
        $stmt=$db->prepare('INSERT INTO titipan_spp_mutasi(no_induk,batch_id,bayar_id,jenis,nominal,tanggal,user_id,keterangan) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->bind_param('siisdsss',$noInduk,$batchId,$bayarId,$kind,$depositUsed,$date,$userId,$note);$stmt->execute();$stmt->close();
    }
    return ['batch_id'=>$batchId,'cash_received'=>$newMoney,'cash_allocated'=>$newMoney,'deposit_used'=>$depositUsed,'deposit_created'=>0.0,'balance_after'=>$balance-$depositUsed,'periods'=>[spp_month_label($month).' '.$year],'bill_count'=>1];
}

/** Titipan adalah tindakan eksplisit; tidak pernah menjadi sisa otomatis dari input SPP. */
function spp_record_deposit(mysqli $db, string $noInduk, ?int $bayarId, float $amount, string $date, string $method, string $userId): array {
    if ($amount <= .001 || !is_finite($amount)) throw new RuntimeException('Isi nominal Titipan SPP lebih dari Rp0.');
    $balance=spp_deposit_balance($db,$noInduk,true);
    $stmt=$db->prepare('INSERT INTO spp_alokasi_batch(no_induk,bayar_id,tanggal,user_id,uang_baru,titipan_baru) VALUES(?,?,?,?,?,?)');
    $stmt->bind_param('sissdd',$noInduk,$bayarId,$date,$userId,$amount,$amount);$stmt->execute();$batchId=(int)$db->insert_id;$stmt->close();
    $kind='masuk';$note='Catat Titipan SPP';
    $stmt=$db->prepare('INSERT INTO titipan_spp_mutasi(no_induk,batch_id,bayar_id,jenis,nominal,tanggal,sistem_pembayaran,user_id,keterangan) VALUES(?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('siisdssss',$noInduk,$batchId,$bayarId,$kind,$amount,$date,$method,$userId,$note);$stmt->execute();$stmt->close();
    return ['batch_id'=>$batchId,'deposit_created'=>$amount,'balance_after'=>$balance+$amount,'bill_count'=>0];
}

function spp_reverse_payment_allocation(mysqli $db, int $bayarId): void {
    $stmt=$db->prepare("SELECT id,no_induk FROM spp_alokasi_batch WHERE bayar_id=? AND status='active' FOR UPDATE");$stmt->bind_param('i',$bayarId);$stmt->execute();$batch=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$batch)return;
    $remaining=spp_deposit_balance($db,(string)$batch['no_induk'],true,(int)$batch['id']);
    if($remaining<-.001)throw new RuntimeException('Transaksi tidak dapat diubah karena titipannya sudah digunakan pada transaksi setelahnya. Batalkan penggunaan titipan yang lebih baru terlebih dahulu.');
    $stmt=$db->prepare("UPDATE spp_alokasi_batch SET status='reversed' WHERE id=?");$id=(int)$batch['id'];$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
    $stmt=$db->prepare('DELETE FROM titipan_spp_mutasi WHERE batch_id=?');$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
}

function spp_assert_paid_order(mysqli $db, string $noInduk): void {
    $stmt=$db->prepare("SELECT ts.bulan,ts.tahun,ts.nominal_tagihan,COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar+a.nominal_dari_titipan ELSE 0 END),0) paid,MAX(CASE WHEN ab.status='active' AND ab.komite_required=1 THEN 1 ELSE 0 END) protected_payment FROM tagihan_spp ts LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id WHERE ts.no_induk=? AND ts.status='open' GROUP BY ts.id ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED)");
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
    $stmt=$db->prepare("SELECT ab.*,COALESCE((SELECT SUM(CASE WHEN m.jenis IN ('masuk','koreksi_masuk') THEN m.nominal ELSE -m.nominal END) FROM titipan_spp_mutasi m WHERE m.no_induk=ab.no_induk AND (m.tanggal<ab.tanggal OR (m.tanggal=ab.tanggal AND m.batch_id<=ab.id))),0) balance_after FROM spp_alokasi_batch ab WHERE ab.bayar_id=? AND ab.status='active' LIMIT 1");
    $stmt->bind_param('i',$bayarId);$stmt->execute();$batch=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$batch)return null;
    $stmt=$db->prepare("SELECT ts.bulan,ts.tahun,ts.kelas_rombel_snapshot,a.nominal_dari_bayar,a.nominal_dari_titipan FROM spp_alokasi a JOIN tagihan_spp ts ON ts.id=a.tagihan_spp_id WHERE a.batch_id=? ORDER BY CAST(ts.tahun AS UNSIGNED),CAST(ts.bulan AS UNSIGNED)");$id=(int)$batch['id'];$stmt->bind_param('i',$id);$stmt->execute();$batch['allocations']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $batch;
}
