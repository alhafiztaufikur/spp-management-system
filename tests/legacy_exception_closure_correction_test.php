<?php
/** A later cashier correction must remain valid; original acceptance and source remain audited. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
if((int)getenv('SPP_DB_PORT')===3306)throw new RuntimeException('Isolated fixture required');
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if(!getenv('SPP_MIGRATION_TEST_UUID')||$koneksi->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_MIGRATION_TEST_UUID'))throw new RuntimeException('Fixture UUID differs');
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/legacy_operational_corrections.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;unit_set_context($db,1);$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];$db->begin_transaction();
try{
    $seed=lft_seed($db,1,1);$source=lf_one($db,'SELECT backup_hash FROM legacy_finance_sources WHERE id=?',[$seed['source']]);$batch=bin2hex(random_bytes(16));$year=spp_master_ensure_year($db,'2026/2027')['tahun_ajaran_id'];
    lf_query($db,"INSERT INTO legacy_full_batches(id,unit_id,source_id,version,digest,cutoff,year_id,state,manifest,before_backup,actor_id) VALUES(?,1,?,1,?,'2026-10-24 12:00:00',?,'completed','{}',?,1)",[$batch,$seed['source'],str_repeat('a',64),$year,str_repeat('b',32)]);
    lf_query($db,"INSERT INTO legacy_op_owners(unit_id,batch_id,source_identity,name,kind,source_class,state) VALUES(1,?,?,'Pemilik fixture','account','GK','ready')",[$batch,$seed['nis']]);$owner=(int)$db->insert_id;
    $row=hash('sha256',$batch.'fee');$record=['table'=>'bayar','row_key'=>$row,'raw'=>['id'=>'123','LAIN_LAIN1'=>'','NO_INDUK'=>$seed['nis']]];
    $context=['unit_id'=>1,'source_key'=>$source['backup_hash']];$category=lful_history_category('fixture');$decision=['status'=>'unresolved','rule'=>'unidentified_fee'];
    $item=['column'=>'JUMLAH1','category'=>$category,'label'=>'Biaya Legacy tanpa kode','amount'=>'100.00','migration'=>['version'=>LMA_VERSION,'period'=>'02 2019','status'=>'unresolved','rule'=>'unidentified_fee','priority'=>'historical']+lfet_metadata($record,$context,'JUMLAH1',$decision,$category)];
    lf_query($db,"INSERT INTO legacy_op_payments(unit_id,batch_id,owner_id,row_key,source_table,source_date,source_period,class_snapshot,items,total) VALUES(1,?,?,?,'bayar','2019-02-03','02 2019','1A',?,'100.00')",[$batch,$owner,$row,lf_json([$item])]);$payment=(int)$db->insert_id;
    $request=['decision_id'=>'fixture-client-closure','reason'=>'Klien menerima histori tanpa identifikasi jenis tambahan.','owners'=>[],'fees'=>[['unit_id'=>1,'batch_id'=>$batch,'source_key'=>$source['backup_hash'],'payment_id'=>$payment,'version'=>1,'detail_key'=>$item['migration']['detail_key'],'row_key'=>$row,'column'=>'JUMLAH1','category'=>$category,'amount'=>'100.00']]];
    lft_assert(lec_reconcile($db,1,$request)['fees_closed']===1,'Closure fixture did not apply');$closures=lec_saved($db,$batch);lec_verify($db,$batch,$closures);
    $input=['cancelled'=>true,'reason'=>'Pembatalan struk terbukti salah melalui koreksi resmi.','preview_expires'=>time()+300,'confirmation'=>'SIMPAN KOREKSI'];
    $preview=lop_correction_preview($db,1,1,'payment',$payment,2,$input);
    lop_correction_apply($db,1,1,'payment',$payment,2,$input,$preview['digest'],hash('sha256',$batch.'cancel'),false);
    lec_verify($db,$batch,$closures);lft_assert(lec_reconcile($db,1,$request)['changes']===0,'Replay replaced a later authorized cancellation');
    $p=lf_one($db,'SELECT * FROM legacy_op_payments WHERE id=?',[$payment]);lft_assert((int)$p['cancelled']===1,'Closure replay revived cancelled receipt');
    $input=['cancelled'=>false,'items'=>[],'reason'=>'Koreksi resmi menghapus rincian histori yang terbukti keliru.','preview_expires'=>time()+300,'confirmation'=>'SIMPAN KOREKSI'];
    // Existing correction intentionally rejects an empty receipt; preserve that protection.
    lft_reject(fn()=>lop_correction_preview($db,1,1,'payment',$payment,3,$input),'Existing empty receipt guard disappeared');
    $db->query('SAVEPOINT tampered');lf_query($db,"UPDATE legacy_op_payments SET note='Tanpa audit' WHERE id=?",[$payment]);
    lft_reject(fn()=>lec_verify($db,$batch,$closures),'Unaudited modification accepted as a correction');$db->query('ROLLBACK TO SAVEPOINT tampered');
    $input=['cancelled'=>false,'reason'=>'Pemulihan struk melalui koreksi resmi yang disahkan.','preview_expires'=>time()+300,'confirmation'=>'SIMPAN KOREKSI'];$preview=lop_correction_preview($db,1,1,'payment',$payment,3,$input);lop_correction_apply($db,1,1,'payment',$payment,3,$input,$preview['digest'],hash('sha256',$batch.'restore'),false);
    lec_verify($db,$batch,$closures);lft_assert(lec_reconcile($db,1,$request)['changes']===0,'Closure replay changed corrected receipt');
    $db->rollback();echo "PASS: closed receipt cancellation, restoration, audit verification, rejection of unaudited changes, existing empty-receipt guard and zero-change replay; rolled back.\n";
}catch(Throwable $e){$db->rollback();throw $e;}
