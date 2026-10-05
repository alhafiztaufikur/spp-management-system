<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_/',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/kelas.php';
$root=(string)getenv('SPP_PROMOTION_ARTIFACTS');if(!is_dir($root))throw new RuntimeException('Private artifacts required');
$sourceYear=(string)(getenv('SPP_TEST_PROMOTION_SOURCE_YEAR')?:'2096/2097');$prefix='00'.(string)random_int(8700000,8799999);
$map=[];foreach([1,2,3] as $unit){
 $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);[$first,$last]=unit_level_bounds();$year=class_ensure_academic_year($koneksi,$sourceYear);
 $students=[];foreach(['junior'=>$first,'a'=>$last-1,'b'=>$last-1,'senior'=>$last] as $key=>$level){
  $nis=$prefix.['junior'=>'1','a'=>'2','b'=>'3','senior'=>'4'][$key];
  if($koneksi->query("SELECT id FROM siswa WHERE NO_INDUK='$nis'")->num_rows)throw new RuntimeException('Fixture exists');
  $class=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=$level AND kode_rombel='A' AND is_active=1")->fetch_row()[0];$grade=(string)$level;$name='BROWSER FLEX '.$unit.' '.$key;
  $s=$koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,250000,10000,1)");$s->bind_param('sssi',$nis,$name,$grade,$class);$s->execute();$id=(int)$koneksi->insert_id;$s->close();
  $label=$level.'A';$s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,250000,10000,'aktif')");$s->bind_param('issis',$year,$nis,$grade,$class,$label);$s->execute();$p=(int)$koneksi->insert_id;$s->close();
  $students[$key]=['nis'=>$nis,'id'=>$id,'placement'=>$p,'level'=>$level];
 }$map[$unit]=['year_id'=>$year,'source_year'=>$sourceYear,'first'=>$first,'last'=>$last,'students'=>$students];
}
file_put_contents($root.'/promotion-ui-fixture.json',json_encode($map));echo "Prepared browser fixture in three units, equal NIS and explicit source history\n";

