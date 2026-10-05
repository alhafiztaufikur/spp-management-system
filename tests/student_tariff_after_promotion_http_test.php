<?php
require_once __DIR__ . "/http_form_scope.php";

/** HTTP regression for editing a promoted student with a different destination-year tariff. */
session_start();
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1' || !str_starts_with(DB_NAME, 'db_spp_audit_')) {
    fwrite(STDERR, "SKIPPED: requires SPP_TEST_ALLOW_MUTATION=1 and db_spp_audit_* database.\n");
    exit(0);
}
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
if ($password === '') throw new RuntimeException('SPP_TEST_ADMIN_PASSWORD is required.');
$baseUrl = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8766'), '/');
spp_test_assert_http_clone($baseUrl, DB_NAME);

function promoted_tariff_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function promoted_tariff_request(string $url, ?array $post, array &$cookies): array {
    $headers = [];
    if ($post !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $key => $value) $pairs[] = $key . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    promoted_tariff_assert($body !== false, 'HTTP request failed.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

function promoted_tariff_amount(mysqli $db, string $table, int $placementId): array {
    if (!in_array($table, ['tagihan_spp', 'tagihan_komite'], true)) throw new InvalidArgumentException('Invalid bill table.');
    $stmt = $db->prepare("SELECT COUNT(*) n,MIN(nominal_tagihan) low,MAX(nominal_tagihan) high FROM {$table} WHERE penempatan_id=?");
    $stmt->bind_param('i', $placementId); $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $result;
}

$_SESSION['active_unit_id'] = 1;
unit_set_context($koneksi, 1);
$nis = (string)random_int(9500000000, 9599999999);
$oldYear = '2187/2188';
$newYear = '2188/2189';
$oldMasterId = 0;
$newMasterId = 0;
$oldYearId = 0;
$newYearId = 0;
$created = false;
$failure = null;
try {
    $koneksi->begin_transaction();
    $classes = [];
    foreach ([5, 6] as $level) {
        $stmt = $koneksi->prepare("SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel='A' AND is_active=1 AND is_placeholder=0 LIMIT 1");
        $stmt->bind_param('i', $level); $stmt->execute();
        $classes[$level] = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
        promoted_tariff_assert($classes[$level] > 0, 'Rombel uji tidak tersedia.');
    }
    $name = 'UJI TARIF SETELAH NAIK';
    $level = '5';
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,250000,15000,1)');
    $stmt->bind_param('sssi', $nis, $name, $level, $classes[5]);
    $stmt->execute(); $studentId = (int)$koneksi->insert_id; $stmt->close();
    $oldMaster = spp_master_ensure_year($koneksi, $oldYear, true);
    $oldMasterId = (int)$oldMaster['id'];
    $oldYearId = (int)$oldMaster['tahun_ajaran_id'];
    $snapshot = '5A'; $status = 'aktif';
    $stmt = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,250000,15000,?)');
    $stmt->bind_param('ississ', $oldYearId, $nis, $level, $classes[5], $snapshot, $status);
    $stmt->execute(); $oldPlacementId = (int)$koneksi->insert_id; $stmt->close();
    komite_sync_placement($koneksi, $oldPlacementId);
    spp_master_save_rates($koneksi, $oldMasterId, array_fill(1, 6, 250000.0));
    spp_publish_students($koneksi, $oldMasterId, [$nis]);
    class_manual_promote_student($koneksi, $nis, $classes[6], $newYear);
    $newMaster = spp_master_ensure_year($koneksi, $newYear, true);
    $newMasterId = (int)$newMaster['id'];
    $newYearId = (int)$newMaster['tahun_ajaran_id'];
    spp_master_save_rates($koneksi, $newMasterId, array_fill(1, 6, 300000.0));
    $stmt = $koneksi->prepare('SELECT id FROM siswa_tahun_ajaran WHERE tahun_ajaran_id=? AND no_induk=? LIMIT 1');
    $stmt->bind_param('is', $newYearId, $nis); $stmt->execute();
    $newPlacementId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
    promoted_tariff_assert($newPlacementId > 0, 'Penempatan tujuan tidak ada.');
    spp_publish_students($koneksi, $newMasterId, [$nis]);
    $koneksi->commit();
    $created = true;

    $cookies = [];
    $login = promoted_tariff_request($baseUrl . '/login.php', ['username' => 'admin', 'password' => $password], $cookies);
    promoted_tariff_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login HTTP gagal.');
    $page = promoted_tariff_request($baseUrl . '/siswa/daftar.php?edit=' . $studentId, null, $cookies);
    promoted_tariff_assert($page['status'] === 200, 'Form edit Data Siswa gagal dibuka.');
    promoted_tariff_assert(str_contains($page['body'], 'TA ' . $newYear . ' · tarif dasar'), 'Pratinjau tidak memilih tahun tujuan.');
    promoted_tariff_assert(preg_match('/<form\b[^>]*\bid="form-master-siswa"[^>]*>(.*?)<\/form>/s', $page['body'], $studentForm) === 1
        && preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $studentForm[1], $match) === 1,
        'Token CSRF form Data Siswa tidak ada.');

    $save = promoted_tariff_request($baseUrl . '/siswa/daftar.php', [
        'aksi' => 'update', 'id' => $studentId, 'csrf_token' => $match[1],
        'no_induk' => $nis, 'nama' => $name, 'master_kelas_id' => $classes[6],
        'advanced_enabled' => '1', 'potongan_spp_nominal' => 30000,
        'psb' => 0, 'pomg' => 30000, 'daftar_ulang' => 0,
        'potong_du' => 0, 'komite_mulai_bulan' => '07',
    ], $cookies);
    promoted_tariff_assert($save['status'] === 302, 'Edit Data Siswa tidak selesai.');
    $student = $koneksi->query('SELECT SPP_PERBULAN,POMG FROM siswa WHERE id=' . $studentId)->fetch_assoc();
    promoted_tariff_assert($student && (float)$student['SPP_PERBULAN'] === 270000.0 && (float)$student['POMG'] === 30000.0,
        'Data Siswa memakai tarif tahun asal atau edit gagal.');
    foreach ([
        [$oldPlacementId, 250000.0, 15000.0],
        [$newPlacementId, 270000.0, 30000.0],
    ] as [$placementId, $expectedSpp, $expectedKomite]) {
        foreach (['tagihan_spp' => $expectedSpp, 'tagihan_komite' => $expectedKomite] as $table => $expected) {
            $amount = promoted_tariff_amount($koneksi, $table, $placementId);
            promoted_tariff_assert((int)$amount['n'] === 12 && (float)$amount['low'] === $expected && (float)$amount['high'] === $expected,
                "Tagihan {$table} berubah tidak sesuai setelah edit HTTP.");
        }
    }
} catch (Throwable $error) {
    try { $koneksi->rollback(); } catch (Throwable $ignored) {}
    $failure = $error;
} finally {
    if ($created) {
        try {
            $koneksi->begin_transaction();
            $stmt = $koneksi->prepare('DELETE FROM spp_audit_log WHERE master_spp_tahun_id IN (?,?) OR no_induk=?');
            $stmt->bind_param('iis', $oldMasterId, $newMasterId, $nis); $stmt->execute(); $stmt->close();
            foreach (['tagihan_spp', 'tagihan_komite', 'siswa_audit_log'] as $table) {
                $column = $table === 'siswa_audit_log' ? 'no_induk_snapshot' : 'no_induk';
                $stmt = $koneksi->prepare("DELETE FROM {$table} WHERE {$column}=?");
                $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
            }
            $stmt = $koneksi->prepare('DELETE FROM siswa_tahun_ajaran WHERE no_induk=?');
            $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
            $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
            $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
            $stmt = $koneksi->prepare('DELETE FROM master_spp_tarif WHERE master_spp_tahun_id IN (?,?)');
            $stmt->bind_param('ii', $oldMasterId, $newMasterId); $stmt->execute(); $stmt->close();
            $stmt = $koneksi->prepare('DELETE FROM master_spp_tahun WHERE id IN (?,?)');
            $stmt->bind_param('ii', $oldMasterId, $newMasterId); $stmt->execute(); $stmt->close();
            $stmt = $koneksi->prepare('DELETE FROM tahun_ajaran WHERE id IN (?,?)');
            $stmt->bind_param('ii', $oldYearId, $newYearId); $stmt->execute(); $stmt->close();
            $koneksi->commit();
        } catch (Throwable $cleanupError) {
            try { $koneksi->rollback(); } catch (Throwable $ignored) {}
            $failure ??= $cleanupError;
        }
    }
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: edit HTTP setelah kenaikan memakai tarif SPP tahun tujuan dan menjaga tagihan lama.\n";
