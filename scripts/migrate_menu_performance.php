<?php
/** Additive indexes only; no balances, bills or historical records are changed. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';
$apply=in_array('--apply',$argv,true);
$definitions=[
    ['legacy_op_journals_data','idx_menu_journal_date','unit_id,source_date,owner_id'],
    ['legacy_op_journals_data','idx_menu_journal_operator','unit_id,batch_id,source_operator,origin'],
    ['legacy_op_payments_data','idx_menu_payment_operator','unit_id,batch_id,source_operator'],
    ['legacy_op_payments_data','idx_menu_payment_period','unit_id,cancelled,source_period,batch_id'],
];
foreach($definitions as [$table,$name,$columns]){
    $indexes=$koneksi->query("SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' GROUP BY INDEX_NAME")->fetch_all(MYSQLI_ASSOC);
    if(!$indexes){echo "SKIP $table is unavailable\n";continue;}
    foreach($indexes as $index)if($index['cols']===$columns||str_starts_with($index['cols'],$columns.',')){echo "READY ".$index['INDEX_NAME']."\n";continue 2;}
    echo ($apply?'APPLY':'DRY RUN')." ".DB_NAME.".$table ($name: $columns)\n";
    if($apply)$koneksi->query("ALTER TABLE `$table` ADD INDEX `$name` ($columns), ALGORITHM=INPLACE, LOCK=NONE");
}
