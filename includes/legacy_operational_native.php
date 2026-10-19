<?php
require_once __DIR__.'/legacy_operational_schema.php';

/** Called inside the native payment transaction, after its details and activity exist. */
function lop_native_payment_changed(mysqli $db, ?array $before, ?array $after): void
{
    $billIds = [];
    foreach ([$before, $after] as $snapshot) {
        foreach ($snapshot['details']['bayar_biaya_lain'] ?? [] as $detail) {
            $id = (int)($detail['tagihan_biaya_lain_id'] ?? 0);
            if ($id > 0) $billIds[$id] = $id;
        }
    }
    if (!$billIds || !lop_ready($db)) return;
    require_once __DIR__.'/legacy_operational_corrections.php';
    sort($billIds, SORT_NUMERIC);
    $batches = [];
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
    foreach (array_keys($batches) as $batchId) {
        $batch = lf_one($db, 'SELECT manifest FROM legacy_full_batches WHERE id=? FOR UPDATE', [$batchId]);
        $manifest = json_decode($batch['manifest'], true, 512, JSON_THROW_ON_ERROR);
        $manifest['working_digest'] = lop_working_digest($db, $batchId);
        lf_query($db, 'UPDATE legacy_full_batches SET manifest=? WHERE id=?', [lf_json($manifest), $batchId]);
    }
}
