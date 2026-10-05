<?php
/** One-time account setup after migrate_units.php. Never writes passwords to Git. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$output = $argv[1] ?? '';
if ($output === '' || !preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $output)) {
    fwrite(STDERR, "Usage: php sql/bootstrap_unit_accounts.php ABSOLUTE_CREDENTIALS_FILE\n"); exit(1);
}
require_once __DIR__ . '/readiness_migration_guard.php';
$accountTarget = (string)getenv('SPP_DB_NAME');
if ($accountTarget === 'db_spp' && !in_array('--apply', $argv, true)) {
    throw new RuntimeException('Bootstrap akun utama memerlukan --apply dan prasyarat persetujuan/backup.');
}
readiness_migration_assert_apply_allowed($argv, $accountTarget);
$workspace = realpath(dirname(__DIR__));
$outputDirectory = realpath(dirname($output));
if ($workspace === false || $outputDirectory === false
    || str_starts_with(strtolower($outputDirectory . DIRECTORY_SEPARATOR),
        strtolower($workspace . DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Berkas kredensial harus berada di luar repository.');
}
require_once __DIR__ . '/../koneksi.php';
if (!unit_schema_ready($koneksi)) throw new RuntimeException('Migrasi unit belum dijalankan.');
$handle = @fopen($output, 'x');
if (!$handle) throw new RuntimeException('Berkas kredensial harus memakai jalur baru di luar repositori.');

try {
    $koneksi->begin_transaction();
    $existing = [];
    foreach ($koneksi->query('SELECT id,username,role,unit_id FROM admin FOR UPDATE') as $row) $existing[$row['username']] = $row;
    $keep = [];
    $accounts = [
        ['bendahara','Bendahara TU SD','bendahara',1],
        ['kasir1','Kasir SD 1','kasir',1], ['kasir2','Kasir SD 2','kasir',1],
        ['kasir3','Kasir SD 3','kasir',1], ['kasir4','Kasir SD 4','kasir',1],
    ];
    foreach ([2=>'smp',3=>'sma'] as $id=>$code) {
        $accounts[]=["bendahara.{$code}","Bendahara TU ".strtoupper($code),'bendahara',$id];
        for ($number=1;$number<=4;$number++) $accounts[]=["kasir{$number}.{$code}","Kasir ".strtoupper($code)." {$number}",'kasir',$id];
    }
    $accounts[]=['superadmin','Super Admin','super_admin',null];
    fwrite($handle, "Akun baru SistemSPP - simpan aman dan hapus berkas setelah diserahkan.\n");
    foreach ($accounts as [$username,$name,$role,$unitId]) {
        $keep[]=$username;
        if (isset($existing[$username])) {
            $row=$existing[$username];
            if ($row['role']!==$role || ($row['unit_id']===null?null:(int)$row['unit_id'])!==$unitId) {
                // Legacy accounts are assigned SD; new unit usernames may not be reused incorrectly.
                if ($unitId!==1) throw new RuntimeException("Username {$username} sudah digunakan akun berbeda.");
                $stmt=$koneksi->prepare('UPDATE admin SET role=?,unit_id=1,is_active=1 WHERE id=?');
                $stmt->bind_param('si',$role,$row['id']);$stmt->execute();$stmt->close();
            } else {
                $stmt=$koneksi->prepare('UPDATE admin SET is_active=1 WHERE id=?');
                $stmt->bind_param('i',$row['id']);$stmt->execute();$stmt->close();
            }
            continue;
        }
        $password=bin2hex(random_bytes(12));
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $stmt=$koneksi->prepare('INSERT INTO admin (username,password,nama,role,unit_id,is_active) VALUES (?,?,?,?,?,1)');
        $stmt->bind_param('ssssi',$username,$hash,$name,$role,$unitId);$stmt->execute();$stmt->close();
        fwrite($handle, $username . " | " . $password . " | " . $role . " | " . unit_label($unitId ?? 0) . "\n");
    }
    // Preserve historical account ownership and audit links; archive legacy unit admins.
    foreach ($existing as $username=>$row) {
        if (in_array($username,$keep,true)) continue;
        $id=(int)$row['id'];
        $koneksi->query("UPDATE admin SET is_active=0 WHERE id={$id}");
    }
    $koneksi->commit();
    fclose($handle);
    echo "OK: akun unit siap. Kredensial baru disimpan pada {$output}.\n";
} catch (Throwable $error) {
    $koneksi->rollback(); fclose($handle); @unlink($output); throw $error;
}
