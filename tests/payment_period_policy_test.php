<?php
require_once __DIR__.'/../includes/payment_period.php';

$due = ['state'=>'due','remaining'=>50000];
$settled = ['state'=>'settled','remaining'=>0];
$bills = [
    '2026-10'=>['spp'=>$settled,'komite'=>$due],
    '2026-11'=>['spp'=>$due,'komite'=>$due],
    '2027-01'=>['spp'=>$due],
    '2027-07'=>['spp'=>$settled],
];
$verify = static function(bool $pass,string $message): void {
    if (!$pass) throw new RuntimeException($message);
};
$verify(payment_period_oldest_due($bills) === '2026-10','Komite-only arrears start Multi Bulan in October.');
[$start,$end] = payment_period_bounds('range','2027-01',payment_period_oldest_due($bills));
$verify([$start,$end] === ['2026-10','2027-01'],'A range can include arrears across the calendar year.');
$bills['2026-10']['komite'] = $settled;
$verify(payment_period_oldest_due($bills) === '2026-11','Settled periods are skipped.');
$verify(payment_period_oldest_due(['2027-07'=>['spp'=>$settled]]) === null,'A student with all periods settled has no range start.');
$verify(payment_period_numeric_label('2026-11') === '11 2026','A monthly report period uses a numeric month and year.');
$verify(payment_period_range_label(['mulai'=>'2026-11','akhir'=>'2027-05'],true) === '11 2026 – 05 2027','The report range includes both numeric endpoints.');
[$start,$end] = payment_period_bounds('academic','2026/2027',null);
$verify(payment_period_range_label(['mulai'=>$start,'akhir'=>$end],true) === '07 2026 – 06 2027','A full academic year uses July through June in the report.');
echo "PASS Multi Bulan arrears, Komite-only balance, settled periods and historical academic-year bounds.\n";
