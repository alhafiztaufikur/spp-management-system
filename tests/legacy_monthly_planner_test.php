<?php
require_once __DIR__.'/../includes/legacy_billing.php';
$checks=0;function mcheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$s=['unit_id'=>2,'student_id'=>1274,'id'=>1,'NO_INDUK'=>'252607034','kind'=>'active','migration_year'=>'2026/2027','raw'=>['SPP_PERBULAN'=>'327500','POMG'=>'15000']];
function mp(string $period,string $amount,string $class='8A',bool $cancelled=false): array {return ['row_key'=>hash('sha256',$period.$amount),'source_period'=>$period,'class_snapshot'=>$class,'cancelled'=>$cancelled,'items'=>[['category'=>'spp','amount'=>$amount],['category'=>'komite','amount'=>'15000']],'raw'=>['U_SPP'=>$amount,'U_SORGA'=>'15000','potong_spp'=>'0']];}
$p=[mp('07 2026','327500'),mp('09 2026','327500'),mp('12 2026','327500')];$r=lmp_plan($s,$p)['positions'];
mcheck($r['spp|2026-08']['principal']===32750000&&$r['spp|2026-08']['paid']===0,'Missing month in proven interval becomes due');
mcheck($r['spp|2026-12']['remaining']===0,'Future period credit remains in December');
mcheck($r['komite|2026-12']['remaining']===0,'Komite is paired with future SPP');
mcheck($r['spp|2026-10']['remaining']===32750000&&$r['komite|2026-10']['remaining']===1500000,'October Raisa');
$r=lmp_plan($s,[mp('08 2025','297500','7A'),mp('06 2026','297500','7A'),mp('07 2026','327500')])['positions'];
mcheck($r['spp|2025-10']['principal']===29750000&&$r['spp|2026-10']['principal']===32750000,'Rates stay in their own academic year');
mcheck(!isset($r['spp|2025-07']),'No invented month before earliest membership evidence');
$discounted=mp('07 2026','300000');$discounted['raw']['potong_spp']='27500';$r=lmp_plan($s,[$discounted])['positions'];
mcheck($r['spp|2026-07']['gross']===32750000&&$r['spp|2026-07']['discount']===2750000,'Source discount retained');
$cancelled=mp('07 2026','327500','8A',true);$r=lmp_plan($s,[$cancelled])['positions'];
mcheck($r['spp|2026-07']['paid']===0&&$r['spp|2026-07']['principal']===32750000,'Cancellation restores due without erasing tariff proof');
$corrected=mp('07 2026','327500');$corrected['items'][0]['amount']='320000';$r=lmp_plan($s,[$corrected])['positions'];
mcheck($r['spp|2026-07']['principal']===32750000&&$r['spp|2026-07']['remaining']===750000,'Correction changes credit rather than source rate');
$account=$s;$account['student_id']=null;$account['kind']='account';mcheck(lmp_plan($account,$p)['positions']===[],'Savings-only owner has no automatic debt');
$s['unit_id']=1;$r=lmp_plan($s,$p)['positions'];mcheck($r['spp|2026-07']['unit_id']===1,'Same NIS remains scoped to unit');
$manual=lmp_publication(null,'2026-10',32750000,0);mcheck($manual['principal']===32750000,'Explicit CRUD/publication accepts assigned liability');
mcheck(lmp_publication(null,'2026-10',32750000,32750000)['status']==='waived','Full waiver');
mcheck(lmp_publication(null,'2026-10',32750000,0,true)['status']==='covered_psb','PSB coverage');
$s['raw']['SPP_PERBULAN']='400000';$r=lmp_plan($s,[mp('07 2026','327500')])['positions'];mcheck(!isset($r['spp|2026-10']),'Changed header rate is not retroactively assumed');
$fullPlan=lmp_plan($s,$p);$compact=$p;
foreach($compact as &$payment)foreach($payment['items'] as &$item)$item=array_intersect_key($item,array_flip(['category','amount']));unset($payment,$item);
mcheck(lmp_plan($s,$compact)===$fullPlan,'Compact billing cache preserves all monthly planner positions and evidence');
echo "$checks planner checks passed\n";
