<?php
/** Cross-unit business helpers on a disposable database, all fixtures rolled back. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/kelas.php';require_once __DIR__.'/../includes/transaction_authorization.php';require_once __DIR__.'/../includes/legacy_import.php';
function business_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
unit_set_context($koneksi,0);$actors=[];
foreach(['super_admin','admin','kasir'] as $role){$actors[$role]=$koneksi->query("SELECT id,role FROM admin WHERE role='$role' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();business_assert((bool)$actors[$role],'Actor missing '.$role);}
foreach(['admin','kasir'] as $role)foreach([1,2,3] as $unit){
    $_SESSION=['admin_id'=>(int)$actors[$role]['id'],'admin_role'=>$role,'active_unit_id'=>$unit];unit_set_context($koneksi,$unit);$koneksi->begin_transaction();
    try{
        [$first,$last]=unit_level_bounds();$master=spp_master_ensure_year($koneksi,'2177/2178',true);$mid=(int)$master['id'];$year=(int)$master['tahun_ajaran_id'];$rates=array_fill_keys(range($first,$last),250000.0);spp_master_save_rates($koneksi,$mid,$rates);
        $class=(int)$koneksi->query('SELECT id FROM master_kelas WHERE tingkat='.$first.' AND is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 1')->fetch_row()[0];
        $nis='OP'.bin2hex(random_bytes(4));$name='Siswa Uji Tarif '.$role;$level=(string)$first;
        $s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,PSB) VALUES(?,?,?,?,250000,10000,100000)');$s->bind_param('sssi',$nis,$name,$level,$class);$s->execute();$s->close();
        $s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,250000,10000,'aktif')");$snapshot=$level.'A';$s->bind_param('issis',$year,$nis,$level,$class,$snapshot);$s->execute();$s->close();
        $published=spp_publish_students($koneksi,$mid,[$nis]);business_assert($published['created']===12,'SPP publication '.$role.'/'.$unit);
        $state=spp_master_state($koneksi,$mid,true);$version=spp_master_rate_version($state,spp_master_rates($koneksi,$mid,true));
        spp_master_correct_published_rates($koneksi,$mid,array_fill_keys(range($first,$last),300000.0),$version,true);
        $bills=$koneksi->query("SELECT COUNT(*) n,MIN(nominal_tagihan) amount FROM tagihan_spp WHERE no_induk='$nis'")->fetch_assoc();business_assert((int)$bills['n']===12&&(float)$bills['amount']===300000.0,'Cross-unit tariff correction '.$role.'/'.$unit);
        echo 'PASS '.$role.'/'.$unit.' publish 12 SPP bills and correct unpaid tariff; actor role unchanged'.PHP_EOL;
    }finally{$koneksi->rollback();}
}
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$koneksi->begin_transaction();
    try{
        $_SESSION=['admin_role'=>'admin','admin_id'=>(int)$actors['admin']['id'],'active_unit_id'=>$unit];
        $denied=false;try{transaction_authorization_assert_super($koneksi,(int)$actors['admin']['id']);}catch(RuntimeException $e){$denied=true;}business_assert($denied,'Admin could decide');
        $denied=false;try{transaction_authorization_assert_super($koneksi,(int)$actors['kasir']['id']);}catch(RuntimeException $e){$denied=true;}business_assert($denied,'Cashier could decide');
        transaction_authorization_assert_super($koneksi,(int)$actors['super_admin']['id']);
        $koneksi->query('UPDATE admin SET is_active=0 WHERE id='.(int)$actors['super_admin']['id']);
        $denied=false;try{transaction_authorization_assert_super($koneksi,(int)$actors['super_admin']['id']);}catch(RuntimeException $e){$denied=true;}business_assert($denied,'Inactive Super Admin could decide');
    }finally{$koneksi->rollback();}
}
unit_set_context($koneksi,0);$denied=false;try{transaction_authorization_assert_super($koneksi,(int)$actors['super_admin']['id']);}catch(RuntimeException $e){$denied=true;}business_assert($denied,'All-unit approval accepted');
echo 'PASS authorization decisions remain Super Admin-only, active and single-unit'.PHP_EOL;
