<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/legacy_finance.php';require_once __DIR__.'/../includes/savings_book.php';
require_once __DIR__.'/legacy_finance_support.php';
foreach(['0'=>0,'250000'=>25000000,'30.50'=>3050,'30.5000'=>3050] as $v=>$c)lft_assert(lf_cents((string)$v)===$c,'Exact cents');
foreach([null,1.5,'1.001','-1','1e3','NaN','1,000'] as $v)lft_reject(fn()=>lf_cents($v),'Unsafe amount accepted');
$raws=[['table'=>'tabungan','raw'=>['SALDO'=>'300.50']],['table'=>'transaksi_m','raw'=>['MASUK'=>'400.50','KELUAR'=>null,'TANGGAL'=>'2019-02-01T10:00:00']],['table'=>'transaksi_k','raw'=>['MASUK'=>null,'KELUAR'=>'100','TANGGAL'=>'2019-02-02T10:00:00']]];
lft_assert(lf_reconcile($raws)['ready'],'Audited NULL opposite-direction journal');
lft_assert(!lf_reconcile($raws,'2019-01-31')['ready'],'Future source journal accepted');
foreach(['duplicate','mismatch','precision','date','direction','missing'] as $case){$bad=$raws;switch($case){case 'duplicate':$bad[]=$raws[0];break;case 'mismatch':$bad[0]['raw']['SALDO']='301';break;case 'precision':$bad[0]['raw']['SALDO']='300.501';break;case 'date':$bad[1]['raw']['TANGGAL']=null;break;case 'direction':$bad[1]['raw']['KELUAR']='1';break;case 'missing':$bad[1]['raw']['MASUK']=null;break;}lft_assert(!lf_reconcile($bad)['ready'],'Unsafe reconciliation '.$case);}
$book=savings_book_make([['id'=>1,'tanggal'=>'2026-10-16','masuk'=>10,'keluar'=>0,'urutan_mutasi'=>0]],310.5,['amount'=>'300.50','effective_date'=>'2026-10-15']);lft_assert($book['entries'][0]['masuk']===0&&$book['balance']===31050,'Opening was treated as a deposit');
echo "PASS: exact amounts, duplicates, discrepancies, precision, NULL dates, direction, incomplete journals, separate opening book entry.\n";
if(getenv('SPP_TEST_ALLOW_MUTATION')!=='1')exit;
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);lf_require_schema($koneksi);
require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/savings_book_print.php';
$db=$koneksi;$actor=(int)$db->query("SELECT id FROM admin WHERE is_active=1 AND role='super_admin' LIMIT 1")->fetch_row()[0];
$fixtures=[];$sharedNis=(string)random_int(9700000000,9799999999);
foreach([1,2,3] as $unit){
    $f=lft_seed($db,$actor,$unit,false,$sharedNis);$fixtures[]=$f;
    $proposal=['effective_date'=>date('Y-m-d'),'evidence'=>'Bukti yayasan QA terisolasi','attested'=>true,'savings'=>true,'debts'=>[['component'=>'Komponen QA '.$f['id'],'period'=>'2018-07','amount'=>'15000','kind'=>'other','source_row_key'=>$f['key'],'distinction'=>'Bukti kewajiban berbeda dari tagihan existing.']]];
    $before=lf_one($db,'SELECT (SELECT SUM(total_jumlah) FROM bayar) cash,(SELECT COUNT(*) FROM transaksi_m) deposits');
    $r=lf_save_review($db,$actor,$unit,$f['id'],1,$proposal);
    lft_reject(fn()=>lf_save_review($db,$actor,$unit,$f['id'],1,$proposal),'Stale review accepted');
    lft_reject(fn()=>lf_apply($db,$actor,$unit,$f['id'],2,'wrong'),'Missing confirmation accepted');
    lft_reject(fn()=>lf_apply($db,$actor,0,$f['id'],2,'TERAPKAN LEGACY'),'All-unit mutation accepted');
    $r=lf_apply($db,$actor,$unit,$f['id'],2,'TERAPKAN LEGACY');lft_assert($r['status']==='applied','Application failed');
    lf_apply($db,$actor,$unit,$f['id'],2,'TERAPKAN LEGACY');
    lft_assert($before===lf_one($db,'SELECT (SELECT SUM(total_jumlah) FROM bayar) cash,(SELECT COUNT(*) FROM transaksi_m) deposits'),'Opening created cash');
    lft_assert((float)lf_one($db,'SELECT SALDO FROM tabungan WHERE NO_INDUK=?',[$f['nis']])['SALDO']===300.5,'Opening cache');
    [$student,$book]=savings_book_load($db,$f['nis'],$f['student'],$unit);lft_assert($book['balance']===30050&&$book['entries'][0]['label']==='Saldo awal Legacy','Opening book');
    $filters=report_filters($db,['tanggal_awal'=>date('Y-m-d'),'tanggal_akhir'=>date('Y-m-d')]);$transactions=report_savings_transactions($db,date('Y-m-d').' 00:00:00',date('Y-m-d',strtotime('+1 day')).' 00:00:00',$filters);
    $only=array_values(array_filter($transactions,fn($t)=>$t['NO_INDUK']===$f['nis']));lft_assert(count($only)===1&&$only[0]['jenis']==='Saldo Awal','Opening report row');$summary=report_savings_cash_summary($only);lft_assert($summary['total_masuk']==0&&$summary['count_total']===0,'Opening counted as cash');
    $debt=lf_one($db,'SELECT * FROM legacy_debts WHERE review_id=?',[$f['id']]);lft_assert((float)$debt['amount']===15000.0,'Missing Legacy debt');
    lft_reject(fn()=>lf_query($db,'UPDATE master_biaya_lain SET nominal=10 WHERE id=?',[$debt['master_id']]),'Reserved master changed');
    lft_reject(fn()=>lf_query($db,'DELETE FROM legacy_savings_openings WHERE review_id=?',[$f['id']]),'Opening directly deleted');
    lft_reject(fn()=>lf_query($db,"UPDATE legacy_finance_rows SET raw_data='{}' WHERE source_id=?",[$f['source']]),'Archive edited');
}
// Pending target is held, and a failed debt application rolls back its opening too.
$pending=lft_seed($db,$actor,1,true);$proposal=['effective_date'=>date('Y-m-d'),'evidence'=>'Bukti yayasan QA terisolasi','attested'=>true,'savings'=>true,'debts'=>[]];lf_save_review($db,$actor,1,$pending['id'],1,$proposal);lft_reject(fn()=>lf_apply($db,$actor,1,$pending['id'],2,'TERAPKAN LEGACY'),'Pending target accepted');
$atomic=lft_seed($db,$actor,1);$bad=$proposal;$bad['debts']=[['component'=>'QA atomik','period'=>'2018-07','amount'=>'10','kind'=>'other','source_row_key'=>str_repeat('a',64),'distinction'=>'Pemeriksaan bukti kewajiban existing.']];lf_save_review($db,$actor,1,$atomic['id'],1,$bad);lft_reject(fn()=>lf_apply($db,$actor,1,$atomic['id'],2,'TERAPKAN LEGACY'),'Missing source accepted');lft_assert(!lf_one($db,'SELECT id FROM legacy_savings_openings WHERE student_id=?',[$atomic['student']]),'Partial opening survived rollback');
// Cashier updates serialize on student, and a used opening is not cancellable.
$f=$fixtures[0];unit_set_context($db,1);$db->begin_transaction();lf_one($db,'SELECT id FROM siswa WHERE id=? FOR UPDATE',[$f['student']]);lf_query($db,"INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id) VALUES(?,NOW(),50,0,?)",[$f['nis'],$actor]);lf_query($db,'UPDATE tabungan SET SALDO=SALDO+50 WHERE NO_INDUK=?',[$f['nis']]);$db->commit();lft_reject(fn()=>lf_cancel_opening($db,$actor,1,$f['id'],3,'Uji saldo awal sudah digunakan','BATALKAN SALDO AWAL'),'Used opening cancelled');
[$s,$book]=savings_book_load($db,$f['nis'],$f['student'],1);lft_assert($book['balance']===35050,'New deposit did not follow opening');
$f=$fixtures[1];lf_cancel_opening($db,$actor,2,$f['id'],3,'Bukti koreksi QA tanpa transaksi lanjutan','BATALKAN SALDO AWAL');lft_assert((float)lf_one($db,'SELECT SALDO FROM tabungan WHERE NO_INDUK=?',[$f['nis']])['SALDO']===0.0,'Unused opening cancellation');
lf_cancel_debts($db,$actor,2,$f['id'],4,'Bukti kewajiban QA dibatalkan sebelum pembayaran','BATALKAN TUNGGAKAN LEGACY');lft_assert(lf_one($db,'SELECT t.status FROM legacy_debts d JOIN tagihan_biaya_lain t ON t.id=d.bill_id WHERE d.review_id=?',[$f['id']])['status']==='cancelled','Unpaid debt cancellation');
$duplicate=lft_seed($db,$actor,3);$original=lf_review_load($db,$fixtures[2]['id']);lf_map($db,$actor,3,$duplicate['id'],1,$fixtures[2]['student'],'Pencocokan sumber QA kedua ke siswa yang sama.');$again=$original['review_data'];$again['savings']=false;$again['debts'][0]['source_row_key']=$duplicate['key'];lf_save_review($db,$actor,3,$duplicate['id'],2,$again);lft_reject(fn()=>lf_apply($db,$actor,3,$duplicate['id'],3,'TERAPKAN LEGACY'),'Same obligation imported from another backup');
unit_set_context($db,3);lft_reject(fn()=>lf_review_load($db,$fixtures[0]['id']),'Cross-unit review exposed');
unit_set_context($db,0);$all=report_build($db,'saldo-tabungan',report_filters($db,['q'=>$sharedNis]));lft_assert(count($all['rows'])===3 && array_sum(array_column($all['rows'],'saldo_saat_ini'))===651.0,'Same NIS across units mixed opening balances');
file_put_contents(dirname((string)getenv('SPP_TEST_ISOLATED_INSTANCE')).'/finance-fixtures.json',lf_json($fixtures));
echo "PASS: all units, stale versions, confirmation, retry, pending target, atomic rollback, protected archive/master/opening, no cash, running books/reports and cancellation guards.\n";
