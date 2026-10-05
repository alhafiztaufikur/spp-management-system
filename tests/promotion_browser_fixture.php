<?php
/** Compatible fixture entry point for the source-history browser regression. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) exit(1);
$action = $argv[1] ?? '';
if (!in_array($action, ['setup', 'verify'], true)) {
    fwrite(STDERR, "Usage: php tests/promotion_browser_fixture.php setup|verify\n"); exit(1);
}
if (!getenv('SPP_PROMOTION_ARTIFACTS') && getenv('SPP_UI_ARTIFACTS')) {
    putenv('SPP_PROMOTION_ARTIFACTS='.dirname((string)getenv('SPP_UI_ARTIFACTS')));
}
if ($action === 'setup') { require __DIR__.'/flexible_promotion_browser_fixture.php'; exit; }
require_once __DIR__.'/../koneksi.php';
$map = json_decode(file_get_contents((string)getenv('SPP_PROMOTION_ARTIFACTS').'/promotion-ui-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($map as $unit => $fixture) {
    unit_set_context($koneksi, (int)$unit);
    foreach ($fixture['students'] as $key => $student) {
        $s = $koneksi->prepare('SELECT KELAS,is_active FROM siswa WHERE id=? AND unit_id=?');
        $s->bind_param('ii', $student['id'], $unit); $s->execute(); $active = $s->get_result()->fetch_assoc(); $s->close();
        $s = $koneksi->prepare('SELECT status FROM siswa_tahun_ajaran WHERE id=? AND unit_id=?');
        $s->bind_param('ii', $student['placement'], $unit); $s->execute(); $status = $s->get_result()->fetch_row()[0]; $s->close();
        $s = $koneksi->prepare('SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk=? AND unit_id=?');
        $s->bind_param('si', $student['nis'], $unit); $s->execute(); $count = (int)$s->get_result()->fetch_row()[0]; $s->close();
        $senior = $key === 'senior';
        if (!$active || (int)$active['is_active'] !== ($senior ? 0 : 1)
            || (int)$active['KELAS'] !== $student['level'] + ($senior ? 0 : 1)
            || $status !== ($senior ? 'lulus' : 'pindah') || $count !== ($senior ? 1 : 2)) {
            throw new RuntimeException('Browser flow state mismatch: unit '.$unit.' / '.$key);
        }
    }
}
echo "PASS: source statuses, active classes and exact placement counts across SD/SMP/SMA\n";
