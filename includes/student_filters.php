<?php
require_once __DIR__.'/filter_choices.php';
/** List/export share the same allowed options and OR predicates. */
function student_list_filters(mysqli $db,array $source):array {
    $classes=class_all($db,true,true);$options=[];
    foreach($classes as $row)$options[(string)$row['id']]=class_label($row);
    $class=filter_register('kelas',$source['kelas']??null,$options,'0','0');
    $status=filter_register('status',$source['status']??null,['active'=>'Aktif','archived'=>'Arsip/Lulus','legacy'=>'Legacy'],'active','all');
    $extra=filter_sql_values($class,'s.master_kelas_id').filter_sql_values($status,"CASE WHEN s.legacy_pending=1 THEN 'legacy' WHEN s.is_active=0 THEN 'archived' ELSE 'active' END");
    return [(int)filter_scalar($class,'0'),filter_scalar($status,'all'),$classes,$extra];
}
