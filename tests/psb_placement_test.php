<?php
if (PHP_SAPI!=='cli' || getenv('SPP_TEST_ALLOW_MUTATION')!=='1' || !preg_match('/^db_spp_(audit|test)_/', (string)getenv('SPP_DB_NAME'))) exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/psb_placement.php';
function psb_assert(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
unit_set_context($koneksi, 0);
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$_SESSION['admin_id']=$actor; $_SESSION['admin_role']='super_admin';
$prefix='00'.random_int(8100000,8199999);
$koneksi->begin_transaction();
try {
    foreach ([1,2,3] as $unit) {
        $_SESSION['active_unit_id']=$unit; unit_set_context($koneksi,$unit); [$first,$last]=unit_level_bounds();
        $year=du_current_academic_year(); class_ensure_academic_year($koneksi,$year);
        $psb=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=0 AND kode_rombel='PSB' LIMIT 1")->fetch_row()[0];
        $targets=$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=$first AND is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
        $a=(int)$targets[0]['id'];$b=(int)$targets[1]['id'];
        $create=static function (string $suffix) use ($koneksi,$prefix,$psb): string {
            $nis=$prefix.$suffix;$name='TEST PSB placement '.$suffix;
            $s=$koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB,asal_psb,POMG,potongan_spp_nominal,is_active) VALUES(?,?,'0',?,1000000,1,100000,20000,1)");
            $s->bind_param('ssi',$nis,$name,$psb);$s->execute();$s->close();return $nis;
        };
        $one=$create('1');$two=$create('2');$missing=$create('3');$control=$create('4');
        $missingBefore=$koneksi->query("SELECT * FROM siswa WHERE NO_INDUK='$missing'")->fetch_assoc();
        $date=date('Y-m-d H:i:s');$month=date('m');$calendarYear=date('Y');
        $pay=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,U_PSB,TGL_BYR,BULAN,TAHUN,th_ajaran,total_jumlah,payment_link_version) VALUES(?,'PSB',100000,?,?,?,?,100000,1)");
        $pay->bind_param('sssss',$one,$date,$month,$calendarYear,$year);$pay->execute();$paymentId=(int)$koneksi->insert_id;$pay->close();
        $paymentBefore=$koneksi->query("SELECT * FROM bayar WHERE id=$paymentId")->fetch_assoc();
        $result=psb_placement_batch($koneksi,[$one,$two,$missing,$one],[$one=>$a,$two=>$b],$year);
        psb_assert(count($result['successes'])===2 && count($result['failures'])===1 && $result['attempted']===3,'Partial batch or deduplication incorrect');
        foreach ([$one=>$a,$two=>$b] as $nis=>$target) {
            $s=$koneksi->query("SELECT * FROM siswa WHERE NO_INDUK='$nis'")->fetch_assoc();
            psb_assert((int)$s['KELAS']===$first && (int)$s['master_kelas_id']===$target,'Wrong first grade/rombel');
            psb_assert((float)$s['PSB']===1000000.0 && (int)$s['asal_psb']===1 && (float)$s['potongan_spp_nominal']===20000.0,'Student components changed');
            psb_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$nis'")->fetch_row()[0]===1,'Placement missing/duplicated');
            psb_assert((int)$koneksi->query("SELECT COUNT(*) FROM tagihan_spp WHERE no_induk='$nis'")->fetch_row()[0]===0,'SPP automatically published');
            psb_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_audit_log WHERE no_induk_snapshot='$nis'")->fetch_row()[0]===1,'Audit missing');
        }
        psb_assert($paymentBefore===$koneksi->query("SELECT * FROM bayar WHERE id=$paymentId")->fetch_assoc(),'Existing PSB payment changed');
        psb_assert($missingBefore===$koneksi->query("SELECT * FROM siswa WHERE NO_INDUK='$missing'")->fetch_assoc(),'Failure left partial student changes');
        psb_assert(!(int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$missing'")->fetch_row()[0],'Failure created placement');
        // Independently execute the established initial placement path used by Data Siswa.
        $rate=spp_current_effective_rate($koneksi,(string)$first,20000,$year);$spp=$rate['year']==='Belum disiapkan'?0.0:(float)$rate['net'];
        $koneksi->query("UPDATE siswa SET KELAS='$first',master_kelas_id=$a,SPP_PERBULAN=$spp WHERE NO_INDUK='$control'");
        $sync=null;$p=class_sync_student_current_year($koneksi,$control,$a,$spp,100000,true,$sync);
        spp_sync_student_discount($koneksi,$control,20000,$p);du_create_bill_for_placement($koneksi,$p,false);du_reconcile_current_student_override($koneksi,$control);
        $columns='kelas,kelas_rombel_snapshot,spp_perbulan_snapshot,spp_covered_by_psb,komite_snapshot,komite_mulai_bulan,status';
        $actual=$koneksi->query("SELECT $columns FROM siswa_tahun_ajaran WHERE no_induk='$one'")->fetch_assoc();
        $expected=$koneksi->query("SELECT $columns FROM siswa_tahun_ajaran WHERE no_induk='$control'")->fetch_assoc();
        psb_assert($actual===$expected,'Initial snapshot differs from Data Siswa');
        psb_assert((int)$actual['spp_covered_by_psb']===($first===1?1:0),'PSB SPP protection incorrect');
        foreach (['tagihan_komite'=>'bulan,tahun,nominal_tagihan','tagihan_daftar_ulang'=>'nominal_awal,nominal_tagihan'] as $table=>$cols) {
            $actual=$koneksi->query("SELECT $cols FROM $table WHERE no_induk='$one' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
            $expected=$koneksi->query("SELECT $cols FROM $table WHERE no_induk='$control' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
            psb_assert($actual===$expected,'Billing differs from Data Siswa: '.$table);
        }
        $replay=psb_placement_batch($koneksi,[$one],[$one=>$a],$year);
        psb_assert(!$replay['successes'] && count($replay['failures'])===1,'Replay accepted');
        $badLevel=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=$last AND is_active=1 AND is_placeholder=0 LIMIT 1")->fetch_row()[0];
        $foreign=(int)$koneksi->query('SELECT id FROM master_kelas_data WHERE unit_id<>'.$unit.' AND tingkat>0 LIMIT 1')->fetch_row()[0];
        $code='TP'.random_int(80000,89999);$koneksi->query("INSERT INTO master_kelas(tingkat,kode_rombel,is_active,is_placeholder) VALUES($first,'$code',0,0)");$inactive=(int)$koneksi->insert_id;
        $code='PH'.random_int(80000,89999);$koneksi->query("INSERT INTO master_kelas(tingkat,kode_rombel,is_active,is_placeholder) VALUES($first,'$code',1,1)");$placeholder=(int)$koneksi->insert_id;
        foreach ([$badLevel,$foreign,$inactive,$placeholder,0] as $bad) {
            $failed=psb_placement_batch($koneksi,[$missing],[$missing=>$bad],$year);
            psb_assert(!$failed['successes'] && count($failed['failures'])===1,'Invalid target accepted');
        }
        $yearId=class_current_academic_year_id($koneksi);
        $koneksi->query("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES($yearId,'$missing','$first',$a,'TEST old history','aktif')");
        $oldPlacement=(int)$koneksi->insert_id;
        psb_assert(!psb_placement_batch($koneksi,[$missing],[$missing=>$a],$year)['successes'],'Existing placement overwritten');
        $koneksi->query("DELETE FROM siswa_tahun_ajaran WHERE id=$oldPlacement");
        $blocked=false;try { psb_placement_batch($koneksi,[$missing],[$missing=>$a],class_next_academic_year_label($year)); } catch (RuntimeException $e) { $blocked=true; }
        psb_assert($blocked,'Future admission year accepted');
        $koneksi->query("UPDATE siswa SET is_active=0 WHERE NO_INDUK='$missing'");
        psb_assert(!psb_placement_batch($koneksi,[$missing],[$missing=>$a],$year)['successes'],'Inactive student accepted');
        $koneksi->query("UPDATE admin SET is_active=0 WHERE id=$actor");
        $blocked=false;try { psb_placement_batch($koneksi,[$missing],[$missing=>$a],$year); } catch (RuntimeException $e) { $blocked=true; }
        psb_assert($blocked,'Inactive actor accepted');$koneksi->query("UPDATE admin SET is_active=1 WHERE id=$actor");
        echo 'PASS: unit '.$unit.' first-grade placement, same/leading-zero NIS, billing equivalence, immutable PSB payment, partial/replay/invalid targets and inactive identities'.PHP_EOL;
    }
    unit_set_context($koneksi,0);$_SESSION['active_unit_id']=0;$blocked=false;
    try { psb_placement_batch($koneksi,[$prefix.'3'],[$prefix.'3'=>1],du_current_academic_year()); } catch (RuntimeException $e) { $blocked=true; }
    psb_assert($blocked,'All-unit placement accepted');
    echo "PASS: All Unit is read-only\n";
} finally { $koneksi->rollback(); }
