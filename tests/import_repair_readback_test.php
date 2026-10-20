<?php
/** Real-data acceptance; --read-only also permits the repaired local database. */
$readOnly=PHP_SAPI==='cli'&&in_array('--read-only',$argv,true);
$isolated=getenv('SPP_DB_PORT')==='58360'&&getenv('SPP_TEST_ALLOW_MUTATION')==='1';
if(PHP_SAPI!=='cli'||(!$isolated&&!($readOnly&&getenv('SPP_DB_PORT')==='3306'&&getenv('SPP_DB_NAME')==='db_spp_test2')))throw new RuntimeException('Use the owned audit instance, or --read-only on the repaired local database.');
$_SESSION = ['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
if($readOnly)$koneksi->query('SET SESSION TRANSACTION READ ONLY');
elseif(!str_contains(str_replace('\\','/',$koneksi->query('SELECT @@datadir')->fetch_row()[0]),'/import_audit_20261020/'))throw new RuntimeException('Unexpected instance identity.');
require_once __DIR__.'/../includes/import_repair.php';
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/../includes/registration_combined.php';
require_once __DIR__.'/../includes/history_class_filter.php';
require_once __DIR__.'/../includes/savings_owners.php';
require_once __DIR__.'/../includes/savings_book_print.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/general_multiple.php';
require_once __DIR__.'/../includes/legacy_transactions.php';
require_once __DIR__.'/../includes/migrated_savings.php';
$db = $koneksi; $passed = []; $failed = []; $timings = [];
function ira_case(string $name, callable $action): void {
    global $passed,$failed,$timings;
    $start = microtime(true);
    try { $action(); $passed[] = $name; echo 'PASS '.$name.PHP_EOL; }
    catch (Throwable $e) { $failed[] = ['case'=>$name,'error'=>$e->getMessage()]; echo 'FAIL '.$name.': '.$e->getMessage().PHP_EOL; }
    $timings[$name] = round((microtime(true)-$start)*1000);
}
function ira_assert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function ira_scope(int $unit): void { global $db; $_SESSION['active_unit_id']=$unit; unit_set_context($db,$unit); }
ira_case('Complete schema contract',function()use($db){$p=ir_plan($db);ira_assert(!$p['missing']&&!$p['missing_modules']&&!$p['violations']&&!$p['mismatches'],'Schema contract failed');});
ira_case('All-unit batch integrity restores its connection scope',function()use($db){
    ira_scope(0);
    foreach(lf_rows($db,'SELECT id FROM legacy_full_batches') as $batch){lful_check_result($db,$batch['id']);ira_assert((int)$db->query('SELECT current_unit_id()')->fetch_row()[0]===0,'Audit leaked its unit scope');}
});
ira_case('Partitioned all-unit billing evidence equals independent unit reads',function(){
    $connect=static function():mysqli{$c=new mysqli(getenv('SPP_DB_HOST')?:'127.0.0.1',getenv('SPP_DB_USER')?:'root',getenv('SPP_DB_PASS')?:'',getenv('SPP_DB_NAME')?:'db_spp_test2',(int)getenv('SPP_DB_PORT'));$c->set_charset('utf8mb4');$c->query('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');$c->query('SET SESSION TRANSACTION READ ONLY');return $c;};
    $shared=$connect();$independent=$connect();
    try{
        unit_set_context($shared,0);lb_cache($shared,true);
        foreach([1,2,3] as $unit){unit_set_context($shared,$unit);$actual=lb_cache($shared);unit_set_context($independent,$unit);$expected=lb_cache($independent,true);ira_assert($actual==$expected,'Partitioned billing evidence differs for unit '.$unit);}
    }finally{$shared->close();$independent->close();}
});
foreach ([1=>[46852,23309,3064],2=>[60181,21160,5084],3=>[11211,5209,1140],0=>[118244,49678,9288]] as $unit=>$expected) {
    ira_scope($unit);
    ira_case("Unit $unit narrow savings recap equals complete history",function()use($db){
        $classes=history_class_filter($db,[]);
        foreach([[],['all_periods'=>'1']] as $input){
            $totals=[];
            foreach([false,true] as $summary){$q=mh_savings_read_query($db,$input,$classes,['*'],'2022-01-01','2027-01-01',$summary);
                $totals[]=lf_one($db,'SELECT COUNT(*) n,SUM(nominal) incoming,SUM(keluar) outgoing FROM ('.$q['sql'].') x',$q['args']);}
            ira_assert($totals[0]===$totals[1],'Narrow recap omitted journals or changed cash');
        }
    });
    ira_case("Unit $unit general report includes every payment and savings journal",function()use($db,$expected){
        $summary=general_payment_summary($db,'2022-01-01','2027-01-01');
        ira_assert((int)$summary['jml_tx']===$expected[1],'General report omits historical payments');
        $components=general_payment_components($db,'2022-01-01','2027-01-01');
        ira_assert(abs(array_sum(array_column($components,'total'))-(float)$summary['total'])<0.01,'General component total differs');
        $page=general_payment_rows($db,'2022-01-01','2027-01-01','','terbaru',10);
        ira_assert(count($page)===10&&count(array_filter($page,static fn($r)=>$r['id']<0&&$r['sistem_pembayaran']==='Legacy'))===10,'General page lost receipt identity');
        $s=general_savings_read($db,'2022-01-01','2027-01-01');
        $raw=hp_one($db,"SELECT SUM(incoming) incoming,SUM(outgoing) outgoing FROM legacy_op_journals WHERE cancelled=0 AND origin<>'adjustment' AND source_date>='2022-01-01' AND source_date<'2027-01-01'");
        ira_assert(abs((float)$s['total_masuk']-(float)$raw['incoming'])<0.01&&abs((float)$s['total_keluar']-(float)$raw['outgoing'])<0.01,'General savings omits a historical account');
    });
    ira_case("Unit $unit merged payment history",function()use($db,$expected){
        $classes=history_class_filter($db,[]);
        $all=mh_payment_page($db,['all_periods'=>'1'],$classes,['*'],'','',1,25);
        ira_assert($all['total']===$expected[0],'All-year payment count differs');
        $range=mh_payment_page($db,[],$classes,['*'],'2022-01-01 00:00:00','2027-01-01 00:00:00',1,25);
        ira_assert($range['total']===$expected[1],'2022–2026 count differs');
        $next=mh_payment_page($db,['all_periods'=>'1'],$classes,['*'],'','',2,25);
        ira_assert(!array_intersect(array_column($all['rows'],'id'),array_column($next['rows'],'id')),'Pages overlap');
        foreach($all['rows'] as $p){ira_assert($p['id']<0,'Historical ID changed');$m=lt_model($db,-(int)$p['id']-1000000000);ira_assert((float)$m['payment']['total_jumlah']===(float)$p['total_jumlah'],'Detail amount differs');}
    });
    ira_case("Unit $unit registration history including undated",function()use($db,$expected){
        $p=rc_page($db,['source'=>'all']);ira_assert((int)$p['summary']['legacy_count']===$expected[2],'Undated DU omitted');
        foreach(array_slice($p['rows'],0,3) as $g){$receipts=rc_receipts($db,$g);ira_assert(isset($receipts['legacy'])||is_array($receipts),'Registration detail missing');}
    });
    if (!$unit) continue;
    ira_case("Unit $unit savings books and isolation",function()use($db,$unit){
        $student=lf_one($db,"SELECT s.id,s.NO_INDUK FROM siswa s JOIN legacy_op_owners o ON o.student_id=s.id AND o.unit_id=s.unit_id JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id WHERE a.balance>0 LIMIT 1");
        [$s,$book]=savings_book_load($db,$student['NO_INDUK'],(int)$student['id'],$unit);ira_assert(count($book['entries'])>0,'Student book empty');
        $owner=lf_one($db,'SELECT o.id FROM legacy_op_owners o JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id WHERE o.student_id IS NULL AND a.balance>0 LIMIT 1');
        [$o,$ownerBook]=savings_book_owner_load($db,(int)$owner['id']);ira_assert(count($ownerBook['entries'])>0,'Non-student book empty');
        ira_scope($unit===1?2:1);$rejected=false;try{savings_book_owner_load($db,(int)$owner['id']);}catch(SavingsBookRequestError $e){$rejected=true;}finally{ira_scope($unit);}ira_assert($rejected,'Cross-unit book exposed');
    });
    if(!$readOnly)ira_case("Unit $unit CHECK rejects invalid tariff",function()use($db){
        $id=(int)lf_one($db,'SELECT id FROM legacy_op_tariffs LIMIT 1')['id'];$db->begin_transaction();$rejected=false;
        try{$db->query('UPDATE legacy_op_tariffs SET amount=-1 WHERE id='.$id);}catch(mysqli_sql_exception $e){$rejected=$e->getCode()===3819;}finally{$db->rollback();}
        ira_assert($rejected,'Negative tariff not rejected by CHECK');
    });
}
ira_scope(1);
ira_case('Adam has 54 source payments',function()use($db){$s=lf_one($db,"SELECT * FROM siswa WHERE NAMA LIKE 'Adam Kamil Iman%' LIMIT 1");ira_assert(count(mh_student_legacy_history($db,$s))===54,'Student history count differs');});
foreach([1,2,3,0] as $unit){
    ira_scope($unit);
    ira_case("Unit $unit report preserves Legacy operator and method filters",function()use($db,$unit){
        $range="source_date>='2022-01-01' AND source_date<'2027-01-01'";
        $p=hp_one($db,"SELECT unit_id,CAST(source_operator AS BINARY) source_operator,SUM(total) amount,COUNT(*) n FROM legacy_op_payments WHERE cancelled=0 AND $range GROUP BY unit_id,CAST(source_operator AS BINARY) ORDER BY n DESC LIMIT 1");
        $operator=lful_operator_key((int)$p['unit_id'],$p['source_operator']);
        $input=['template'=>'penerimaan','tanggal_awal'=>'2022-01-01','tanggal_akhir'=>'2026-12-31','siswa_status'=>'all','kategori'=>'semua','operator'=>[$operator],'metode'=>['Legacy']];
        $filters=report_filters($db,$input);
        ira_assert($filters['_multi']['operator']===[$operator]&&$filters['_multi']['metode']===['Legacy'],'Filter became a wildcard');
        $r=report_build($db,'penerimaan',$filters);
        ira_assert(abs(array_sum(array_column($r['rows'],'total_penerimaan'))-(float)$p['amount'])<0.01,'Operator receipt total differs from its source');
        $j=hp_one($db,"SELECT unit_id,CAST(source_operator AS BINARY) source_operator,SUM(incoming) incoming,SUM(outgoing) outgoing,COUNT(*) n FROM legacy_op_journals WHERE cancelled=0 AND origin='legacy' AND $range GROUP BY unit_id,CAST(source_operator AS BINARY) ORDER BY n DESC LIMIT 1");
        $input['template']='tabungan-siswa';$input['operator']=[lful_operator_key((int)$j['unit_id'],$j['source_operator'])];
        $r=report_build($db,'tabungan-siswa',report_filters($db,$input));
        ira_assert(abs(array_sum(array_column($r['rows'],'masuk'))-(float)$j['incoming'])<0.01&&abs(array_sum(array_column($r['rows'],'keluar'))-(float)$j['outgoing'])<0.01,'Operator savings totals differ from their source');
    });
}
$base=['tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-20','tahun_ajaran'=>'2026/2027','bulan_awal'=>'07','bulan_akhir'=>'10','tahun_awal'=>2026,'tahun_akhir'=>2026,'siswa_status'=>'all','kategori'=>'semua'];
foreach (array_keys(report_registry()) as $template) ira_case('Report '.$template.' across all units',function()use($db,$base,$template){
    $input=$base+['template'=>$template];if(in_array($template,['status','per-item'],true))$input['kategori']='spp';
    $sum=[];$count=0;
    foreach([1,2,3] as $unit){ira_scope($unit);$r=report_build($db,$template,report_filters($db,$input));$count+=count($r['rows']);foreach(report_money_totals($r,$template) as $t){$k=$t['key']??$t['label'];$sum[$k]=($sum[$k]??0)+(float)$t['value'];}}
    ira_scope(0);$r=report_build($db,$template,report_filters($db,$input));$totals=[];foreach(report_money_totals($r,$template) as $t)$totals[$t['key']??$t['label']]=(float)$t['value'];
    foreach($sum as $k=>$amount)ira_assert(abs($amount-($totals[$k]??0))<0.01,'Cross-unit report total differs: '.$k);
    if(isset($r['legacy_undated_count']))ira_assert($r['legacy_undated_count']===9288,'All-unit subtitle omitted an undated source unit');
    if(!in_array($template,['setoran','kas-tabungan'],true))ira_assert(count($r['rows'])===$count,'Cross-unit rows missing or duplicated');
});
$result=['passed'=>count($passed),'cases'=>$passed,'failed'=>$failed,'timings_ms'=>$timings];
if($path=getenv('SPP_IMPORT_AUDIT_RESULT'))file_put_contents($path,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
exit($failed?1:0);
