<?php
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/reports.php';
function scope_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function scope_totals(array $report,string $template):array{$out=[];foreach(report_money_totals($report,$template) as $item){$key=$item['key']??$item['label'];$out[$key]=($out[$key]??0)+(float)$item['value'];}ksort($out);return $out;}
$source=['tanggal_awal'=>'2000-01-01','tanggal_akhir'=>'2035-12-31','tahun_ajaran'=>'2026/2027','bulan_awal'=>'07','bulan_akhir'=>'06','tahun'=>'2026','kategori'=>'spp','siswa_status'=>'all'];
foreach(['super_admin','admin','kasir','bendahara'] as $role){
    $_SESSION=['admin_id'=>1,'admin_role'=>$role,'admin_unit_id'=>1,'active_unit_id'=>1];$_SERVER['REQUEST_METHOD']='GET';
    foreach(global_report_templates() as $template){
        $parts=[];$expected=[];$rows=0;
        foreach([1,2,3] as $unit){$query=$source+['template'=>$template,'unit'=>(string)$unit];if($template==='penerimaan')$query['kategori']='semua';global_report_scope($koneksi,$query);$filters=report_filters($koneksi,$query);$part=report_build($koneksi,$template,$filters);scope_assert(report_query_unit_id()===$unit,'Read scope lost');scope_assert(unit_active_id()===1,'Operational unit changed');$parts[$unit]=$part;$rows+=count($part['rows']);foreach(scope_totals($part,$template) as $key=>$amount)$expected[$key]=($expected[$key]??0)+$amount;}
        $query=$source+['template'=>$template,'unit'=>'all'];if($template==='penerimaan')$query['kategori']='semua';global_report_scope($koneksi,$query);$filters=report_filters($koneksi,$query);$combined=report_build($koneksi,$template,$filters);ksort($expected);
        scope_assert(count($combined['rows'])===$rows,'Combined row count: '.$template.' '.$role);scope_assert(scope_totals($combined,$template)===$expected,'Combined totals: '.$template.' '.$role);
        foreach($combined['rows'] as $row)scope_assert(in_array((int)$row['unit_id'],[1,2,3],true),'Missing row ownership');
        scope_assert(report_query_unit_id()===0&&unit_active_id()===1,'Combined scope/session leaked');
        if($template==='penerimaan'){$sql=(float)$koneksi->query("SELECT COALESCE(SUM(total_jumlah),0) FROM bayar WHERE TGL_BYR>='2000-01-01' AND TGL_BYR<'2036-01-01'")->fetch_row()[0];scope_assert(abs(array_sum(array_column($combined['rows'],'total_penerimaan'))-$sql)<.001,'Receipts differ from independent SQL');}
        if($template==='saldo-tabungan'){$sql=(float)$koneksi->query('SELECT COALESCE(SUM(SALDO),0) FROM tabungan')->fetch_row()[0];scope_assert(abs(array_sum(array_column($combined['rows'],'saldo_saat_ini'))-$sql)<.001,'Savings differ from independent SQL');}
    }
    $query=['unit'=>'active'];scope_assert(global_report_scope($koneksi,$query)===1,'Legacy active lost');
    $query=['unit'=>'2','scope_change'=>'1','kelas'=>['rombel:1'],'student_id'=>'1','operator'=>'1','q'=>'old','kategori'=>'psb','tanggal_awal'=>'2026-07-01','tanggal_akhir'=>'2026-08-01','tahun_ajaran'=>'2026/2027'];global_report_scope($koneksi,$query);
    scope_assert(!array_intersect(['kelas','student_id','operator','q','kategori'],array_keys($query)),'Dependent filters survived switch');scope_assert($query['tanggal_awal']==='2026-07-01'&&$query['tahun_ajaran']==='2026/2027','Date/year lost');
    $html=global_report_selector($koneksi,2,$query,'template.php');scope_assert(substr_count($html,'data-report-unit=')===4&&substr_count($html,'aria-current="true"')===1,'Selector state incorrect');scope_assert(report_query_unit_id()===2&&unit_active_id()===1,'Selector changed context/session');
    echo "PASS: $role all nine templates, sums/SQL reconciliation, ownership, selected/operational scope and filter reset\n";
}
