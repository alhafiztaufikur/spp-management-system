<?php
if(PHP_SAPI!=='cli')exit(1);require_once __DIR__.'/legacy_operational_resolution_test.php';
$identity='11152017';$person=op_raw($identity,'GK','0','Amar Dewa');
$rows=[$person,lr_row('tabungan',['NO_INDUK'=>$identity,'SALDO'=>'1000','id'=>35]),lr_row('tabungan',['NO_INDUK'=>$identity,'SALDO'=>'0','id'=>36]),lr_row('transaksi_m',['NO_INDUK'=>$identity,'MASUK'=>'533000','KELUAR'=>null,'TANGGAL'=>'2015-02-05']),lr_row('transaksi_k',['NO_INDUK'=>$identity,'MASUK'=>null,'KELUAR'=>'532000','TANGGAL'=>'2015-04-20'])];$original=$rows;$cfg=op_config($rows,3);$p=lop_prepare($rows,$cfg,3,'2026-10-17');
lft_assert(!$p['pending']&&$p['summary']['savings_total']==='1000.00'&&count($p['journals'])===2&&$p['summary']['adjustments']===0&&$rows===$original,'Unique balanced zero-header resolution changed source, money or journals');
$r=$p['resolutions']['zero-header:'.$identity];lft_assert($r['selected_source']['id']===35&&$r['archived_source']['id']===36&&$r['amount']==='1000.00','Source header evidence missing');
$swapped=$rows;[$swapped[1],$swapped[2]]=[$swapped[2],$swapped[1]];lft_assert(lop_prepare($swapped,$cfg,3,'2026-10-17')['resolutions']['zero-header:'.$identity]===$r,'Source order changed selected balance');
$cases=[];$bad=$rows;$bad[2]['raw']['SALDO']='500';$cases[]=$bad;$bad=$rows;$extra=$bad[2];$extra['row_key']=hash('sha256','third-header');$bad[]=$extra;$cases[]=$bad;$bad=$rows;$bad[1]['raw']['SALDO']='999';$cases[]=$bad;$bad=$rows;$bad[3]['raw']['TANGGAL']='invalid';$cases[]=$bad;$bad=$rows;$bad[3]['raw']['MASUK']='-533000';$cases[]=$bad;$bad=$rows;$bad[2]['raw']['SALDO']=null;$cases[]=$bad;$bad=$rows;$bad[]=$person;$cases[]=$bad;
foreach($cases as $bad){$out=[];$resolved=lop_resolve_zero_header($bad,'2026-10-17',$out);lft_assert($resolved===$bad&&!$out,'Ambiguous/invalid/mismatched account silently resolved');}
lft_assert($p===lop_prepare($rows,$cfg,3,'2026-10-17'),'Repeated resolution not deterministic');
echo "PASS: unique matching positive header plus zero archived, exact balance, immutable evidence, deterministic order/retry and ambiguous/invalid rejection.\n";
