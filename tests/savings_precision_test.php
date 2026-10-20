<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/savings_authorization.php';
$checks=0;
foreach([['90000',9000000],['90.000',9000000],['90.000,50',9000050],['123,13',12313],['123.13',12313]] as [$raw,$expected]){
    if((int)round(savings_nominal_value($raw)*100)!==$expected)throw new RuntimeException('Amount precision lost.');$checks++;
}
if((int)round(savings_nominal_value(123.13)*100)!==12313)throw new RuntimeException('JSON approval precision lost.');$checks++;
foreach(['0','-1','1e5','12,345','1.23.45','1000000000000',INF,12.345] as $bad){$rejected=false;try{savings_nominal_value($bad);}catch(Throwable $e){$rejected=true;}if(!$rejected)throw new RuntimeException('Invalid amount accepted.');$checks++;}
$payload=savings_edit_payload(['nominal'=>'123,13','tanggal'=>'2026-01-01T09:00:00','keterangan'=>'Pecahan tetap'],'edit');
if((int)round($payload['nominal']*100)!==12313)throw new RuntimeException('Correction payload truncated.');$checks++;
echo "PASS $checks savings precision checks\n";
