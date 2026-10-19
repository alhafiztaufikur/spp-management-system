<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/legacy_operational_resolution_test.php';
$identity='220817';$person=op_raw($identity,'GK','0','Rohis Mhi');
$bank=[lr_row('tabungan',['NO_INDUK'=>$identity,'SALDO'=>'0.13100000005215406'])];
foreach([['transaksi_k','2018-01-03','100000'],['transaksi_k','2018-01-27','543000'],['transaksi_m','2017-09-07','131000'],['transaksi_m','2017-09-19','226000'],['transaksi_m','2017-09-07','0.131'],['transaksi_m','2017-11-27','141000'],['transaksi_m','2017-08-22','145000']] as [$table,$date,$amount])$bank[]=lr_row($table,['NO_INDUK'=>$identity,'TANGGAL'=>$date,'MASUK'=>$table==='transaksi_m'?$amount:null,'KELUAR'=>$table==='transaksi_k'?$amount:null,'user_id'=>'operator sumber']);
$original=$bank;$fix=['amount'=>'0.00','evidence'=>'Saldo akhir nol disahkan yayasan; presisi jurnal diperbaiki.','confirmed'=>true];
$rec=lf_reconcile($bank);lft_assert($rec['accounts']===1&&$rec['balance']===null&&$rec['journal_issues']&&!in_array('Rekening sumber tidak unik atau tidak tersedia.',$rec['issues'],true),'Invalid amount hid unique source account');
lft_assert(!lop_fixed_bank($bank,null,$fix)['ready'],'Balance FIX bypassed source journal precision');
$cfg=op_config([$person,...$bank],2);$proposal=lop_savings_fix_proposal($bank,$cfg,$fix,'2026-10-17',$identity);
lft_assert(count($proposal['journals'])===1&&$proposal['journal_changes'][0]['original']==='0.131'&&$proposal['journal_changes'][0]['amount']==='0.13','Explicit journal proposal incorrect');
lft_assert($proposal['bank']['ready']&&$proposal['bank']['journals']===7&&$proposal['bank']['incoming']==='643000.13'&&$proposal['bank']['outgoing']==='643000.00'&&$proposal['bank']['adjustment']==='-0.13'&&$bank===$original,'Proposal totals, delta or immutable source incorrect');
$cfg['op_savings'][$identity]=$proposal['fix'];$cfg['op_journals']=$proposal['journals'];$plan=lop_prepare([$person,...$bank],$cfg,2,'2026-10-17');
lft_assert(!$plan['pending']&&$plan['summary']['savings_total']==='0.00'&&count($plan['journals'])===8,'Confirmed precision/FIX did not publish one adjustment');
$adjustments=array_filter($plan['journals'],fn($j)=>($j['origin']??'')==='adjustment');lft_assert(count($adjustments)===1&&array_values($adjustments)[0]['outgoing']==='0.13','Adjustment missing or doubled');
lft_assert($plan===lop_prepare([$person,...$bank],$cfg,2,'2026-10-17'),'Retry changed financial projection');
lft_assert($proposal['journals'][array_key_first($proposal['journals'])]['date']==='2017-09-07 00:00:00','Source date lost');
lft_assert(count(array_filter($plan['journals'],fn($j)=>($j['operator']??null)==='operator sumber'))===7,'Source operator lost');
$again=lop_savings_fix_proposal($bank,$cfg,$fix,'2026-10-17',$identity);lft_assert(!$again['journals']&&$again['bank']['ready'],'Existing correction overwritten');
foreach(['0.135'=>'0.14','0.134999'=>'0.13','0.009'=>'0.01','999.999'=>'1000.00'] as $v=>$expected)lft_assert(lop_precision_cents($v)===$expected,'Decimal string rounding incorrect');
foreach(['-0.131','x','1e3'] as $v)lft_reject(fn()=>lop_precision_cents($v),'Invalid precision candidate accepted');
foreach(['amount'=>'-1','evidence'=>'','confirmed'=>false] as $field=>$value){$bad=$fix;$bad[$field]=$value;lft_reject(fn()=>lop_savings_fix_proposal($bank,[],$bad,'2026-10-17',$identity),'Invalid FIX accepted');}
$bad=$bank;$bad[]=$bank[0];lft_reject(fn()=>lop_savings_fix_proposal($bad,[],$fix,'2026-10-17',$identity),'Duplicate source account accepted');
$bad=$bank;$bad[2]['raw']['TANGGAL']='invalid';lft_reject(fn()=>lop_savings_fix_proposal($bad,[],$fix,'2026-10-17',$identity),'Invalid date bypassed');
$bad=$bank;$bad[2]['raw']['KELUAR']='-1';lft_reject(fn()=>lop_savings_fix_proposal($bad,[],$fix,'2026-10-17',$identity),'Negative journal bypassed');
$bad=$bank;$bad[0]['raw']['SALDO']='invalid';lft_reject(fn()=>lop_savings_fix_proposal($bad,[],$fix,'2026-10-17',$identity),'Invalid text balance bypassed');
$bad=$bank;$bad[0]['raw']['SALDO']='0.13100000005215407';lft_assert(!lop_fixed_bank(lop_effective_bank_rows($bad,$cfg),null,$proposal['fix'])['ready'],'Changed source reused acknowledgement');
echo "PASS: SMP explicit precision confirmation, seven journals, one -0.13 adjustment, zero closing, immutable source, deterministic retry and invalid/ambiguous rejection.\n";
