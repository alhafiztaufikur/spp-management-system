<?php
/** Disposable restored-instance fixture for transaction_entry_browser_test.js. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/http_form_scope.php';
$manifest = spp_test_isolated_manifest();
require_once __DIR__.'/../koneksi.php';
spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/transaction_entry_date.php';
$root = dirname((string)getenv('SPP_TEST_ISOLATED_INSTANCE'));
$action = $argv[1] ?? 'state';
$fixturePath = $root.'/entry-fixture.json';
if ($action === 'setup') {
    if (is_file($fixturePath)) throw new RuntimeException('Use a fresh fixture.');
    $admin = $koneksi->query("SELECT id,nama FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    if (!$admin) throw new RuntimeException('Active Super Admin required.');
    $fixture = ['units'=>[], 'sessions'=>[]];
    foreach ([1,2,3] as $unit) {
        unit_set_context($koneksi, $unit);
        $class = $koneksi->query("SELECT id,tingkat,kode_rombel FROM master_kelas WHERE jenis='reguler' AND is_active=1 AND is_placeholder=0 ORDER BY tingkat,id LIMIT 1")->fetch_assoc();
        if (!$class) throw new RuntimeException('Regular class required.');
        $nis = (string)(9988261000+$unit);
        $name = 'QA Tanggal Transaksi '.$unit;
        $statement = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS,master_kelas_id,PSB,is_active) VALUES (?,?,?,?,1000000,1)');
        $statement->bind_param('sssi', $nis, $name, $class['tingkat'], $class['id']);
        $statement->execute();
        $fixture['units'][$unit] = ['student_id'=>(int)$koneksi->insert_id, 'nis'=>$nis, 'name'=>$name];
        $fixture['units'][$unit]['billed_nis'] = $koneksi->query("SELECT no_induk FROM tagihan_spp WHERE bulan='11' AND tahun='2026' AND status='open' ORDER BY id LIMIT 1")->fetch_row()[0] ?? null;
        $sessionId = bin2hex(random_bytes(16));
        session_id($sessionId);
        session_start();
        $_SESSION = ['admin_id'=>(int)$admin['id'], 'admin_nama'=>$admin['nama'], 'admin_role'=>'super_admin',
            'active_unit_id'=>$unit, 'admin_unit_id'=>null, 'csrf_token'=>bin2hex(random_bytes(32))];
        session_write_close();
        $fixture['sessions'][$unit] = $sessionId;
    }
    unit_set_context($koneksi,1);
    $fixture['account'] = $koneksi->query("SELECT o.id,a.balance FROM legacy_op_owners o JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE o.student_id IS NULL AND o.state='ready' AND b.state='completed' AND a.balance>=100 ORDER BY o.id LIMIT 1")->fetch_assoc();
    if (!$fixture['account']) throw new RuntimeException('Restored ready non-student savings account required.');
    file_put_contents($fixturePath, json_encode($fixture, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "PASS isolated fixture and sessions prepared.\n";
    exit;
}
if ($action === 'guards') {
    $changed = transaction_entry_date_schema_upgrade($koneksi);
    if (transaction_entry_date_schema_upgrade($koneksi) !== 0) throw new RuntimeException('Date guard upgrade is not idempotent.');
    echo "PASS cash date guard adjustment: $changed triggers; replay zero changes.\n";
    exit;
}
$fixture = json_decode(file_get_contents($fixturePath),true,512,JSON_THROW_ON_ERROR);
if ($action === 'guard-tests') {
    unit_set_context($koneksi,1);
    $nis=$fixture['units'][1]['nis'];
    $statement=$koneksi->prepare("INSERT INTO transaksi_m (NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES (?, '2020-01-02 10:00:00',1,0,'1','QA guard rollback')");
    $statement->bind_param('s',$nis);
    $reject=static function(callable $run): void {
        try {$run();} catch (mysqli_sql_exception $error) {if($error->getCode()===1644)return;throw $error;}
        throw new RuntimeException('Expected existing database guard to reject INSERT.');
    };
    $koneksi->begin_transaction();
    try {
        $reject(static fn()=>$statement->execute());
        $id=transaction_entry_insert($koneksi,'2020-01-02 10:00:00',static fn()=>$statement->execute());
        if($id<=0||$koneksi->query('SELECT @transaction_entry_timestamp')->fetch_row()[0]!==null)throw new RuntimeException('Insert ID or timestamp cleanup failed.');
        $reject(static fn()=>$statement->execute());
        try {transaction_entry_insert($koneksi,'2020-01-02 10:00:00',static function(){throw new DomainException('QA callback');});}catch(DomainException $error){}
        if($koneksi->query('SELECT @transaction_entry_timestamp')->fetch_row()[0]!==null)throw new RuntimeException('Failed callback leaked timestamp allowance.');
        $nis=$fixture['units'][2]['nis'];
        $reject(static fn()=>transaction_entry_insert($koneksi,'2020-01-02 10:00:00',static fn()=>$statement->execute()));
        $nis=$fixture['units'][1]['nis'];
        $koneksi->query("UPDATE legacy_full_batches SET state='blocked' WHERE unit_id=1");
        $reject(static fn()=>transaction_entry_insert($koneksi,'2020-01-02 10:00:00',static fn()=>$statement->execute()));
        echo "PASS date guard, exact timestamp allowance, insert ID, cleanup on success/error, cross-unit and migration gates.\n";
    } finally {$koneksi->rollback();}
    exit;
}
$state = [];
foreach ([1,2,3] as $unit) {
    unit_set_context($koneksi,$unit);
    $nis = $fixture['units'][$unit]['nis'];
    $state['units'][$unit]['payments'] = $koneksi->query("SELECT id,NO_INDUK,TGL_BYR,BULAN,TAHUN,U_PSB,total_jumlah FROM bayar WHERE NO_INDUK='$nis' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $state['units'][$unit]['balance'] = $koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0] ?? '0.00';
    foreach (['masuk'=>'transaksi_m','keluar'=>'transaksi_k'] as $kind=>$table) {
        $state['units'][$unit][$kind] = $koneksi->query("SELECT id,NO_INDUK,TANGGAL,MASUK,KELUAR FROM $table WHERE NO_INDUK='$nis' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }
}
unit_set_context($koneksi,1);
$owner = (int)$fixture['account']['id'];
$state['account']['journals'] = $koneksi->query("SELECT id,source_date,incoming,outgoing FROM legacy_op_journals WHERE owner_id=$owner AND origin='new' AND note LIKE 'QA custom date %' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$state['account']['balance'] = $koneksi->query("SELECT balance FROM legacy_op_accounts WHERE owner_id=$owner")->fetch_row()[0];
if ($action === 'verify-reports') {
    require_once __DIR__.'/../includes/reports.php';
    $checks=0;
    foreach([1,2,3] as $unit){
        unit_set_context($koneksi,$unit);
        $_SESSION['active_unit_id']=$unit;
        $f=report_filters($koneksi,['tanggal_awal'=>'2020-01-01','tanggal_akhir'=>'2031-01-01','kategori'=>'semua','siswa_status'=>'all','all_periods'=>'1','student_id'=>$fixture['units'][$unit]['student_id']]);
        $payments=report_payment_components($koneksi,$f,true);
        foreach($state['units'][$unit]['payments'] as $row){
            $found=array_values(array_filter($payments,static fn($r)=>(int)$r['id']===(int)$row['id']&&$r['nis']===$row['NO_INDUK']));
            if(count($found)!==1||$found[0]['tanggal']!==$row['TGL_BYR'])throw new RuntimeException('Payment report cash date mismatch.');
            $checks++;
        }
        $savings=report_savings_transactions($koneksi,'2020-01-01 00:00:00','2031-01-02 00:00:00',$f);
        foreach(['masuk','keluar'] as $kind)foreach($state['units'][$unit][$kind] as $row){
            $found=array_values(array_filter($savings,static fn($r)=>(int)$r['id']===(int)$row['id']&&$r['NO_INDUK']===$row['NO_INDUK']&&strtolower($r['jenis'])===$kind));
            if(count($found)!==1||$found[0]['tanggal']!==$row['TANGGAL'])throw new RuntimeException('Student savings report cash date mismatch.');
            $checks++;
        }
        if($unit===1){
            $ownerFilters=report_filters($koneksi,['tanggal_awal'=>'2020-01-01','tanggal_akhir'=>'2031-01-01','owner_id'=>$owner,'all_periods'=>'1','siswa_status'=>'all']);
            $savings=report_savings_transactions($koneksi,'2020-01-01 00:00:00','2031-01-02 00:00:00',$ownerFilters);
        }
        if($unit===1)foreach($state['account']['journals'] as $row){
            $found=array_values(array_filter($savings,static fn($r)=>(int)$r['id']===-1000000000-(int)$row['id']));
            if(count($found)!==1||$found[0]['tanggal']!==$row['source_date'])throw new RuntimeException('Non-student savings report cash date mismatch.');
            $checks++;
        }
    }
    echo "PASS $checks report cash date readbacks.\n";
    exit;
}
echo json_encode($state,JSON_THROW_ON_ERROR);
