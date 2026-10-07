<?php
/** Read-only fixture: model totals plus independent SQL; session artifacts stay outside the repository. */
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_(audit|test)_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/transaction_authorization.php';require_once __DIR__.'/../includes/registration_history.php';
$dir=(string)getenv('SPP_QA_DIR');if(!$dir||!is_dir($dir))throw new RuntimeException('Artifact directory required.');
unit_set_context($koneksi,0);$super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
function rw_cookie(int $actor,int $scope):string {session_id('registrationqa'.bin2hex(random_bytes(12)));session_start();$_SESSION=['admin_id'=>$actor,'active_unit_id'=>$scope];$cookie=session_id();session_write_close();return $cookie;}
$data=['database'=>DB_NAME,'units'=>[],'roles'=>[]];
foreach([1,2,3,0] as $unit){
    $_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
    $f=registration_filters($koneksi,[]);$f['per_page']=100;$model=registration_page($koneksi,$f);
    $sql="SELECT t.id tagihan_id,t.unit_id,t.nominal_tagihan total,COALESCE(SUM(d.jumlah),0) paid,GREATEST(0,t.nominal_tagihan-COALESCE(SUM(d.jumlah),0)) remaining FROM tagihan_daftar_ulang t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id LEFT JOIN bayar_du d ON d.tagihan_daftar_ulang_id=t.id AND d.unit_id=t.unit_id WHERE t.status='open' GROUP BY t.id,t.unit_id,t.nominal_tagihan";
    $expected=$koneksi->query($sql)->fetch_all(MYSQLI_ASSOC);
    if((int)$model['summary']['students']!==count($expected)||abs((float)$model['summary']['paid']-array_sum(array_column($expected,'paid')))>.001)throw new RuntimeException('Model/SQL mismatch.');
    $students=$koneksi->query("SELECT s.id,s.NO_INDUK,s.unit_id,s.master_kelas_id,COALESCE(k.tingkat,s.KELAS) tingkat FROM siswa s JOIN tagihan_daftar_ulang t ON t.no_induk=s.NO_INDUK AND t.unit_id=s.unit_id LEFT JOIN master_kelas k ON k.id=s.master_kelas_id WHERE t.status='open' ORDER BY s.id LIMIT 1")->fetch_assoc();
    if($students && isset($f['options']['kelas']['tingkat:'.$students['tingkat']])) {
        $selected=registration_filters($koneksi,['kelas'=>['tingkat:'.$students['tingkat'],'rombel:'.$students['master_kelas_id']], 'status'=>['lunas','cicilan']]);
        $selected['per_page']=100;$dedup=registration_page($koneksi,$selected);
        $baseFilter=registration_filters($koneksi,['kelas'=>'tingkat:'.$students['tingkat']]);$baseFilter['per_page']=100;
        $levelOnly=registration_page($koneksi,$baseFilter);
        if($dedup['summary']!==$levelOnly['summary'])throw new RuntimeException('Overlapping class filters duplicated or lost rows.');
        $byId=registration_filters($koneksi,['student_id'=>$students['id'],'q'=>'deliberately mismatched name']);$idResult=registration_page($koneksi,$byId);
        foreach($idResult['rows'] as $r)if($r['no_induk']!==$students['NO_INDUK']||(int)$r['unit_id']!==(int)$students['unit_id'])throw new RuntimeException('Student ID identity mismatch.');
    }
    $data['units'][$unit]=['cookie'=>rw_cookie($super,$unit),'summary'=>$model['summary'],'expected'=>$expected,'rows'=>$model['rows']];
}
unit_set_context($koneksi,1);
foreach(['admin','kasir','bendahara'] as $role){$id=(int)$koneksi->query("SELECT id FROM admin WHERE role='$role' AND is_active=1 AND unit_id=1 LIMIT 1")->fetch_row()[0];$data['roles'][$role]=['id'=>$id,'cookie'=>rw_cookie($id,1)];}
file_put_contents($dir.'/fixture.json',json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo "OK: model matched independent SQL, four scopes; isolated sessions generated.\n";
