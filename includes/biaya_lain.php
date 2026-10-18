<?php
require_once __DIR__.'/other_fee_optional.php';

require_once __DIR__ . '/kelas.php';

function other_fee_master_version(array $master): string {
    return hash('sha256',json_encode(array_map(static fn($key)=>(string)($master[$key]??''),['id','unit_id','nama','nominal','jenis_pembayaran','is_active','updated_at']),JSON_THROW_ON_ERROR));
}
/** Caller owns transaction; lock master before students, matching deletion and payment rules. */
function other_fee_publish(mysqli $db,int $masterId,string $target,array $ids,string $expected,?array $expectedStudents=null): array {
    if(!in_array(unit_active_id(),[1,2,3],true)||!operational_role())throw new RuntimeException('Pilih satu unit operasional.');
    if(!in_array($target,['all','rombel','siswa'],true))throw new RuntimeException('Sasaran penerbitan tidak valid.');
    foreach($ids as $id)if(!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/D',(string)$id))throw new RuntimeException('Pilihan sasaran tidak valid.');
    $ids=array_values(array_unique(array_map('intval',$ids)));if($target!=='all'&&!$ids)throw new RuntimeException('Pilih sasaran penerbitan.');
    $s=$db->prepare('SELECT * FROM master_biaya_lain WHERE id=? FOR UPDATE');$s->bind_param('i',$masterId);$s->execute();$master=$s->get_result()->fetch_assoc();$s->close();
    if(!$master||!(int)$master['is_active']||$master['jenis_pembayaran']!=='tidak_berulang')throw new RuntimeException('Hanya master aktif Tidak Berulang yang dapat diterbitkan.');
    if(!hash_equals(other_fee_master_version($master),$expected))throw new RuntimeException('Master berubah. Muat ulang sebelum menerbitkan.');
    $where=$target==='all'?'':(' AND '.($target==='rombel'?'s.master_kelas_id':'s.id').' IN ('.implode(',',$ids).')');
    $students=$db->query("SELECT s.id,s.NO_INDUK,s.master_kelas_id,s.KELAS,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.is_active=1 AND s.legacy_pending=0 AND s.KELAS NOT IN ('CUSTOM','OUT')$where ORDER BY s.id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    if(!$students)throw new RuntimeException('Tidak ada siswa aktif yang sesuai sasaran.');
    if($expectedStudents!==null){foreach($expectedStudents as $id)if(!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/D',(string)$id))throw new RuntimeException('Pratinjau sasaran tidak valid.');$expectedIds=array_values(array_unique(array_map('intval',$expectedStudents)));sort($expectedIds);$actualIds=array_map('intval',array_column($students,'id'));sort($actualIds);if($actualIds!==$expectedIds)throw new RuntimeException('Sasaran siswa berubah sejak pratinjau. Muat ulang dan tinjau kembali.');}
    if($target==='siswa'&&count($students)!==count($ids))throw new RuntimeException('Siswa sasaran berubah atau berada di unit lain.');
    if($target==='rombel')foreach($ids as $classId){$class=class_find($db,$classId);if(!$class||!(int)$class['is_active'])throw new RuntimeException('Rombel sasaran tidak aktif atau berbeda unit.');}
    $created=0;$skipped=0;$actor=(int)$_SESSION['admin_id'];
    foreach($students as $student){
        $s=$db->prepare('SELECT id FROM tagihan_biaya_lain WHERE master_biaya_lain_id=? AND no_induk=? FOR UPDATE');$s->bind_param('is',$masterId,$student['NO_INDUK']);$s->execute();$exists=$s->get_result()->num_rows;$s->close();if($exists){$skipped++;continue;}
        $label=class_label($student);$s=$db->prepare("INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,master_kelas_id,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot,created_by) VALUES(?,?,?,?,?,?,?)");
        $s->bind_param('isisdsi',$masterId,$student['NO_INDUK'],$student['master_kelas_id'],$master['nama'],$master['nominal'],$label,$actor);$s->execute();$s->close();$created++;
    }
    other_fee_write_audit($db,$masterId,$target,$ids?implode(',',$ids):null,$created,$created*(float)$master['nominal']);
    return ['created'=>$created,'skipped'=>$skipped,'total'=>$created*(float)$master['nominal']];
}

function other_fee_bill_find(mysqli $db, int $billId, string $noInduk, bool $forUpdate = false): ?array {
    if ($forUpdate) {
        $stmt = $db->prepare('SELECT * FROM tagihan_biaya_lain WHERE id=? AND no_induk=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('is', $billId, $noInduk);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$bill) return null;
        $bill['nominal_tagihan'] = (float)$bill['nominal_tagihan'];
        $bill['terbayar'] = other_fee_paid_for_bill($db, $billId, 0, true);
        $bill['sisa'] = max(0, $bill['nominal_tagihan'] - $bill['terbayar']);
        return $bill;
    }
    $stmt = $db->prepare("SELECT t.*, COALESCE(SUM(d.nominal_snapshot), 0) AS terbayar
        FROM tagihan_biaya_lain t
        LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id = t.id
        WHERE t.id = ? AND t.no_induk = ?
        GROUP BY t.id
        LIMIT 1");
    $stmt->bind_param('is', $billId, $noInduk);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($bill) {
        $bill['nominal_tagihan'] = (float)$bill['nominal_tagihan'];
        $bill['terbayar'] = (float)$bill['terbayar'];
        $bill['sisa'] = max(0, $bill['nominal_tagihan'] - $bill['terbayar']);
    }
    return $bill;
}

/** Detail ditarik dengan current read agar dua kasir tidak melihat snapshot pembayaran lama. */
function other_fee_paid_for_bill(mysqli $db, int $billId, int $excludePaymentId = 0, bool $forUpdate = false): float {
    $sql = 'SELECT bayar_id, nominal_snapshot FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=?';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $billId);
    $stmt->execute();
    $paid = 0.0;
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $line) {
        if ((int)$line['bayar_id'] !== $excludePaymentId) $paid += (float)$line['nominal_snapshot'];
    }
    $stmt->close();
    return $paid;
}

function other_fee_open_bills(mysqli $db, string $noInduk): array {
    $stmt = $db->prepare("SELECT t.id, t.master_biaya_lain_id, t.nama_snapshot AS nama,
               t.nominal_tagihan AS nominal, t.status,
               COALESCE(SUM(d.nominal_snapshot), 0) AS terbayar
        FROM tagihan_biaya_lain t
        LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id = t.id
        WHERE t.no_induk = ? AND t.status = 'open'
        GROUP BY t.id
        ORDER BY t.nama_snapshot");
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['nominal'] = (float)$row['nominal'];
        $row['terbayar'] = (float)$row['terbayar'];
        $row['sisa'] = max(0, $row['nominal'] - $row['terbayar']);
    }
    unset($row);
    return $rows;
}

function other_fee_write_audit(
    mysqli $db,
    int $masterId,
    string $target,
    ?string $targetValue,
    int $count,
    float $total
): void {
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $adminName = (string)($_SESSION['admin_nama'] ?? $_SESSION['admin_username'] ?? 'system');
    $action = 'terbitkan_tagihan';
    $stmt = $db->prepare('INSERT INTO tagihan_biaya_lain_audit_log
        (master_biaya_lain_id, aksi, target, target_value, affected_count, total_nominal, admin_id, admin_name)
        VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?)');
    $stmt->bind_param('isssidis', $masterId, $action, $target, $targetValue, $count, $total, $adminId, $adminName);
    $stmt->execute();
    $stmt->close();
}
