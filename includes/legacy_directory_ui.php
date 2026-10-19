<?php
/** Read-only directory controls shared by the Legacy workspaces. */
require_once __DIR__.'/legacy_ui.php';

function legacy_directory_kinds(): array {
    return ['academic'=>'Kelas akademik','psb'=>'PSB','alumni'=>'Alumni','out'=>'Siswa keluar','account'=>'Rekening khusus','legacy'=>'Perlu klasifikasi'];
}

function legacy_directory_filters(array $input,string $prefix=''): array {
    $q=$input[$prefix.'q']??'';$kind=$input[$prefix.'kind']??'';$page=$input[$prefix.'page']??'1';
    return [
        'q'=>is_string($q)?mb_substr(trim($q),0,100):'',
        'kind'=>is_string($kind)&&isset(legacy_directory_kinds()[$kind])?$kind:'',
        'page'=>is_scalar($page)&&preg_match('/^[0-9]{1,6}$/D',(string)$page)?max(1,(int)$page):1,
    ];
}

function legacy_directory_context(array $input): array {
    $out=[];
    foreach(['q','page','group','class_id','lg_open','lg_q','lg_kind','lg_page','special_q','special_kind','special_status','special_page','special_member_page'] as $key)if(isset($input[$key])&&is_string($input[$key])&&mb_strlen($input[$key])<=100)$out[$key]=$input[$key];
    return $out;
}

function legacy_directory_pager(string $path,array $query,int $page,int $total,string $pageKey='page',string $anchor=''): void {
    $pages=max(1,(int)ceil($total/25));
    echo '<nav class="legacy-directory-pagination" aria-label="Halaman daftar"><span>Halaman '.number_format($page,0,',','.').' dari '.number_format($pages,0,',','.').' · 25 per halaman</span><div>';
    foreach([-1=>'Sebelumnya',1=>'Berikutnya'] as $delta=>$label){$next=$page+$delta;if($next<1||$next>$pages)continue;echo '<a class="btn btn-ghost dbt-button dbt-button-outline" href="'.legacy_ui_e(legacy_ui_url($path,array_replace($query,[$pageKey=>$next])).$anchor).'">'.legacy_ui_e($label).'</a>';}
    echo '</div></nav>';
}
