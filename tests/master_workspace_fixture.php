<?php
/** Source assertions and isolated sessions for the presentation audit. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/daftar_ulang.php';
unit_set_context($koneksi, 0);
if (DB_NAME !== $koneksi->query('SELECT DATABASE()')->fetch_row()[0]) exit(1);
$dir = (string)getenv('SPP_QA_DIR');
if (!$dir || !is_dir($dir)) throw new RuntimeException('Artifact directory required.');
if (in_array('--cleanup', $argv, true)) {
    $data = json_decode(file_get_contents($dir.'/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($data['closed_ids'] ?? [] as $id) {
        $stmt = $koneksi->prepare("DELETE FROM tahun_ajaran WHERE id=? AND label=? AND status='closed'");
        $stmt->bind_param('is', $id, $data['closed_year']); $stmt->execute(); $stmt->close();
    }
    echo "OK: temporary closed years removed from clone.\n";
    exit;
}
$super = (int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
if (!$super) throw new RuntimeException('Active Super Admin required.');
$data = ['database'=>DB_NAME, 'units'=>[], 'roles'=>[], 'closed_ids'=>[], 'year'=>du_current_academic_year()];
for ($y=2170;$y<2190;$y++) {
    $label=$y.'/'.($y+1);
    if ((int)$koneksi->query("SELECT COUNT(*) FROM tahun_ajaran WHERE label='$label'")->fetch_row()[0]===0) {
        $data['closed_year']=$label; $data['draft_year']=($y+1).'/'.($y+2); break;
    }
}
function mw_cookie(int $id, int $unit): string {
    session_id('masterworkspace'.bin2hex(random_bytes(12))); session_start();
    $_SESSION=['admin_id'=>$id,'active_unit_id'=>$unit]; $cookie=session_id(); session_write_close(); return $cookie;
}
foreach ([1,2,3,0] as $unit) {
    $_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>$unit]; unit_set_context($koneksi,$unit);
    $rows=$koneksi->query('SELECT id,NO_INDUK FROM siswa WHERE is_active=1 AND legacy_pending=0 ORDER BY CAST(KELAS AS UNSIGNED),KELAS,NAMA')->fetch_all(MYSQLI_ASSOC);
    $edit=$koneksi->query("SELECT id FROM siswa WHERE is_active=1 AND legacy_pending=0 AND NO_INDUK REGEXP '^[0-9]{1,10}$' ORDER BY id LIMIT 1")->fetch_assoc();
    $data['units'][$unit]=['cookie'=>mw_cookie($super,$unit),'students'=>count($rows),'edit_id'=>(int)($edit['id']??0)];
    if (!$unit) continue;
    [$first,$last]=unit_level_bounds($unit); $data['units'][$unit]['first']=$first; $data['units'][$unit]['last']=$last;
    $active=$koneksi->query("SELECT KELAS,COUNT(*) n FROM siswa WHERE is_active=1 AND CAST(KELAS AS UNSIGNED) BETWEEN $first AND $last GROUP BY KELAS")->fetch_all(MYSQLI_ASSOC);
    $data['units'][$unit]['counts']=array_column($active,'n','KELAS');
    $year=$koneksi->query("SELECT id,status FROM tahun_ajaran WHERE label='".$data['year']."'")->fetch_assoc();
    $yearId=(int)($year['id']??0); $data['units'][$unit]['status']=$year['status']??'draft';
    $rates=$koneksi->query("SELECT kelas,Jumlah FROM daftar_ulang WHERE tahun_ajaran_id=$yearId")->fetch_all(MYSQLI_ASSOC);
    $data['units'][$unit]['rates']=array_column($rates,'Jumlah','kelas');
    $begin=substr($data['closed_year'],0,4).'-07-01'; $end=substr($data['closed_year'],5,4).'-06-30';
    $stmt=$koneksi->prepare("INSERT INTO tahun_ajaran (label,tanggal_mulai,tanggal_selesai,status) VALUES (?,?,?,'closed')");
    $stmt->bind_param('sss',$data['closed_year'],$begin,$end);$stmt->execute();$data['closed_ids'][]=$koneksi->insert_id;$stmt->close();
    file_put_contents($dir.'/fixture.json',json_encode($data,JSON_PRETTY_PRINT));
}
unit_set_context($koneksi,1);
foreach (['admin','kasir','bendahara'] as $role) {
    $id=(int)$koneksi->query("SELECT id FROM admin WHERE role='$role' AND is_active=1 LIMIT 1")->fetch_row()[0];
    if ($id) $data['roles'][$role]=mw_cookie($id,1);
}
file_put_contents($dir.'/fixture.json',json_encode($data,JSON_PRETTY_PRINT));
echo "OK: source counts/rates, four unit sessions, and temporary closed years on clone.\n";
