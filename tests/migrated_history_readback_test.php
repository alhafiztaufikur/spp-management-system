<?php
/** Real migration readback. Mutations are restricted to the private restored instance. */
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58246'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')throw new RuntimeException('Use the owned isolated readback instance.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/history_operator_filter.php';
require_once __DIR__.'/../includes/migrated_history.php';
require_once __DIR__.'/../includes/savings_workspace.php';
require_once __DIR__.'/../includes/savings_book_print.php';
require_once __DIR__.'/../includes/reports.php';
$db=$koneksi;$identity=$db->query('SELECT @@datadir d,@@port p')->fetch_assoc();
if((int)$identity['p']!==58246||!str_contains(str_replace('\\','/',$identity['d']),'/legacy_readback_20261020/mysql-data/'))throw new RuntimeException('Unexpected database instance.');
$cases=[];
function mh_assert(bool $condition,string $label): void {global $cases;if(!$condition)throw new RuntimeException($label);$cases[]=$label;}
function mh_scope(int $unit): void {global $db;$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);}
function mh_page(array $input=[],int $page=1): array {global $db;return mh_payment_page($db,$input,['values'=>['*']],['*'],'2026-10-01','2026-10-21',$page,10);}
foreach([1=>[240,46852,3064],2=>[194,60181,5084],3=>[44,11211,1140],0=>[478,118244,9288]] as $unit=>[$month,$all,$undated]){
    mh_scope($unit);$r=mh_page();mh_assert($r['total']===$month&&count($r['rows'])===10,"Unit $unit count equals visible page");
    mh_assert($r['all']===$all&&$r['outside']===$all-$month,"Unit $unit complete source summary");
    $next=mh_page([],2);mh_assert(!array_intersect(array_column($r['rows'],'id'),array_column($next['rows'],'id')),"Unit $unit stable pages without duplicate IDs");
    $r=mh_page(['undated'=>'1']);mh_assert($r['total']===$undated&&count($r['rows'])===10,"Unit $unit undated DU preserved");
    $r=mh_page(['all_periods'=>'1']);mh_assert($r['total']===$all,"Unit $unit all periods including undated");
}
mh_scope(3);$focus=lf_one($db,"SELECT id FROM legacy_op_payments WHERE source_table='bayar_du' ORDER BY id LIMIT 1");$focused=mh_page(['all_periods'=>'1','selected'=>-1000000000-(int)$focus['id']]);mh_assert(in_array(-1000000000-(int)$focus['id'],array_map('intval',array_column($focused['rows'],'id')),true),'Direct ID opens correct page for undated receipt');
$students=mh_students_source($db);mh_assert((int)lf_one($db,"SELECT COUNT(*) n FROM $students s JOIN master_kelas mk ON mk.id=s.master_kelas_id AND mk.unit_id=s.unit_id WHERE mk.jenis='out'")['n']===103,'OUT read projection preserves stored migration classification');
mh_scope(1);$account=mh_page(['owner_type'=>'account']);mh_assert($account['total']===1&&$account['rows'][0]['student_id']===null,'Non-student receipt retains internal owner and source identity');
$row=$account['rows'][0];$model=lful_history_model($db,-(int)$row['id']);mh_assert($model['legacy']['NAMA']===$row['NAMA'],'Negative ID detail resolves same owner');
$student=lf_one($db,'SELECT s.* FROM siswa s JOIN legacy_op_owners o ON o.student_id=s.id AND o.unit_id=s.unit_id WHERE o.kind=\'active\' LIMIT 1');
$counts=mh_student_counts($db,1,$student['NO_INDUK'],(int)$student['id']);mh_assert($counts['payments']>0&&$counts['total']>=$counts['payments'],'Data Siswa history includes Legacy');
$src=mh_savings_source($db);$op=lf_one($db,"SELECT source_operator FROM legacy_op_journals WHERE origin='legacy' LIMIT 1");$key=lful_operator_key(1,$op['source_operator']);
mh_assert((int)lf_one($db,"SELECT COUNT(*) n FROM $src t WHERE t.operator_key=?",[$key])['n']>0,'Savings Legacy operator is searchable');
foreach([1,2,3] as $unit){
    mh_scope($unit);$f=report_filters($db,['tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-20','siswa_status'=>'all','metode'=>'Legacy']);
    $components=lful_report_components($db,$f);$expected=lf_one($db,"SELECT SUM(total) total FROM legacy_op_payments WHERE cancelled=0 AND source_date>='2026-10-01' AND source_date<'2026-10-21'")['total'];
    mh_assert((int)round(array_sum(array_column($components,'nominal'))*100)===lf_cents($expected),"Unit $unit Legacy report components equal original totals");
    $f['operator']='';$f['_multi']['operator']=['*'];$cash=report_settlement_data($db,$f);mh_assert((float)$cash['payment_total']===0.0,"Unit $unit Legacy not new cash receipts");
    $o=lf_one($db,"SELECT o.id FROM legacy_op_owners o JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id WHERE o.student_id IS NULL AND a.opening>0 LIMIT 1");
    [$owner,$book]=savings_book_owner_load($db,(int)$o['id']);mh_assert($book['balance']===(int)round((float)$owner['SALDO']*100),"Unit $unit non-student book preserves closing balance");
    $s=lf_one($db,"SELECT s.* FROM siswa s JOIN legacy_op_owners o ON o.student_id=s.id AND o.unit_id=s.unit_id JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id WHERE a.opening>0 LIMIT 1");
    [$owner,$book]=savings_book_load($db,$s['NO_INDUK'],(int)$s['id'],$unit);mh_assert($book['balance']===(int)round((float)$owner['SALDO']*100),"Unit $unit student book preserves closing balance");
}
require_once __DIR__.'/../includes/migrated_savings.php';
foreach([1,2,3] as $unit){
    mh_scope($unit);$owner=lf_one($db,"SELECT o.id,o.name,a.balance FROM legacy_op_owners o JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id WHERE o.student_id IS NULL AND a.opening>0 LIMIT 1");
    $q=mh_savings_read_query($db,['all_periods'=>'1','owner_id'=>$owner['id']],['values'=>['*']],['*'],'2026-10-01','2026-10-21');$tx=lf_rows($db,$q['sql'],$q['args']);
    mh_assert(count($tx)>0&&count(array_unique(array_column($tx,'legacy_owner_id')))===1,"Unit $unit savings read/export uses exact internal owner");
    $net=array_sum(array_column($tx,'nominal'))-array_sum(array_column($tx,'keluar'))+array_sum(array_column($tx,'adjustment_delta'));
    mh_assert((int)round($net*100)===lf_cents($owner['balance']),"Unit $unit exported journal plus adjustment equals live balance");
    $f=report_filters($db,['template'=>'tabungan-siswa','all_periods'=>'1','owner_id'=>$owner['id'],'siswa_status'=>'all']);$report=report_savings_student_data($db,$f);
    mh_assert(count($report['rows'])===count($tx)&&count(array_unique(array_column($report['rows'],'nama')))===1,"Unit $unit report and screen select same owner rows");
    $last=end($report['rows']);mh_assert((int)round($last['saldo_saat_ini']*100)===lf_cents($owner['balance']),"Unit $unit report running balance matches book");
}
mh_scope(0);$accounts=lf_rows($db,'SELECT a.*,o.student_id FROM legacy_op_accounts a JOIN legacy_op_owners o ON o.id=a.owner_id AND o.unit_id=a.unit_id');
$ledgers=[];foreach(lf_rows($db,"SELECT owner_id,id,source_date tanggal,incoming masuk,outgoing keluar,origin,IF(incoming>0,0,1) urutan_mutasi FROM legacy_op_journals WHERE cancelled=0 AND origin<>'new' ORDER BY source_date,(origin='adjustment'),id") as $j)$ledgers[$j['owner_id']][]=$j;
foreach($accounts as $a){$book=savings_book_make([], (float)$a['opening'],['amount'=>$a['opening'],'effective_date'=>$a['effective_date']],$ledgers[$a['owner_id']]??[]);if($book['balance']!==lf_cents($a['opening']))throw new RuntimeException('Ledger closing mismatch');}
mh_assert(count($accounts)===2418,'All 2418 ledgers including FIX adjustments reconcile');
mh_scope(3);$nativeBefore=mh_page(['source'=>'system'])['total'];$db->begin_transaction();
try{
    $s=lf_one($db,"SELECT * FROM siswa WHERE is_active=1 LIMIT 1");
    lf_query($db,"INSERT INTO bayar(unit_id,NO_INDUK,TGL_BYR,KELAS,total_jumlah,U_SPP,created_at) VALUES(?,?,?,?,?,?,?)",[3,$s['NO_INDUK'],'2026-10-20 00:10:00',$s['KELAS'],'123.45','123.45','2026-10-20 00:10:00']);
    $native=mh_page(['source'=>'system']);$mixed=mh_page();mh_assert($native['total']===$nativeBefore+1&&$mixed['total']===45,'Native-only filter and mixed source count/read match');
    mh_assert((int)$native['rows'][0]['id']>0&&(int)round((float)$native['rows'][0]['total_jumlah']*100)===12345,'Native positive ID and decimal unchanged');
}finally{$db->rollback();}
mh_assert(mh_page(['source'=>'system'])['total']===$nativeBefore,'Rollback leaves restored fixture unchanged');
echo json_encode(['passed'=>count($cases),'cases'=>$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
