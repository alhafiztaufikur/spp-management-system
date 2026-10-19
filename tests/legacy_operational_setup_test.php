<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/legacy_finance_support.php';
function setup_row(string $table,array $raw,int $n): array {
    return ['table'=>$table,'raw'=>$raw,'reference_only'=>false,'row_key'=>hash('sha256',$table.$n),
        'student_ordinal'=>$n,'money_columns'=>array_values(array_intersect(array_keys($raw),['SPP_PERBULAN','POMG','U_SPP','U_SORGA','U_TABUNGAN','SALDO','MASUK','KELUAR']))];
}
$records=[];
foreach([['21220001','1A'],['21220002','1B'],['GK01','gk'],['OUT01','out'],['BTQ01','btq']] as $n=>[$identity,$class]) {
    $records[]=setup_row('siswa',['NO_INDUK'=>$identity,'NAMA'=>'Source '.$n,'KELAS'=>$class,'SPP_PERBULAN'=>'530000','POMG'=>'15000'],$n);
}
$records[]=setup_row('bayar',['NO_INDUK'=>'21220001','U_SPP'=>'500000','U_SORGA'=>'1000','U_TABUNGAN'=>'200','TGL_BYR'=>'2026-10-01T12:00:00','BULAN'=>'Oktober','TAHUN'=>'2026'],9);
foreach([['21220001','1000','1000'],['21220002','1000','800'],['GK01','2000','2000']] as $n=>[$identity,$balance,$incoming]) {
    $records[]=setup_row('tabungan',['NO_INDUK'=>$identity,'SALDO'=>$balance],20+$n);
    $records[]=setup_row('transaksi_m',['NO_INDUK'=>$identity,'MASUK'=>$incoming,'KELUAR'=>'0','TANGGAL'=>'2026-10-01T10:00:00'],30+$n);
}
$manifest=['fingerprint'=>hash('sha256','owned source')];
$cfg=lop_source_setup($records,$manifest,['cutoff'=>'2026-10-17 18:00:00'],1);
$plan=lop_prepare($records,$cfg,1,'2026-10-01T18:00:00');
lft_assert($plan['owners']['21220001']['ready']&&$plan['owners']['21220002']['ready'],'Clear students require repeated attestations');
lft_assert($plan['owners']['21220001']['spp']==='530000.00','Source tariff changed');
lft_assert($plan['owners']['21220001']['savings']!==null,'Matched savings require redundant attestation');
lft_assert($plan['owners']['21220002']['savings']!==null&&$plan['summary']['source_balance_fixes']===1&&$plan['owners']['21220002']['savings']['balance']===$plan['owners']['21220002']['savings']['source_balance'],'Source closing policy not applied with explicit adjustment');
lft_assert(!$plan['owners']['BTQ01']['ready']&&!$plan['owners']['GK01']['student'],'Unclassified or special account changed identity');
lft_assert($plan['summary']['payments']===1&&$plan['summary']['payment_total']==='501000.00','Payment lost or savings counted as fees');
lft_assert($plan['summary']['obligations_unreviewed']===2&&$plan['summary']['debt_total']==='0.00','Unknown obligations certified automatically');
lft_assert(empty($cfg['op_groups']['1A']['obligations_attested']),'Setup falsely attests obligations');
$old=$cfg;$old['components']['bayar.U_SPP']['category']='histori:'.str_repeat('a',32);
$again=lop_source_setup($records,$manifest,$old,1);
lft_assert($again['components']===$old['components'],'Setup overwrote reviewed mappings');
$changed=$cfg;$changed['source_fingerprint']=hash('sha256','changed source');
lft_assert(!lop_prepare($records,$changed,1,'2026-10-01T18:00:00')['owners']['21220001']['ready'],'Changed source kept approval');
echo "PASS: one source setup, source tariffs, matched savings, independent holds, no invented debts, mapping preservation and source invalidation.\n";
if(in_array('--sources',$argv,true)) {
    if(!str_contains(str_replace('\\','/',(string)getenv('SPP_LEGACY_STORAGE')),'/legacy_operational_qa_'))throw new RuntimeException('Owned source inspection directory required');
    if(!defined('DB_NAME'))define('DB_NAME','db_spp');
    $jobs=json_decode(file_get_contents(dirname(legacy_root(),2).'/operational-source-jobs.json'),true,512,JSON_THROW_ON_ERROR);
    $results=[];
    foreach($jobs as $item) {
        $job=legacy_job($item['id']);[$rows,$source]=lf_extract_read($job,true);
        $cfg=lop_source_setup($rows,$source,['cutoff'=>'2026-10-17 18:00:00'],$item['unit']);
        $p=lop_prepare($rows,$cfg,$item['unit'],$source['source_date']);
        lft_assert($p['summary']['students']>0&&$p['summary']['pending']>0,'Source defaults did not preserve independent exceptions');
        $results[]=$item+['summary'=>$p['summary'],'fingerprint'=>$source['fingerprint']];
        echo 'PASS source '.$item['version'].' unit '.$item['unit'].': '.$p['summary']['students'].' students ready; '.$p['summary']['payments']." payments ready.\n";
        unset($rows,$p,$cfg);gc_collect_cycles();
    }
    file_put_contents(dirname(legacy_root(),2).'/operational-source-setup-results.json',lf_json($results));
}
