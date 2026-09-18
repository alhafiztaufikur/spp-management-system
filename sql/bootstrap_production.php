<?php

/**
 * Buat schema kosong dari schema.sql tanpa akun, siswa, atau transaksi demo.
 * Jalankan satu kali pada database BARU yang masih kosong.
 */
if (PHP_SAPI !== 'cli' || !in_array('--execute', $argv, true)) {
    fwrite(STDERR, "Jalankan lewat CLI dengan --execute pada database kosong.\n");
    exit(1);
}

require_once __DIR__ . '/../koneksi.php';

$target = (string)getenv('SPP_BOOTSTRAP_TARGET');
$username = trim((string)getenv('SPP_BOOTSTRAP_ADMIN_USER'));
$password = (string)getenv('SPP_BOOTSTRAP_ADMIN_PASSWORD');
if ($target === '' || $target !== DB_NAME) {
    fwrite(STDERR, "SPP_BOOTSTRAP_TARGET harus sama persis dengan SPP_DB_NAME.\n");
    exit(1);
}
if ($username === '' || strlen($username) > 50 || strlen($password) < 12) {
    fwrite(STDERR, "Isi username admin dan password bootstrap minimal 12 karakter.\n");
    exit(1);
}
if ($koneksi->query('SHOW TABLES')->num_rows !== 0) {
    fwrite(STDERR, "Database target tidak kosong; bootstrap dibatalkan tanpa perubahan.\n");
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/schema.sql');
if ($schema === false) throw new RuntimeException('schema.sql tidak dapat dibaca.');
$schema = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $schema);
$statements = preg_split('/;\s*(?:\r?\n|$)/', $schema);
$ddl = [];
$masterClasses = null;
foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement === '') continue;
    if (preg_match('/^CREATE TABLE\b/i', $statement)) {
        $ddl[] = $statement;
    } elseif (preg_match('/^INSERT INTO\s+`?master_kelas`?(?:\s|\()/i', $statement)) {
        $masterClasses = $statement;
    } elseif (preg_match('/^(CREATE DATABASE|USE\b|SET FOREIGN_KEY_CHECKS\b|DROP TABLE\b)/i', $statement)) {
        continue;
    } elseif (preg_match('/^(?:INSERT INTO|UPDATE)\s+`?(?:admin|siswa|tahun_ajaran|Daftar_ulang|siswa_tahun_ajaran|tagihan_daftar_ulang|tagihan_komite)`?(?:\s|\()/i', $statement)) {
        continue;
    } else {
        throw new RuntimeException('Pernyataan schema tidak dikenal; bootstrap dibatalkan: ' . substr($statement, 0, 80));
    }
}
if (count($ddl) < 30 || $masterClasses === null) {
    throw new RuntimeException('Struktur schema.sql tidak sesuai harapan; bootstrap dibatalkan.');
}

$koneksi->query('SET FOREIGN_KEY_CHECKS = 0');
try {
    foreach ($ddl as $statement) $koneksi->query($statement);
    $koneksi->query($masterClasses);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $name = 'Administrator';
    $role = 'admin';
    $stmt = $koneksi->prepare('INSERT INTO admin(username,password,nama,role) VALUES(?,?,?,?)');
    $stmt->bind_param('ssss', $username, $hash, $name, $role);
    $stmt->execute();
    $stmt->close();
} finally {
    $koneksi->query('SET FOREIGN_KEY_CHECKS = 1');
}

$tables = $koneksi->query('SHOW TABLES')->num_rows;
$classes = (int)$koneksi->query('SELECT COUNT(*) total FROM master_kelas')->fetch_assoc()['total'];
$students = (int)$koneksi->query('SELECT COUNT(*) total FROM siswa')->fetch_assoc()['total'];
$users = (int)$koneksi->query('SELECT COUNT(*) total FROM admin')->fetch_assoc()['total'];
echo "Bootstrap selesai: {$tables} tabel, {$classes} kelas/rombel, {$users} admin, {$students} siswa demo.\n";
