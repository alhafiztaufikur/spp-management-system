<?php
/** Disposable fixture: browser decisions must also prove the stored amounts. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    throw new RuntimeException('Use a named disposable audit clone and test flag.');
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__.'/../includes/transaction_authorization.php';
unit_set_context($koneksi, 1);
if ($koneksi->query('SELECT DATABASE()')->fetch_row()[0] !== getenv('SPP_DB_NAME')) {
    throw new RuntimeException('Database identity mismatch.');
}
$nis = '9988333001';
$action = $argv[1] ?? '';
if ($action === 'setup') {
    $file = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE');
    if (!is_file($file) || ($password = trim((string)file_get_contents($file))) === '') {
        throw new RuntimeException('Test password file required.');
    }
    $koneksi->begin_transaction();
    if ((int)$koneksi->query("SELECT COUNT(*) FROM siswa WHERE NO_INDUK='$nis'")->fetch_row()[0]) {
        throw new RuntimeException('Fixture already exists.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $koneksi->prepare("UPDATE admin SET password=? WHERE (username='superadmin' OR (username='kasir1' AND unit_id=1)) AND is_active=1");
    $stmt->bind_param('s', $hash); $stmt->execute();
    if ($stmt->affected_rows !== 2) throw new RuntimeException('Two active test accounts required.');
    $stmt->close();
    $class = (int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND kode_rombel='A' AND is_active=1 AND is_placeholder=0 LIMIT 1")->fetch_row()[0];
    $koneksi->query("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB) VALUES('$nis','BROWSER OTORISASI','1',$class,1000)");
    $month = date('m'); $year = date('Y');
    $koneksi->query("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,U_PSB,TGL_BYR,BULAN,TAHUN,sistem_pembayaran,total_jumlah,payment_link_version) VALUES('$nis','1',$class,100,NOW(),'$month','$year','Tunai',100,1)");
    $paymentId=(int)$koneksi->insert_id;
    $creator=(int)$koneksi->query("SELECT id FROM admin WHERE username='kasir1'")->fetch_row()[0];
    payment_activity_record($koneksi,$paymentId,'created',$creator,null,transaction_authorization_snapshot($koneksi,$paymentId)['data'],'created:'.$paymentId);
    $koneksi->commit();
    echo "OK: authorization browser fixture ready.\n";
} elseif (in_array($action, ['state', 'verify'], true)) {
    $payment = $koneksi->query("SELECT id,U_PSB,total_jumlah FROM bayar WHERE NO_INDUK='$nis'")->fetch_assoc();
    $requests = $koneksi->query("SELECT id,status,action FROM transaksi_otorisasi WHERE no_induk_snapshot='$nis' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    if ($action === 'state') {
        echo json_encode(['payment'=>$payment, 'requests'=>$requests], JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        if (!$payment || (float)$payment['U_PSB'] !== 200.0 || (float)$payment['total_jumlah'] !== 200.0
            || array_column($requests, 'status') !== ['approved','rejected','cancelled']) {
            throw new RuntimeException('Browser decisions did not produce the expected final database state.');
        }
        echo "OK: one approved edit applied; rejection/cancellation preserve payment Rp200; three decision records.\n";
    }
} else {
    throw new RuntimeException('Usage: setup|state|verify');
}
