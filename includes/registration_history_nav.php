<?php
/** Translate only compatible list filters between the existing DU presentations. */
function registration_history_tab_query(array $input,string $target,array $archiveYears):array {
    $out=['view'=>$target,'page'=>1];
    foreach(['q','student_id','operator','per_page'] as $key)if(isset($input[$key]))$out[$key]=$input[$key];
    if($target!=='deleted')$out['source']=$target==='legacy'?'legacy':'all';
    $values=static fn($v)=>array_map(static fn($x)=>in_array((string)$x,['','all','semua','0'],true)?'*':(string)$x,is_array($v)?$v:[$v]);
    $years=$values($input['year']??$input['tahun_ajaran']??'*');
    if($target==='deleted')$years=array_values(array_filter($years,static fn($v)=>$v==='*'||isset($archiveYears[$v])));
    if($years)$out[$target==='deleted'?'tahun_ajaran':'year']=$years;
    $classes=$values($input['class_id']??$input['kelas']??'*');
    $out[$target==='deleted'?'kelas':'class_id']=array_map(static function($v)use($target){
        if($target==='deleted'&&ctype_digit((string)$v))return 'rombel:'.$v;
        return $target!=='deleted'&&str_starts_with((string)$v,'rombel:')?substr((string)$v,7):$v;
    },$classes);
    $statuses=array_values(array_intersect($values($input['status_filter']??$input['status']??'*'),['*','lunas','cicilan']));
    if($statuses)$out[$target==='deleted'?'status':'status_filter']=$statuses;
    return $out;
}
function registration_history_navigation(array $query,string $current,array $archiveYears):string {
    $e=static fn($v)=>htmlspecialchars($v,ENT_QUOTES,'UTF-8');$html='<nav class="ph-tabs du-history-tabs" aria-label="Jenis riwayat">';
    foreach(['active'=>'Riwayat Aktif','deleted'=>'Riwayat Dihapus','legacy'=>'Histori Legacy'] as $tab=>$label){
        $href='riwayat_daftar_ulang.php?'.http_build_query(registration_history_tab_query($query,$tab,$archiveYears));
        $html.='<a href="'.$e($href).'"'.($current===$tab?' aria-current="page"':'').'>'.$label.'</a>';
    }
    return $html.'</nav>';
}
