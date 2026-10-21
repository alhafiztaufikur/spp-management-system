<?php
session_start();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
function history_fail(int $status,string $code,string $message): void {http_response_code($status);echo json_encode(['ok'=>false,'code'=>$code,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');history_fail(405,'method_not_allowed','Gunakan GET untuk membaca riwayat.');}
if(empty($_SESSION['admin_id']))history_fail(401,'session_expired','Sesi habis. Silakan masuk kembali.');
require_once '../koneksi.php';require_once '../includes/auth.php';require_once '../includes/reports.php';require_once '../includes/payment_history_context.php';require_once '../includes/payment_activity.php';require_once '../includes/payment_permissions.php';
if(empty($_SESSION['admin_id']))history_fail(401,'session_expired','Sesi habis. Silakan masuk kembali.');
if(!hasRole(['admin']))history_fail(403,'access_denied','Akun tidak dapat membuka riwayat pembayaran.');
requireRole(['admin']);
$nis=$_GET['no_induk']??'';$studentId=$_GET['student_id']??'0';$unit=$_GET['unit_id']??null;$scope=$_GET['scope']??'active';
if(!is_string($nis)||strlen($nis)>10||!is_string($studentId)||!preg_match('/^[0-9]{1,10}$/D',$studentId)||!is_string($scope)||!preg_match('~^(active|all|unknown|[0-9]{4}/[0-9]{4})$~D',$scope))history_fail(400,'invalid_request','Parameter riwayat tidak valid. Pilih ulang siswa.');
if($unit!==null&&(!is_string($unit)||!preg_match('/^[0-3]$/D',$unit)))history_fail(400,'invalid_request','Parameter unit tidak valid.');
if($unit!==null&&(int)$unit!==unit_active_id())history_fail(409,'unit_changed','Unit formulir berubah. Muat ulang Input Pembayaran.');
$nis=trim($nis);session_write_close();
if($nis===''){echo json_encode(['ok'=>true,'period'=>'Pilih siswa untuk melihat transaksi.','rows'=>[],'scopes'=>[],'scope'=>'active']);exit;}
try {
    try{$student=unit_resolve_student($koneksi,$nis,(int)$studentId);}catch(mysqli_sql_exception $e){throw $e;}catch(RuntimeException $e){history_fail(409,'student_scope_mismatch','Siswa tidak tersedia atau tidak sesuai unit. Pilih ulang siswa.');}
    lb_scope_student_read($koneksi,$nis);
    $context=payment_history_current_context($koneksi,$nis);
    $s=$koneksi->prepare('SELECT DISTINCT riwayat_tahun_ajaran FROM bayar WHERE NO_INDUK=? ORDER BY riwayat_tahun_ajaran DESC');$s->bind_param('s',$nis);$s->execute();$years=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $scopes=[['value'=>'active','label'=>'Penempatan Aktif: '.$context['year'].' · '.$context['class']],['value'=>'all','label'=>'Semua Tahun']];
    foreach($years as $y)if($y['riwayat_tahun_ajaran']!==null)$scopes[]=['value'=>$y['riwayat_tahun_ajaran'],'label'=>'TA '.$y['riwayat_tahun_ajaran']];
    $scopes[]=['value'=>'unknown','label'=>'Riwayat Lama: Konteks Belum Tercatat'];
    $year=$scope==='active'?$context['year']:$scope;
    $where=$scope==='all'?'':($scope==='unknown'?' AND b.riwayat_tahun_ajaran IS NULL':' AND b.riwayat_tahun_ajaran=?');
    $s=$koneksi->prepare("SELECT b.*,a.nama operator_nama,COALESCE(du.total,0) du_total,du.years du_years FROM bayar b
      LEFT JOIN admin a ON b.user_id REGEXP '^[1-9][0-9]*$' AND a.id=CAST(b.user_id AS UNSIGNED)
      LEFT JOIN (SELECT bayar_id,SUM(jumlah) total,GROUP_CONCAT(DISTINCT th_ajaran ORDER BY th_ajaran SEPARATOR ', ') years FROM bayar_du GROUP BY bayar_id) du ON du.bayar_id=b.id
      WHERE b.NO_INDUK=?$where ORDER BY b.TGL_BYR DESC,b.id DESC");
    if($scope==='all'||$scope==='unknown')$s->bind_param('s',$nis);else $s->bind_param('ss',$nis,$year);
    $s->execute();$payments=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $events=payment_activity_for_payments($koneksi,array_column($payments,'id'));$rows=[];
    foreach($payments as $p){
        $components=[];foreach(['U_SPP'=>'SPP','U_PSB'=>'PSB','U_KOMITE'=>'Komite'] as $key=>$label)if((float)$p[$key]>.001)$components[]=$label;
        if((float)$p['U_LAIN']-(float)$p['du_total']>.001)$components[]='Biaya Lain';
        if((float)$p['du_total']>.001)$components[]='Daftar Ulang (TA '.$p['du_years'].')';
        $creator=payment_activity_summary($events[(int)$p['id']]??[])['creator'];
        $caps=payment_capabilities($koneksi,$p,$events[(int)$p['id']]??[],false,[]);
        $rows[]=['id'=>(int)$p['id'],'unit_id'=>(int)$p['unit_id'],'tanggal_raw'=>$p['TGL_BYR'],'period_key'=>(int)$p['TAHUN']*100+report_month_code((string)$p['BULAN']),'total_raw'=>(float)$p['total_jumlah'],'components'=>$components,'can_detail'=>true,'can_print'=>$caps['can_print'],'detail_url'=>'detail.php?'.http_build_query(['id'=>(int)$p['id'],'unit_id'=>(int)$p['unit_id'],'readonly'=>'1']),'print_url'=>$caps['can_print']?'../laporan/cetak_struk.php?id='.(int)$p['id']:null,'time_precision'=>'datetime','tanggal_date'=>spp_date_parts($p['TGL_BYR'])['date'],'tanggal_time'=>spp_date_parts($p['TGL_BYR'])['time'],'tanggal'=>spp_date_label($p['TGL_BYR'],true),'periode'=>(report_months()[report_month_code((string)$p['BULAN'])]??$p['BULAN']).' '.$p['TAHUN'],
          'komponen'=>$components?implode(', ',$components):'Koreksi','metode'=>$p['sistem_pembayaran'],'operator'=>($creator['actor_name']??'')?:($p['operator_nama']?:'Tidak tercatat'),
          'total'=>'Rp '.number_format((float)$p['total_jumlah'],0,',','.'),'context_year'=>$p['riwayat_tahun_ajaran'],'context_class'=>$p['riwayat_kelas_snapshot']];
    }
    require_once '../includes/migrated_history.php';
    $legacyIds=mh_student_legacy_ids($koneksi,$student);$legacyCount=count($legacyIds);
    $legacy=in_array($scope,['all','unknown'],true)?mh_student_legacy_history($koneksi,$student,$legacyIds):[];
    if($scope==='all'||$scope==='unknown')$rows=array_merge($rows,$legacy);
    usort($rows,static fn($a,$b)=>strcmp((string)$b['tanggal_raw'],(string)$a['tanggal_raw'])?:($b['id']<=>$a['id']));
    foreach($scopes as &$historyScope)if($historyScope['value']==='unknown')$historyScope['label']='Riwayat Lama / Legacy: '.$legacyCount.' transaksi Legacy';unset($historyScope);
    echo json_encode(['ok'=>true,'period'=>'History pembayaran '.$student['NAMA'].': '.count($rows).' transaksi · '.($scope==='all'?'Semua Tahun':($scope==='unknown'?'Konteks belum tercatat':'TA '.$year)),
      'rows'=>$rows,'scopes'=>$scopes,'scope'=>$scope,'legacy_count'=>$legacyCount,'active_context'=>$context,'student_id'=>(int)$student['id'],'unit_id'=>(int)$student['unit_id']],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){error_log('History siswa gagal: '.get_class($e).' code='.(int)$e->getCode().' unit='.unit_active_id());history_fail(503,'history_unavailable','Riwayat pembayaran belum dapat dimuat. Silakan coba lagi.');}
