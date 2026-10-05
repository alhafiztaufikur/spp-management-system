<?php

if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
    throw new RuntimeException('Jalankan hanya lewat CLI dengan SPP_TEST_ALLOW_MUTATION=1.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$database = 'db_spp_audit_schema_install_' . bin2hex(random_bytes(6));
$host = getenv('SPP_DB_HOST') ?: 'localhost';
$user = getenv('SPP_DB_USER') ?: 'root';
$pass = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
$port = filter_var(getenv('SPP_DB_PORT') ?: '3306', FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if ($port === false) throw new RuntimeException('Port database latihan tidak valid.');
$db = new mysqli($host, $user, $pass, '', $port);
$db->set_charset('utf8mb4');
$created = false;
$credentialsFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'spp-unit-accounts-' . bin2hex(random_bytes(6)) . '.txt';

try {
    // CREATE without IF NOT EXISTS reserves only a fresh disposable target.
    // If the unlikely random name already exists, leave that database untouched.
    $db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db->select_db($database);
    require_once __DIR__ . '/../sql/schema_source.php';
    $sql = spp_schema_source();
    $sql = str_replace(
        ['CREATE DATABASE IF NOT EXISTS `db_spp`', 'USE `db_spp`'],
        ["CREATE DATABASE IF NOT EXISTS `{$database}`", "USE `{$database}`"],
        $sql,
        $replacementCount
    );
    if ($replacementCount !== 2) throw new RuntimeException('Kontrak nama database pada schema.sql berubah.');

    $db->multi_query($sql);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) $result->free();
    } while ($db->more_results() && $db->next_result());

    $required = ['master_spp_tahun', 'master_spp_tarif', 'tagihan_spp', 'spp_alokasi_batch', 'spp_alokasi', 'spp_audit_log', 'transaksi_otorisasi', 'keuangan_request'];
    $quoted = implode(',', array_fill(0, count($required), '?'));
    $stmt = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ({$quoted})");
    $params = array_merge([$database], $required);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $tables = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $stmt->close();
    sort($tables); sort($required);
    if ($tables !== $required) throw new RuntimeException('Tabel Master SPP pada instalasi baru tidak lengkap.');

    $retired=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('U_TITIPAN_SPP','gunakan_titipan','titipan_digunakan','titipan_baru','nominal_dari_titipan')")->fetch_row()[0];
    if($retired || (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'titipan_spp_mutasi%'")->fetch_row()[0]) throw new RuntimeException('Fresh install recreated Titipan SPP.');
    $empty = $db->query("SELECT
        (SELECT COUNT(*) FROM `{$database}`.siswa) siswa,
        (SELECT COUNT(*) FROM `{$database}`.bayar) pembayaran,
        (SELECT COUNT(*) FROM `{$database}`.tahun_ajaran) tahun_ajaran")->fetch_assoc();
    if ((int)$empty['siswa'] !== 0 || (int)$empty['pembayaran'] !== 0 || (int)$empty['tahun_ajaran'] !== 0) {
        throw new RuntimeException('schema.sql tidak boleh menyisipkan siswa, pembayaran, atau tahun ajaran demo.');
    }

    // The documented fresh install continues through migrate_units.php.
    // A schema-only check misses constraints that still limit SMP/SMA to grade 6.
    putenv('SPP_DB_NAME=' . $database);
    require __DIR__ . '/../sql/migrate_units.php';
    if ((int)$koneksi->query("SELECT COUNT(*) n FROM information_schema.VIEWS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa'")->fetch_assoc()['n'] !== 1) {
        throw new RuntimeException('Migrasi multiunit tidak membuat view operasional siswa.');
    }
    require_once __DIR__.'/../includes/legacy_schema.php';
    if(!legacy_schema_ready($koneksi))throw new RuntimeException('Fresh install missing Legacy/unit identity schema');
    // The documented workflow runs account bootstrap in a new CLI process.
    $process = proc_open([PHP_BINARY, __DIR__ . '/../sql/bootstrap_unit_accounts.php', $credentialsFile],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null,
        ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Proses bootstrap akun tidak dapat dibuka.');
    fclose($pipes[0]);
    $bootstrapOutput = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $bootstrapError = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Bootstrap akun clone gagal: ' . trim($bootstrapError ?: $bootstrapOutput));
    }
    if ((int)$koneksi->query('SELECT COUNT(*) FROM admin')->fetch_row()[0] !== 19
        || !is_file($credentialsFile) || filesize($credentialsFile) === 0) {
        throw new RuntimeException('Bootstrap akun clone tidak lengkap.');
    }
    foreach ([2 => 7, 3 => 10] as $unitId => $grade) {
        unit_set_context($koneksi, $unitId);
        $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat={$grade} AND kode_rombel='A'")->fetch_assoc();
        if (!$class) throw new RuntimeException("Kelas {$grade}A unit {$unitId} tidak tersedia.");
        $koneksi->query("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status)
            VALUES('2026/2027','2026-07-01','2027-06-30','draft')");
        $yearId = (int)$koneksi->insert_id;
        $koneksi->query("INSERT INTO master_spp_tahun(tahun_ajaran_id) VALUES({$yearId})");
        $masterId = (int)$koneksi->insert_id;
        $koneksi->query("INSERT INTO master_spp_tarif(master_spp_tahun_id,tingkat,nominal_dasar)
            VALUES({$masterId},{$grade},250000)");
        $wrongGrade = $unitId === 2 ? 6 : 7;
        $rejected = false;
        try {
            $koneksi->query("INSERT INTO master_spp_tarif(master_spp_tahun_id,tingkat,nominal_dasar)
                VALUES({$masterId},{$wrongGrade},250000)");
        } catch (mysqli_sql_exception $error) {
            $rejected = $error->getSqlState() === '45000';
        }
        if (!$rejected) throw new RuntimeException("Tarif tingkat {$wrongGrade} diterima di unit {$unitId}.");
    }

    // The fresh clone has no payments. Remove only test tariffs before the demo profile is installed.
    $koneksi->query('DELETE FROM master_spp_tarif_data');
    unit_set_context($koneksi,1);
    $sdClass=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND kode_rombel='A' LIMIT 1")->fetch_row()[0];
    for($number=1;$number<=6;$number++){
        $nis='99000000'.str_pad((string)$number,2,'0',STR_PAD_LEFT);
        $koneksi->query("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id) VALUES('$nis','Siswa Demo SD install $number','1',$sdClass)");
    }
    for($run=0;$run<2;$run++){
        $process=proc_open([PHP_BINARY,__DIR__.'/../sql/seed_demo_multiunit.php','--apply','--as-of=2026-09-30'],
            [['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Seeder multiunit tidak dapat dijalankan.');
        fclose($pipes[0]);$seedOut=stream_get_contents($pipes[1]);fclose($pipes[1]);$seedError=stream_get_contents($pipes[2]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException('Seed instalasi baru gagal: '.$seedError.$seedOut);
    }
    require_once __DIR__.'/../includes/spp_billing.php';
    foreach([2,3] as $unitId){
        unit_set_context($koneksi,$unitId);
        if((int)$koneksi->query('SELECT COUNT(*) FROM bayar')->fetch_row()[0]!==13)throw new RuntimeException('Seed pembayaran multiunit tidak idempoten.');
        $wrong=(int)$koneksi->query("SELECT COUNT(*) FROM spp_alokasi a JOIN tagihan_spp ts ON ts.id=a.tagihan_spp_id WHERE ABS(a.nominal_dari_bayar-ts.nominal_tagihan)>.001")->fetch_row()[0];
        if($wrong)throw new RuntimeException('Seed SPP tidak melunasi tepat satu tagihan.');
        foreach($koneksi->query('SELECT DISTINCT no_induk FROM spp_alokasi_batch')->fetch_all(MYSQLI_ASSOC) as $seedStudent)
            spp_assert_paid_order($koneksi,(string)$seedStudent['no_induk']);
    }
    echo "OK: instalasi baru/multiunit, tarif SMP/SMA, seed pembayaran langsung, dan rerun tanpa duplikasi.\n";
} finally {
    if (is_file($credentialsFile)) {
        if (realpath(dirname($credentialsFile)) !== realpath(sys_get_temp_dir())
            || !preg_match('/^spp-unit-accounts-[a-f0-9]{12}\.txt$/D', basename($credentialsFile))) {
            throw new RuntimeException('Berkas kredensial tes tidak cocok; penghapusan dibatalkan.');
        }
        unlink($credentialsFile);
    }
    if ($created) {
        $currentDatabase = (string)$db->query('SELECT DATABASE()')->fetch_row()[0];
        if (!preg_match('/^db_spp_audit_schema_install_[a-f0-9]{12}$/D', $database)
            || $currentDatabase !== $database) {
            throw new RuntimeException('Target penghapusan database latihan tidak sesuai; database dipertahankan.');
        }
        $db->query("DROP DATABASE `{$database}`");
    }
    $db->close();
}
