<?php

function payment_activity_ready(mysqli $db): bool
{
    return (bool)$db->query("SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='pembayaran_aktivitas'")->fetch_row();
}

function payment_activity_labels(): array
{
    return ['created'=>'Pembayaran dibuat','baseline'=>'Data lama tercatat','edited'=>'Pembayaran diubah',
        'deleted'=>'Pembayaran dihapus','request_edit'=>'Pengajuan edit','request_delete'=>'Pengajuan hapus',
        'approved'=>'Pengajuan disetujui','rejected'=>'Pengajuan ditolak','cancelled'=>'Pengajuan dibatalkan',
        'failed'=>'Otorisasi gagal'];
}

function payment_activity_actor(mysqli $db, $identity): array
{
    $identity = trim((string)$identity);
    if ($identity === '') return ['id'=>null,'nama'=>'','username'=>'','role'=>''];
    // IDs and usernames are stable; names are accepted only when unambiguous.
    $s = $db->prepare('SELECT id,nama,username,role FROM admin WHERE CAST(id AS CHAR)=?');
    $s->bind_param('s', $identity); $s->execute(); $row = $s->get_result()->fetch_assoc(); $s->close();
    if ($row) return $row;
    $s = $db->prepare('SELECT id,nama,username,role FROM admin WHERE username=?');
    $s->bind_param('s', $identity); $s->execute(); $row = $s->get_result()->fetch_assoc(); $s->close();
    if ($row) return $row;
    $s = $db->prepare('SELECT id,nama,username,role FROM admin WHERE nama=? LIMIT 2');
    $s->bind_param('s', $identity); $s->execute(); $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
    return count($rows) === 1 ? $rows[0] : ['id'=>null,'nama'=>'','username'=>'','role'=>''];
}

