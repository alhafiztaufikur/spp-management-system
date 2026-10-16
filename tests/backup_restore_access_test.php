<?php
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1' || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) exit(1);
require __DIR__ . '/../koneksi.php';
require __DIR__ . '/http_form_scope.php';
$base = rtrim((string)getenv('SPP_HTTP_BASE'), '/');
spp_test_assert_http_clone($base, DB_NAME);
function backup_access_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function backup_access_request(string $base, string $sid, string $method = 'GET', string $path = 'backup_restore.php'): array {
    $body = file_get_contents($base . '/' . $path, false, stream_context_create(['http' => [
        'method' => $method, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20,
        'header' => 'Cookie: ' . session_name() . '=' . $sid . "\r\n",
    ]]));
    return [(int)explode(' ', $http_response_header[0])[1], (string)$body];
}
$account = $koneksi->query("SELECT * FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
backup_access_assert((bool)$account, 'No fixture account');
$id = (int)$account['id'];
$sid = 'backupui' . bin2hex(random_bytes(12));
function backup_access_session(string $sid, int $id, int $unit): void {
    session_id($sid); session_start();
    $_SESSION = ['admin_id'=>$id, 'admin_role'=>'super_admin', 'active_unit_id'=>$unit];
    session_write_close();
}
try {
    foreach ([0,1,2,3] as $unit) {
        backup_access_session($sid, $id, $unit);
        [$status,$body] = backup_access_request($base,$sid);
        backup_access_assert($status===200 && str_contains($body,'Seluruh Database SistemSPP'), 'Wrong read scope');
        foreach (['POST','PUT','PATCH','DELETE'] as $method) {
            [$status] = backup_access_request($base,$sid,$method);
            backup_access_assert(in_array($status,[405,409],true), 'Write method allowed: '.$method);
        }
    }
    foreach (['admin','bendahara','kasir'] as $role) {
        $koneksi->query("UPDATE admin SET role='$role',unit_id=1 WHERE id=$id");
        backup_access_session($sid,$id,1); // Intentionally stale session claims Super Admin.
        [$status] = backup_access_request($base,$sid);
        backup_access_assert($status===($role==='bendahara'?302:200), 'Fresh role access incorrect for backup UI');
        $route = $role==='kasir' ? 'tabungan/masuk.php' : ($role==='bendahara' ? 'laporan/index.php' : 'dashboard.php');
        [$status,$body] = backup_access_request($base,$sid,'GET',$route);
        $visible=str_contains($body,'href="backup_restore.php"') || str_contains($body,'href="../backup_restore.php"');
        backup_access_assert($status===200 && $visible===($role!=='bendahara'), 'Backup menu differs from operational role');
    }
    $koneksi->query("UPDATE admin SET role='super_admin',unit_id=NULL,is_active=0 WHERE id=$id");
    backup_access_session($sid,$id,0);
    [$status] = backup_access_request($base,$sid);
    backup_access_assert($status===302,'Disabled account retained access');
} finally {
    $role=$koneksi->real_escape_string($account['role']); $unit=$account['unit_id']===null?'NULL':(int)$account['unit_id']; $active=(int)$account['is_active'];
    $koneksi->query("UPDATE admin SET role='$role',unit_id=$unit,is_active=$active WHERE id=$id");
    session_id($sid); session_start(); $_SESSION=[]; session_destroy();
}
echo "OK: operational backup URL/menu, fresh role and active-session validation; four read scopes and invalid write methods rejected\n";
