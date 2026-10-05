<?php
/** Demo fixtures for a migrated, disposable multiunit database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$apply = false;
$asOfText = date('Y-m-d');
$asOfProvided = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $apply = true; continue; }
    if ($argument === '--apply-live') {
        fwrite(STDERR, "--apply-live dinonaktifkan; seeder demo hanya boleh pada clone disposable.\n");
        exit(1);
    }
    if (str_starts_with($argument, '--as-of=')) { $asOfText = substr($argument, 8); $asOfProvided = true; continue; }
    fwrite(STDERR, "Usage: php sql/seed_demo_multiunit.php [--apply] [--as-of=YYYY-MM-DD]\n");
    exit(1);
}
$asOf = DateTimeImmutable::createFromFormat('!Y-m-d', $asOfText, new DateTimeZone('Asia/Jakarta'));
if (!$asOf || $asOf->format('Y-m-d') !== $asOfText) {
    fwrite(STDERR, "Tanggal --as-of harus YYYY-MM-DD.\n"); exit(1);
}
if ($apply && !$asOfProvided) {
    fwrite(STDERR, "--apply memerlukan --as-of=YYYY-MM-DD.\n"); exit(1);
}
$database = (string)getenv('SPP_DB_NAME');
if (!preg_match('/^db_spp_(?:test|audit)_[a-z0-9_]+$/D', $database)) {
    fwrite(STDERR, "Seeder demo memerlukan SPP_DB_NAME=db_spp_test_* atau db_spp_audit_*.\n"); exit(1);
}
if ($apply && getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
    fwrite(STDERR, "--apply memerlukan SPP_TEST_ALLOW_MUTATION=1 pada clone disposable.\n"); exit(1);
}

require_once __DIR__ . '/../koneksi.php';
if (DB_NAME !== $database || (string)$koneksi->query('SELECT DATABASE() AS target')->fetch_assoc()['target'] !== $database) {
    throw new RuntimeException('Koneksi tidak menuju clone yang diminta.');
}
if (!unit_schema_ready($koneksi)) throw new RuntimeException('Jalankan migrasi multiunit pada database uji terlebih dahulu.');

function demo_query(mysqli $db, string $sql, array $values = []): mysqli_result|bool {
    $stmt = $db->prepare($sql);
    if ($values) $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}
function demo_one(mysqli $db, string $sql, array $values = []): ?array {
    $result = demo_query($db, $sql, $values);
    return $result instanceof mysqli_result ? ($result->fetch_assoc() ?: null) : null;
}
function demo_count(mysqli $db, string $table): int {
    return (int)demo_one($db, "SELECT COUNT(*) AS n FROM `{$table}`")['n'];
}
function demo_insert(mysqli $db, string $table, array $data): int {
    $columns = array_keys($data);
    $quoted = implode(',', array_map(static fn($column) => "`{$column}`", $columns));
    $marks = implode(',', array_fill(0, count($columns), '?'));
    demo_query($db, "INSERT INTO `{$table}` ({$quoted}) VALUES ({$marks})", array_values($data));
    return (int)$db->insert_id;
}
function demo_existing_or_insert(mysqli $db, string $table, string $condition, array $keys, array $data): int {
    $row = demo_one($db, "SELECT id FROM `{$table}` WHERE {$condition} LIMIT 1", $keys);
    return $row ? (int)$row['id'] : demo_insert($db, $table, $data);
}

$profiles = [
    2 => ['code'=>'SMP','grades'=>[7=>[350000,150000,1600000],8=>[360000,160000,1700000],9=>[375000,175000,1800000]],'psb'=>4100000],
    3 => ['code'=>'SMA','grades'=>[10=>[450000,200000,2000000],11=>[460000,210000,2100000],12=>[475000,225000,2200000]],'psb'=>4600000],
];
$before = [];
foreach ([1,2,3] as $unitId) {
    unit_set_context($koneksi, $unitId);
    $before[$unitId] = [
        'siswa'=>demo_count($koneksi,'siswa'), 'bayar'=>demo_count($koneksi,'bayar'),
        'tabungan'=>demo_count($koneksi,'tabungan'),
        'mutasi_tabungan'=>demo_count($koneksi,'transaksi_m')+demo_count($koneksi,'transaksi_k'),
    ];
}
echo "Database: " . DB_NAME . "; tanggal contoh: {$asOfText}; mode: " . ($apply ? 'TERAPKAN' : 'PERIKSA') . "\n";
foreach ($before as $unitId=>$counts) echo "Unit " . unit_label($unitId) . ': ' . json_encode($counts) . "\n";
if (!$apply) {
    echo "Rencana: 36 siswa per SMP/SMA (33 reguler, 3 PSB), tagihan dan transaksi terkait; SD hanya tabungan jika masih kosong pada database uji. Gunakan --apply pada clone disposable dengan SPP_TEST_ALLOW_MUTATION=1.\n";
    exit(0);
}

function demo_students(mysqli $db, int $unitId, array $profile, string $asOfText): void {
    unit_set_context($db, $unitId);
    $code = $profile['code'];
    $expected = [];
    for ($number=1; $number<=33; $number++) $expected[$code.'26'.str_pad((string)$number,4,'0',STR_PAD_LEFT)] = true;
    for ($number=1; $number<=3; $number++) $expected[$code.'PSB'.str_pad((string)$number,3,'0',STR_PAD_LEFT)] = true;
    foreach (demo_query($db, 'SELECT NO_INDUK,NAMA FROM siswa')->fetch_all(MYSQLI_ASSOC) as $student) {
        if (!isset($expected[$student['NO_INDUK']]) || !str_contains($student['NAMA'], 'Demo '.$code)) {
            throw new RuntimeException("Unit {$code} sudah mempunyai siswa di luar seeder; data tidak disentuh.");
        }
    }
    $year = demo_one($db, "SELECT id,status FROM tahun_ajaran WHERE label='2026/2027'");
    if (!$year || $year['status']==='closed') throw new RuntimeException("Tahun ajaran {$code} 2026/2027 tidak tersedia atau sudah ditutup.");
    $yearId = (int)$year['id'];
    if ($year['status']==='draft') demo_query($db, "UPDATE tahun_ajaran SET status='published',published_at=COALESCE(published_at,NOW()) WHERE id=?", [$yearId]);
    $master = demo_one($db, 'SELECT id,status FROM master_spp_tahun WHERE tahun_ajaran_id=?', [$yearId]);
    if ($master && $master['status']==='closed') throw new RuntimeException("Master SPP {$code} sudah ditutup.");
    $masterId = $master ? (int)$master['id'] : demo_insert($db,'master_spp_tahun',['tahun_ajaran_id'=>$yearId,'status'=>'published','published_at'=>$asOfText.' 08:00:00']);
    if ($master && $master['status']==='draft') demo_query($db, "UPDATE master_spp_tahun SET status='published',published_at=COALESCE(published_at,NOW()) WHERE id=?", [$masterId]);

    $duMasters = [];
    foreach ($profile['grades'] as $grade=>$rates) {
        [$spp,$komite,$du] = $rates;
        $tariff = demo_one($db,'SELECT id,nominal_dasar FROM master_spp_tarif WHERE master_spp_tahun_id=? AND tingkat=?',[$masterId,$grade]);
        if ($tariff && (int)$tariff['nominal_dasar'] !== $spp) throw new RuntimeException("Tarif SPP {$code} kelas {$grade} sudah berbeda; tidak ditimpa.");
        if (!$tariff) demo_insert($db,'master_spp_tarif',['master_spp_tahun_id'=>$masterId,'tingkat'=>$grade,'nominal_dasar'=>$spp]);
        $duMaster = demo_one($db,'SELECT id,Jumlah FROM Daftar_ulang WHERE th_ajaran=? AND kelas=?',['2026/2027',(string)$grade]);
        if ($duMaster && (int)$duMaster['Jumlah'] !== $du) throw new RuntimeException("Tarif Daftar Ulang {$code} kelas {$grade} sudah berbeda; tidak ditimpa.");
        $duMasters[$grade] = $duMaster ? (int)$duMaster['id'] : demo_insert($db,'Daftar_ulang',['tahun_ajaran_id'=>$yearId,'th_ajaran'=>'2026/2027','kelas'=>(string)$grade,'Jumlah'=>$du]);
    }
    $feeIds = [];
    foreach (['Buku Paket'=>180000,'Seragam Olahraga'=>220000] as $label=>$amount) {
        $name = 'DEMO '.$label.' '.$code;
        $fee = demo_one($db,'SELECT id,nominal FROM master_biaya_lain WHERE nama=?',[$name]);
        if ($fee && (int)$fee['nominal'] !== $amount) throw new RuntimeException("Master {$name} sudah berbeda; tidak ditimpa.");
        $feeIds[$label] = $fee ? (int)$fee['id'] : demo_insert($db,'master_biaya_lain',['nama'=>$name,'nominal'=>$amount,'is_active'=>1]);
    }

    $grades = array_keys($profile['grades']);
    $regular = [];
    for ($number=1; $number<=33; $number++) {
        $grade = $grades[intdiv($number-1,11)];
        $section = ['A','B','C','D'][intdiv(($number-1)%11,3)];
        $class = demo_one($db,'SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel=?',[$grade,$section]);
        if (!$class) throw new RuntimeException("Rombel {$grade}{$section} {$code} tidak ditemukan.");
        $classId = (int)$class['id'];
        $nis = $code.'26'.str_pad((string)$number,4,'0',STR_PAD_LEFT);
        [$spp,$komite,$du] = $profile['grades'][$grade];
        if (!demo_one($db,'SELECT id FROM siswa WHERE NO_INDUK=?',[$nis])) {
            demo_insert($db,'siswa',[
                'NO_INDUK'=>$nis,'NO_induk_diknas'=>'D26'.$code.str_pad((string)$number,4,'0',STR_PAD_LEFT),
                'NAMA'=>'Siswa Demo '.$code.' '.str_pad((string)$number,3,'0',STR_PAD_LEFT),
                'KELAS'=>(string)$grade,'master_kelas_id'=>$classId,'SPP_PERBULAN'=>$spp,
                'POMG'=>$komite,'DAFTAR_ULANG'=>$du,'tot_du'=>$du,'is_active'=>1,
            ]);
        }
        $placement = demo_existing_or_insert($db,'siswa_tahun_ajaran','tahun_ajaran_id=? AND no_induk=?',[$yearId,$nis],[
            'tahun_ajaran_id'=>$yearId,'no_induk'=>$nis,'kelas'=>(string)$grade,'master_kelas_id'=>$classId,
            'kelas_rombel_snapshot'=>$grade.$section,'spp_perbulan_snapshot'=>$spp,'komite_snapshot'=>$komite,'status'=>'aktif',
        ]);
        demo_existing_or_insert($db,'tagihan_daftar_ulang','tahun_ajaran_id=? AND no_induk=?',[$yearId,$nis],[
            'tahun_ajaran_id'=>$yearId,'penempatan_id'=>$placement,'master_daftar_ulang_id'=>$duMasters[$grade],
            'no_induk'=>$nis,'kelas_snapshot'=>(string)$grade,'tahun_ajaran_snapshot'=>'2026/2027',
            'nominal_awal'=>$du,'nominal_tagihan'=>$du,'status'=>'open',
        ]);
        for ($period=0; $period<12; $period++) {
            $month = str_pad((string)((($period+6)%12)+1),2,'0',STR_PAD_LEFT);
            $calendarYear = $period<6 ? '2026' : '2027';
            demo_existing_or_insert($db,'tagihan_spp','no_induk=? AND tahun=? AND bulan=?',[$nis,$calendarYear,$month],[
                'master_spp_tahun_id'=>$masterId,'tahun_ajaran_id'=>$yearId,'penempatan_id'=>$placement,
                'no_induk'=>$nis,'tingkat_snapshot'=>$grade,'master_kelas_id'=>$classId,'kelas_rombel_snapshot'=>$grade.$section,
                'bulan'=>$month,'tahun'=>$calendarYear,'tarif_dasar_snapshot'=>$spp,'nominal_tagihan'=>$spp,'status'=>'open',
            ]);
            demo_existing_or_insert($db,'tagihan_komite','no_induk=? AND tahun=? AND bulan=?',[$nis,$calendarYear,$month],[
                'tahun_ajaran_id'=>$yearId,'penempatan_id'=>$placement,'no_induk'=>$nis,'kelas_rombel_snapshot'=>$grade.$section,
                'bulan'=>$month,'tahun'=>$calendarYear,'nominal_tagihan'=>$komite,'status'=>'open',
            ]);
        }
        $regular[] = ['nis'=>$nis,'grade'=>$grade,'class_id'=>$classId,'class_label'=>$grade.$section,'spp'=>$spp,'komite'=>$komite,'du'=>$du];
    }
    $psbClass = demo_one($db,"SELECT id FROM master_kelas WHERE tingkat=0 AND kode_rombel='PSB'");
    if (!$psbClass) throw new RuntimeException("Kelas PSB {$code} tidak ditemukan.");
    $psb = [];
    for ($number=1; $number<=3; $number++) {
        $nis = $code.'PSB'.str_pad((string)$number,3,'0',STR_PAD_LEFT);
        if (!demo_one($db,'SELECT id FROM siswa WHERE NO_INDUK=?',[$nis])) demo_insert($db,'siswa',[
            'NO_INDUK'=>$nis,'NO_induk_diknas'=>'D'.$code.'PSB'.str_pad((string)$number,3,'0',STR_PAD_LEFT),
            'NAMA'=>'Calon Demo '.$code.' '.str_pad((string)$number,2,'0',STR_PAD_LEFT),
            'KELAS'=>'PSB','master_kelas_id'=>(int)$psbClass['id'],
            'PSB'=>$profile['psb'],'asal_psb'=>1,'is_active'=>1,
        ]);
        $psb[] = ['nis'=>$nis,'grade'=>'PSB','class_id'=>(int)$psbClass['id'],'class_label'=>'PSB'];
    }
    if (demo_count($db,'siswa') !== 36) throw new RuntimeException("Unit {$code} harus berisi tepat 36 siswa demo.");
    demo_payments($db,$code,$regular,$psb,$feeIds,$profile,$asOfText);
}

function demo_payments(mysqli $db, string $code, array $regular, array $psb, array $feeIds, array $profile, string $asOfText): void {
    $operator = 'kasir1.'.strtolower($code);
    if (!demo_one($db,"SELECT id FROM admin WHERE username=? AND role='kasir' AND is_active=1",[$operator])) throw new RuntimeException("Akun {$operator} belum tersedia.");
    $sequence = 0;
    $add = static function(string $kind, int $number, array $student, array $amounts, array $extra=[]) use ($db,$code,$operator,$asOfText,&$sequence,$feeIds): void {
        $sequence++;
        $key = "DEMO-UNIT-{$code}-{$kind}-".str_pad((string)$number,2,'0',STR_PAD_LEFT);
        $old = demo_one($db,'SELECT id,TGL_BYR FROM bayar WHERE KETERANGAN=?',[$key]);
        if ($old) {
            if (substr((string)$old['TGL_BYR'],0,10)!==$asOfText) throw new RuntimeException("Seeder {$code} pernah dipakai dengan --as-of berbeda.");
            return;
        }
        $sum = array_sum($amounts);
        $time = $asOfText.' '.sprintf('%02d:%02d:00',9+intdiv($sequence-1,12),($sequence-1)%12*4);
        $method = ['Tunai','VA','Qris'][($sequence-1)%3];
        $data = [
            'NO_INDUK'=>$student['nis'],'KELAS'=>(string)$student['grade'],'master_kelas_id'=>$student['class_id'],
            'kelas_rombel_snapshot'=>$student['class_label'],
            'U_PSB'=>$amounts['psb']??0,'U_SPP'=>$amounts['spp']??0,
            'U_KOMITE'=>$amounts['komite']??0,
            'U_LAIN'=>$amounts['lain']??0,'KETERANGAN'=>$key,'TGL_BYR'=>$time,
            'BULAN'=>$kind==='spp'?'07':null,'TAHUN'=>$kind==='spp'?'2026':null,
            'user_id'=>$operator,'sistem_pembayaran'=>$method,
            'th_ajaran'=>$kind==='du'?'2026/2027':null,'kelas_du'=>$kind==='du'?(string)$student['grade']:null,
            'LAIN_LAIN1'=>$extra['fee_name']??null,'JUMLAH1'=>$amounts['lain']??0,
            'total_jumlah'=>$sum,'payment_link_version'=>1,
        ];
        $paymentId = demo_insert($db,'bayar',$data);
        if ($kind==='spp') {
            demo_insert($db,'bayar_spp_periode',['bayar_id'=>$paymentId,'no_induk'=>$student['nis'],'bulan'=>'07','tahun'=>'2026']);
            $batch = demo_insert($db,'spp_alokasi_batch',[
                'no_induk'=>$student['nis'],'bayar_id'=>$paymentId,'tanggal'=>$time,'user_id'=>$operator,
                'uang_baru'=>$amounts['spp'],'komite_required'=>1,
            ]);
            $sppBill=demo_one($db,"SELECT id FROM tagihan_spp WHERE no_induk=? AND tahun='2026' AND bulan='07'",[$student['nis']]);
            $komiteBill=demo_one($db,"SELECT id FROM tagihan_komite WHERE no_induk=? AND tahun='2026' AND bulan='07'",[$student['nis']]);
            demo_insert($db,'spp_alokasi',['batch_id'=>$batch,'tagihan_spp_id'=>$sppBill['id'],'nominal_dari_bayar'=>$amounts['spp']]);
            demo_insert($db,'bayar_komite',['bayar_id'=>$paymentId,'tagihan_komite_id'=>$komiteBill['id'],'nominal'=>$amounts['komite']]);
        } elseif ($kind==='du') {
            $bill=demo_one($db,"SELECT id FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot='2026/2027'",[$student['nis']]);
            demo_insert($db,'bayar_du',['bayar_id'=>$paymentId,'tagihan_daftar_ulang_id'=>$bill['id'],
                'no_induk'=>$student['nis'],'kelas'=>(string)$student['grade'],'th_ajaran'=>'2026/2027','jumlah'=>$amounts['du']]);
        } elseif ($kind==='lain') {
            $feeId=$feeIds[$extra['fee_label']];
            $bill=demo_existing_or_insert($db,'tagihan_biaya_lain','master_biaya_lain_id=? AND no_induk=?',[$feeId,$student['nis']],[
                'master_biaya_lain_id'=>$feeId,'no_induk'=>$student['nis'],'master_kelas_id'=>$student['class_id'],
                'nama_snapshot'=>$extra['fee_name'],'nominal_tagihan'=>$extra['fee_amount'],
                'kelas_rombel_snapshot'=>$student['class_label'],'status'=>'open',
            ]);
            demo_insert($db,'bayar_biaya_lain',['bayar_id'=>$paymentId,'master_biaya_lain_id'=>$feeId,
                'tagihan_biaya_lain_id'=>$bill,'nama_biaya_snapshot'=>$extra['fee_name'],
                'nominal_snapshot'=>$amounts['lain'],'urutan'=>1,'legacy_key'=>'demo']);
        }
    };
    for ($number=1; $number<=6; $number++) {
        $student=$regular[$number-1];
        $add('spp',$number,$student,['spp'=>$student['spp'],'komite'=>$student['komite']]);
    }
    for ($number=1; $number<=3; $number++) $add('du',$number,$regular[$number-1],['du'=>$regular[$number-1]['du']*($number===2?.5:1)]);
    for ($number=1; $number<=2; $number++) $add('psb',$number,$psb[$number-1],['psb'=>$profile['psb']*($number===2?.5:1)]);
    foreach ([4=>'Buku Paket',5=>'Seragam Olahraga'] as $number=>$label) {
        $amount=$label==='Buku Paket'?180000:220000;
        $add('lain',$number,$regular[$number-1],['lain'=>$number===5?$amount/2:$amount],
            ['fee_label'=>$label,'fee_name'=>'DEMO '.$label.' '.$code,'fee_amount'=>$amount]);
    }
}

function demo_savings(mysqli $db, int $unitId, string $asOfText): void {
    unit_set_context($db,$unitId);
    $in=demo_count($db,'transaksi_m'); $out=demo_count($db,'transaksi_k');
    if ($in || $out || demo_count($db,'tabungan')) {
        echo 'Tabungan ' . unit_label($unitId) . " sudah berisi; dilewati.\n";
        return;
    }
    $code=unit_label($unitId);
    $operator=$unitId===1?'kasir1':'kasir1.'.strtolower($code);
    $students=demo_query($db,"SELECT NO_INDUK FROM siswa WHERE NAMA LIKE ? AND KELAS <> 'PSB' ORDER BY NO_INDUK LIMIT 6",['Siswa Demo %'])->fetch_all(MYSQLI_ASSOC);
    if (count($students)!==6) throw new RuntimeException("Butuh enam siswa demo {$code} untuk tabungan.");
    foreach ($students as $index=>$row) {
        $nis=$row['NO_INDUK']; $withdrawal=$index<3?25000:0;
        demo_insert($db,'tabungan',['NO_INDUK'=>$nis,'SALDO'=>100000-$withdrawal]);
        demo_insert($db,'transaksi_m',['NO_INDUK'=>$nis,'TANGGAL'=>$asOfText.' 14:'.sprintf('%02d',$index).':00',
            'MASUK'=>100000,'KELUAR'=>0,'user_id'=>$operator]);
        if ($withdrawal) demo_insert($db,'transaksi_k',['NO_INDUK'=>$nis,'TANGGAL'=>$asOfText.' 15:'.sprintf('%02d',$index).':00',
            'MASUK'=>0,'KELUAR'=>$withdrawal,'user_id'=>$operator]);
    }
}

$koneksi->begin_transaction();
try {
    foreach ($profiles as $unitId=>$profile) demo_students($koneksi,$unitId,$profile,$asOfText);
    foreach ([1,2,3] as $unitId) demo_savings($koneksi,$unitId,$asOfText);
    $koneksi->commit();
} catch (Throwable $error) {
    $koneksi->rollback();
    fwrite(STDERR,"Seeder dibatalkan: {$error->getMessage()}\n");
    exit(1);
}
foreach ([1,2,3] as $unitId) {
    unit_set_context($koneksi,$unitId);
    echo unit_label($unitId).': '.json_encode([
        'siswa'=>demo_count($koneksi,'siswa'),'bayar'=>demo_count($koneksi,'bayar'),
        'tagihan_spp'=>demo_count($koneksi,'tagihan_spp'),'tagihan_komite'=>demo_count($koneksi,'tagihan_komite'),
        'tabungan'=>demo_count($koneksi,'tabungan'),'masuk'=>demo_count($koneksi,'transaksi_m'),
        'keluar'=>demo_count($koneksi,'transaksi_k'),
    ])."\n";
}
