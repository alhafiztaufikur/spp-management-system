<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Help and invalid options never need a database connection.
$apply = false;
$check = false;
$maintenance = false;
$expectedDatabase = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo <<<'HELP'
Skema Migrasi Operasional Legacy

Pemeriksaan saja (tanpa mengubah database):
  php scripts/migrate_legacy_operational.php --check --expect-db=db_spp

Pemasangan setelah backup terverifikasi dan pemeliharaan:
  php scripts/migrate_legacy_operational.php --apply --confirm-maintenance --expect-db=db_spp

Target mengikuti konfigurasi koneksi aplikasi / variabel SPP_DB_*.
--expect-db memeriksa nama target; opsi ini tidak memindahkan koneksi.
Tanpa --apply, hanya kesiapan skema yang diperiksa.
Installer dapat dijalankan ulang dan tidak mengimpor file Legacy.
Panduan: documentation/LEGACY_SCHEMA_CLI.md

HELP;
        exit(0);
    }
    if ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--check') {
        $check = true;
    } elseif ($argument === '--confirm-maintenance') {
        $maintenance = true;
    } elseif (preg_match('/^--expect-db=([A-Za-z0-9_]{1,64})$/D', $argument, $matches)) {
        if ($expectedDatabase !== null) {
            fwrite(STDERR, "--expect-db hanya boleh diberikan sekali.\n");
            exit(1);
        }
        $expectedDatabase = $matches[1];
    } else {
        fwrite(STDERR, "Opsi tidak dikenal atau tidak valid. Gunakan --help.\n");
        exit(1);
    }
}
if ($apply && $check) {
    fwrite(STDERR, "Pilih --check atau --apply, bukan keduanya.\n");
    exit(1);
}

$locked = false;
$exitCode = 0;
try {
    require_once __DIR__ . '/../koneksi.php';
    require_once __DIR__ . '/../includes/legacy_operational_schema.php';
    $target = $koneksi->query('SELECT DATABASE()')->fetch_row()[0];
    if ($expectedDatabase !== null && $target !== $expectedDatabase) {
        throw new RuntimeException('Database terhubung tidak sesuai --expect-db; tidak ada skema yang diubah.');
    }
    echo "Database tujuan: $target\n";
    echo $apply ? "Mode: pemasangan skema\n" : "Mode: pemeriksaan saja\n";

    if (!$apply) {
        echo lop_ready($koneksi)
            ? "Migrasi operasional siap (termasuk Alumni, tarif belum ditetapkan, dan saldo FIX).\n"
            : "Skema operasional Legacy belum lengkap. Ikuti documentation/LEGACY_SCHEMA_CLI.md.\n";
        echo "Tidak ada data sumber diimpor atau transaksi diubah.\n";
    } else {
        if (getenv('SPP_TEST_ALLOW_MUTATION') === '1') {
            require_once __DIR__ . '/../tests/http_form_scope.php';
            spp_test_assert_database_target($koneksi);
        } elseif (!$maintenance || !is_file(__DIR__ . '/../tmp/financial_migration.lock')) {
            throw new RuntimeException('Backup dan pemeliharaan diperlukan sebelum memasang skema.');
        }
        if ((int)$koneksi->query("SELECT GET_LOCK('spp_legacy_full_schema',0)")->fetch_row()[0] !== 1) {
            throw new RuntimeException('Pemasangan lain sedang berjalan.');
        }
        $locked = true;
        lop_schema_apply($koneksi);
        echo "Skema operasional terpasang; tidak ada sumber dimigrasikan.\n";
        echo "Periksa layanan, lalu mulai ulang worker dengan source terbaru sebelum membuka impor.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Gagal: " . $error->getMessage() . "\n");
    if ($apply) {
        fwrite(STDERR, "Pertahankan pemeliharaan sampai penyebab diperbaiki dan pemeriksaan lulus.\n");
    }
    $exitCode = 1;
} finally {
    if ($locked) { $koneksi->query("SELECT RELEASE_LOCK('spp_legacy_full_schema')"); }
}
exit($exitCode);
