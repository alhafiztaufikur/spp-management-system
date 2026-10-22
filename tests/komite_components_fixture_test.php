<?php
/** Native + both Legacy formats, fractional cents and immutable source categories. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
if((int)getenv('SPP_DB_PORT')===3306||!getenv('SPP_KOMITE_TEST_UUID'))throw new RuntimeException('Owned isolated server required.');
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if($koneksi->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_KOMITE_TEST_UUID'))throw new RuntimeException('Server UUID differs.');
require_once __DIR__.'/../includes/transaction_report.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;$_SESSION=['admin_role'=>'super_admin','active_unit_id'=>1];$_GET=[];unit_set_context($db,1);$start='2026-10-01 00:00:00';$end='2026-10-23 00:00:00';
$before=transaction_report_component_totals($db,$start,$end);$beforeMap=array_column($before,'cents','component_key');$total=array_sum($beforeMap);$owner=hp_one($db,"SELECT o.*,s.NO_INDUK,s.KELAS,s.master_kelas_id FROM legacy_op_owners o JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE o.state='ready' AND s.is_active=1 LIMIT 1");$masters=array_column(hp_rows($db,'SELECT id FROM master_biaya_lain ORDER BY id LIMIT 2'),'id');lft_assert($owner&&count($masters)===2,'Owned clone fixture prerequisites missing');$db->begin_transaction();
try{
    lf_query($db,"INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_SPP,U_KOMITE,U_LAIN,potong_spp,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran) VALUES(?,?,?,'QA',100.11,10.22,7.07,1.01,116.39,'2026-10-22 10:00:00','10','2026','1','Tunai')",[$owner['NO_INDUK'],$owner['KELAS'],$owner['master_kelas_id']]);$native=(int)$db->insert_id;
    foreach($masters as $n=>$master)lf_query($db,"INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot) VALUES(?,?,'QA Nama Sama',?)",[$native,$master,$n?'4.04':'3.03']);
    lf_query($db,"INSERT INTO legacy_full_payments(unit_id,batch_id,student_id,row_key,source_table,source_date,source_period,class_snapshot,total) VALUES(?,?,?,?, 'bayar','2026-10-22 10:00:00','10 2026','QA',115.56)",[1,$owner['batch_id'],$owner['student_id'],hash('sha256',random_bytes(32))]);$full=(int)$db->insert_id;
    foreach([['U_SPP','spp','SPP','100.01'],['U_SORGA',lcs_sorga_history_key(),'U_SORGA','15.55']] as [$column,$category,$label,$amount])lf_query($db,'INSERT INTO legacy_full_items(unit_id,payment_id,source_column,category,label,amount) VALUES(1,?,?,?,?,?)',[$full,$column,$category,$label,$amount]);
    $items=[['column'=>'U_SPP','category'=>'spp','label'=>'SPP','amount'=>'100.01'],['column'=>'U_SORGA','category'=>'komite','label'=>'Komite','amount'=>'15.55','migration'=>['version'=>1,'period'=>'10 2026','rule'=>'sorga_is_komite','komite_mapping_version'=>1]]];
    lf_query($db,"INSERT INTO legacy_op_payments(unit_id,batch_id,owner_id,row_key,source_table,source_date,source_period,class_snapshot,items,total) VALUES(1,?,?,?,'bayar','2026-10-22 10:00:00','10 2026','QA',?,115.56)",[$owner['batch_id'],$owner['id'],hash('sha256',random_bytes(32)),lf_json($items)]);$op=(int)$db->insert_id;
    $rows=transaction_report_component_totals($db,$start,$end);$map=array_column($rows,'cents','component_key');lft_assert(array_sum($map)-$total===34751,'Fractional item cash changed');lft_assert($map['komite']-($beforeMap['komite']??0)===4132,'Native, old full and new op Komite did not combine exactly once');
    foreach($masters as $n=>$master)lft_assert(($map['biaya_lain:'.$master]??0)-($beforeMap['biaya_lain:'.$master]??0)===($n?404:303),'Same-label distinct fee masters merged');
    $details=transaction_report_components($db,[['id'=>$native,'unit_id'=>1],['id'=>-$full,'unit_id'=>1],['id'=>-1000000000-$op,'unit_id'=>1]]);lft_assert(count($details)===3&&!str_contains(implode('\n',$details),'U_SORGA:'),'Detail reader differs from aggregate');
    $model=lful_history_model($db,$full);ob_start();lful_history_render($model);$html=ob_get_clean();lft_assert(str_contains($html,'Komite')&&!str_contains($html,'U_SORGA')&&$model['legacy']['items'][1]['label']==='U_SORGA','Full history presentation mutated raw component');
    require_once __DIR__.'/../includes/payment_history.php';$slip=payment_history_model($db,-$full,false);lft_assert($slip['payment']['total_jumlah']==='115.56'&&in_array('Komite',array_column($slip['components'],0),true)&&$slip['capabilities']['can_print'],'Full-format shared receipt adapter unavailable');
    lft_assert(lf_one($db,'SELECT category FROM legacy_full_items WHERE payment_id=? AND source_column=?',[$full,'U_SORGA'])['category']===lcs_sorga_history_key(),'Stored full category changed');
    echo "PASS: mixed native/full/op formats, exact fractional totals, same-name distinct masters, snapshots and raw categories.\n";
}finally{$db->rollback();}
lft_assert(array_sum(array_column(transaction_report_component_totals($db,$start,$end),'cents'))===$total,'Fixture rollback changed financial data');
echo "PASS: fixture writes rolled back on the isolated instance.\n";