function payment_activity_encode(?array $snapshot): ?string
{
    return $snapshot === null ? null : json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function payment_activity_begin_transaction(mysqli $db): void
{
    require_once __DIR__.'/legacy_operational_schema.php';
    // A receipt may wait for a Legacy correction: read its committed result after the lock.
    if (lop_ready($db)) $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->begin_transaction();
}

/** Call inside the financial transaction. Duplicate keys are read, never overwritten. */
function payment_activity_record(mysqli $db, int $paymentId, string $action, $actorIdentity,
    ?array $before, ?array $after, string $key, ?int $authorizationId = null, string $note = '',
    ?string $time = null, bool $reconstructed = false): void
{
    if (!payment_activity_ready($db)) throw new RuntimeException('Jurnal operator belum tersedia. Jalankan migrasi aktivitas pembayaran.');
    if (!isset(payment_activity_labels()[$action])) throw new InvalidArgumentException('Aktivitas tidak valid.');
    $payment = $after['payment'] ?? $before['payment'] ?? [];
    $unit = (int)($payment['unit_id'] ?? unit_active_id());
    $context = (int)$db->query('SELECT current_unit_id()')->fetch_row()[0];
    if ($paymentId <= 0 || $unit < 1 || $unit > 3 || $unit !== $context) throw new RuntimeException('Unit aktivitas tidak sesuai.');
    $s = $db->prepare('SELECT payment_id,action FROM pembayaran_aktivitas WHERE unit_id=? AND event_key=?');
    $s->bind_param('is', $unit, $key); $s->execute(); $existing = $s->get_result()->fetch_assoc(); $s->close();
    if ($existing) {
        if ((int)$existing['payment_id'] !== $paymentId || $existing['action'] !== $action) throw new RuntimeException('Kunci aktivitas sudah dipakai transaksi lain.');
        return;
    }
    $actor = payment_activity_actor($db, $actorIdentity);
    $actorId = $actor['id']; $actorName = $actor['nama']; $username = $actor['username']; $role = $actor['role'];
    $occurred = $time ?: date('Y-m-d H:i:s');
    $beforeJson = payment_activity_encode($before); $afterJson = payment_activity_encode($after); $legacy = (int)$reconstructed;
    $s = $db->prepare('INSERT INTO pembayaran_aktivitas
        (unit_id,payment_id,event_key,action,actor_id,actor_name,actor_username,actor_role,occurred_at,authorization_id,note,before_snapshot,after_snapshot,reconstructed)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $s->bind_param('iississssisssi', $unit,$paymentId,$key,$action,$actorId,$actorName,$username,$role,$occurred,$authorizationId,$note,$beforeJson,$afterJson,$legacy);
    $s->execute(); $s->close();
    if (!$reconstructed && in_array($action, ['created', 'edited', 'deleted'], true)) {
        require_once __DIR__.'/legacy_operational_native.php';
        lop_native_payment_changed($db, $before, $after);
    }
}

function payment_activity_for_payments(mysqli $db, array $ids): array
{
    if (!$ids || !payment_activity_ready($db)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval',$ids))));
    if (!$ids) return [];
    $s = $db->prepare('SELECT * FROM pembayaran_aktivitas WHERE payment_id IN (' . implode(',',array_fill(0,count($ids),'?')) . ') ORDER BY occurred_at,id');
    $types = str_repeat('i',count($ids)); $s->bind_param($types,...$ids); $s->execute();
    $grouped = [];
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $event) $grouped[(int)$event['payment_id']][] = $event;
    $s->close(); return $grouped;
}

function payment_activity_summary(array $events): array
{
    $creator = null;
    foreach ($events as $event) if ($event['action'] === 'created') { $creator = $event; break; }
    $real = array_values(array_filter($events, static fn($e)=>$e['action'] !== 'baseline'));
    return ['creator'=>$creator, 'last'=>$real ? $real[count($real)-1] : ($events ? $events[count($events)-1] : null)];
}

function payment_activity_changes(?array $before, ?array $after): array
{
    if (!$before || !$after) return [];
    $labels = ['NO_INDUK'=>'NIS','KELAS'=>'Kelas','kelas_rombel_snapshot'=>'Kelas/Rombel','TGL_BYR'=>'Tanggal bayar',
        'BULAN'=>'Bulan tagihan','TAHUN'=>'Tahun tagihan','sistem_pembayaran'=>'Metode pembayaran',
        'U_PSB'=>'PSB','U_SPP'=>'SPP','U_KOMITE'=>'Komite','U_LAIN'=>'Biaya Lain','potong_spp'=>'Potongan SPP',
        'total_jumlah'=>'Total bayar','KETERANGAN'=>'Keterangan'];
    $money = ['U_PSB','U_SPP','U_KOMITE','U_LAIN','potong_spp','total_jumlah']; $changes = [];
    foreach ($labels as $key=>$label) {
        $a = $before['payment'][$key] ?? ''; $b = $after['payment'][$key] ?? '';
        $equal = in_array($key,$money,true) ? abs((float)$a-(float)$b)<.001 : (string)$a===(string)$b;
        if ($equal) continue;
        if (in_array($key,$money,true)) { $a='Rp '.number_format((float)$a,abs((float)$a-round((float)$a))>.00001?2:0,',','.'); $b='Rp '.number_format((float)$b,abs((float)$b-round((float)$b))>.00001?2:0,',','.'); }
        $changes[]=['label'=>$label,'before'=>(string)$a,'after'=>(string)$b];
    }
    foreach (['bayar_du'=>'Rincian Daftar Ulang','bayar_biaya_lain'=>'Rincian Biaya Lain','legacy_items'=>'Rincian Pembayaran'] as $table=>$label) {
        $normal = static function(array $rows): string {
            $result=[];
            foreach ($rows as $row) {
                $r=[]; foreach (['tagihan_daftar_ulang_id','tagihan_biaya_lain_id','nama_biaya_snapshot','jumlah','nominal','keterangan','th_ajaran'] as $key)
                    if (isset($row[$key])) $r[$key]=$row[$key];
                $result[]=$r;
            }
            usort($result,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
            return json_encode($result,JSON_UNESCAPED_UNICODE);
        };
        $a=$normal($before['details'][$table]??[]); $b=$normal($after['details'][$table]??[]);
        $display = static function(string $json): string {
            $items=[];
            foreach(json_decode($json,true) as $row) {
                $name=$row['label']??$row['nama_biaya_snapshot']??($row['th_ajaran']??'Tagihan #'.($row['tagihan_biaya_lain_id']??$row['tagihan_daftar_ulang_id']??'—'));
                $n=(float)($row['amount']??$row['jumlah']??$row['nominal']??0);$value='Rp '.number_format($n,abs($n-round($n))>.00001?2:0,',','.');
                $items[]=$name.': '.$value.(!empty($row['keterangan'])?' ('.$row['keterangan'].')':'');
            }
            return $items?implode('; ',$items):'Tidak ada rincian';
        };
        if ($a!==$b) $changes[]=['label'=>$label,'before'=>$display($a),'after'=>$display($b)];
    }
    return $changes;
}
