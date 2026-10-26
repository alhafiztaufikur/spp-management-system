<?php
require_once __DIR__.'/date_format.php';

/** A chosen cash date, with the server clock used by the existing entry flow. */
function transaction_entry_timestamp($day, ?string $requestTime = null): string {
    if (!is_string($day) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $day)
        || !($date = spp_date_value($day)) || $date->format('Y-m-d') !== $day) {
        throw new DomainException('Tanggal transaksi tidak valid. Pilih tanggal yang benar.');
    }
    $clock = date('H:i:s');
    if ($requestTime !== null) {
        if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $requestTime)
            || !($requestDate = spp_date_value($requestTime))) {
            throw new DomainException('Waktu formulir tidak valid. Muat ulang formulir.');
        }
        $clock = $requestDate->format('H:i:s');
    }
    return $day.' '.$clock;
}

/** Exempt only this validated cash timestamp during the native INSERT itself. */
function transaction_entry_insert(mysqli $db, string $timestamp, callable $insert): int {
    $statement = $db->prepare('SET @transaction_entry_timestamp=?');
    $statement->bind_param('s', $timestamp);
    $statement->execute();
    $statement->close();
    try { $insert(); return (int)$db->insert_id; }
    finally { $db->query('SET @transaction_entry_timestamp=NULL'); }
}

/** CLI-only, idempotent adjustment of the three cash-date guards; no data changes. */
function transaction_entry_date_schema_upgrade(mysqli $db): int {
    if (PHP_SAPI !== 'cli') throw new LogicException('Gunakan CLI untuk memperbarui pengaman tanggal.');
    $changed = 0;
    foreach (['bayar_data'=>'TGL_BYR', 'transaksi_m_data'=>'TANGGAL', 'transaksi_k_data'=>'TANGGAL'] as $table=>$column) {
        $name = 'lful_cutoff_'.substr(hash('sha256', $table), 0, 18);
        $row = $db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->fetch_assoc();
        if (!$row || str_contains($row['ACTION_STATEMENT'], '@transaction_entry_timestamp')) continue;
        $old = 'COALESCE(@legacy_full_apply,0)<>1 AND EXISTS';
        if (substr_count($row['ACTION_STATEMENT'], $old) !== 1) throw new RuntimeException('Pengaman tanggal tidak dikenali: '.$name);
        $body = str_replace($old, "COALESCE(@legacy_full_apply,0)<>1 AND NOT (NEW.`$column` <=> @transaction_entry_timestamp) AND EXISTS", $row['ACTION_STATEMENT']);
        $db->query("DROP TRIGGER `$name`");
        try { $db->query("CREATE TRIGGER `$name` BEFORE INSERT ON `$table` FOR EACH ROW $body"); }
        catch (Throwable $error) {
            $db->query("CREATE TRIGGER `$name` BEFORE INSERT ON `$table` FOR EACH ROW ".$row['ACTION_STATEMENT']);
            throw $error;
        }
        $changed++;
    }
    return $changed;
}
