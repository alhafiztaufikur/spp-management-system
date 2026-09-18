<?php

/** Jalankan hanya terhadap database disposable dan server PHP uji. */
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/daftar_ulang.php';
require_once __DIR__ . '/../includes/komite_billing.php';

function du_http_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function du_http_request(string $url, array $data, array &$cookies): array {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http'=>[
        'method'=>$data ? 'POST' : 'GET', 'header'=>implode("\r\n", $headers),
        'content'=>$data ? http_build_query($data) : '', 'ignore_errors'=>true,
        'follow_location'=>0, 'timeout'=>10,
    ]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('HTTP request gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status'=>$status, 'body'=>$body];
}

function du_http_flash(string $baseUrl, array &$cookies): string {
    $page = du_http_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $page['body'], $match)) {
        return trim(html_entity_decode(strip_tags($match[1])));
    }
    return '';
}

function du_http_year_id(mysqli $db, string $label): int {
    $stmt = $db->prepare('SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1');
    $stmt->bind_param('s', $label); $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
    if ($id > 0) return $id;
    [$start, $end] = du_year_dates($label);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status,published_at) VALUES(?,?,?,'published',NOW())");
    $stmt->bind_param('sss', $label, $start, $end); $stmt->execute();
    $id = (int)$db->insert_id; $stmt->close();
    return $id;
}

function du_http_student(mysqli $db, string $nis, string $name, int $classId, array $years, bool $graduate = false): array {
    $active = $graduate ? 0 : 1; $level = '1'; $spp = 250000.0; $komite = 100000.0;
    $stmt = $db->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,?,?,?)');
    $stmt->bind_param('sssiddi', $nis, $name, $level, $classId, $spp, $komite, $active);
    $stmt->execute(); $stmt->close();
    $bills = [];
    foreach ($years as $label => $yearId) {
        $status = $graduate ? 'lulus' : ($label === du_current_academic_year() ? 'aktif' : 'pindah');
        $snapshot = '1A';
        $stmt = $db->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->bind_param('issisdds', $yearId, $nis, $level, $classId, $snapshot, $spp, $komite, $status);
        $stmt->execute(); $placementId = (int)$db->insert_id; $stmt->close();
        $amount = 1000000.0;
        $stmt = $db->prepare('INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,?)');
        $stmt->bind_param('iisssdd', $yearId, $placementId, $nis, $level, $label, $amount, $amount);
        $stmt->execute(); $bills[$label] = (int)$db->insert_id; $stmt->close();
    }
    return $bills;
}

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
    fwrite(STDERR, "SKIPPED: jalankan hanya pada database disposable.\n");
    exit(0);
}

