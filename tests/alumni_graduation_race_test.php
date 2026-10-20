<?php
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58460||!str_contains($koneksi->query('SELECT @@datadir')->fetch_row()[0],'master_audit_20261021'))exit(1);
require_once __DIR__.'/../includes/kelas.php';
$_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']='2032/2033';
if(($argv[1]??'')==='child'){
 $_SESSION=['admin_id'=>(int)$argv[2],'admin_role'=>$argv[3],'active_unit_id'=>1];unit_set_context($koneksi,1);
 $ctx=promotion_context($koneksi,(int)$argv[5]);
 echo json_encode(promotion_run_batch($koneksi,[$argv[4]],[],$ctx['source_year_id'],$ctx['target_year'],6,[$argv[4]=>(int)$argv[6]]),JSON_THROW_ON_ERROR);exit;
}
$qa=getenv('SPP_MASTER_QA_DIR');if(!$qa||!is_dir($qa))exit(1);
unit_set_context($koneksi,1);
$password=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
$s=$koneksi->prepare("INSERT IGNORE INTO admin(username,password,nama,role,unit_id,is_active) VALUES('qa-alumni-race',?,'QA Graduation Operator','admin',NULL,1)");$s->bind_param('s',$password);$s->execute();$s->close();
$actors=$koneksi->query("SELECT id,role FROM admin WHERE is_active=1 ORDER BY role='super_admin' DESC,id LIMIT 2")->fetch_all(MYSQLI_ASSOC);if(count($actors)!==2)throw new RuntimeException('Two fixture actors required.');
$_SESSION=['admin_id'=>(int)$actors[0]['id'],'admin_role'=>$actors[0]['role'],'active_unit_id'=>1];
$class=$koneksi->query('SELECT id FROM master_kelas WHERE tingkat=6 AND is_active=1 AND is_placeholder=0 LIMIT 1')->fetch_row()[0];$year=class_ensure_academic_year($koneksi,'2032/2033');
$students=[];foreach([1,2,3,4] as $number){$nis='09'.random_int(10000000,99999999);$name='QA Graduation Race '.$number;
 $s=$koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,?,'6',?,1)");$s->bind_param('ssi',$nis,$name,$class);$s->execute();$s->close();
 $s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,'6',?,'6A','aktif')");$s->bind_param('isi',$year,$nis,$class);$s->execute();$students[]=['nis'=>$nis,'placement'=>(int)$koneksi->insert_id];$s->close();
}
$run=static function(array $targets,string $label)use($actors,$qa,$year):array {
 $processes=[];foreach($targets as $i=>$student){$out=$qa.'/'.$label.'-'.$i.'.json';$err=$qa.'/'.$label.'-'.$i.'.err';
  $processes[]=proc_open([PHP_BINARY,__FILE__,'child',(string)$actors[$i]['id'],$actors[$i]['role'],$student['nis'],(string)$year,(string)$student['placement']],[0=>['pipe','r'],1=>['file',$out,'w'],2=>['file',$err,'w']],$pipes);fclose($pipes[0]);
 }$results=[];foreach($processes as $i=>$p){if(proc_close($p)!==0)throw new RuntimeException('Concurrent worker failed. Inspect private logs.');$results[]=json_decode(file_get_contents($qa.'/'.$label.'-'.$i.'.json'),true,512,JSON_THROW_ON_ERROR);}return $results;
};
$results=$run([$students[0],$students[1]],'alumni-create-race');
foreach($results as $r)if(count($r['successes'])!==1)throw new RuntimeException('Concurrent graduation failed.');
if($results[0]['successes'][0]['alumni_class_id']!==$results[1]['successes'][0]['alumni_class_id'])throw new RuntimeException('Duplicate Alumni class.');
$results=$run([$students[2],$students[2]],'alumni-replay-race');
if(array_sum(array_map(static fn($r)=>count($r['successes']),$results))!==1)throw new RuntimeException('Concurrent replay graduated twice.');
$nis=$students[3]['nis'];
$koneksi->query("CREATE TRIGGER qa_alumni_audit_failure BEFORE INSERT ON siswa_audit_log_data FOR EACH ROW BEGIN IF NEW.no_induk_snapshot='$nis' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA injected audit failure'; END IF; END");
try{
 $r=promotion_run_batch($koneksi,[$nis],[],$year,'2033/2034',6,[$nis=>$students[3]['placement']]);
 if($r['successes']||count($r['failures'])!==1)throw new RuntimeException('Audit failure was accepted.');
 $row=$koneksi->query("SELECT s.KELAS,s.is_active,p.status FROM siswa s JOIN siswa_tahun_ajaran p ON p.no_induk=s.NO_INDUK AND p.unit_id=s.unit_id WHERE s.NO_INDUK='$nis'")->fetch_assoc();
 if($row['KELAS']!=='6'||(int)$row['is_active']!==1||$row['status']!=='aktif')throw new RuntimeException('Failed audit did not roll back graduation.');
}finally{$koneksi->query('DROP TRIGGER qa_alumni_audit_failure');}
echo "OK: two actors reuse one Alumni class; concurrent replay graduates once; audit failure rolls back all changes.\n";
