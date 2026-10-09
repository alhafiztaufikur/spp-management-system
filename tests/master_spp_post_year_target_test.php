<?php

/** A form POST must use its year, even if a stale URL has another year. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
session_start();
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/spp_billing.php';
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', DB_NAME)) {
    throw new RuntimeException('Tes ini hanya untuk clone audit dengan flag mutasi tes.');
}

$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'admin';
$_SESSION['active_unit_id'] = 1;
unit_set_context($koneksi, 1);
$_SERVER['PHP_SELF'] = '/master_spp.php';
$_SERVER['REQUEST_METHOD'] = 'POST';

$urlYear = '';
$postedYear = '';
$fallbackYear = '';
for ($start = 2180; $start <= 2190; $start++) {
    $first = $start . '/' . ($start + 1);
    $second = ($start + 1) . '/' . ($start + 2);
    $third = ($start + 2) . '/' . ($start + 3);
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE label IN (?,?,?)');
    $stmt->bind_param('sss', $first, $second, $third); $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    if ($exists === 0) { $urlYear = $first; $postedYear = $second; $fallbackYear = $third; break; }
}
if ($urlYear === '') throw new RuntimeException('Tiga tahun ajaran kosong tidak ditemukan.');

$token = bin2hex(random_bytes(32));
$arrayYear = in_array('--array-year', $argv, true);
$invalidYear = $arrayYear || in_array('--invalid-year', $argv, true);
$_SESSION['csrf_master_spp'] = $token;
$_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR'] = $fallbackYear;
$_GET = ['tahun' => $urlYear];
$_POST = [
    'tahun_ajaran' => $arrayYear ? ['tahun-salah'] : ($invalidYear ? 'tahun-salah' : $postedYear),
    'aksi' => 'simpan_tarif',
    'csrf_token' => $token,
    'jumlah' => array_fill(1, 6, 250000),
    'confirm_rate_change' => '1',
    'expected_rate_version' => spp_master_rate_version(['label'=>$postedYear,'status'=>'draft'],array_fill(1,6,0.0)),
];

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (!str_contains($message, 'session_start(): Ignoring session_start() because a session is already active')) {
        $warnings[] = $message;
    }
    return true;
});
register_shutdown_function(static function () use ($koneksi, $urlYear, $postedYear, $fallbackYear, $invalidYear, $arrayYear, &$warnings): void {
    while (ob_get_level() > 0) ob_end_clean();
    restore_error_handler();
    try {
        $masters = [];
        foreach ([$urlYear, $postedYear, $fallbackYear] as $label) {
            $stmt = $koneksi->prepare('SELECT y.id year_id,m.id master_id FROM tahun_ajaran y
                LEFT JOIN master_spp_tahun m ON m.tahun_ajaran_id=y.id WHERE y.label=?');
            $stmt->bind_param('s', $label); $stmt->execute();
            $masters[$label] = $stmt->get_result()->fetch_assoc(); $stmt->close();
        }
        $postedMasterId = (int)($masters[$postedYear]['master_id'] ?? 0);
        $ratesValid = false;
        if (!$invalidYear && $postedMasterId > 0) {
            $stmt = $koneksi->prepare('SELECT tingkat,nominal_dasar FROM master_spp_tarif WHERE master_spp_tahun_id=? ORDER BY tingkat');
            $stmt->bind_param('i', $postedMasterId); $stmt->execute();
            $rates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $ratesValid = count($rates) === 6;
            foreach ($rates as $index => $rate) {
                if ((int)$rate['tingkat'] !== $index + 1 || (float)$rate['nominal_dasar'] !== 250000.0) $ratesValid = false;
            }
        }
        $valid = !$warnings && !$masters[$urlYear] && !$masters[$fallbackYear] && ($invalidYear
            ? !$masters[$postedYear]
            : ($masters[$postedYear] && $ratesValid));

        foreach ($masters as $row) {
            if (!$row) continue;
            $yearId = (int)$row['year_id'];
            $masterId = (int)($row['master_id'] ?? 0);
            if ($masterId > 0) {
                $stmt = $koneksi->prepare('DELETE FROM spp_audit_log WHERE master_spp_tahun_id=?');
                $stmt->bind_param('i', $masterId); $stmt->execute(); $stmt->close();
                $stmt = $koneksi->prepare('DELETE FROM master_spp_tarif WHERE master_spp_tahun_id=?');
                $stmt->bind_param('i', $masterId); $stmt->execute(); $stmt->close();
                $stmt = $koneksi->prepare('DELETE FROM master_spp_tahun WHERE id=?');
                $stmt->bind_param('i', $masterId); $stmt->execute(); $stmt->close();
            }
            $stmt = $koneksi->prepare('DELETE FROM tahun_ajaran WHERE id=?');
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
        }
        if (!$valid) {
            fwrite(STDERR, 'FAILED: POST Master SPP salah menargetkan tahun, tidak menyimpan tarif, atau mengeluarkan warning: ' . implode(' | ', $warnings) . PHP_EOL);
            exit(1);
        }
        echo $invalidYear
            ? ($arrayYear ? "PASS: POST Master SPP menolak tahun formulir berbentuk array tanpa warning/data baru.\n" : "PASS: POST Master SPP menolak tahun formulir tidak valid tanpa menulis data.\n")
            : "PASS: POST Master SPP memakai tahun formulir dan membiarkan tahun URL.\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
});

ob_start();
include __DIR__ . '/../master_spp.php';
