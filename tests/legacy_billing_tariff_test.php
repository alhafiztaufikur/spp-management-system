<?php
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58246'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')throw new RuntimeException('Private fixture only.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/daftar_ulang.php';require_once __DIR__.'/../includes/tagihan_sekali.php';$db=$koneksi;
if($db->query('SELECT @@server_uuid')->fetch_row()[0]!=='9d552e39-cbe6-11f1-9a5e-0a0027000012')throw new RuntimeException('Wrong private server');$checks=[];$check=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
foreach([1,2,3] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);lb_cache($db,true);$bills=lf_rows($db,"SELECT * FROM tagihan_daftar_ulang WHERE status='open' ORDER BY id");$paid=null;$empty=null;foreach($bills as $b){$credit=lb_du_bill_credit($db,$b['no_induk'],$b['tahun_ajaran_snapshot']);if($credit>0&&!$paid)$paid=$b;if($credit==0&&!$empty)$empty=$b;}if(!$empty){$empty=$bills[0];if($paid['id']===$empty['id'])$empty=$bills[1];}$check($paid&&$empty,"Unit $unit tariff fixtures available");
 $db->begin_transaction();try{
  $owner=lb_student($db,$empty['no_induk']);lf_query($db,"UPDATE legacy_op_payments SET cancelled=1 WHERE owner_id=? AND source_table='bayar_du' AND source_period=?",[$owner['id'],$empty['tahun_ajaran_snapshot']]);lb_cache($db,true);
  $year=$empty['tahun_ajaran_snapshot'];$level=(int)$empty['kelas_snapshot'];$master=lf_one($db,'SELECT * FROM Daftar_ulang WHERE id=?',[$empty['master_daftar_ulang_id']]);$amount=(float)$master['Jumlah']+10000;
  lb_mark_du_master($db,$year,[$level]);$changed=du_sync_open_bills_for_master_rate($db,(int)$master['id'],$year,(float)$master['Jumlah'],$amount);lf_query($db,'UPDATE Daftar_ulang SET Jumlah=? WHERE id=?',[$amount,$master['id']]);
  $new=lf_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$empty['id']]);$check(abs((float)$new['nominal_awal']-$amount)<.001,"Unit $unit explicit DU master changes unpaid snapshot");$check(abs((float)$new['nominal_tagihan']-max(0,$amount-((float)$empty['nominal_awal']-(float)$empty['nominal_tagihan'])))<.001,"Unit $unit individual discount retained once");
  $same=lf_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$paid['id']]);$check($same['nominal_tagihan']===$paid['nominal_tagihan'],"Unit $unit source DU installment freezes snapshot");
  $plan=lb_reconcile($db,1,false);$check($plan['du_corrected']===0,"Unit $unit reconciliation respects explicit override");
 }finally{$db->rollback();lb_cache($db,true);}
 $check(lf_one($db,'SELECT nominal_tagihan FROM tagihan_daftar_ulang WHERE id=?',[$empty['id']])['nominal_tagihan']===$empty['nominal_tagihan'],"Unit $unit DU tariff rollback complete");
}
echo json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT).PHP_EOL;
