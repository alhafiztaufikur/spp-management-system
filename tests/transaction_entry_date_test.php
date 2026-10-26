<?php
require_once __DIR__.'/../includes/transaction_entry_date.php';
require_once __DIR__.'/../includes/payment_form_feedback.php';
date_default_timezone_set('Asia/Jakarta');
$checks = 0;
$check = static function(bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
foreach (['2020-01-01', date('Y-m-d'), '2030-12-31', '2028-02-29', '1000-01-01', '9999-12-31'] as $day) {
    $check(transaction_entry_timestamp($day, '2026-10-26 11:42:35') === $day.' 11:42:35', 'Chosen date and stable request clock: '.$day);
}
foreach ([null, [], ['2026-10-26'], '', '2026-02-29', '2026-04-31', '2026-13-01', '2026-00-01',
    '26/10/2026', '2026-1-01', '2026-10-26 12:00:00', ' 2026-10-26', "2026-10-26\n", '0000-01-01'] as $bad) {
    try {
        transaction_entry_timestamp($bad);
        throw new RuntimeException('Invalid cash date accepted: '.json_encode($bad));
    } catch (DomainException $error) { $checks++; }
}
foreach (['', '2026-02-30 11:42:35', '2026-10-26 24:00:00', '2026-10-26', 'bad'] as $bad) {
    try {
        transaction_entry_timestamp('2026-10-26', $bad);
        throw new RuntimeException('Invalid request clock accepted.');
    } catch (DomainException $error) { $checks++; }
}
$before = date('H:i:s');
$stamp = transaction_entry_timestamp('2020-01-01');
$after = date('H:i:s');
$check(in_array(substr($stamp, 11), [$before, $after], true), 'Default time uses server clock.');
$draft = payment_capture_draft(['tanggal_bayar'=>'2028-02-29', 'bulan_bayar'=>'11', 'tahun_bayar'=>'2026']);
$check($draft === ['tanggal_bayar'=>'2028-02-29', 'bulan_bayar'=>'11', 'tahun_bayar'=>'2026'], 'Cash date and billing period survive failure independently.');
$check(!isset(payment_capture_draft(['tanggal_bayar'=>[]])['tanggal_bayar']), 'Invalid date array is not restored.');
echo "PASS $checks transaction entry date checks.\n";
