<?php
/** Independent item oracle on the owned migrated clone; all fixture edits roll back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58470||!str_contains($koneksi->query('SELECT @@datadir')->fetch_row()[0],'global_audit_20261021'))throw new RuntimeException('Owned report clone required.');
require_once __DIR__.'/../includes/transaction_report.php';
$qa=(string)getenv('SPP_GLOBAL_QA_DIR');$fixture=json_decode(file_get_contents($qa.'/fixture.json'),true);$models=json_decode(file_get_contents($qa.'/payment-models.json'),true);
$checks=0;
function tt_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function tt_cents($value):int{$v=(string)$value;$negative=str_starts_with($v,'-');[$whole,$decimal]=array_pad(explode('.',ltrim($v,'-'),2),2,'');return ($negative?-1:1)*((int)$whole*100+(int)str_pad($decimal,2,'0'));}
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>0];unit_set_context($koneksi,0);
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
// Read archive/detail tables directly, without the production item SQL or label helper.
$raw=[];$add=static function($id,$unit,$key,$label,$amount)use(&$raw){$cents=tt_cents($amount);if($cents)$raw[(int)$id][]=['unit'=>(int)$unit,'key'=>$key,'label'=>$label,'cents'=>$cents];};
$all=$models[0]['undated']['rows'];$native=[];$full=[];$op=[];
foreach($all as $p){$id=(int)$p['id'];if($id>0)$native[]=$id;elseif($id>-1000000000)$full[]=-$id;else $op[]=-1000000000-$id;}
if($native){$ids=implode(',',$native);
 foreach($koneksi->query("SELECT * FROM bayar WHERE id IN ($ids)")->fetch_all(MYSQLI_ASSOC) as $r)foreach(['U_PSB'=>['psb','PSB'],'U_SPP'=>['spp','SPP'],'U_KOMITE'=>['komite','Komite'],'potong_spp'=>['potongan_spp','Potongan SPP']] as $field=>[$key,$label])$add($r['id'],$r['unit_id'],$key,$label,$field==='potong_spp'?'-'.$r[$field]:$r[$field]);
 foreach($koneksi->query("SELECT d.*,t.master_biaya_lain_id bill_master FROM bayar_biaya_lain d LEFT JOIN tagihan_biaya_lain t ON t.id=d.tagihan_biaya_lain_id AND t.unit_id=d.unit_id WHERE d.bayar_id IN ($ids)")->fetch_all(MYSQLI_ASSOC) as $r){$master=$r['master_biaya_lain_id']??$r['bill_master'];$key=$master?'biaya_lain:'.$master:(trim((string)$r['legacy_key'])!==''?'snapshot_key:'.$r['unit_id'].':'.$r['legacy_key']:'snapshot_item:'.$r['unit_id'].':'.$r['id']);$add($r['bayar_id'],$r['unit_id'],$key,$r['nama_biaya_snapshot'],$r['nominal_snapshot']);}
 foreach($koneksi->query("SELECT * FROM bayar_du WHERE bayar_id IN ($ids)")->fetch_all(MYSQLI_ASSOC) as $r)$add($r['bayar_id'],$r['unit_id'],'daftar_ulang','Daftar Ulang',$r['jumlah']);
}
if($full)foreach($koneksi->query('SELECT * FROM legacy_full_items WHERE payment_id IN ('.implode(',',$full).')')->fetch_all(MYSQLI_ASSOC) as $r)$add(-$r['payment_id'],$r['unit_id'],$r['category'],$r['label'],$r['amount']);
if($op)foreach($koneksi->query('SELECT id,unit_id,items FROM legacy_op_payments WHERE id IN ('.implode(',',$op).')')->fetch_all(MYSQLI_ASSOC) as $r)foreach(json_decode($r['items'],true) as $item)$add(-1000000000-$r['id'],$r['unit_id'],$item['category'],$item['label'],$item['amount']);
$result=[];
foreach([1,2,3,0] as $unit)foreach(['dated','undated','empty'] as $profile){
 $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$_GET=['include_undated'=>$profile==='undated'?'1':'0'];$model=$models[$unit][$profile];$expected=[];
 foreach($model['rows'] as $p){$paid=0;foreach($raw[(int)$p['id']]??[] as $i){tt_check($i['unit']===(int)$p['unit_id'],'Item owner unit matches immutable payment');$expected[$i['key']]=($expected[$i['key']]??0)+$i['cents'];$paid+=$i['cents'];}tt_check($paid===tt_cents($p['total_jumlah']),'Receipt components equal independently read payment cents');}
 $start=$profile==='empty'?'2090-01-01':'2026-10-01';$end=$profile==='empty'?'2090-02-01':'2026-10-23';$time=microtime(true);
 $totals=transaction_report_component_totals($koneksi,$start.' 00:00:00',$end.' 00:00:00');$actual=[];foreach($totals as $r){tt_check(!isset($actual[$r['component_key']]),'One row per proven canonical key');$actual[$r['component_key']]=$r['cents'];tt_check(!str_contains($r['label'],'(Legacy)'),'No source suffix in aggregate labels');}
 ksort($expected);ksort($actual);tt_check($expected===$actual,'Canonical component cents match independent archive/native oracle');tt_check(array_sum($actual)===$model['cents'],'All filtered component cents equal payment total');
 if($profile!=='empty')tt_check(count(array_filter($totals,static fn($r)=>$r['component_key']==='spp'&&$r['label']==='SPP'))===1,'SPP from native and Legacy is merged once');
 $result[$unit][$profile]=['seconds'=>round(microtime(true)-$time,3),'components'=>count($actual),'cents'=>array_sum($actual)];
}
// Exercise the complete 118k-row history without building an export or one query per row.
foreach([1,2,3,0] as $unit){
 $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$_GET=['all_periods'=>'1'];$time=microtime(true);
 $actual=transaction_report_component_totals($koneksi,'2026-10-01 00:00:00','2026-10-23 00:00:00');
 $aggregateSeconds=microtime(true)-$time;
 $paid=$koneksi->query("SELECT SUM(amount) amount,COUNT(*) count FROM (SELECT CAST(total_jumlah AS DECIMAL(20,2)) amount FROM bayar UNION ALL SELECT p.total FROM legacy_full_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id JOIN siswa s ON s.id=p.student_id AND s.unit_id=p.unit_id WHERE b.state='completed' UNION ALL SELECT p.total FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0) source")->fetch_assoc();
 tt_check(array_sum(array_column($actual,'cents'))===tt_cents($paid['amount']),'Full-history component total equals independent source receipts');
 $result[$unit]['all']=['aggregate_seconds'=>round($aggregateSeconds,3),'with_oracle_seconds'=>round(microtime(true)-$time,3),'receipts'=>(int)$paid['count'],'components'=>count($actual)];
}
$koneksi->commit();
// Identical labels with different master IDs must not become one component.
$_SESSION['active_unit_id']=1;unit_set_context($koneksi,1);$_GET=['student_id'=>$fixture['native'][1]['student_id']];$id=(int)$fixture['native'][1]['ids'][0];
$masters=array_column($koneksi->query('SELECT id FROM master_biaya_lain ORDER BY id LIMIT 2')->fetch_all(MYSQLI_ASSOC),'id');tt_check(count($masters)===2,'Two distinct proven optional fee masters available');
$koneksi->begin_transaction();try{
 foreach($masters as $i=>$master){$amount=$i?2.02:1.01;$s=$koneksi->prepare("INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot) VALUES(?,?,'QA Nama Sama',?)");$s->bind_param('iid',$id,$master,$amount);$s->execute();$s->close();}
 $rows=transaction_report_component_totals($koneksi,'2026-10-01 00:00:00','2026-10-23 00:00:00');$keys=array_column($rows,'component_key');foreach($masters as $master)tt_check(in_array('biaya_lain:'.$master,$keys,true),'Separate keys retained for same-label fees');
}finally{$koneksi->rollback();}
foreach([null,'bad',['komponen']] as $value)tt_check(transaction_report_panel($value)==='ringkasan','Malformed presentation safely defaults');
file_put_contents($qa.'/component-result.json',json_encode(['checks'=>$checks,'profiles'=>$result],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "OK: $checks independent component checks\n";
