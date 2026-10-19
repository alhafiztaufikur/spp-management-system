<?php
require_once __DIR__.'/kelas.php';
require_once __DIR__.'/filter_choices.php';

/** History membership follows the current account, not financial snapshots. */
function history_class_filter(mysqli $db,array $source):array {
    $rows=$db->query("SELECT mk.* FROM master_kelas mk WHERE mk.is_active=1 OR EXISTS(SELECT 1 FROM siswa s WHERE s.master_kelas_id=mk.id AND s.unit_id=mk.unit_id) ORDER BY mk.unit_id,CASE mk.jenis WHEN 'out' THEN 0 WHEN 'psb' THEN 1 WHEN 'reguler' THEN 2 ELSE 3 END,mk.tingkat,mk.kode_rombel,mk.nama_custom,mk.id")->fetch_all(MYSQLI_ASSOC);
    $options=[];foreach($rows as $r)$options[(string)$r['id']]=class_label($r);
    $input=$source['rombel']??['*'];
    if(!is_array($input)){
        if(!is_scalar($input))filter_choice_error('Kelas/Rombel');
        $input=in_array((string)$input,['','all','0','*'],true)?['*']:[(string)$input];
    }
    $values=filter_register('rombel',$input,$options,'*','*');
    return ['values'=>$values,'rows'=>$rows];
}
function history_class_where(array $filter,string $studentAlias='s'):string {
    return filter_sql_values($filter['values'],$studentAlias.'.master_kelas_id');
}
/** An EXISTS keeps unfiltered archives independent of a live account. */
function history_class_archive_where(array $filter,string $unitExpression,string $nisExpression):string {
    if(filter_is_all($filter['values']))return '';
    return ' AND EXISTS(SELECT 1 FROM siswa hc WHERE hc.unit_id='.$unitExpression.' AND hc.NO_INDUK='.$nisExpression.history_class_where($filter,'hc').')';
}
function history_class_field(array $filter,string $id):void {
    $e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    echo '<div class="field-row history-rombel-field"><label class="field-label" for="'.$e($id).'">Kelas/Rombel</label><select class="field-input field-select" id="'.$e($id).'" name="rombel" data-filter-multiple aria-label="Kelas/Rombel"><option value="">Semua Kelas/Rombel</option>';
    $group=null;foreach($filter['rows'] as $row){
        if(unit_all_readonly()&&$group!==(int)$row['unit_id']){if($group!==null)echo '</optgroup>'; $group=(int)$row['unit_id'];echo '<optgroup label="'.$e(unit_label($group)).'">';}
        echo '<option value="'.(int)$row['id'].'">'.$e(class_label($row)).'</option>';
    }
    if($group!==null)echo '</optgroup>';echo '</select></div>';
}
