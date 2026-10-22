<?php
/** Deliberate SQL failure on the disposable clone; the renamed column is restored. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
if((int)getenv('SPP_DB_PORT')===3306||!getenv('SPP_KOMITE_TEST_UUID'))throw new RuntimeException('Owned isolated instance required.');
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
$db=$koneksi;if($db->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_KOMITE_TEST_UUID'))throw new RuntimeException('Server UUID differs.');
$f=json_decode(file_get_contents(getenv('SPP_QA_DIR').'/fixture.json'),true);spp_test_assert_http_clone($f['base'],DB_NAME);
if($db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_full_items_data' AND COLUMN_NAME='source_column'")->fetch_row()[0]!=1)throw new RuntimeException('Test source column unavailable.');
$before=$db->query('SHOW CREATE TABLE legacy_full_items_data')->fetch_row()[1];$renamed=false;
try{
    $db->query('ALTER TABLE legacy_full_items_data RENAME COLUMN source_column TO komite_qa_hidden_column');$renamed=true;
    $html=file_get_contents($f['base'].'/laporan/index.php?tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22',false,stream_context_create(['http'=>['header'=>'Cookie: PHPSESSID='.$f['sessions'][1],'timeout'=>120,'ignore_errors'=>true]]));
    if(!str_contains($http_response_header[0]??'',' 200 ')||str_contains($html,'Fatal error:')||!str_contains($html,'Rekap komponen belum dapat dimuat.'))throw new RuntimeException('SQL failure escaped the recap panel.');
    libxml_use_internal_errors(true);$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($doc);
    $footer=$xp->evaluate('normalize-space(string(//table[contains(@class,"finance-components-table")]/tfoot/tr/td))');$count=$xp->query('//*[@data-report-section]')->item(0)?->getAttribute('data-total');
    if($footer!=='—'||!str_contains($html,'240 transaksi')||str_contains($html,'Tidak ada pembayaran sesuai filter.'))throw new RuntimeException('Failure looks like an empty or zero-valued report.');
}finally{if($renamed)$db->query('ALTER TABLE legacy_full_items_data RENAME COLUMN komite_qa_hidden_column TO source_column');}
if($db->query('SHOW CREATE TABLE legacy_full_items_data')->fetch_row()[1]!==$before)throw new RuntimeException('Clone schema did not return to its initial definition.');
echo "PASS: aggregate SQL failure retains 240 transactions, displays retry and unknown total; disposable schema restored exactly.\n";
