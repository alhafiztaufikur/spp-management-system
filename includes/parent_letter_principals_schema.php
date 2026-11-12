<?php
/** Installed explicitly by CLI; HTTP readiness checks never write. */
function plp_schema_plan(mysqli $db): array {
    $ddl=[];
    $tables=[
        'parent_letter_principals_data'=>"CREATE TABLE parent_letter_principals_data (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            unit_id TINYINT UNSIGNED NOT NULL, name VARCHAR(150) NOT NULL,
            deleted_at DATETIME NULL,
            active_name VARCHAR(150) GENERATED ALWAYS AS (IF(deleted_at IS NULL,name,NULL)) STORED,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY plp_unit_id(unit_id,id), UNIQUE KEY plp_active_name(unit_id,active_name),
            CONSTRAINT plp_unit_fk FOREIGN KEY(unit_id) REFERENCES unit_sekolah(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'parent_letter_principal_settings_data'=>"CREATE TABLE parent_letter_principal_settings_data (
            unit_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            selected_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT plps_unit_fk FOREIGN KEY(unit_id) REFERENCES unit_sekolah(id),
            CONSTRAINT plps_selected_fk FOREIGN KEY(unit_id,selected_id) REFERENCES parent_letter_principals_data(unit_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach($tables as $table=>$sql){
        $exists=$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND TABLE_TYPE='BASE TABLE'")->num_rows;
        if(!$exists)$ddl[]=$sql;
        else {
            $columns=array_column($db->query("SHOW COLUMNS FROM $table")->fetch_all(MYSQLI_ASSOC),'Field');
            $required=$table==='parent_letter_principals_data'?['id','unit_id','name','deleted_at','active_name','created_at','updated_at']:['unit_id','selected_id','version','updated_at'];
            if(array_diff($required,$columns))throw new RuntimeException('Struktur Kepala Sekolah bertentangan: '.$table);
        }
    }
    foreach(['parent_letter_principals','parent_letter_principal_settings'] as $view){
        $definition=$db->query("SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$view'")->fetch_row();
        if(!$definition)$ddl[]="CREATE VIEW $view AS SELECT * FROM {$view}_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION";
        elseif(!str_contains($definition[0],'current_unit_id'))throw new RuntimeException('View Kepala Sekolah tidak memiliki pembatas unit.');
        foreach(['bi'=>'INSERT','bu'=>'UPDATE'] as $suffix=>$event){
            $trigger=$view.'_data_'.$suffix;
            if(!$db->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$trigger'")->num_rows){
                $guard=$suffix==='bu'?' OR OLD.unit_id<>current_unit_id() OR NEW.unit_id<>OLD.unit_id':'';
                $ddl[]="CREATE TRIGGER $trigger BEFORE $event ON {$view}_data FOR EACH ROW BEGIN IF current_unit_id() NOT BETWEEN 1 AND 3 OR NEW.unit_id<>current_unit_id()$guard THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Perubahan Kepala Sekolah lintas unit ditolak'; END IF; END";
            }
        }
    }
    return $ddl;
}

function plp_schema_ready(mysqli $db): bool {
    try{return plp_schema_plan($db)===[];}catch(Throwable $e){return false;}
}

function plp_seed_pending(mysqli $db): array {
    if(!plp_schema_ready($db))return [1,2,3];
    $found=array_map('intval',array_column($db->query('SELECT unit_id FROM parent_letter_principal_settings_data')->fetch_all(MYSQLI_ASSOC),'unit_id'));
    return array_values(array_diff([1,2,3],$found));
}

function plp_schema_apply(mysqli $db): array {
    if(PHP_SAPI!=='cli')throw new RuntimeException('Pemasangan Kepala Sekolah hanya melalui CLI.');
    $plan=plp_schema_plan($db);foreach($plan as $sql)$db->query($sql);
    $old=(int)($GLOBALS['app_unit_id']??1);$seeded=[];
    try {
        foreach([1=>'Hairul Laka',2=>'Ahmad Hubbudin',3=>'Nur Qomariyah'] as $unit=>$name){
            unit_set_context($db,$unit);$db->begin_transaction();
            try {
                // Lock the permanent unit row even before its settings row exists.
                $db->query("SELECT id FROM unit_sekolah WHERE id=$unit FOR UPDATE");
                if(!$db->query("SELECT 1 FROM parent_letter_principal_settings WHERE unit_id=$unit")->num_rows){
                    $existing=$db->query("SELECT id FROM parent_letter_principals WHERE unit_id=$unit AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch_row();
                    if($existing)$id=(int)$existing[0];
                    else {$s=$db->prepare('INSERT INTO parent_letter_principals(unit_id,name) VALUES(?,?)');$s->bind_param('is',$unit,$name);$s->execute();$id=(int)$db->insert_id;$s->close();}
                    $s=$db->prepare('INSERT INTO parent_letter_principal_settings(unit_id,selected_id) VALUES(?,?)');$s->bind_param('ii',$unit,$id);$s->execute();$s->close();$seeded[]=$unit;
                }
                $db->commit();
            }catch(Throwable $e){$db->rollback();throw $e;}
        }
    }finally{unit_set_context($db,$old);}
    return ['schema_changes'=>count($plan),'seeded_units'=>$seeded];
}
