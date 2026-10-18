<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/savings_workspace.php';require_once __DIR__.'/../includes/savings_authorization.php';
$fixture=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/rombel-fixture.json'),true);$checks=0;
foreach([1,2,3] as $unit){
    $_SESSION=['admin_id'=>$fixture['sessions']['super_admin']['actor'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);$item=$fixture['units'][$unit];
    $filters=report_filters($koneksi,['q'=>$item['nis'],'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-31','siswa_status'=>'active']);
    $data=report_savings_balance_data($koneksi,$filters);$rows=array_values(array_filter($data['rows'],fn($row)=>$row['nis']===$item['nis']));
    if(count($rows)!==1||!str_contains($rows[0]['kelas'],'Tabungan Guru'))throw new RuntimeException('Custom balance label missing');$checks++;
    $transactions=report_savings_transactions($koneksi,'2026-10-01 00:00:00','2026-11-01 00:00:00',$filters);$rows=array_values(array_filter($transactions,fn($row)=>$row['NO_INDUK']===$item['nis']));
    if(count($rows)!==2||!str_contains($rows[0]['kelas_label'],'Tabungan Guru'))throw new RuntimeException('Custom transaction label missing');$checks++;
    $q=$koneksi->prepare('SELECT id FROM transaksi_m WHERE NO_INDUK=? LIMIT 1');$q->bind_param('s',$item['nis']);$q->execute();$id=(int)$q->get_result()->fetch_row()[0];$q->close();$row=savings_transaction($koneksi,'masuk',$id);
    if(!str_contains($row['kelas_label'],'Tabungan Guru'))throw new RuntimeException('Custom detail label missing');$checks++;
}
$result='PASS: '.$checks." custom savings report/detail assertions across three units.\n";file_put_contents(getenv('SPP_SAVINGS_QA').'/report-result.txt',$result);echo $result;
