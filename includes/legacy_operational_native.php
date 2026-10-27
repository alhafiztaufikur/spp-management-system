<?php
require_once __DIR__.'/legacy_operational_schema.php';

/** Called inside the native payment transaction, after its details and activity exist. */
function lop_native_payment_changed(mysqli $db, ?array $before, ?array $after): void
{
    require_once __DIR__.'/other_fee_billing.php';
    ofb_native_changed($db,$before,$after);
    $billIds = [];
    foreach ([$before, $after] as $snapshot) {
        foreach ($snapshot['details']['bayar_biaya_lain'] ?? [] as $detail) {
            $id = (int)($detail['tagihan_biaya_lain_id'] ?? 0);
            if ($id > 0) $billIds[$id] = $id;
        }
    }
    if (!lop_ready($db)) return;
    require_once __DIR__.'/legacy_billing.php';
    $owners=[];foreach([$before,$after] as $snapshot){$nis=(string)($snapshot['payment']['NO_INDUK']??$snapshot['NO_INDUK']??'');$student=lb_student($db,$nis);if($student)$owners[]=(int)$student['id'];}
    $billingBatches=lb_sync_owners($db,$owners);
    if(!$billIds){lb_refresh_manifests($db,$billingBatches);return;}
    require_once __DIR__.'/legacy_operational_corrections.php';
    sort($billIds, SORT_NUMERIC);
    $batches = array_fill_keys($billingBatches,true);
    foreach ($billIds as $billId) {
        $obligations = lf_rows($db, 'SELECT ob.*,o.batch_id FROM legacy_op_obligations ob
            JOIN legacy_op_owners o ON o.id=ob.owner_id AND o.unit_id=ob.unit_id
            WHERE ob.bill_id=? ORDER BY ob.id FOR UPDATE', [$billId]);
        foreach ($obligations as $obligation) {
            $remaining = lf_cents($obligation['principal']) - lop_obligation_paid($db, (int)$obligation['id']);
            if ($remaining < 0) throw new DomainException('Pembayaran melebihi kewajiban Legacy yang disahkan.');
            if ($remaining !== lf_cents($obligation['remaining'])) {
                lf_query($db, 'UPDATE legacy_op_obligations SET remaining=?,version=version+1 WHERE id=?',
                    [lf_money($remaining), $obligation['id']]);
                $batches[$obligation['batch_id']] = true;
            }
        }
    }
    lb_refresh_manifests($db,array_keys($batches));
}
