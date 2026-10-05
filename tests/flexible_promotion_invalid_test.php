<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_/',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/kelas.php';
function pi_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$_SESSION['active_unit_id']=1;unit_set_context($koneksi,1);$_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']='2087/2088';$koneksi->begin_transaction();
try{
 $year=class_ensure_academic_year($koneksi,'2087/2088');$ctx=promotion_context($koneksi,$year);$from=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=5 AND kode_rombel='A'")->fetch_row()[0];$to=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=6 AND kode_rombel='A'")->fetch_row()[0];
 $foreign=(int)$koneksi->query("SELECT id FROM master_kelas_data WHERE unit_id=2 AND tingkat=7 AND kode_rombel='A'")->fetch_row()[0];
 $make=function(string $kind)use($koneksi,$year,$from):array{
  $nis='00'.(string)random_int(88000000,88999999);
  if($kind==='legacy')$koneksi->query("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active,legacy_pending) VALUES('$nis','TEST invalid Legacy','LEGACY',0,1)");
  elseif($kind==='psb')$koneksi->query("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active) VALUES('$nis','TEST invalid PSB','0',1)");
  else $koneksi->query("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES('$nis','TEST invalid source','5',$from,1)");
  $id=(int)$koneksi->insert_id;$p=0;
  if(!in_array($kind,['missing','legacy','psb'],true)){$koneksi->query("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES($year,'$nis','5',$from,'5A','aktif')");$p=(int)$koneksi->insert_id;}
  return [$nis,$id,$p];
 };
 foreach(['missing','legacy','psb','archived','current-class','source-rombel','newer','wrong-source-id','crossunit-target','same-level'] as $kind){
  [$nis,$id,$p]=$make($kind);
  if($kind==='archived')$koneksi->query("UPDATE siswa SET is_active=0 WHERE id=$id");
  if($kind==='current-class')$koneksi->query("UPDATE siswa SET KELAS='6',master_kelas_id=$to WHERE id=$id");
  if($kind==='source-rombel')$koneksi->query("UPDATE siswa_tahun_ajaran SET master_kelas_id=$to WHERE id=$p");
  if($kind==='newer'){$new=class_ensure_academic_year($koneksi,'2089/2090');$koneksi->query("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,status) VALUES($new,'$nis','5',$from,'aktif')");}
  $before=(int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$nis'")->fetch_row()[0];
  $dest=$kind==='crossunit-target'?$foreign:($kind==='same-level'?$from:$to);$expected=$kind==='wrong-source-id'?$p+100000:$p;
  $r=promotion_batch($koneksi,[$nis],[$nis=>$dest],$ctx,5,[$nis=>$expected]);
  pi_assert(!$r['successes']&&count($r['failures'])===1,'Invalid case accepted: '.$kind);
  pi_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$nis'")->fetch_row()[0]===$before,'Invalid case wrote history: '.$kind);
 }
 $blocked=false;unit_set_context($koneksi,0);$_SESSION['active_unit_id']=0;try{promotion_context($koneksi,$year);}catch(RuntimeException $e){$blocked=true;}pi_assert($blocked,'All-unit mutation allowed');
 echo "PASS: no source, Legacy/PSB/archive, mismatched active/source rombel, later history, forged source ID, wrong/same-level target and All unit held without writes\n";
}catch(Throwable $e){throw $e;}finally{$koneksi->rollback();unset($_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']);}

