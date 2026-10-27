<?php
/** Explicit installer contract; HTTP readiness only reads information_schema. */
function ofb_schema_plan(mysqli $db,bool $preflight=false): array {
    $ddl=[];$conflicts=[];
    $columns=[];foreach($db->query("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tagihan_biaya_lain_data'") as $c)$columns[$c['COLUMN_NAME']]=$c['COLUMN_TYPE'];
    if(!$columns)throw new RuntimeException('Pasang skema multiunit sebelum tagihan Biaya Lain.');
    foreach(['occurrence_key'=>"varchar(96)",'origin'=>"enum('issued','purchase','legacy')",'obligation_period'=>'varchar(40)','provenance'=>'json'] as $key=>$type){
        if(isset($columns[$key])&&strtolower($columns[$key])!==$type)$conflicts[]=$key.': definisi kolom bertentangan';
        if(!isset($columns[$key]))$ddl[]="ALTER TABLE tagihan_biaya_lain_data ADD `$key` ".match($key){'occurrence_key'=>"VARCHAR(96) NOT NULL DEFAULT 'once'",'origin'=>"ENUM('issued','purchase','legacy') NOT NULL DEFAULT 'issued'",'obligation_period'=>'VARCHAR(40) NULL','provenance'=>'JSON NULL'};
    }
    $indexes=[];foreach($db->query("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tagihan_biaya_lain_data' GROUP BY INDEX_NAME,NON_UNIQUE") as $i)$indexes[$i['INDEX_NAME']]=$i;
    $old=[];$new=false;foreach($indexes as $name=>$i)if(!(int)$i['NON_UNIQUE']){
        if($i['cols']==='unit_id,master_biaya_lain_id,no_induk')$old[]=$name;
        if($i['cols']==='unit_id,master_biaya_lain_id,no_induk,occurrence_key')$new=true;
    }
    if(!$new){if(isset($indexes['ofb_occurrence']))$conflicts[]='ofb_occurrence: index bertentangan';$ddl[]='ALTER TABLE tagihan_biaya_lain_data ADD UNIQUE KEY ofb_occurrence(unit_id,master_biaya_lain_id,no_induk,occurrence_key)';}
    foreach($old as $name)$ddl[]="ALTER TABLE tagihan_biaya_lain_data DROP INDEX `$name`";
    if(!$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='other_fee_legacy_credits_data'")->num_rows){
        $ddl[]="CREATE TABLE other_fee_legacy_credits_data (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,unit_id TINYINT UNSIGNED NOT NULL,
            bill_id BIGINT NOT NULL,op_payment_id BIGINT NULL,full_item_id BIGINT NULL,
            detail_key CHAR(64) NOT NULL,amount DECIMAL(15,2) NOT NULL,source_fingerprint CHAR(64) NOT NULL,
            evidence JSON NOT NULL,actor_id INT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ofb_detail(unit_id,detail_key),KEY ofb_bill(unit_id,bill_id),
            CONSTRAINT ofb_bill_fk FOREIGN KEY(unit_id,bill_id) REFERENCES tagihan_biaya_lain_data(unit_id,id),
            CONSTRAINT ofb_op_fk FOREIGN KEY(op_payment_id) REFERENCES legacy_op_payments_data(id),
            CONSTRAINT ofb_full_fk FOREIGN KEY(full_item_id) REFERENCES legacy_full_items_data(id),
            CONSTRAINT ofb_actor_fk FOREIGN KEY(actor_id) REFERENCES admin(id),
            CONSTRAINT ofb_amount CHECK(amount>0),
            CONSTRAINT ofb_source CHECK((op_payment_id IS NULL) <> (full_item_id IS NULL))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }else{
        $fks=$db->query("SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='other_fee_legacy_credits_data' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
        foreach(['bill_id'=>['tagihan_biaya_lain_data','id'],'unit_id'=>['tagihan_biaya_lain_data','unit_id'],'op_payment_id'=>['legacy_op_payments_data','id'],'full_item_id'=>['legacy_full_items_data','id'],'actor_id'=>['admin','id']] as $col=>[$table,$target])if(!array_filter($fks,static fn($f)=>$f['COLUMN_NAME']===$col&&$f['REFERENCED_TABLE_NAME']===$table&&$f['REFERENCED_COLUMN_NAME']===$target))$conflicts[]='FK credit tidak lengkap: '.$col;
        $checks=$db->query("SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='other_fee_legacy_credits_data' AND CONSTRAINT_TYPE='CHECK'")->fetch_all(MYSQLI_ASSOC);
        if(count(array_filter($checks,static fn($r)=>$r['ENFORCED']==='YES'))<2)$conflicts[]='CHECK credit tidak lengkap';
    }
    if(!$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='other_fee_billing_audit_data'")->num_rows)$ddl[]="CREATE TABLE other_fee_billing_audit_data (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,unit_id TINYINT UNSIGNED NOT NULL,bill_id BIGINT NOT NULL,
        actor_id INT NOT NULL,action VARCHAR(40) NOT NULL,before_data JSON NOT NULL,after_data JSON NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY ofba_bill(unit_id,bill_id),
        CONSTRAINT ofba_bill_fk FOREIGN KEY(unit_id,bill_id) REFERENCES tagihan_biaya_lain_data(unit_id,id),
        CONSTRAINT ofba_actor_fk FOREIGN KEY(actor_id) REFERENCES admin(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    else {
        $fk=$db->query("SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='other_fee_billing_audit_data' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
        foreach(['bill_id'=>['tagihan_biaya_lain_data','id'],'unit_id'=>['tagihan_biaya_lain_data','unit_id'],'actor_id'=>['admin','id']] as $col=>[$table,$target])if(!array_filter($fk,static fn($f)=>$f['COLUMN_NAME']===$col&&$f['REFERENCED_TABLE_NAME']===$table&&$f['REFERENCED_COLUMN_NAME']===$target))$conflicts[]='FK audit tidak lengkap: '.$col;
    }
    if($preflight){
        $bad=$db->query('SELECT t.id,t.unit_id,t.no_induk,t.nominal_tagihan FROM tagihan_biaya_lain_data t LEFT JOIN siswa_data s ON s.unit_id=t.unit_id AND s.NO_INDUK=t.no_induk LEFT JOIN master_biaya_lain_data m ON m.id=t.master_biaya_lain_id AND m.unit_id=t.unit_id WHERE s.id IS NULL OR m.id IS NULL OR t.nominal_tagihan<=0 LIMIT 20')->fetch_all(MYSQLI_ASSOC);
        if($bad)$conflicts[]='Tagihan tidak sah/orphan: '.json_encode($bad,JSON_THROW_ON_ERROR);
    }
    foreach(['tagihan_biaya_lain','other_fee_legacy_credits','other_fee_billing_audit'] as $view){
        $definition=$db->query("SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$view'")->fetch_row();
        if(!$definition||($view==='tagihan_biaya_lain'&&!str_contains($definition[0],'occurrence_key')))$ddl[]="CREATE OR REPLACE VIEW $view AS SELECT * FROM {$view}_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION";
    }
    if($conflicts)throw new RuntimeException('Preflight tagihan Biaya Lain: '.implode('; ',$conflicts));
    return $ddl;
}
function ofb_schema_ready(mysqli $db): bool {
    try{return !ofb_schema_plan($db);}catch(Throwable $e){return false;}
}
function ofb_schema_apply(mysqli $db): int {
    if(PHP_SAPI!=='cli')throw new RuntimeException('Installer hanya melalui CLI.');
    $plan=ofb_schema_plan($db,true);foreach($plan as $sql)$db->query($sql);
    if(!ofb_schema_ready($db))throw new RuntimeException('Struktur tagihan Biaya Lain belum lengkap.');
    return count($plan);
}
