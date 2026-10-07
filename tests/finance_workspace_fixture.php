<?php
/** Read-only database fixture; writes isolated PHP sessions and QA artifacts only. */
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';
unit_set_context($koneksi,0);
$dir=(string)getenv('SPP_QA_DIR');if(!$dir||!is_dir($dir))throw new RuntimeException('SPP_QA_DIR must be an existing artifact directory.');
function fw_session(int $id,int $unit):array{
    session_id('financeqa'.bin2hex(random_bytes(12)));session_start();$_SESSION=['admin_id'=>$id,'active_unit_id'=>$unit];$cookie=session_id();session_write_close();return ['PHPSESSID'=>$cookie];
}
$super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$result=['database'=>DB_NAME,'units'=>[],'roles'=>[]];
foreach([1,2,3,0] as $unit){
    unit_set_context($koneksi,$unit);
    $rows=$koneksi->query("SELECT b.id,b.total_jumlah,s.id student_id,s.NAMA,s.NO_INDUK FROM bayar b JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id WHERE b.TGL_BYR>='2026-10-01' AND b.TGL_BYR<'2026-10-08' ORDER BY b.TGL_BYR DESC,b.id DESC")->fetch_all(MYSQLI_ASSOC);
    $incoming=$koneksi->query("SELECT COUNT(*) n,COALESCE(SUM(t.MASUK),0) total FROM transaksi_m t JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id WHERE t.TANGGAL>='2026-10-01' AND t.TANGGAL<'2026-10-08'")->fetch_assoc();
    $outgoing=$koneksi->query("SELECT COUNT(*) n,COALESCE(SUM(t.KELUAR),0) total FROM transaksi_k t JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id WHERE t.TANGGAL>='2026-10-01' AND t.TANGGAL<'2026-10-08'")->fetch_assoc();
    $result['units'][$unit]=['cookies'=>fw_session($super,$unit),'rows'=>$rows,'payment_total'=>array_sum(array_column($rows,'total_jumlah')),'incoming'=>$incoming,'outgoing'=>$outgoing];
}
unit_set_context($koneksi,1);
require_once __DIR__.'/../includes/payment_activity.php';
foreach(['admin','bendahara','kasir'] as $role){
    $id=(int)$koneksi->query("SELECT id FROM admin WHERE role='$role' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_row()[0];
    $result['roles'][$role]=['cookies'=>fw_session($id,1),'id'=>$id];
}
file_put_contents($dir.'/fixture.json',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
echo "OK: read-only fixtures for four scopes and three unit roles.\n";
