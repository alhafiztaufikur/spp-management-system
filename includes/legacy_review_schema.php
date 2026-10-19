<?php
function lop_review_schema_ready(mysqli $db): bool {
    return (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='master_kelas' AND COLUMN_NAME IN ('legacy_kind','graduation_year')) OR (TABLE_NAME='master_biaya_lain' AND COLUMN_NAME='legacy_tariff_pending'))")->fetch_row()[0]===3
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('lop_class_kind','lop_tariff_pending','lop_journal_origin') AND CONSTRAINT_TYPE='CHECK'")->fetch_row()[0]===3;
}
/** Additive CLI upgrade; no DDL may be invoked from a request. */
function lop_review_schema_apply(mysqli $db): void {
    if(PHP_SAPI!=='cli')throw new LogicException('Pemasangan melalui CLI.');
    foreach(['master_kelas_data'=>['legacy_kind'=>"VARCHAR(12) NOT NULL DEFAULT 'academic'",'graduation_year'=>'SMALLINT UNSIGNED NULL'],
        'master_biaya_lain_data'=>['legacy_tariff_pending'=>'TINYINT NOT NULL DEFAULT 0']] as $table=>$columns){
        foreach($columns as $name=>$definition)if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$name'")->fetch_row()[0])$db->query("ALTER TABLE $table ADD COLUMN $name $definition");
    }
    $checks=$db->query("SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.CONSTRAINT_TYPE='CHECK' AND tc.TABLE_NAME IN ('master_biaya_lain_data','legacy_op_journals_data','master_kelas_data')")->fetch_all(MYSQLI_ASSOC);
    foreach($checks as $c){$clause=strtolower($c['CHECK_CLAUSE']);if(($c['TABLE_NAME']==='master_biaya_lain_data'&&str_contains($clause,'nominal'))||($c['TABLE_NAME']==='legacy_op_journals_data'&&str_contains($clause,'origin'))||($c['TABLE_NAME']==='master_kelas_data'&&$c['CONSTRAINT_NAME']==='lop_class_kind')){$name=str_replace('`','``',$c['CONSTRAINT_NAME']);$db->query("ALTER TABLE {$c['TABLE_NAME']} DROP CHECK `$name`");}}
    if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='lop_class_kind'")->fetch_row()[0])$db->query("ALTER TABLE master_kelas_data ADD CONSTRAINT lop_class_kind CHECK (legacy_kind IN ('academic','alumni') AND (legacy_kind<>'alumni' OR (tingkat=0 AND is_active=0 AND graduation_year IS NOT NULL AND graduation_year BETWEEN 2000 AND 2099 AND kode_rombel=CONCAT('Alumni',graduation_year))))");
    $db->query("ALTER TABLE master_biaya_lain_data ADD CONSTRAINT lop_tariff_pending CHECK (nominal>0 OR (nominal=0 AND is_active=0 AND legacy_tariff_pending=1))");
    $db->query("ALTER TABLE legacy_op_journals_data ADD CONSTRAINT lop_journal_origin CHECK (origin IN ('legacy','new','adjustment'))");
    foreach(['master_kelas','master_biaya_lain','legacy_op_journals'] as $view)$db->query("CREATE OR REPLACE VIEW $view AS SELECT * FROM {$view}_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
}
