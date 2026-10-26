<?php
/** Shared semantic contract; inspection never alters schema or operational data. */
function sct_constraint_contract(): array {
    return [
        'fk_sct_student'=>[['unit_id','student_id'],'siswa_data',['unit_id','id']],
        'fk_sct_placement'=>[['penempatan_id'],'siswa_tahun_ajaran_data',['id']],
        'fk_sct_year'=>[['tahun_ajaran_id'],'tahun_ajaran_data',['id']],
        'fk_sct_actor'=>[['actor_id'],'admin',['id']],
    ];
}
function sct_constraint_inspect(mysqli $db,bool $preflight=false): array {
    $out=['missing'=>[],'conflicts'=>[],'invalid'=>[]];
    $table=$db->query("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='spp_tarif_siswa_data'")->fetch_row();
    if(!$table)return ['missing'=>['spp_tarif_siswa_data'=>'create'],'conflicts'=>[],'invalid'=>[]];
    if($table[0]!=='BASE TABLE'){$out['conflicts'][]='spp_tarif_siswa_data bukan tabel fisik';return $out;}
    foreach($db->query("SELECT CONSTRAINT_NAME,TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME<>'spp_tarif_siswa_data' AND CONSTRAINT_NAME IN ('fk_sct_student','fk_sct_placement','fk_sct_year','fk_sct_actor','ck_sct_nonnegative')") as $reserved)$out['conflicts'][]=$reserved['CONSTRAINT_NAME'].': nama sudah digunakan tabel lain';
    $all=$db->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('spp_tarif_siswa_data','siswa_data','siswa_tahun_ajaran_data','tahun_ajaran_data','admin')")->fetch_all(MYSQLI_ASSOC);$cols=[];
    foreach($all as $c)$cols[$c['TABLE_NAME']][$c['COLUMN_NAME']]=$c;
    $fks=[];
    foreach($db->query("SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA=(DATABASE()) same_schema,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='spp_tarif_siswa_data' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION") as $r){
        $name=$r['CONSTRAINT_NAME'];$fks[$name]['columns'][]=$r['COLUMN_NAME'];$fks[$name]['refs'][]=$r['REFERENCED_COLUMN_NAME'];$fks[$name]['table']=$r['REFERENCED_TABLE_NAME'];$fks[$name]['valid']=(bool)$r['same_schema']&&in_array($r['UPDATE_RULE'],['RESTRICT','NO ACTION'],true)&&in_array($r['DELETE_RULE'],['RESTRICT','NO ACTION'],true);
    }
    foreach(sct_constraint_contract() as $name=>[$columns,$parent,$refs]){
        $found=false;
        foreach($fks as $actualName=>$f){
            $sameColumns=$f['columns']===$columns;
            if($sameColumns&&$f['table']===$parent&&$f['refs']===$refs&&$f['valid'])$found=true;
            elseif($sameColumns||$actualName===$name)$out['conflicts'][]=$actualName.': definisi FK bertentangan';
        }
        foreach($columns as $n=>$column){
            $child=$cols['spp_tarif_siswa_data'][$column]??null;$target=$cols[$parent][$refs[$n]]??null;
            if(!$child||!$target||$child['COLUMN_TYPE']!==$target['COLUMN_TYPE']||$child['IS_NULLABLE']!=='NO')$out['conflicts'][]=$name.': tipe/nullability kolom tidak sesuai';
        }
        if(!$found)$out['missing'][$name]='ALTER TABLE spp_tarif_siswa_data ADD CONSTRAINT `'.$name.'` FOREIGN KEY (`'.implode('`,`',$columns).'`) REFERENCES `'.$parent.'` (`'.implode('`,`',$refs).'`) ON UPDATE RESTRICT ON DELETE RESTRICT';
        if($preflight&&!$out['conflicts']){
            $joins=[];foreach($columns as $n=>$column)$joins[]='p.`'.$refs[$n].'`=c.`'.$column.'`';
            $count=(int)$db->query('SELECT COUNT(*) FROM spp_tarif_siswa_data c LEFT JOIN `'.$parent.'` p ON '.implode(' AND ',$joins).' WHERE p.`'.$refs[0].'` IS NULL')->fetch_row()[0];
            if($count)$out['invalid'][$name]=['orphan_rows'=>$count];
        }
    }
    $nominal=$cols['spp_tarif_siswa_data']['nominal_akhir']??null;
    if(!$nominal||$nominal['COLUMN_TYPE']!=='decimal(15,2)'||$nominal['IS_NULLABLE']!=='NO')$out['conflicts'][]='nominal_akhir: tipe/nullability tidak sesuai';
    $found=false;
    foreach($db->query("SELECT t.CONSTRAINT_NAME,t.ENFORCED,c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME='spp_tarif_siswa_data' AND t.CONSTRAINT_TYPE='CHECK'") as $r){
        $clause=preg_replace('/[\\s`()]/','',strtolower($r['CHECK_CLAUSE']));
        if(in_array($clause,['nominal_akhir>=0','0<=nominal_akhir'],true)){
            if($r['ENFORCED']==='YES')$found=true;else $out['conflicts'][]=$r['CONSTRAINT_NAME'].': CHECK tidak aktif';
        }elseif($r['CONSTRAINT_NAME']==='ck_sct_nonnegative')$out['conflicts'][]='ck_sct_nonnegative: definisi bertentangan';
    }
    if(!$found)$out['missing']['ck_sct_nonnegative']='ALTER TABLE spp_tarif_siswa_data ADD CONSTRAINT ck_sct_nonnegative CHECK (nominal_akhir >= 0) ENFORCED';
    if($preflight&&$nominal){$count=(int)$db->query('SELECT COUNT(*) FROM spp_tarif_siswa_data WHERE nominal_akhir<0 OR nominal_akhir IS NULL')->fetch_row()[0];if($count)$out['invalid']['nominal_akhir']=['invalid_rows'=>$count];}
    return $out;
}
