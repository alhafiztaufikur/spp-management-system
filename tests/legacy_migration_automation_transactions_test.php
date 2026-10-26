<?php
/** Transaction rollback test. Requires a separately owned MySQL instance, never port 3306. */
if(PHP_SAPI!=='cli'||(int)getenv('SPP_DB_PORT')===3306||!getenv('SPP_MIGRATION_TEST_UUID'))throw new RuntimeException('Instance MySQL uji terisolasi wajib disebutkan.');
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
$db=$koneksi;if($db->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_MIGRATION_TEST_UUID'))throw new RuntimeException('Identitas server uji tidak cocok.');
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/legacy_billing.php';require_once __DIR__.'/../includes/legacy_operational_corrections.php';require_once __DIR__.'/legacy_finance_support.php';
$unit=1;unit_set_context($db,$unit);$actor=(int)lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")['id'];
$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit];
$before=(int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_payments')['n'];$mastersBefore=(int)lf_one($db,'SELECT COUNT(*) n FROM master_biaya_lain')['n'];$db->begin_transaction();
try{
    $fixture=lft_seed($db,$actor,$unit);$student=$fixture['student'];$nis=$fixture['nis'];$batch=bin2hex(random_bytes(16));$year=(int)spp_master_ensure_year($db,'2026/2027')['tahun_ajaran_id'];
    lf_query($db,"INSERT INTO legacy_full_batches(id,unit_id,source_id,version,digest,cutoff,year_id,state,manifest,before_backup,actor_id) VALUES(?,?,?,1,?,'2026-10-19 11:00:00',?,'completed','{}',?,?)",[$batch,$unit,$fixture['source'],str_repeat('a',64),$year,str_repeat('a',32),$actor]);
    lf_query($db,"INSERT INTO legacy_full_students(unit_id,batch_id,source_identity,student_id,review_id,settings) VALUES(?,?,?,?,?,?)",[$unit,$batch,$nis,$student,$fixture['id'],lf_json(['start_period'=>'2026-11','kind'=>'active'])]);
    lf_query($db,"INSERT INTO legacy_op_groups(unit_id,code,label,kind,aliases) VALUES(?,'AUTOMATION_QA','Pengujian migrasi','academic','[]')",[$unit]);$group=(int)$db->insert_id;
    lf_query($db,"INSERT INTO legacy_op_owners(unit_id,batch_id,source_identity,name,kind,group_id,student_id,source_class,state) VALUES(?,?,?,'Pengujian migrasi','active',?,?,'1A','ready')",[$unit,$batch,$nis,$group,$student]);$owner=(int)$db->insert_id;
    $newItems=[['column'=>'U_SPP','category'=>'spp','label'=>'SPP','amount'=>'100.00','migration'=>['version'=>1,'period'=>'04 2026']],['column'=>'U_KEGIATAN','category'=>'daftar_ulang','label'=>'Daftar Ulang','amount'=>'1000.00','migration'=>['version'=>1,'period'=>'2026/2027','source_row_key'=>hash('sha256','source'),'source_code'=>null,'status'=>'direct','rule'=>'du_recap_reconciled']]];
    $oldItems=[['column'=>'U_KEGIATAN','category'=>'daftar_ulang','label'=>'Daftar Ulang','amount'=>'200.00']];$ids=[];
    foreach([$newItems,$oldItems] as $index=>$items){$rowKey=hash('sha256',$batch.'|'.$index);$total=$index===0?'1100.00':'200.00';lf_query($db,"INSERT INTO legacy_op_payments(unit_id,batch_id,owner_id,row_key,source_table,source_date,source_period,class_snapshot,items,total) VALUES(?,?,?,?,'bayar','2026-04-15 08:00:00','04 2026','1A',?,?)",[$unit,$batch,$owner,$rowKey,lf_json($items),$total]);$ids[]=(int)$db->insert_id;}
    lft_assert(lb_du_credit($db,$nis,'2026/2027',$unit)===1000.0&&lb_du_credit($db,$nis,'2025/2026',$unit)===200.0,'Pembayaran DU baru atau lama masuk tahun yang salah');
    require_once __DIR__.'/../includes/reports.php';
    lft_assert(isset(report_legacy_years($db)['2026/2027']),'Tahun DU pembayaran di muka hilang dari pilihan tahun');
    $periods=report_legacy_periods($db,['2026/2027']);lft_assert(($periods['payments'][$nis]['daftar_ulang']['2026/2027']['paid']??0)===100000,'Filter tahun menghilangkan DU dalam kuitansi campuran');
    foreach($ids as $index=>$id){$r=lf_one($db,'SELECT ('.lb_du_year_sql('p').') year FROM legacy_op_payments p WHERE p.id=?',[$id]);lft_assert($r['year']===($index===0?'2026/2027':'2025/2026'),'Pembaca SQL tidak kompatibel');}
    $inputItems=$newItems;foreach($inputItems as &$item)unset($item['migration']);unset($item);$inputItems[1]['migration']=['version'=>1,'period'=>'2099/2100'];
    $preview=lop_correction_preview($db,$actor,$unit,'payment',$ids[0],1,['items'=>$inputItems,'note'=>'Catatan diperjelas','reason'=>'Pengujian pelestarian metadata migrasi.','preview_expires'=>time()+300]);
    lft_assert(lf_canonical($preview['after']['items'][1]['migration'])===lf_canonical($newItems[1]['migration']),'Koreksi menghilangkan metadata tahun DU');
    $oldPreview=lop_correction_preview($db,$actor,$unit,'payment',$ids[1],1,['note'=>'Catatan lama diperjelas','reason'=>'Pengujian kompatibilitas format lama.','preview_expires'=>time()+300]);lft_assert(!isset($oldPreview['after']['items'][0]['migration']),'Koreksi menyuntikkan aturan baru ke data lama');
    $key=lful_proposal_key('automation-qa');$cfg=['components'=>['x'=>['category'=>lful_new_category($key)]],'new_masters'=>[$key=>['name'=>'Biaya Pengujian Otomasi','nominal'=>'0.00','tariff_pending'=>true,'confirmed'=>true,'reason'=>'Nama sumber pada pengujian migrasi otomatis.']]];
    $proposals=lful_proposals($db,$cfg);$resolved=lful_create_proposals($db,$unit,$proposals);$master=lf_one($db,"SELECT * FROM master_biaya_lain WHERE nama='Biaya Pengujian Otomasi'");lft_assert((int)$master['is_active']===0&&(int)$master['legacy_tariff_pending']===1&&$master['nominal']==='0.00','Master historis menjadi tarif aktif');
    $projection=lful_resolve_projection(['payments'=>[['items'=>[['category'=>lful_new_category($key),'amount'=>'7000.00']]]],'students'=>[]],$resolved);lft_assert($projection['payments'][0]['items'][0]['category']==='biaya_lain:'.$master['id']&&$projection['payments'][0]['items'][0]['amount']==='7000.00','Resolusi master mengubah pembayaran');
    require_once __DIR__.'/../includes/legacy_operational_obligations.php';
    $coverage=['category'=>'daftar_ulang','period'=>'2026/2027','principal'=>'1000.00','disposition'=>'settled','evidence'=>'Pembayaran DU tahun berikutnya telah direkonsiliasi.'];$sppCoverage=['category'=>'spp','period'=>'2026-04','principal'=>'100.00','disposition'=>'settled','evidence'=>'Pembayaran SPP bulanan telah direkonsiliasi.'];
    lop_apply_obligations($db,$unit,$actor,$batch,$fixture['source'],['owners'=>[$nis=>['ready'=>true,'student'=>true,'decision'=>['coverage'=>[$coverage,$sppCoverage]]]],'cutoff'=>'2026-10-19 11:00:00'],[$nis=>['id'=>$owner,'student_id'=>$student]]);
    $bound=lf_one($db,'SELECT * FROM legacy_op_payments WHERE id=?',[$ids[0]]);$boundItems=json_decode($bound['items'],true);lft_assert(!empty($boundItems[1]['obligation_id']),'DU campuran tidak dihubungkan ke tahun kewajiban yang benar');
    foreach($boundItems as &$item)unset($item['migration']);unset($item);
    $boundPreview=lop_correction_preview($db,$actor,$unit,'payment',$ids[0],(int)$bound['version'],['items'=>$boundItems,'note'=>'Catatan pembayaran terikat','reason'=>'Pengujian periode kewajiban DU campuran.','preview_expires'=>time()+300]);lft_assert(lma_item_period($boundPreview['after']['items'][1],'04 2026')==='2026/2027','Koreksi kewajiban campuran memakai periode bulanan');
    lf_query($db,"INSERT INTO legacy_op_owners(unit_id,batch_id,source_identity,name,kind,group_id,source_class,state) VALUES(?,?,?,'Pemilik koreksi uji','account',?,'1A','ready')",[$unit,$batch,'QA-SECOND',$group]);$secondOwner=(int)$db->insert_id;$movedItems=$boundItems;
    foreach(['spp'=>['2026-04','100.00'],'daftar_ulang'=>['2026/2027','1000.00']] as $category=>[$period,$principal]){lf_query($db,'INSERT INTO legacy_op_obligations(unit_id,owner_id,category,period,principal,remaining,evidence) VALUES(?,?,?,?,?,?,?)',[$unit,$secondOwner,$category,$period,$principal,$principal,'Kewajiban pemilik tujuan pengujian.']);$obligation=(int)$db->insert_id;foreach($movedItems as &$item)if($item['category']===$category)$item['obligation_id']=$obligation;unset($item);}
    $moved=lop_correction_preview($db,$actor,$unit,'payment',$ids[0],(int)$bound['version'],['owner_id'=>$secondOwner,'items'=>$movedItems,'reason'=>'Pengujian metadata pada koreksi pemilik.','preview_expires'=>time()+300]);lft_assert(lf_canonical($moved['after']['items'][1]['migration'])===lf_canonical($newItems[1]['migration']),'Koreksi pemilik menghilangkan bukti sumber atau tahun DU');
    $reclassified=$boundItems;$reclassified[1]['category']='histori:'.str_repeat('a',32);unset($reclassified[1]['obligation_id']);$changed=lop_correction_preview($db,$actor,$unit,'payment',$ids[0],(int)$bound['version'],['items'=>$reclassified,'reason'=>'Pengujian bukti sumber setelah klasifikasi.','preview_expires'=>time()+300]);lft_assert(lf_canonical($changed['after']['items'][1]['migration'])===lf_canonical($newItems[1]['migration']),'Koreksi klasifikasi menghilangkan bukti sumber');
    lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM bayar')['n']===0,'Histori berubah menjadi transaksi operasional baru');
    // This write regression may only run on the UUID/port-guarded instance above.
    require_once __DIR__.'/../includes/legacy_fee_reconstruction.php';
    $feeRaw=['id'=>'69487','NO_INDUK'=>$nis,'KELAS'=>'1A','TGL_BYR'=>'2026-09-24','BULAN'=>'09','TAHUN'=>'2026','U_LAIN'=>'0','LAIN_LAIN1'=>'057  ','JUMLAH1'=>'5.00','LAIN_LAIN2'=>'057','JUMLAH2'=>'7.00'];
    $feeRowKey=hash('sha256',$batch.'fee-bayar');
    foreach(['lain'=>['KODE'=>'057','NAMA'=>'Biaya Pelacakan QA'],'bayar'=>$feeRaw] as $table=>$raw){$rk=$table==='bayar'?$feeRowKey:hash('sha256',$batch.'fee-lain');$amounts=$table==='bayar'?['U_LAIN'=>'0','JUMLAH1'=>'5.00','JUMLAH2'=>'7.00']:[];
        lf_query($db,'INSERT INTO legacy_finance_rows(unit_id,source_id,source_table,row_key,row_hash,occurrence,source_nis,amounts,raw_data,reference_only) VALUES(?,?,?,?,?,1,?,?,?,?)',[$unit,$fixture['source'],$table,$rk,hash('sha256',lf_canonical($raw)),$table==='bayar'?$nis:'',lf_json($amounts),lf_json($raw),$table==='lain'?1:0]);}
    $feeItems=[];foreach([1=>'5.00',2=>'7.00'] as $slot=>$amount)$feeItems[]=['column'=>'JUMLAH'.$slot,'category'=>lful_history_category('qa-fee-'.$slot),'label'=>'057','amount'=>$amount];
    lf_query($db,"INSERT INTO legacy_op_payments(unit_id,batch_id,owner_id,row_key,source_table,source_date,source_period,class_snapshot,items,total) VALUES(?,?,?,?,'bayar','2026-09-24','09 2026','1A',?,'12.00')",[$unit,$batch,$owner,$feeRowKey,lf_json($feeItems)]);$feePayment=(int)$db->insert_id;
    $mapping=['automation_version'=>LMA_VERSION,'du_policy'=>'reference','other_policy'=>'source_details','discount_policy'=>'information','accounting_reason'=>'Isolated automatic fee trace source fixture.'];lf_query($db,'UPDATE legacy_full_batches SET manifest=? WHERE id=?',[lf_json(['mapping'=>$mapping]),$batch]);
    $cashBefore=lf_one($db,'SELECT COUNT(*) receipts,SUM(total) amount FROM legacy_op_payments');$nativeBefore=(int)lf_one($db,'SELECT COUNT(*) n FROM bayar_biaya_lain')['n'];
    $trace=lfer_reconcile($db,$actor);lft_assert($trace['payments_enriched']===1&&$trace['masters_created']===1,'Fee source was not automatically enriched');
    $fee=lf_one($db,'SELECT * FROM legacy_op_payments WHERE id=?',[$feePayment]);$fee['items']=json_decode($fee['items'],true);lfet_validate_payment($fee);$target=(int)$fee['items'][0]['migration']['target_master_id'];
    $feeMaster=lf_one($db,'SELECT * FROM master_biaya_lain WHERE id=?',[$target]);lft_assert((int)$feeMaster['is_active']===0&&(int)$feeMaster['legacy_tariff_pending']===1&&$feeMaster['nominal']==='0.00','Fee master invented an active tariff');
    lft_assert($fee['items'][0]['category']===$fee['items'][1]['category']&&$fee['items'][0]['migration']['detail_key']!==$fee['items'][1]['migration']['detail_key'],'Repeated source code lost per-slot identity');
    lft_assert(lfer_reconcile($db,$actor)['changes']===0,'Fee enrichment replay wrote changes');
    lft_assert(lf_one($db,'SELECT COUNT(*) receipts,SUM(total) amount FROM legacy_op_payments')===$cashBefore&&(int)lf_one($db,'SELECT COUNT(*) n FROM bayar_biaya_lain')['n']===$nativeBefore,'Fee trace duplicated cash/native details');
    require_once __DIR__.'/../includes/legacy_fee_trace_read.php';$fee['source_id']=$fixture['source'];$receipt=lfet_hydrate_receipts($db,[$fee])[0];lft_assert($receipt['items'][0]['source_trace']['transaction_id']==='69487'&&$receipt['items'][0]['source_trace']['code']==='057','Stored receipt cannot trace source transaction/code');
    lft_assert((int)lf_one($db,"SELECT COUNT(*) n FROM legacy_op_audit WHERE entity_type='fee_trace' AND entity_id=?",[$feePayment])['n']===1,'Fee trace audit missing or duplicated');
    echo "PASS: automatic fee enrichment, inactive master, distinct slots, replay zero, immutable cash, receipt source lookup and audit.\n";
    echo "PASS: pembacaan DU baru/lama di MySQL, metadata koreksi, master nonaktif, resolusi ID dan nominal tetap.\n";
}finally{$db->rollback();}
lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_payments')['n']===$before&&(int)lf_one($db,'SELECT COUNT(*) n FROM master_biaya_lain')['n']===$mastersBefore,'Rollback pengujian meninggalkan hasil migrasi');
echo "PASS: seluruh penulisan fixture dibatalkan pada instance uji terisolasi.\n";
if(in_array('--draft',$argv,true)){
    // Exercise the real enqueue -> prepare -> bulk -> simulate -> confirm path; never enqueue apply.
    lful_empty($db,$unit);$rows=[];$add=static function(string $table,array $raw)use(&$rows):void{$rows[]=['table'=>$table,'raw'=>$raw,'reference_only'=>in_array($table,['lain','Daftar_ulang','du'],true)||str_starts_with($table,'tb_'),'student_ordinal'=>count($rows)+1,'money_columns'=>array_values(array_intersect(array_keys($raw),['U_SPP','U_KEGIATAN','U_LAIN','JUMLAH1','jumlah','Jumlah','SALDO','MASUK','KELUAR','SPP_PERBULAN','POMG']))];};
    $nis='212200001';$add('siswa',['NO_INDUK'=>$nis,'NAMA'=>'Pengujian Draf Otomatis','KELAS'=>'1A','SPP_PERBULAN'=>'530000','POMG'=>'15000']);$add('lain',['KODE'=>'0022','NAMA'=>'Buku Otomasi QA']);
    if(in_array('--du-publication',$argv,true)){$rows[0]['raw']['KELAS']='1A';$rows[0]['raw']['DAFTAR_ULANG']='1000000';$rows[0]['raw']['potong_du']='0';$rows[0]['raw']['tot_du']='1000000';} // Complete individual header; no class tariff row.
    foreach(['2026-04-15','2026-05-15'] as $date)$add('bayar',['NO_INDUK'=>$nis,'KELAS'=>'1A','U_SPP'=>'100000','U_KEGIATAN'=>'500000','LAIN_LAIN1'=>'0022','JUMLAH1'=>'7000','U_LAIN'=>'0','TGL_BYR'=>$date,'BULAN'=>substr($date,5,2),'TAHUN'=>'2026','th_ajaran'=>'2026/2027','kelas_du'=>in_array('--du-publication',$argv,true)?'1':'2']);
    foreach([1,2] as $ignored)$add('bayar_du',['no_induk'=>$nis,'kelas'=>in_array('--du-publication',$argv,true)?'1':'2','th_ajaran'=>'2026/2027','jumlah'=>'1000000']);
    $add('Daftar_ulang',['th_ajaran'=>'2026/2027','kelas'=>'2','Jumlah'=>'1100000']);
    $add('du',['th_ajaran'=>'2026/2027','kelas'=>'3','Jumlah'=>'1200000']);
    $add('tabungan',['NO_INDUK'=>$nis,'SALDO'=>'90000']);$add('transaksi_m',['NO_INDUK'=>$nis,'MASUK'=>'90000','TANGGAL'=>'2022-01-01']);$add('tb_total',['NO_INDUK'=>$nis,'SALDO'=>'22']);$add('tb_masuk',['NO_INDUK'=>$nis,'MASUK'=>'22','TANGGAL'=>'2023-07-12']);
    $temporary=tempnam(legacy_root(),'automation-');file_put_contents($temporary,'Owned automation draft test');$job=legacy_enqueue($temporary,'automation-qa.dat',$unit,$actor,'full');unlink($temporary);$id=$job['id'];$dir=legacy_root().'/'.$id;$counts=[];foreach($rows as $r)$counts[$r['table']]=($counts[$r['table']]??0)+1;
    file_put_contents($dir.'/finance.jsonl',implode("\n",array_map('lf_json',$rows))."\n");file_put_contents($dir.'/finance_manifest.json',lf_json(['profile'=>'legacy-finance-v1','unit'=>$unit,'backup_hash'=>$job['hash'],'source_date'=>'2026-10-17T08:00:00','counts'=>$counts,'rows_sha256'=>hash_file('sha256',$dir.'/finance.jsonl')]));file_put_contents($dir.'/operational-request.json','{"mode":"operational"}');
    lful_stage($db,$job);$d=lful_draft($id);lft_assert(lma_enabled($d['settings'])&&!empty($d['settings']['op_preserve_unproven_balances']),'Pekerjaan baru tidak mengaktifkan aturan baru dan perlindungan saldo belum terbukti');$year=spp_master_ensure_year($db,'2026/2027');$d=lful_save($db,$actor,$unit,$id,(int)$d['version'],['cutoff'=>'2026-10-19 11:00:00','year_id'=>(int)$year['tahun_ajaran_id']]);
    [$sourceRows,$manifest]=lf_extract_read($job,true,true);
    foreach([2=>110000000,3=>120000000] as $grade=>$principal){
        $preview=ldu_source_preview($sourceRows,['tariff-only'=>['ready'=>true,'kind'=>'active','student'=>true,'raw'=>['KELAS'=>$grade.'A','potong_du'=>'0'],'source_key'=>'tariff-only']],[],'2026/2027');
        lft_assert($preview['ready']===1&&$preview['principal_cents']===$principal,'Pembaca operasional menghilangkan tarif referensi DU dari preview.');
    }
    $catalog=lful_catalog_read($id);$patch=lop_bulk_patch($sourceRows,$catalog,$d['settings'],$unit,'all');$d=lful_save($db,$actor,$unit,$id,(int)$d['version'],$patch['patch']);$sim=lop_simulate($db,$id);
    lft_assert($sim['ready']&&$sim['summary']['payments']===2&&$sim['summary']['payment_total']==='1214000.00'&&$sim['summary']['savings_total']==='90000.00'&&$sim['summary']['du_reconciled']===2&&$sim['summary']['new_masters']===1,'Alur draf nyata menghasilkan proyeksi salah: '.lf_json($sim['issues']));
    $confirmation=lful_confirm($db,$actor,$unit,$id,(int)$d['version'],$sim['digest']);lft_assert(!lf_one($db,'SELECT id FROM siswa')&&!lf_one($db,'SELECT id FROM master_biaya_lain'),'Simulasi menerapkan siswa atau master');
    require_once __DIR__.'/../includes/legacy_operational_history.php';$references=lop_history_search($db,legacy_job($id),['kind'=>'du_reconciled']);lft_assert($references['total']===2,'Rekap DU tidak dapat ditelusuri dari draf migrasi');
    echo "PASS: antrean baru, aturan versi baru, persetujuan massal, simulasi dan konfirmasi nyata; tanpa menjalankan penerapan.\n";
    if(in_array('--publication',$argv,true)){
        // Opt-in end-to-end publication on the guarded, owned test instance only.
        require_once __DIR__.'/../includes/database_backup.php';db_backup_assert_application(db_backup_config(),$db);db_backup_store(true);$backup=lful_backup($db,$actor,$id,'automation-test-before');
        putenv('SPP_FULL_TEST_FAIL=before_commit');$interrupted=false;try{lop_apply($db,$job,$sim,$backup);}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'before_commit'))throw $e;$interrupted=true;}finally{putenv('SPP_FULL_TEST_FAIL');}
        lft_assert($interrupted&&!lf_one($db,'SELECT id FROM siswa')&&!lf_one($db,'SELECT id FROM legacy_op_payments')&&!lf_one($db,'SELECT id FROM master_biaya_lain'),'Kegagalan penerapan meninggalkan sebagian hasil');
        lful_enqueue_apply($db,$actor,$unit,$id,(int)$d['version'],$confirmation['token']);lop_run($db,legacy_job($id));$firstDigest=lop_working_digest($db,$id);$firstManifest=lf_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$id])['manifest'];lop_run($db,legacy_job($id));
        lft_assert(lop_working_digest($db,$id)===$firstDigest&&lf_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$id])['manifest']===$firstManifest,'Pengulangan publikasi mengubah hasil atau fingerprint');
        lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_payments')['n']===2&&(int)lf_one($db,'SELECT COUNT(*) n FROM master_biaya_lain')['n']===1&&lf_cents((string)lf_one($db,'SELECT SALDO FROM tabungan WHERE NO_INDUK=?',[$nis])['SALDO'])===9000000,'Penerapan mengubah sumber saldo atau menduplikasi pembayaran/master');
        $stored=lop_projection($db,$id)[0];foreach($stored as $payment)foreach($payment['items'] as $item)if($item['category']==='daftar_ulang')lft_assert(lma_item_period($item,$payment['period'])==='2026/2027','Metadata DU tidak tersimpan dalam hasil penerapan');
        if(in_array('--du-publication',$argv,true)){
            require_once __DIR__.'/../includes/legacy_du.php';$bill=du_find_bill($db,$nis,10,2026);
            lft_assert($bill&&$bill['nominal_tagihan']===1000000.0&&$bill['terbayar']===1000000.0&&(float)$bill['sisa']===0.0,'Migrasi baru tidak membentuk tagihan DU lunas dari bukti lengkap.');
            lft_assert(ldu_reconcile($db,$actor)['changes']===0,'Migrasi baru dan rekonsiliasi existing tidak setara.');
            lft_assert(ldu_verify($db)['lunas']===1,'Verifikasi DU tidak mencakup hasil publikasi baru.');
            echo "PASS: migrasi baru DU lunas, kelas kewajiban terpisah dari kelas kas, master terbit otomatis, kredit sekali dan rekonsiliasi nol perubahan.\n";
        }
        lop_check_result($db,$id);lft_assert(lop_history_search($db,legacy_job($id),['kind'=>'du_reconciled'])['total']===2,'Rekap tidak dapat ditelusuri setelah publikasi');
        echo "PASS: publikasi fixture pada instance terisolasi, rollback atomik, pemulihan, metadata tersimpan dan pengulangan tanpa duplikasi.\n";
    }
}
