<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_activation.php';require_once __DIR__.'/../includes/reports.php';
function la_assert($condition,$message){if(!$condition)throw new RuntimeException($message);}
$nis='0000000099';$ids=[];$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
foreach([1=>1,2=>7,3=>10] as $unit=>$level){
    $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);
    $exists=$koneksi->query("SELECT id FROM siswa WHERE NO_INDUK='$nis'")->fetch_row();
    if(!$exists){$name='Audit Legacy '.$unit;$s=$koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active,legacy_pending) VALUES(?,?,'LEGACY',0,1)");$s->bind_param('ss',$nis,$name);$s->execute();$id=(int)$koneksi->insert_id;
        $raw=json_encode(['NO_INDUK'=>$nis,'NAMA'=>$name,'KELAS'=>'Tidak diketahui']);$hash=str_repeat((string)$unit,64);$s=$koneksi->prepare("INSERT INTO legacy_student_import(student_id,unit_id,source_hash,source_row,source_name,source_class,raw_data,imported_by) VALUES(?,?,?,1,'TEST.dat','Tidak diketahui',?,1)");$s->bind_param('iiss',$id,$unit,$hash,$raw);$s->execute();
    }else $id=(int)$exists[0];$ids[$unit]=$id;
    $student=$koneksi->query('SELECT * FROM siswa WHERE id='.$id)->fetch_assoc();
    if($student['legacy_pending']){
        foreach(["INSERT INTO tabungan(NO_INDUK,SALDO) VALUES('$nis',0)","INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas) SELECT id,'$nis','$level' FROM tahun_ajaran LIMIT 1"] as $sql){$blocked=false;try{$koneksi->query($sql);}catch(mysqli_sql_exception $e){$blocked=true;}la_assert($blocked,'Pending identity accepted an operational child');}
        $class=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=$level AND kode_rombel='A' AND is_active=1 LIMIT 1")->fetch_row()[0];
        $year=$koneksi->query("SELECT id,label FROM tahun_ajaran WHERE label='2026/2027' LIMIT 1")->fetch_assoc();
        $rate=spp_current_effective_rate($koneksi,(string)$level,0,$year['label']);
        $fees=['spp'=>$rate['net'],'psb'=>100000*$unit,'komite'=>10000*$unit,'du'=>50000*$unit];
        $before=[];foreach(['tagihan_spp','tagihan_komite','tagihan_daftar_ulang','tagihan_tahunan_siswa','tabungan','bayar'] as $table)$before[$table]=(int)$koneksi->query('SELECT COUNT(*) FROM '.$table)->fetch_row()[0];
        legacy_activate($koneksi,$id,$class,(int)$year['id'],$fees,$actor,'Audit');
        foreach($before as $table=>$count)la_assert((int)$koneksi->query('SELECT COUNT(*) FROM '.$table)->fetch_row()[0]===$count,'Activation created '.$table);
        $replay=false;try{legacy_activate($koneksi,$id,$class,(int)$year['id'],$fees,$actor,'Audit');}catch(RuntimeException $e){$replay=true;}la_assert($replay,'Activation replay was accepted');
        la_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$nis'")->fetch_row()[0]===1,'Placement duplicated');
        $koneksi->query("INSERT INTO tabungan(NO_INDUK,SALDO) VALUES('$nis',0)");
    }
    echo 'PASS: unit '.$unit.' pending guard, activation, no automatic bills/accounts, replay and first placement'.PHP_EOL;
}
unit_set_context($koneksi,0);$_SESSION['active_unit_id']=0;
la_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa WHERE NO_INDUK='$nis'")->fetch_row()[0]===3,'NIS per unit did not preserve three identities');
$ambiguous=false;try{unit_resolve_student($koneksi,$nis);}catch(RuntimeException $e){$ambiguous=true;}la_assert($ambiguous,'Ambiguous NIS selected a student');
unit_set_context($koneksi,0);$rows=report_build($koneksi,'saldo-tabungan',report_filters($koneksi,['siswa_status'=>'active','q'=>$nis]))['rows'];la_assert(count($rows)===3,'Combined report merged equal NIS');
echo "PASS: leading zero, same NIS in three units, ambiguous URL guard, combined report identities\n";
