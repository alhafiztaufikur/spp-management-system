<?php
require_once __DIR__ . '/../koneksi.php';

function student_psb_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function student_psb_request(string $url, array $data, array &$cookies): array {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name=>$value) $pairs[] = $name . '=' . $value;
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
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) $status=(int)$m[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $m)) $cookies[$m[1]]=$m[2];
    }
    return ['status'=>$status,'body'=>$body];
}

function student_psb_page(string $baseUrl, array &$cookies, string $query=''): array {
    return student_psb_request($baseUrl . '/siswa/daftar.php' . $query, [], $cookies);
}

function student_psb_csrf(string $html): string {
    if (!preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m)) {
        throw new RuntimeException('Token CSRF Master Siswa tidak ditemukan.');
    }
    return $m[1];
}

function student_psb_flash(string $html): string {
    if (!preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $html, $m)) return '';
    return trim(html_entity_decode(strip_tags($m[1])));
}

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
    fwrite(STDERR, "SKIPPED: jalankan hanya pada database disposable.\n");
    exit(0);
}

$baseUrl = getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8097';
$created = [];
try {
    $psbClass = (int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=0 AND kode_rombel='PSB' AND is_active=1 LIMIT 1")->fetch_assoc()['id'];
    $regularClass = (int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND is_placeholder=0 AND is_active=1 LIMIT 1")->fetch_assoc()['id'];
    student_psb_assert($psbClass > 0 && $regularClass > 0, 'Master kelas untuk tes tidak lengkap.');

    $cookies=[];
    $login=student_psb_request($baseUrl . '/login.php',['username'=>'admin','password'=>'admin123'],$cookies);
    student_psb_assert($login['status']===302 && isset($cookies['PHPSESSID']), 'Login admin gagal.');
    $page=student_psb_page($baseUrl,$cookies);
    student_psb_assert($page['status']===200,'Master Siswa tidak dapat dibuka.');
    $csrf=student_psb_csrf($page['body']);

    $base = static function(string $nis,string $name,int $classId,float $pangkal,float $psb,string $token): array {
        return [
            'aksi'=>'tambah','csrf_token'=>$token,'no_induk'=>$nis,'nama'=>$name,
            'master_kelas_id'=>$classId,'advanced_enabled'=>'1','spp_perbulan'=>250000,
            'pangkal'=>$pangkal,'psb'=>$psb,'pomg'=>100000,'daftar_ulang'=>1000000,
            'potong_pangkal'=>0,'potong_du'=>0,
        ];
    };

    $nonPsbNis=(string)random_int(9700000000,9799999999);
    $response=student_psb_request($baseUrl . '/siswa/daftar.php',$base($nonPsbNis,'UJI NON PSB',$regularClass,1000,3600000,$csrf),$cookies);
    student_psb_assert($response['status']===302,'PSB pada siswa reguler tidak ditolak.');
    $page=student_psb_page($baseUrl,$cookies);
    student_psb_assert(str_contains(student_psb_flash($page['body']),'hanya dapat diatur'),'Pesan penolakan PSB siswa reguler tidak tepat.');
    student_psb_assert((int)$koneksi->query("SELECT COUNT(*) total FROM siswa WHERE NO_INDUK='$nonPsbNis'")->fetch_assoc()['total']===0,'Siswa reguler dengan PSB tetap tersimpan.');

    $zeroNis=(string)random_int(9700000000,9799999999);
    $response=student_psb_request($baseUrl . '/siswa/daftar.php',$base($zeroNis,'UJI PSB NOL',$psbClass,1000,0,$csrf),$cookies);
    student_psb_assert($response['status']===302,'Siswa PSB tanpa nominal tidak ditolak.');
    $page=student_psb_page($baseUrl,$cookies);
    student_psb_assert(str_contains(student_psb_flash($page['body']),'wajib diisi'),'Pesan PSB wajib tidak tepat.');

    $psbNis=(string)random_int(9700000000,9799999999); $created[]=$psbNis;
    $response=student_psb_request($baseUrl . '/siswa/daftar.php',$base($psbNis,'UJI MASTER PSB',$psbClass,2000,3600000,$csrf),$cookies);
    student_psb_assert($response['status']===302,'Siswa PSB valid tidak tersimpan.');
    $student=$koneksi->query("SELECT id,asal_psb,PSB FROM siswa WHERE NO_INDUK='$psbNis'")->fetch_assoc();
    student_psb_assert($student && (int)$student['asal_psb']===1 && abs((float)$student['PSB']-3600000)<.001,'Penanda atau nominal PSB tidak tersimpan.');

    $paymentDate=date('Y-m-d H:i:s');$month=date('m');$year=date('Y');$psbPaid=1000000.0;$pangkalPaid=1000.0;$total=$psbPaid+$pangkalPaid;
    $stmt=$koneksi->prepare('INSERT INTO bayar(NO_INDUK,KELAS,U_PANGKAL,U_PSB,TGL_BYR,BULAN,TAHUN,total_jumlah,payment_link_version) VALUES(?,?,?,?,?,?,?,?,1)');
    $class='0';$stmt->bind_param('ssddsssd',$psbNis,$class,$pangkalPaid,$psbPaid,$paymentDate,$month,$year,$total);$stmt->execute();$stmt->close();

    $page=student_psb_page($baseUrl,$cookies,'?edit='.(int)$student['id']);$csrf=student_psb_csrf($page['body']);
    $update=$base($psbNis,'UJI MASTER PSB',$regularClass,2000,900000,$csrf);
    $update['aksi']='update';$update['id']=(int)$student['id'];$update['asal_psb']=0;
    $response=student_psb_request($baseUrl . '/siswa/daftar.php',$update,$cookies);
    student_psb_assert($response['status']===302,'Penurunan PSB tidak ditolak.');
    $page=student_psb_page($baseUrl,$cookies,'?edit='.(int)$student['id']);
    student_psb_assert(str_contains(student_psb_flash($page['body']),'lebih kecil dari yang sudah dibayar'),'Batas nominal PSB terbayar tidak ditegakkan.');
    $stored=$koneksi->query('SELECT asal_psb,PSB FROM siswa WHERE id='.(int)$student['id'])->fetch_assoc();
    student_psb_assert((int)$stored['asal_psb']===1 && abs((float)$stored['PSB']-3600000)<.001,'Penolakan update merusak penanda/nominal PSB.');

    $csrf=student_psb_csrf($page['body']);
    $legacy=$base($psbNis,'UJI MASTER PSB',$regularClass,2000,3600000,$csrf);
    $legacy['aksi']='update';$legacy['id']=(int)$student['id'];$legacy['bangunan']=100;
    student_psb_request($baseUrl . '/siswa/daftar.php',$legacy,$cookies);
    $page=student_psb_page($baseUrl,$cookies,'?edit='.(int)$student['id']);
    student_psb_assert(str_contains(student_psb_flash($page['body']),'sudah tidak didukung'),'Request komponen lama tidak ditolak.');

    echo "OK: aturan Master Siswa PSB, penanda permanen, batas cicilan, dan penolakan field lama tervalidasi.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'FAILED: '.$error->getMessage().PHP_EOL);exit(1);
} finally {
    foreach($created as $nis){$stmt=$koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');$stmt->bind_param('s',$nis);$stmt->execute();$stmt->close();}
}
