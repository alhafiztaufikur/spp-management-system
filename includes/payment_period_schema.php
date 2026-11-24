<?php

function payment_period_schema_plan(mysqli $db): array {
    $steps = [];
    $key = $db->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='bayar_komite_data' AND INDEX_NAME='PRIMARY'")->fetch_row()[0];
    if ($key === 'bayar_id') {
        $index = $db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME='bayar_komite_data' AND INDEX_NAME<>'PRIMARY'
            AND SEQ_IN_INDEX=1 AND COLUMN_NAME='bayar_id' LIMIT 1")->num_rows;
        if (!$index) $steps[] = 'ALTER TABLE bayar_komite_data ADD KEY idx_bayar_komite_bayar (bayar_id)';
        $steps[] = 'ALTER TABLE bayar_komite_data DROP PRIMARY KEY, ADD PRIMARY KEY (bayar_id, tagihan_komite_id)';
    } elseif ($key !== 'bayar_id,tagihan_komite_id') {
        throw new RuntimeException('Kunci alokasi Komite tidak sesuai skema multiunit.');
    }
    $objects = $db->query("SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('pembayaran_rentang','pembayaran_rentang_data')")->fetch_all(MYSQLI_ASSOC);
    $types = array_column($objects, 'TABLE_TYPE', 'TABLE_NAME');
    if (!isset($types['pembayaran_rentang_data'])) {
        $steps[] = "CREATE TABLE pembayaran_rentang_data (
            bayar_id INT NOT NULL PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
            mode ENUM('range','academic') NOT NULL, mulai CHAR(7) NOT NULL, akhir CHAR(7) NOT NULL,
            KEY (unit_id,bayar_id),
            FOREIGN KEY (unit_id,bayar_id) REFERENCES bayar_data(unit_id,id) ON DELETE CASCADE,
            CHECK (mulai<=akhir)
        ) ENGINE=InnoDB";
    } elseif ($types['pembayaran_rentang_data'] !== 'BASE TABLE') {
        throw new RuntimeException('Tabel rincian rentang tidak valid.');
    }
    if (!isset($types['pembayaran_rentang'])) {
        $steps[] = 'CREATE VIEW pembayaran_rentang AS SELECT * FROM pembayaran_rentang_data
            WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION';
    } elseif ($types['pembayaran_rentang'] !== 'VIEW') {
        throw new RuntimeException('View rincian rentang tidak valid.');
    }
    return $steps;
}

function payment_period_schema_ready(mysqli $db): bool {
    static $ready = [];
    $key = spl_object_id($db).':'.$db->thread_id;
    if (PHP_SAPI !== 'cli' && array_key_exists($key, $ready)) return $ready[$key];
    try { return $ready[$key] = !payment_period_schema_plan($db); }
    catch (RuntimeException $e) { return $ready[$key] = false; }
}

function payment_period_schema_apply(mysqli $db): int {
    if (PHP_SAPI !== 'cli') throw new LogicException('Pemasangan skema hanya melalui CLI.');
    $steps = payment_period_schema_plan($db);
    foreach ($steps as $sql) $db->query($sql);
    if (payment_period_schema_plan($db)) throw new RuntimeException('Pemasangan skema pembayaran rentang belum selesai.');
    return count($steps);
}
