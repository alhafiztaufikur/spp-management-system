<?php
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/legacy_finance_support.php';
foreach(['A2025'=>2025,'B2024'=>2024,'a2122'=>2022,'A1920'=>2020,'OUT'=>null,'6A'=>null] as $code=>$year)lft_assert(lop_alumni_year($code)===$year,'Graduation suggestion '.$code);
lft_assert(!lop_testing_identity(['NO_INDUK'=>'2122001','NAMA'=>'Testari Putri']),'Substring matched a real name');
lft_assert(lop_testing_identity(['NO_INDUK'=>'test','NAMA'=>'test']),'Testing source lost');
$rows=[['table'=>'tabungan','raw'=>['SALDO'=>'1000']],['table'=>'transaksi_m','raw'=>['MASUK'=>'800','KELUAR'=>null,'TANGGAL'=>'2026-10-01']]];
foreach(['0'=>'-800.00','500'=>'-300.00','900'=>'100.00'] as $fix=>$delta){$r=lop_fixed_bank($rows,null,['amount'=>(string)$fix,'evidence'=>'Bukti penutupan rekening yayasan','confirmed'=>true]);lft_assert($r['ready']&&$r['source_balance']==='1000.00'&&$r['adjustment']===$delta,'FIX/adjustment mismatch');}
lft_reject(fn()=>lop_fixed_bank($rows,null,['amount'=>'-1','evidence'=>'Valid proof attached','confirmed'=>true]),'Negative FIX accepted');
$rows[1]['row_key']='journal';$copy=$rows;$effective=lop_effective_bank_rows($rows,['op_journals'=>['journal'=>['incoming'=>'900','outgoing'=>'0','date'=>'2026-10-01','confirmed'=>true,'evidence'=>'Koreksi jurnal sumber yang disahkan.']]]);lft_assert($rows===$copy&&lop_fixed_bank($effective,null,['amount'=>'975','confirmed'=>true,'evidence'=>'Bukti saldo penutupan rekening.'])['adjustment']==='75.00','FIX preview differs from corrected journal or raw mutated');
$ambiguous=$rows;$ambiguous[]=$rows[0];lft_assert(!lop_fixed_bank($ambiguous,null,['amount'=>'900','evidence'=>'Valid proof attached','confirmed'=>true])['ready'],'FIX bypassed duplicate account identity');
$records=[['table'=>'lain','raw'=>['KODE'=>'057','NAMA'=>'Eskul Robotik']],['table'=>'lain','raw'=>['KODE'=>'0016','NAMA'=>'Basket']],['table'=>'lain','raw'=>['KODE'=>'0016','NAMA'=>'Pendalaman Materi']],['table'=>'bayar','raw'=>['LAIN_LAIN1'=>'057','JUMLAH1'=>'500','LAIN_LAIN2'=>'057','JUMLAH2'=>'200','LAIN_LAIN3'=>'022','JUMLAH3'=>'100','LAIN_LAIN4'=>'0016','JUMLAH4'=>'10']]];
foreach($records as &$r)$r['money_columns']=array_values(array_intersect(array_keys($r['raw']),['JUMLAH1','JUMLAH2','JUMLAH3','JUMLAH4']));unset($r);
$catalog=array_values(lful_catalog($records));lft_assert($catalog[0]['name']==='Eskul Robotik'&&$catalog[0]['proposal_key']===$catalog[1]['proposal_key'],'Master resolution across slots');
lft_assert($catalog[2]['name']==='Lain Test'&&$catalog[3]['ambiguous'],'Unmatched or duplicate code hidden');
require_once __DIR__.'/../includes/savings_book.php';
$book=savings_book_make([],900,['amount'=>'900','effective_date'=>'2026-10-17'],[['tanggal'=>'2026-10-01','masuk'=>'800','keluar'=>'0'],['tanggal'=>'2026-10-17','masuk'=>'100','keluar'=>'0','origin'=>'adjustment']]);
lft_assert($book['balance']===90000&&$book['entries'][1]['label']==='Penyesuaian Saldo Migrasi'&&$book['entries'][2]['masuk']===0,'Book counted FIX twice');
echo "PASS: alumni suggestions, exact test identity, FIX positive/negative/zero, duplicate account hold, source fee names and savings book continuation.\n";
