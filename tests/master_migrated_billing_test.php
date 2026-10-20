<?php
/** Exercise real migrated tariffs without retaining a single mutation. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]===3306)throw new RuntimeException('Use an isolated server.');
require_once __DIR__.'/../includes/spp_billing.php';
$checks=0;
function mb_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];
foreach([1,2,3] as $unit){
 $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);lb_cache($koneksi,true);
 $finance=[];foreach(['bayar','bayar_du','transaksi_m','transaksi_k'] as $table)$finance[$table]=lf_one($koneksi,"SELECT COUNT(*) n FROM $table")['n'];
 $duBills=lf_rows($koneksi,"SELECT t.* FROM tagihan_daftar_ulang t WHERE t.status='open' AND NOT EXISTS(SELECT 1 FROM bayar_du b WHERE b.tagihan_daftar_ulang_id=t.id)");
 $unpaid=null;$paid=[];
 foreach($duBills as $bill){if(!lb_student($koneksi,$bill['no_induk']))continue;if(lb_du_bill_credit($koneksi,$bill['no_induk'],$bill['tahun_ajaran_snapshot'])>.001)$paid[]=$bill;elseif(!$unpaid)$unpaid=$bill;}
 mb_check((bool)$unpaid&&count($paid)>0,"Unit $unit real Legacy DU has unpaid and paid fixtures");
 $koneksi->begin_transaction();
 try{
  $master=lf_one($koneksi,'SELECT * FROM Daftar_ulang WHERE id=?',[$unpaid['master_daftar_ulang_id']]);$amount=(float)$master['Jumlah']+10000;
  lb_mark_du_master($koneksi,$unpaid['tahun_ajaran_snapshot'],[(int)$unpaid['kelas_snapshot']]);
  du_sync_open_bills_for_master_rate($koneksi,(int)$master['id'],$unpaid['tahun_ajaran_snapshot'],(float)$master['Jumlah'],$amount);
  lf_query($koneksi,'UPDATE Daftar_ulang SET Jumlah=? WHERE id=?',[$amount,$master['id']]);
  $changed=lf_one($koneksi,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$unpaid['id']]);
  mb_check(abs((float)$changed['nominal_awal']-$amount)<.001,"Unit $unit explicit DU master edit updates unpaid Legacy bill");
  $discount=(float)$unpaid['nominal_awal']-(float)$unpaid['nominal_tagihan'];
  mb_check(abs((float)$changed['nominal_tagihan']-max(0,$amount-$discount))<.001,"Unit $unit DU discount is retained once");
  foreach($paid as $bill)mb_check(lf_one($koneksi,'SELECT nominal_tagihan FROM tagihan_daftar_ulang WHERE id=?',[$bill['id']])['nominal_tagihan']===$bill['nominal_tagihan'],"Unit $unit paid Legacy DU snapshot {$bill['id']} stays frozen");
 }finally{$koneksi->rollback();lb_cache($koneksi,true);}
 mb_check(lf_one($koneksi,'SELECT nominal_tagihan FROM tagihan_daftar_ulang WHERE id=?',[$unpaid['id']])['nominal_tagihan']===$unpaid['nominal_tagihan'],"Unit $unit DU master rollback restores original bill");

 $master=lf_one($koneksi,"SELECT m.*,y.label FROM master_spp_tahun m JOIN tahun_ajaran y ON y.id=m.tahun_ajaran_id WHERE m.status='published' AND EXISTS(SELECT 1 FROM tagihan_spp t WHERE t.master_spp_tahun_id=m.id) ORDER BY y.label DESC LIMIT 1");
 mb_check((bool)$master,"Unit $unit published migrated SPP master exists");
 $bills=lf_rows($koneksi,"SELECT t.* FROM tagihan_spp t WHERE t.master_spp_tahun_id=? AND t.status='open' AND NOT EXISTS(SELECT 1 FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id WHERE a.tagihan_spp_id=t.id AND b.status='active')",[$master['id']]);
 $unpaid=null;$paid=[];
 foreach($bills as $bill){if(!lb_student($koneksi,$bill['no_induk']))continue;if(lb_spp_credit($koneksi,$bill['no_induk'],$bill['bulan'],$bill['tahun'])>.001)$paid[]=$bill;elseif(!$unpaid)$unpaid=$bill;}
 mb_check((bool)$unpaid&&count($paid)>0,"Unit $unit real Legacy SPP has unpaid and paid fixtures");
 $koneksi->begin_transaction();
 try{
  $master=spp_master_state($koneksi,(int)$master['id']);$rates=spp_master_rates($koneksi,(int)$master['id']);$version=spp_master_rate_version($master,$rates);foreach($rates as &$rate)$rate+=10000;unset($rate);
  spp_master_correct_published_rates($koneksi,(int)$master['id'],$rates,$version,true);
  $changed=lf_one($koneksi,'SELECT * FROM tagihan_spp WHERE id=?',[$unpaid['id']]);
  mb_check(abs((float)$changed['tarif_dasar_snapshot']-$rates[(int)$unpaid['tingkat_snapshot']])<.001,"Unit $unit explicit SPP edit reprices unpaid Legacy bill");
  foreach($paid as $bill){$same=lf_one($koneksi,'SELECT * FROM tagihan_spp WHERE id=?',[$bill['id']]);mb_check($same['nominal_tagihan']===$bill['nominal_tagihan']&&$same['tarif_dasar_snapshot']===$bill['tarif_dasar_snapshot'],"Unit $unit paid Legacy SPP snapshot {$bill['id']} stays frozen");}
  $rejected=false;try{spp_master_correct_published_rates($koneksi,(int)$master['id'],$rates,$version,true);}catch(RuntimeException $e){$rejected=true;}
  mb_check($rejected,"Unit $unit stale master SPP version is rejected");
  $publish=spp_publish_students($koneksi,(int)$master['id'],[$unpaid['no_induk']]);
  mb_check($publish['created']===0,"Unit $unit republishing migrated SPP creates no duplicate bills");
 }finally{$koneksi->rollback();lb_cache($koneksi,true);}
 mb_check(lf_one($koneksi,'SELECT nominal_tagihan FROM tagihan_spp WHERE id=?',[$unpaid['id']])['nominal_tagihan']===$unpaid['nominal_tagihan'],"Unit $unit SPP master rollback restores original bill");
 foreach($finance as $table=>$count)mb_check(lf_one($koneksi,"SELECT COUNT(*) n FROM $table")['n']===$count,"Unit $unit master audit preserves $table");
}
echo "OK: $checks migrated billing checks\n";
