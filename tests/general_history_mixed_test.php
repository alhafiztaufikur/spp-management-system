<?php
/** Rollback-only native + Legacy read-model reconciliation on the owned restore. */
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58360'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')throw new RuntimeException('Owned audit instance required.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/general_multiple.php';
if(!str_contains(str_replace('\\','/',$koneksi->query('SELECT @@datadir')->fetch_row()[0]),'/import_audit_20261020/'))throw new RuntimeException('Unexpected instance.');
foreach([1,2,3] as $unit){
    $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);
    $before=general_payment_summary($koneksi,'2022-01-01','2027-01-01');
    $student=lf_one($koneksi,'SELECT NO_INDUK,KELAS FROM siswa WHERE is_active=1 ORDER BY id LIMIT 1');
    $koneksi->begin_transaction();
    try{
        lf_query($koneksi,"INSERT INTO bayar(unit_id,NO_INDUK,KELAS,BULAN,TAHUN,U_PSB,U_SPP,U_KOMITE,potong_spp,total_jumlah,TGL_BYR,sistem_pembayaran,user_id) VALUES(?,?,?,'10','2026','123.13','100.01','20.02','0.03','243.13','2026-10-20 09:00:00','Tunai','1')",[$unit,$student['NO_INDUK'],$student['KELAS']]);
        $id=(int)$koneksi->insert_id;
        $after=general_payment_summary($koneksi,'2022-01-01','2027-01-01');
        $components=general_payment_components($koneksi,'2022-01-01','2027-01-01');
        if((int)$after['jml_tx']!==(int)$before['jml_tx']+1||abs((float)$after['total']-(float)$before['total']-243.13)>.001||abs(array_sum(array_column($components,'total'))-(float)$after['total'])>.001)throw new RuntimeException('Mixed general totals differ.');
        $rows=general_payment_rows($koneksi,'2026-10-20','2026-10-21',$student['NO_INDUK']);
        $native=array_values(array_filter($rows,static fn($r)=>(int)$r['id']===$id));
        if(count($native)!==1||$native[0]['sistem_pembayaran']!=='Tunai')throw new RuntimeException('Native receipt identity lost.');
        echo "PASS unit $unit native + Legacy counts, discount, exact cents, and receipt identity\n";
    }finally{$koneksi->rollback();}
    $final=general_payment_summary($koneksi,'2022-01-01','2027-01-01');
    if($before!=$final)throw new RuntimeException('Rollback changed the original report.');
}
