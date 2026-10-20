<?php
/** Acceptance against a restored real dataset; never runs on the user's database. */
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58246')throw new RuntimeException('Use the isolated performance database.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/registration_combined.php';
require_once __DIR__.'/../includes/migrated_savings.php';
require_once __DIR__.'/../includes/savings_owners.php';
require_once __DIR__.'/../includes/legacy_operational.php';
$identity=$koneksi->query('SELECT @@port port,@@datadir dir')->fetch_assoc();
if((int)$identity['port']!==58246||!str_contains(str_replace('\\','/',$identity['dir']),'/history_performance_'))throw new RuntimeException('Unexpected instance.');
$checks=[];$timings=[];$reference=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
function hp_assert(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;}
function hp_scope(int $unit): void {global $koneksi;$_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);}
function hp_reject(callable $fn,string $label):void {try{$fn();}catch(DomainException|InvalidArgumentException $e){hp_assert(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
foreach([1=>[240,46852,3064],2=>[194,60181,5084],3=>[44,11211,1140],0=>[478,118244,9288]] as $unit=>$expected){
    hp_scope($unit);$start=microtime(true);
    $p=mh_payment_page($koneksi,[],['values'=>['*']],['*'],'2026-10-01','2026-10-21',1,10);
    hp_assert($p['total']===$expected[0]&&count($p['rows'])===10,"Unit $unit October count and populated page");
    $operator=$p['rows'][0]['user_id'];
    $operatorPage=mh_payment_page($koneksi,['all_periods'=>'1'],['values'=>['*']],[$operator],'2026-10-01','2026-10-21',1,10);
    $operatorCount=(int)hp_one($koneksi,"SELECT COUNT(*) n FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0 AND CONCAT('legacy:',p.unit_id,':',LOWER(HEX(COALESCE(p.source_operator,''))))=?",[$operator])['n'];
    hp_assert($operatorPage['total']===$operatorCount&&$operatorPage['rows']!==[],"Unit $unit Legacy operator filter matches source count");
    $searchPage=mh_payment_page($koneksi,['search'=>$p['rows'][0]['NO_INDUK'],'all_periods'=>'1'],['values'=>['*']],['*'],'2026-10-01','2026-10-21',1,10);
    hp_assert(in_array((int)$p['rows'][0]['id'],array_map('intval',array_column($searchPage['rows'],'id')),true),"Unit $unit source identity search retains selected receipt");
    $next=mh_payment_page($koneksi,[],['values'=>['*']],['*'],'2026-10-01','2026-10-21',2,10);
    hp_assert(!array_intersect(array_column($p['rows'],'id'),array_column($next['rows'],'id')),"Unit $unit disjoint payment pages");
    $all=mh_payment_page($koneksi,['all_periods'=>'1'],['values'=>['*']],['*'],'2026-10-01','2026-10-21',99999,10);
    hp_assert($all['total']===$expected[1]&&$all['page']===$all['pages'],"Unit $unit complete history and clamped page");
    $target=(int)$all['rows'][0]['id'];
    $selected=mh_payment_page($koneksi,['all_periods'=>'1','selected'=>$target],['values'=>['*']],['*'],'2026-10-01','2026-10-21',1,10);
    hp_assert(in_array($target,array_map('intval',array_column($selected['rows'],'id')),true),"Unit $unit selected payment resolves across pages");
    $du=rc_page($koneksi,['source'=>'legacy']);
    hp_assert((int)$du['summary']['legacy_count']===$expected[2],"Unit $unit undated DU retained");
    $duNext=rc_page($koneksi,['source'=>'legacy','page'=>2]);
    hp_assert(!array_intersect(array_column($du['rows'],'id'),array_column($duNext['rows'],'id')),"Unit $unit disjoint DU pages");
    $duSelected=rc_page($koneksi,['source'=>'legacy','selected'=>$duNext['rows'][0]['id'],'selected_unit'=>$duNext['rows'][0]['unit_id']]);
    hp_assert($duSelected['page']===2,"Unit $unit selected DU opens correct page");
    $empty=rc_page($koneksi,['q'=>'__missing_owner_749892__','page'=>999]);
    hp_assert($empty['rows']===[]&&$empty['total']===0&&$empty['page']===1&&count($empty['yearOptions'])>0,"Unit $unit empty result retains year options");
    $accounts=mh_account_page($koneksi,[]);
    hp_assert(count($accounts['rows'])===25&&$accounts['total']>=25,"Unit $unit bounded account recap");
    $ids=[];foreach($accounts['rows'] as $r)$ids[]=$r['student_id']?'s:'.$r['student_id']:'o:'.$r['legacy_owner_id'];
    hp_assert(count($ids)===count(array_unique($ids)),"Unit $unit recap owner identity unique");
    $timings[$unit]=['read_tests_ms'=>round((microtime(true)-$start)*1000),'peak_memory_bytes'=>memory_get_peak_usage(true)];
}
hp_scope(0);hp_assert((int)lf_one($koneksi,'SELECT COUNT(*) n FROM legacy_op_accounts')['n']===2418,'All 2418 accounts retained');
$foreign=lf_one($koneksi,'SELECT id FROM legacy_op_owners WHERE unit_id=2 AND student_id IS NULL LIMIT 1');hp_scope(1);
hp_reject(fn()=>so_resolve($koneksi,['owner_id'=>$foreign['id']]),'Foreign unit account rejected');
hp_reject(fn()=>rc_page($koneksi,['year'=>'2099/2100']),'Unavailable year rejected');
$owner=lf_one($koneksi,'SELECT id FROM legacy_op_owners WHERE student_id IS NULL LIMIT 1');
hp_reject(fn()=>so_resolve($koneksi,['owner_id'=>$owner['id'],'student_id'=>1]),'Conflicting owner identities rejected');
$native=rc_page($koneksi,['source'=>'system']);hp_assert((int)$native['summary']['legacy_count']===0,'Native-only filter remains valid');
foreach([1,2,3] as $unit){hp_scope($unit);$batch=lf_one($koneksi,"SELECT id,manifest FROM legacy_full_batches WHERE state='completed' LIMIT 1");hp_assert(hash_equals($reference[$unit]['digest'],lop_working_digest($koneksi,$batch['id'])),"Unit $unit exact working fingerprint preserved");}
$koneksi->rollback();
$result=['checks'=>$checks,'count'=>count($checks),'timings'=>$timings];
if(!empty($argv[1]))file_put_contents($argv[1],json_encode($result,JSON_PRETTY_PRINT));
echo 'PASS '.count($checks)." history/performance acceptance checks\n";
