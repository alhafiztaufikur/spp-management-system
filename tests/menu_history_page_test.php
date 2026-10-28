<?php
/** Exact grouped-history contract; requires an isolated, real restored database. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/report_history_page.php';
$unit=(int)($argv[1]??1);if(!in_array($unit,[0,1,2,3],true))throw new RuntimeException('Invalid unit');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
$checks=0;
function history_equal($a,$b,string $label):void {
    global $checks;
    if($a!==$b){
        $root=(string)getenv('SPP_QA_DIR');
        if($root&&is_dir($root))file_put_contents($root.'/history-mismatch.json',json_encode(['label'=>$label,'actual'=>$a,'expected'=>$b],JSON_PRETTY_PRINT|JSON_PRESERVE_ZERO_FRACTION));
        throw new RuntimeException('History output changed: '.$label);
    }
    $checks++;
}
try{
    foreach([
        ['siswa_status'=>'all'],
        ['siswa_status'=>'active','page'=>2,'per_page'=>25],
        ['siswa_status'=>'all','komponen_tagihan'=>'daftar_ulang','per_page'=>50],
        ['siswa_status'=>'archived','tanggal_awal'=>'2025-01-01','tanggal_akhir'=>'2026-10-27'],
        ['siswa_status'=>'all','status'=>'lunas'],
        ['siswa_status'=>'active','status'=>'cicilan'],
        ['siswa_status'=>'all','status'=>'tunggakan'],
        ['siswa_status'=>'all','q'=>'a'],
    ] as $input){
        $_GET=$input;$f=report_filters($koneksi,$input);
        $full=report_build($koneksi,'riwayat-tagihan',$f);
        $groups=report_billing_history_group_students($full['rows']);
        $page=report_paginate($groups,$f,false);
        $spilled=report_history_page_data($koneksi,$f,false,true);
        if(!$spilled)throw new RuntimeException('Spill unavailable');
        history_equal($spilled['detail_count'],count($full['rows']),'detail count');
        history_equal($spilled['summary'],report_summaries($full['rows']),'summary');
        history_equal(report_money_totals($spilled['report'],'riwayat-tagihan'),report_money_totals($full,'riwayat-tagihan'),'money totals');
        $light=static function($list){foreach($list as &$g)$g['items']=[];unset($g);return $list;};
        $readPage=report_paginate($spilled['groups'],$f,false);$readPage['rows']=$light($readPage['rows']);$page['rows']=$light($page['rows']);
        history_equal($readPage,$page,'selected page and metadata');
        history_equal($light($spilled['groups']),$light($groups),'all groups and order');
        history_equal(report_billing_history_component_columns($spilled['groups']),report_billing_history_component_columns($groups),'columns');
        $meta=$spilled['report'];unset($meta['_billing_groups'],$meta['_read_detail_count'],$meta['_read_totals']);
        $full['rows']=[];history_equal($meta,$full,'metadata and notes');
        history_equal(isset($GLOBALS['report_history_sink'][$koneksi->thread_id]),false,'consumer restored');
        echo 'PASS unit '.$unit.' '.json_encode($input).' peak '.round(memory_get_peak_usage(true)/1048576).' MiB'.PHP_EOL;
    }
}finally{$koneksi->rollback();}
echo 'PASS '.$checks.' exact grouped-history checks'.PHP_EOL;
