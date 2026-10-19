<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/http_form_scope.php';
spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_operational_native.php';
require_once __DIR__.'/../includes/legacy_operational_corrections.php';
require_once __DIR__.'/../includes/transaction_authorization.php';
require_once __DIR__.'/../includes/payment_activity.php';
require_once __DIR__.'/legacy_finance_support.php';
$db = $koneksi;
$root = dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));
$fixture = json_decode(file_get_contents($root.'/operational-fixture.json'), true);
unit_set_context($db, 1);
$_SESSION = ['admin_id'=>$fixture['actor'], 'admin_role'=>'super_admin', 'active_unit_id'=>1];
$ob = lf_one($db, 'SELECT * FROM legacy_op_obligations ORDER BY id LIMIT 1');
$detail = lf_one($db, 'SELECT * FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=? ORDER BY id DESC LIMIT 1', [$ob['bill_id']]);
$paymentId = (int)$detail['bayar_id'];
$snapshot = transaction_authorization_snapshot($db, $paymentId)['data'];
// Normalize only the owned pre-hook fixture; there is no production backfill.
$db->begin_transaction();
lop_native_payment_changed($db, null, $snapshot);
$db->commit();
lop_check_result($db, $fixture['job']);
$initial = lf_one($db, 'SELECT * FROM legacy_op_obligations WHERE id=?', [$ob['id']]);
$expected = lf_cents($initial['principal'])-lop_obligation_paid($db, (int)$ob['id']);
lft_assert(lf_cents($initial['remaining']) === $expected, 'Native receipt left stale remaining');
$db->begin_transaction();
try {
    $reduced = lf_cents((string)$detail['nominal_snapshot'])-100;
    lft_assert($reduced > 0, 'Owned installment too small');
    lf_query($db, 'UPDATE bayar_biaya_lain SET nominal_snapshot=? WHERE id=?', [lf_money($reduced), $detail['id']]);
    $after = transaction_authorization_snapshot($db, $paymentId)['data'];
    payment_activity_record($db, $paymentId, 'edited', $fixture['actor'], $snapshot, $after, 'op-native-edit-'.bin2hex(random_bytes(12)));
    $current = lf_one($db, 'SELECT * FROM legacy_op_obligations WHERE id=?', [$ob['id']]);
    lft_assert(lf_cents($current['remaining']) === $expected+100 && (int)$current['version'] > (int)$initial['version'], 'Edit did not refresh obligation');
    lf_query($db, 'DELETE FROM bayar_biaya_lain WHERE id=?', [$detail['id']]);
    payment_activity_record($db, $paymentId, 'deleted', $fixture['actor'], $after, null, 'op-native-delete-'.bin2hex(random_bytes(12)));
    $current = lf_one($db, 'SELECT * FROM legacy_op_obligations WHERE id=?', [$ob['id']]);
    lft_assert(lf_cents($current['remaining']) === $expected+lf_cents((string)$detail['nominal_snapshot']), 'Delete did not reopen remaining');
    $batch = lf_one($db, 'SELECT manifest FROM legacy_full_batches WHERE id=?', [$fixture['job']]);
    lft_assert(json_decode($batch['manifest'], true)['working_digest'] === lop_working_digest($db, $fixture['job']), 'Working digest not refreshed atomically');
} finally { $db->rollback(); }
lft_assert(lf_one($db, 'SELECT * FROM legacy_op_obligations WHERE id=?', [$ob['id']]) === $initial, 'Rollback did not preserve obligation');
lop_check_result($db, $fixture['job']);
echo "PASS: native receipt/edit/delete refresh remaining and version atomically; rollback preserves all original rows.\n";
