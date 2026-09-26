<?php

function transaction_authorization_schema_ready(mysqli $db): bool
{
    $result = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transaksi_otorisasi' LIMIT 1");
    return $result && $result->num_rows > 0;
}

function transaction_authorization_assert_ready(mysqli $db): void
{
    if (!transaction_authorization_schema_ready($db)) {
        throw new RuntimeException('Schema otorisasi transaksi belum tersedia. Jalankan sql/add_transaction_authorization.sql.');
    }
}

function transaction_authorization_table_exists(mysqli $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $cache[$table] = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $cache[$table];
}

function transaction_authorization_column_exists(mysqli $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $cache[$key] = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $cache[$key];
}

function transaction_authorization_snapshot(mysqli $db, int $paymentId): array
{
    $stmt = $db->prepare('SELECT b.*,s.NAMA,s.NO_induk_diknas FROM bayar b LEFT JOIN siswa s ON s.NO_INDUK=b.NO_INDUK WHERE b.id=? LIMIT 1');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment) throw new RuntimeException('Transaksi pembayaran tidak ditemukan.');

    $details = [];
    $relations = [
        'bayar_du' => ['bayar_id', 'id'],
        'bayar_biaya_lain' => ['bayar_id', 'id'],
        'bayar_tahunan_siswa' => ['bayar_id', 'id'],
        'bayar_komite' => ['bayar_id', 'id'],
        'bayar_spp_periode' => ['bayar_id', 'bayar_id'],
        'spp_alokasi_batch' => ['bayar_id', 'id'],
        'titipan_spp_mutasi' => ['bayar_id', 'id'],
        'transaksi_m' => ['bayar_id', 'id'],
    ];
    foreach ($relations as $table => [$column, $order]) {
        if (!transaction_authorization_table_exists($db, $table)
            || !transaction_authorization_column_exists($db, $table, $column)
            || !transaction_authorization_column_exists($db, $table, $order)) continue;
        $query = "SELECT * FROM `$table` WHERE `$column`=? ORDER BY `$order`";
        $detailStmt = $db->prepare($query);
        $detailStmt->bind_param('i', $paymentId);
        $detailStmt->execute();
        $details[$table] = $detailStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $detailStmt->close();
    }
    if (transaction_authorization_table_exists($db, 'spp_alokasi') && transaction_authorization_table_exists($db, 'spp_alokasi_batch')) {
        $allocationStmt = $db->prepare('SELECT a.* FROM spp_alokasi a JOIN spp_alokasi_batch ab ON ab.id=a.batch_id WHERE ab.bayar_id=? ORDER BY a.id');
        $allocationStmt->bind_param('i', $paymentId);
        $allocationStmt->execute();
        $details['spp_alokasi'] = $allocationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $allocationStmt->close();
    }

    $snapshot = ['payment' => $payment, 'details' => $details];
    $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($encoded === false) throw new RuntimeException('Snapshot transaksi tidak dapat dibuat.');
    return ['data' => $snapshot, 'json' => $encoded, 'hash' => hash('sha256', $encoded)];
}

function transaction_authorization_payload(array $source, string $action): array
{
    if (!in_array($action, ['edit', 'hapus'], true)) throw new RuntimeException('Jenis permintaan transaksi tidak dikenal.');
    if ($action === 'hapus') return ['aksi' => 'hapus', 'id' => (int)($source['id'] ?? 0)];

    $allowed = [
        'id','no_induk','tanggal_bayar','bulan_bayar','tahun_bayar','sistem_pembayaran',
        'uang_pangkal','uang_psb','uang_spp','uang_komite','uang_du','potongan_spp',
        'tabungan_wajib','total_jumlah','catatan','kelas_du','tahun_ajaran_du',
        'tagihan_daftar_ulang_id','gunakan_titipan_spp','spp_action',
    ];
    $payload = ['aksi' => 'update'];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $source)) $payload[$key] = is_array($source[$key]) ? $source[$key] : (string)$source[$key];
    }
    foreach (['biaya_lain_detail_id','biaya_lain_tagihan_id','biaya_lain_nominal','biaya_lain_keterangan'] as $key) {
        if (isset($source[$key]) && is_array($source[$key])) $payload[$key] = array_values($source[$key]);
    }
    $payload['id'] = (int)($source['id'] ?? 0);
    return $payload;
}

