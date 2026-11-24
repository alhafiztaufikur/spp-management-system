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
$status = payment_period_availability($bills,'2026-11');
$verify($status['monthly_period'] === ['bulan'=>'10','tahun'=>'2026','label'=>'Oktober 2026'],'Komite-only arrears must select October.');
$verify($status['years'] === ['2026/2027'],'A settled future bill should not offer the next academic year.');
$bills['2026-10']['komite'] = $settled;
$status = payment_period_availability($bills,'2026-11');
$verify($status['monthly_period'] === null && $status['oldest'] === '2026-11','Current-month balance qualifies after arrears are settled.');
$bills['2027-07']['spp'] = $due;
$verify(payment_period_availability($bills,'2026-11')['years'] === ['2027/2028','2026/2027'],'New payable academic year must appear.');
$january = payment_period_availability(['2027-01'=>['spp'=>$due]],'2027-01');
$verify($january['years'] === ['2026/2027'] && $january['monthly_period'] === null,'January must keep the same academic year.');
$verify(payment_period_availability(['2027-07'=>['spp'=>$due]],'2027-07')['years'] === ['2027/2028'],'July must use the new year.');
$verify(payment_period_numeric_label('2026-11') === '11 2026','A monthly report period uses a numeric month and year.');
$verify(payment_period_range_label(['mulai'=>'2026-11','akhir'=>'2027-05'],true) === '11 2026 – 05 2027','The report range includes both numeric endpoints.');
[$start,$end] = payment_period_bounds('academic','2026/2027',null);
$verify(payment_period_range_label(['mulai'=>$start,'akhir'=>$end],true) === '07 2026 – 06 2027','A full academic year uses July through June in the report.');
echo "PASS arrears, Komite-only balance, publication-backed years, January and July rollover.\n";
