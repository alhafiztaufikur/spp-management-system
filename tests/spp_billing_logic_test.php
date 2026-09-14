<?php
require_once __DIR__.'/../includes/spp_billing.php';
function spp_logic_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$net=spp_net_tariff(250000,12.5);
spp_logic_assert($net['discount']===31250.0&&$net['net']===218750.0,'Potongan pecahan SPP salah.');
spp_logic_assert(abs((float)spp_net_tariff(250000,100)['net'])<.001,'Potongan penuh tidak menghasilkan Rp0.');
$periods=spp_academic_periods('2026/2027');
spp_logic_assert(count($periods)===12,'Periode SPP bukan 12 bulan.');
spp_logic_assert($periods[0]['bulan']==='07'&&$periods[0]['tahun']==='2026','Periode awal bukan Juli tahun pertama.');
spp_logic_assert($periods[11]['bulan']==='06'&&$periods[11]['tahun']==='2027','Periode akhir bukan Juni tahun kedua.');
echo "OK: rumus tarif dan urutan Juli-Juni tervalidasi.\n";