$baseUrl = getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8097';
$students = [(string)random_int(9500000000, 9599999999), (string)random_int(9500000000, 9599999999), (string)random_int(9500000000, 9599999999)];
$failure = null;
try {
    $current = du_current_academic_year();
    $currentStart = (int)substr($current, 0, 4);
    $previous = ($currentStart - 1) . '/' . $currentStart;
    $yearIds = [$previous=>du_http_year_id($koneksi, $previous), $current=>du_http_year_id($koneksi, $current)];
    $classId = (int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND is_placeholder=0 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc()['id'];
    du_http_assert($classId > 0, 'Rombel kelas 1 tidak tersedia.');
    $studentBills = du_http_student($koneksi, $students[0], 'UJI DU LINTAS TAHUN', $classId, $yearIds);
    $otherBills = du_http_student($koneksi, $students[1], 'UJI DU PEMILIK LAIN', $classId, [$previous=>$yearIds[$previous]]);
    $graduateBills = du_http_student($koneksi, $students[2], 'UJI DU LULUSAN', $classId, [$previous=>$yearIds[$previous]], true);
    $stmtPlacement=$koneksi->prepare('SELECT id FROM siswa_tahun_ajaran WHERE no_induk=? AND tahun_ajaran_id=?');
    $stmtPlacement->bind_param('si',$students[0],$yearIds[$current]);$stmtPlacement->execute();$placementId=(int)$stmtPlacement->get_result()->fetch_assoc()['id'];$stmtPlacement->close();
    komite_sync_placement($koneksi,$placementId);
    $master=spp_master_ensure_year($koneksi,$current,true);
    $startYear=(string)$currentStart;$monthCode='07';$classLabel='1A';$rate=250000.0;$discount=0.0;$billStatus='open';$level=1;
    $stmtSpp=$koneksi->prepare('INSERT INTO tagihan_spp(master_spp_tahun_id,tahun_ajaran_id,penempatan_id,no_induk,tingkat_snapshot,master_kelas_id,kelas_rombel_snapshot,bulan,tahun,tarif_dasar_snapshot,potongan_persen_snapshot,potongan_nominal_snapshot,nominal_tagihan,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $masterId=(int)$master['id'];$yearId=$yearIds[$current];
    $stmtSpp->bind_param('iiisiisssdddds',$masterId,$yearId,$placementId,$students[0],$level,$classId,$classLabel,$monthCode,$startYear,$rate,$discount,$discount,$rate,$billStatus);
    $stmtSpp->execute();$stmtSpp->close();

    $cookies = [];
    $login = du_http_request($baseUrl . '/login.php', ['username'=>'admin','password'=>'admin123'], $cookies);
    du_http_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login administrator gagal.');
    $form = du_http_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    du_http_assert($form['status'] === 200 && str_contains($form['body'], 'name="tagihan_daftar_ulang_id"'), 'Kontrak ID tagihan belum tersedia pada form.');
    du_http_assert(str_contains($form['body'], 'du-selector-trigger') && str_contains($form['body'], 'LULUS · TA ' . $previous), 'Dropdown tunggakan atau label lulusan belum dirender.');

    $month = '07'; $calendarYear = (string)$currentStart;
    $submit = static function (string $nis, int $billId, float $du, float $spp = 0, float $komite=0) use ($baseUrl, &$cookies, $month, $calendarYear): array {
        return du_http_request($baseUrl . '/pembayaran/proses.php', [
            'aksi'=>'input', 'payment_plan'=>'monthly', 'no_induk'=>$nis,
            'bulan_bayar'=>$month, 'tahun_bayar'=>$calendarYear, 'sistem_pembayaran'=>'Tunai',
            'uang_spp'=>$spp, 'uang_komite'=>$komite, 'uang_du'=>$du, 'tagihan_daftar_ulang_id'=>$billId,
        ], $cookies);
    };

    du_http_assert($submit($students[0], $studentBills[$previous], 300000, 250000, 100000)['status'] === 302, 'Pembayaran gabungan DU lama, SPP, dan Komite berjalan gagal.');
    $stmt = $koneksi->prepare('SELECT b.id,b.U_SPP,bd.tagihan_daftar_ulang_id,bd.th_ajaran,bd.kelas FROM bayar b JOIN bayar_du bd ON bd.bayar_id=b.id WHERE b.NO_INDUK=? ORDER BY b.id DESC LIMIT 1');
    $stmt->bind_param('s', $students[0]); $stmt->execute(); $payment = $stmt->get_result()->fetch_assoc(); $stmt->close();
    du_http_assert($payment && (int)$payment['tagihan_daftar_ulang_id'] === $studentBills[$previous] && $payment['th_ajaran'] === $previous && $payment['kelas'] === '1', 'Snapshot DU tidak disalin dari tagihan terpilih.');
    $receipt = du_http_request($baseUrl . '/laporan/cetak_struk.php?id=' . (int)$payment['id'], [], $cookies);
    du_http_assert($receipt['status'] === 200 && str_contains($receipt['body'], 'Uang Daftar Ulang (TA ' . $previous . ')'), 'Struk tidak menampilkan tahun tagihan Daftar Ulang.');

    du_http_assert($submit($students[1], 0, 100000)['status'] === 302 && str_contains(du_http_flash($baseUrl, $cookies), 'Pilih ulang tagihan'), 'Submit DU tanpa ID tidak ditolak.');
    du_http_assert($submit($students[1], $studentBills[$previous], 100000)['status'] === 302 && str_contains(du_http_flash($baseUrl, $cookies), 'tidak ditemukan untuk siswa'), 'ID tagihan milik siswa lain tidak ditolak.');

    $edit = du_http_request($baseUrl . '/pembayaran/edit.php?id=' . (int)$payment['id'], [], $cookies);
    du_http_assert(preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $edit['body'], $tokenMatch) === 1, 'Token CSRF edit tidak ditemukan.');
    $move = du_http_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'update', 'id'=>(int)$payment['id'], 'csrf_token'=>$tokenMatch[1], 'no_induk'=>$students[0],
        'tanggal_bayar'=>date('Y-m-d H:i:s'), 'bulan_bayar'=>$month, 'tahun_bayar'=>$calendarYear,
        'sistem_pembayaran'=>'Tunai', 'uang_spp'=>250000, 'uang_komite'=>100000, 'uang_du'=>300000,
        'tagihan_daftar_ulang_id'=>$studentBills[$current],
    ], $cookies);
    du_http_assert($move['status'] === 302, 'Pemindahan tagihan saat edit tidak selesai.');
    $moved = $koneksi->query('SELECT tagihan_daftar_ulang_id,th_ajaran FROM bayar_du WHERE bayar_id=' . (int)$payment['id'])->fetch_assoc();
    du_http_assert($moved && (int)$moved['tagihan_daftar_ulang_id'] === $studentBills[$current] && $moved['th_ajaran'] === $current, 'Relasi DU tidak berpindah ke tagihan tujuan: ' . du_http_flash($baseUrl, $cookies));

    du_http_assert($submit($students[2], $graduateBills[$previous], 100000)['status'] === 302, 'Lulusan tidak dapat melunasi tunggakan DU.');
    du_http_assert($submit($students[2], $graduateBills[$previous], 100000, 250000)['status'] === 302, 'Request campuran lulusan tidak mengembalikan redirect.');
    du_http_assert(str_contains(du_http_flash($baseUrl, $cookies), 'Tagihan Komite'), 'Backend tidak menolak SPP lulusan tanpa tagihan bulanan.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    foreach ($students as $nis) {
        $stmt = $koneksi->prepare('DELETE FROM bayar WHERE NO_INDUK=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM tagihan_daftar_ulang WHERE no_induk=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM tagihan_spp WHERE no_induk=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM tagihan_komite WHERE no_induk=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM siswa_tahun_ajaran WHERE no_induk=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
    }
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: pembayaran DU lintas tahun, validasi ID, pemindahan edit, struk, dan batas titipan lulusan tervalidasi.\n";