function transaction_authorization_create(mysqli $db, int $paymentId, string $action, array $payload, string $reason, int $requesterId): int
{
    transaction_authorization_assert_ready($db);
    $reason = trim($reason);
    if ($requesterId <= 0) throw new RuntimeException('Identitas pemohon tidak valid.');
    if (mb_strlen($reason) < 5) throw new RuntimeException('Alasan pengajuan wajib diisi minimal 5 karakter.');
    if (mb_strlen($reason) > 500) throw new RuntimeException('Alasan pengajuan maksimal 500 karakter.');

    $db->begin_transaction();
    try {
        $lock = $db->prepare('SELECT id FROM bayar WHERE id=? FOR UPDATE');
        $lock->bind_param('i', $paymentId);
        $lock->execute();
        if ($lock->get_result()->num_rows === 0) throw new RuntimeException('Transaksi pembayaran tidak ditemukan.');
        $lock->close();

        $pending = $db->prepare("SELECT id FROM transaksi_otorisasi WHERE bayar_id=? AND status='pending' LIMIT 1 FOR UPDATE");
        $pending->bind_param('i', $paymentId);
        $pending->execute();
        if ($pending->get_result()->num_rows > 0) throw new RuntimeException('Transaksi ini sudah memiliki permintaan yang menunggu otorisasi.');
        $pending->close();

        $snapshot = transaction_authorization_snapshot($db, $paymentId);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) throw new RuntimeException('Usulan perubahan tidak dapat disimpan.');
        $payment = $snapshot['data']['payment'];
        $reference = 'TRX-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);
        $noInduk = (string)($payment['NO_INDUK'] ?? '');
        $studentName = (string)($payment['NAMA'] ?? '');
        $stmt = $db->prepare("INSERT INTO transaksi_otorisasi (bayar_id,transaction_reference,no_induk_snapshot,student_name_snapshot,action,status,before_snapshot,snapshot_hash,proposed_payload,request_reason,requested_by) VALUES (?,?,?,?,?,'pending',?,?,?,?,?)");
        $stmt->bind_param('issssssssi', $paymentId, $reference, $noInduk, $studentName, $action, $snapshot['json'], $snapshot['hash'], $payloadJson, $reason, $requesterId);
        $stmt->execute();
        $requestId = (int)$db->insert_id;
        $stmt->close();
        $db->commit();
        return $requestId;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function transaction_authorization_find(mysqli $db, int $requestId, bool $forUpdate = false): ?array
{
    transaction_authorization_assert_ready($db);
    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $db->prepare("SELECT r.*,req.nama requested_by_name,req.role requested_by_role,reviewer.nama decided_by_name FROM transaksi_otorisasi r JOIN admin req ON req.id=r.requested_by LEFT JOIN admin reviewer ON reviewer.id=r.decided_by WHERE r.id=?$suffix");
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function transaction_authorization_pending_for_payments(mysqli $db, array $paymentIds): array
{
    if (!$paymentIds || !transaction_authorization_schema_ready($db)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds))));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $db->prepare("SELECT id,bayar_id,action,requested_by,requested_at FROM transaksi_otorisasi WHERE status='pending' AND bayar_id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $result[(int)$row['bayar_id']] = $row;
    $stmt->close();
    return $result;
}

function transaction_authorization_mark_failed(mysqli $db, int $requestId, int $approverId, string $note): void
{
    if ($requestId <= 0 || !transaction_authorization_schema_ready($db)) return;
    $note = mb_substr(trim($note), 0, 1000);
    $stmt = $db->prepare("UPDATE transaksi_otorisasi SET status='failed',decided_by=?,decision_note=?,decided_at=NOW() WHERE id=? AND status='pending'");
    $stmt->bind_param('isi', $approverId, $note, $requestId);
    $stmt->execute();
    $stmt->close();
}

function transaction_authorization_decode_payload(array $request): array
{
    $payload = json_decode((string)($request['proposed_payload'] ?? ''), true);
    if (!is_array($payload)) throw new RuntimeException('Data usulan transaksi tidak dapat dibaca.');
    return $payload;
}

function transaction_authorization_decide(mysqli $db, int $requestId, string $status, int $deciderId, string $note = ''): void
{
    if (!in_array($status, ['approved', 'rejected', 'cancelled'], true)) {
        throw new RuntimeException('Status keputusan otorisasi tidak valid.');
    }
    $note = trim($note);
    if ($status === 'rejected' && $note === '') throw new RuntimeException('Catatan penolakan wajib diisi.');
    if (mb_strlen($note) > 1000) throw new RuntimeException('Catatan keputusan maksimal 1000 karakter.');
    $appliedSql = $status === 'approved' ? ',applied_at=NOW()' : '';
    $stmt = $db->prepare("UPDATE transaksi_otorisasi SET status=?,decided_by=?,decision_note=?,decided_at=NOW()$appliedSql WHERE id=? AND status='pending'");
    $stmt->bind_param('sisi', $status, $deciderId, $note, $requestId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Permintaan sudah diproses atau tidak lagi tersedia.');
    }
    $stmt->close();
}

function transaction_authorization_status_label(string $status): string
{
    return [
        'pending'=>'Menunggu Otorisasi','approved'=>'Disetujui','rejected'=>'Ditolak',
        'cancelled'=>'Dibatalkan','failed'=>'Gagal/Kedaluwarsa',
    ][$status] ?? ucfirst($status);
}

function transaction_authorization_action_label(string $action): string
{
    return $action === 'hapus' ? 'Penghapusan' : 'Perubahan';
}

function transaction_authorization_money($value): string
{
    $raw = trim((string)$value);
    $number = is_numeric($raw) ? (float)$raw : (float)str_replace(['.', ','], ['', '.'], $raw);
    return 'Rp ' . number_format($number, 0, ',', '.');
}
