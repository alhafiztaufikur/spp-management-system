<?php
/** Private HTTP/browser fixtures, never available through HTTP or a user's MySQL. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
$qa=getenv('SPP_MASTER_QA_DIR');
if(!$qa||!is_dir($qa)||str_starts_with(strtolower(realpath($qa)),strtolower(realpath(__DIR__.'/..')))||!str_contains($koneksi->query('SELECT @@datadir')->fetch_row()[0],'master_audit_20261021')||(int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58460)throw new RuntimeException('Owned private test instance required.');
require_once __DIR__.'/../includes/student_alumni.php';require_once __DIR__.'/../includes/special_classes.php';require_once __DIR__.'/../includes/parent_letter_drafts.php';
$actor=$koneksi->query("SELECT id,nama FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
$_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>'super_admin','active_unit_id'=>1];
$fixture=['base'=>'http://127.0.0.1:58461','database'=>DB_NAME,'port'=>58460,'actor'=>(int)$actor['id'],'units'=>[]];
foreach([1,2,3] as $unit){
 $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);[, $last]=unit_level_bounds();
 $class=$koneksi->query("SELECT * FROM master_kelas WHERE tingkat=$last AND is_active=1 AND is_placeholder=0 LIMIT 1")->fetch_assoc();
 $nis='980000000'.$unit;$name='QA Master Graduate '.$unit;
 $s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,DAFTAR_ULANG,tot_du,is_active) VALUES(?,?,?,?,530000,25000,100,100,1)');$grade=(string)$last;$s->bind_param('sssi',$nis,$name,$grade,$class['id']);$s->execute();$id=(int)$koneksi->insert_id;$s->close();
 $year=class_ensure_academic_year($koneksi,du_current_academic_year());$label=class_label($class);
 $s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,530000,25000,'aktif')");$s->bind_param('issis',$year,$nis,$grade,$class['id'],$label);$s->execute();$placement=(int)$koneksi->insert_id;$s->close();
 $result=promotion_run_batch($koneksi,[$nis],[],$year,class_next_academic_year_label(du_current_academic_year()),$last,[$nis=>$placement]);
 if(count($result['successes'])!==1)throw new RuntimeException('Fixture graduation failed.');
 $fixture['units'][$unit]=['student_id'=>$id,'nis'=>$nis,'alumni'=>$result['successes'][0]['target'],'year'=>$year,'placement'=>$placement];
}
unit_set_context($koneksi,1);$_SESSION['active_unit_id']=1;
foreach([18,26,53] as $count)for($i=1;$i<=$count;$i++)special_class_save($koneksi,0,'QA Count'.$count.' '.str_pad((string)$i,3,'0',STR_PAD_LEFT),1);
$fixture['special_class']=(int)$koneksi->query("SELECT id FROM master_kelas WHERE nama_custom='QA Count53 001'")->fetch_row()[0];
$fixture['sessions']=[];
foreach([0,1,2,3] as $unit){
 session_id('masterqa'.$unit.bin2hex(random_bytes(12)));session_start();$_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>'super_admin','active_unit_id'=>$unit,'csrf_student'=>bin2hex(random_bytes(32)),'csrf_master_kelas'=>bin2hex(random_bytes(32))];
 $fixture['sessions'][$unit]=session_id();
 if($unit===1){
  $student=['unit_id'=>1,'nama'=>'Siswa Contoh PNG','nis'=>'9800000001','nis_diknas'=>'0012345678','kelas'=>'6A','items'=>[['komponen'=>'SPP','periode'=>'Oktober 2026','sisa'=>530000]],'total_tunggakan'=>530000];
  $single=parent_letter_draft_create(['students'=>[$student],'today'=>date('Y-m-d'),'unit'=>1]);
  parent_letter_draft_update($single,['1|9800000001'=>['format'=>'rich_text','html'=>'<p>Pesan <strong>khusus</strong> untuk orang tua.</p><ul><li>Mohon diperiksa.</li></ul>']]);
  $fixture['single_draft']=$single;
  $students=[];foreach([1,2] as $number){$copy=$student;$copy['nis']='980000000'.$number;$copy['nama']='Siswa Contoh PNG '.$number;$copy['items']=array_fill(0,38,['komponen'=>'Biaya pendidikan dan kegiatan siswa','periode'=>'TA 2026/2027','sisa'=>125000]);$copy['total_tunggakan']=4750000;$students[]=$copy;}
  $multi=parent_letter_draft_create(['students'=>$students,'today'=>date('Y-m-d'),'unit'=>1]);
  parent_letter_draft_update($multi,['1|9800000001'=>'Pesan penerima pertama.','1|9800000002'=>'Pesan penerima kedua.']);$fixture['multi_draft']=$multi;
 }
 session_write_close();
}
file_put_contents($qa.'/fixture.json',json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo "Private fixture and sessions ready.\n";
