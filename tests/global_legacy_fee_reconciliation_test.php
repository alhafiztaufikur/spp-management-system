<?php
/** Independent slot/count/money oracle over every fee in an isolated restored database. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/reports.php';
$checks=0;$evidence=[];$db=$koneksi;$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
function gfr_cents(string $value): int {
    if(!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D',$value,$m))throw new RuntimeException('Oracle expected exact decimal cents.');
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
function gfr_check(bool $ok,string $message): void {
    global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS $message\n";
}
$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try {
    foreach([1,2,3] as $unit){
        unit_set_context($db,$unit);$_SESSION['active_unit_id']=$unit;
        // Physical tables and source JSON deliberately bypass all report projection helpers.
        $sql="SELECT category,SUM(amount) amount,COUNT(*) slots FROM (
            SELECT CONCAT('biaya_lain:',d.master_biaya_lain_id) category,CAST(d.nominal_snapshot AS DECIMAL(20,2)) amount
            FROM bayar_biaya_lain_data d JOIN bayar_data b ON b.unit_id=d.unit_id AND b.id=d.bayar_id
            JOIN siswa_data s ON s.unit_id=b.unit_id AND s.NO_INDUK=b.NO_INDUK
            WHERE d.unit_id=$unit AND d.tagihan_biaya_lain_id IS NULL AND d.master_biaya_lain_id IS NOT NULL
            UNION ALL
            SELECT i.category,i.amount FROM legacy_full_items_data i
            JOIN legacy_full_payments_data p ON p.unit_id=i.unit_id AND p.id=i.payment_id
            JOIN legacy_full_batches_data b ON b.unit_id=p.unit_id AND b.id=p.batch_id
            JOIN siswa_data s ON s.unit_id=p.unit_id AND s.id=p.student_id
            WHERE i.unit_id=$unit AND b.state='completed'
            UNION ALL
            SELECT j.category,j.amount FROM legacy_op_payments_data p
            JOIN legacy_op_owners_data o ON o.unit_id=p.unit_id AND o.id=p.owner_id
            JOIN legacy_full_batches_data b ON b.unit_id=p.unit_id AND b.id=p.batch_id
            JOIN JSON_TABLE(CAST(p.items AS JSON),'$[*]' COLUMNS(category VARCHAR(100) PATH '$.category',amount DECIMAL(20,2) PATH '$.amount')) j
            WHERE p.unit_id=$unit AND p.cancelled=0 AND b.state='completed'
        ) src WHERE category REGEXP '^biaya_lain:[1-9][0-9]*$' AND amount>0 GROUP BY category ORDER BY category";
        $expected=[];foreach($db->query($sql)->fetch_all(MYSQLI_ASSOC) as $r)$expected[$r['category']]=['cents'=>gfr_cents($r['amount']),'slots'=>(int)$r['slots']];
        $actual=[];$unitMatches=true;
        foreach(report_fee_payment_rows($db) as $r){
            $unitMatches=$unitMatches&&(int)$r['unit_id']===$unit;
            $actual[$r['category']]??=['cents'=>0,'slots'=>0];
            $actual[$r['category']]['cents']+=$r['amount_cents'];$actual[$r['category']]['slots']+=$r['detail_count'];
        }
        ksort($actual);gfr_check($unitMatches,'Projection keeps unit '.$unit.' ownership');
        gfr_check(array_keys($expected)===array_keys($actual),'Unit '.$unit.' retains exactly the source fee categories');
        foreach($expected as $category=>$position)gfr_check($position===$actual[$category],'Unit '.$unit.' '.$category.' matches source slots and exact cents');
        $f=report_filters($db,['template'=>'riwayat-tagihan','tanggal_awal'=>'','tanggal_akhir'=>'','siswa_status'=>'all']);
        $legacy=[];foreach(report_fee_payment_rows($db) as $r)if($r['legacy']&&$r['student_id'])$legacy[$r['category']]=($legacy[$r['category']]??0)+$r['amount_cents'];
        $history=[];foreach(report_billing_fee_payment_rows($db,$f) as $r)$history[$r['komponen_key']]=($history[$r['komponen_key']]??0)+(int)round($r['terbayar']*100);
        ksort($legacy);ksort($history);gfr_check($legacy===$history,'Unit '.$unit.' billing evidence retains all active student fee payments');
        $evidence[$unit]=['categories'=>count($expected),'source_slots'=>array_sum(array_column($expected,'slots')),'source_cents'=>array_sum(array_column($expected,'cents')),'fees'=>$expected];
    }
}finally{$db->rollback();}
$output=getenv('SPP_GLOBAL_QA_DIR');if($output)file_put_contents($output.'/fee-reconciliation.json',json_encode(['checks'=>$checks,'units'=>$evidence],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "PASS $checks independent fee evidence checks; read-only snapshot.\n";
