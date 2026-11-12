<?php
require_once __DIR__.'/parent_letter_principals_schema.php';

class ParentLetterPrincipalConflict extends RuntimeException {}

function plp_school_name(int $unit): string {
    return [1=>"SD Al-Qur'an (SDA) Mutiara Hikmah",2=>'SMP IT Mutiara Hikmah',3=>'SMA IT Mutiara Hikmah'][$unit]??'';
}

function plp_rows(mysqli $db,string $sql,array $params=[]): array {
    $s=$db->prepare($sql);if($params){$types='';foreach($params as $p)$types.=is_int($p)?'i':'s';$s->bind_param($types,...$params);}
    $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
}

function plp_state(mysqli $db,int $unit): array {
    if(!in_array($unit,[1,2,3],true)||!in_array(unit_active_id(),[0,$unit],true))throw new InvalidArgumentException('Unit Kepala Sekolah tidak valid.');
    $rows=plp_rows($db,'SELECT s.selected_id,s.version,p.id,p.name FROM parent_letter_principal_settings s LEFT JOIN parent_letter_principals p ON p.unit_id=s.unit_id AND p.deleted_at IS NULL WHERE s.unit_id=? ORDER BY p.name,p.id',[$unit]);
    $settings=$rows[0]??null;
    if(!$settings)throw new RuntimeException('Pengaturan Kepala Sekolah belum tersedia.');
    $names=[];foreach($rows as $row)if($row['id']!==null)$names[]=['id'=>(int)$row['id'],'name'=>$row['name']];
    if(!in_array((int)$settings['selected_id'],array_column($names,'id'),true))throw new RuntimeException('Kepala sekolah terpilih tidak tersedia.');
    return ['unit_id'=>$unit,'school_name'=>plp_school_name($unit),'selected_id'=>(int)$settings['selected_id'],'version'=>(int)$settings['version'],'names'=>$names];
}

function plp_name($name): string {
    if(!is_string($name)||!mb_check_encoding($name,'UTF-8')||preg_match('/[\p{C}]/u',$name))throw new InvalidArgumentException('Nama kepala sekolah tidak valid.');
    $name=trim(preg_replace('/[\s\p{Z}]+/u',' ',$name));
    if($name===''||mb_strlen($name,'UTF-8')>150||preg_match('/[\p{C}]/u',$name))throw new InvalidArgumentException('Nama kepala sekolah wajib diisi, maksimal 150 karakter.');
    return $name;
}

function plp_mutate(mysqli $db,int $unit,array $input): array {
    if(!in_array($unit,[1,2,3],true)||$unit!==unit_active_id()||(int)($GLOBALS['app_unit_id']??-1)!==$unit)throw new ParentLetterPrincipalConflict('Unit formulir berubah. Muat ulang halaman sebelum menyimpan.');
    $action=$input['action']??'';
    if(!in_array($action,['add','edit','delete','select'],true)||!is_int($input['version']??null))throw new InvalidArgumentException('Permintaan Kepala Sekolah tidak valid.');
    $id=$input['id']??0;
    if($action!=='add'&&(!is_int($id)||$id<1))throw new InvalidArgumentException('Pilih kepala sekolah yang tersedia.');
    $name=in_array($action,['add','edit'],true)?plp_name($input['name']??null):'';
    $db->begin_transaction();
    try {
        $settings=plp_rows($db,'SELECT selected_id,version FROM parent_letter_principal_settings WHERE unit_id=? FOR UPDATE',[$unit])[0]??null;
        if(!$settings||(int)$settings['version']!==$input['version'])throw new ParentLetterPrincipalConflict('Daftar kepala sekolah telah berubah. Muat ulang daftar lalu ulangi perubahan.');
        $names=plp_rows($db,'SELECT id,name FROM parent_letter_principals WHERE unit_id=? AND deleted_at IS NULL',[$unit]);
        $ids=array_map('intval',array_column($names,'id'));
        if($action!=='add'&&!in_array($id,$ids,true))throw new InvalidArgumentException('Kepala sekolah tidak tersedia pada unit ini.');
        $selected=(int)$settings['selected_id'];$changed=true;
        if(in_array($action,['add','edit'],true)){
            if(plp_rows($db,'SELECT id FROM parent_letter_principals WHERE unit_id=? AND deleted_at IS NULL AND name=? AND id<>?',[$unit,$name,$action==='edit'?$id:0]))throw new InvalidArgumentException('Nama tersebut sudah ada pada unit ini.');
            if($action==='add'){
                $s=$db->prepare('INSERT INTO parent_letter_principals(unit_id,name) VALUES(?,?)');$s->bind_param('is',$unit,$name);$s->execute();$selected=(int)$db->insert_id;$s->close();
            }else{
                $s=$db->prepare('UPDATE parent_letter_principals SET name=? WHERE unit_id=? AND id=?');$s->bind_param('sii',$name,$unit,$id);$s->execute();$changed=$s->affected_rows>0;$s->close();
            }
        }elseif($action==='select'){$changed=$selected!==$id;$selected=$id;}
        else {
            if(count($ids)<2)throw new InvalidArgumentException('Tambahkan kepala sekolah pengganti sebelum menghapus nama terakhir.');
            if($selected===$id){
                $replacement=$input['replacement_id']??0;
                if(!is_int($replacement)||$replacement===$id||!in_array($replacement,$ids,true))throw new InvalidArgumentException('Pilih kepala sekolah pengganti sebelum menghapus.');
                $selected=$replacement;
            }
            $s=$db->prepare('UPDATE parent_letter_principals SET deleted_at=NOW() WHERE unit_id=? AND id=?');$s->bind_param('ii',$unit,$id);$s->execute();$s->close();
        }
        if($changed){$s=$db->prepare('UPDATE parent_letter_principal_settings SET selected_id=?,version=version+1 WHERE unit_id=?');$s->bind_param('ii',$selected,$unit);$s->execute();$s->close();}
        $state=plp_state($db,$unit);$db->commit();return $state;
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function plp_snapshots(mysqli $db,array $students): array {
    if(!plp_schema_ready($db))throw new RuntimeException('Pengaturan Kepala Sekolah belum dipasang. Hubungi administrator.');
    $units=array_unique(array_map(static fn($s)=>(int)$s['unit_id'],$students));$out=[];
    // One SELECT ensures a mixed-unit draft cannot combine different read moments.
    $rows=$db->query('SELECT s.unit_id,p.id,p.name FROM parent_letter_principal_settings s JOIN parent_letter_principals p ON p.unit_id=s.unit_id AND p.id=s.selected_id AND p.deleted_at IS NULL')->fetch_all(MYSQLI_ASSOC);
    foreach($rows as $row){$unit=(int)$row['unit_id'];if(in_array($unit,$units,true))$out[$unit]=['principal_id'=>(int)$row['id'],'principal_name'=>$row['name'],'school_name'=>plp_school_name($unit)];}
    if(count($out)!==count($units))throw new RuntimeException('Pilih kepala sekolah untuk setiap unit penerima sebelum membuat surat.');
    return $out;
}
