<?php
/** Read-only source models used to read back the existing PDF/XLSX renderers. */
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58360')throw new RuntimeException('Use the owned audit restore.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
if(!str_contains(str_replace('\\','/',$koneksi->query('SELECT @@datadir')->fetch_row()[0]),'/import_audit_20261020/'))throw new RuntimeException('Unexpected database instance.');
$out=[];
foreach([1,2,3,0] as $unit){
    $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);
    $student=hp_one($koneksi,"SELECT s.id,s.NO_INDUK,s.NAMA,s.unit_id,s.master_kelas_id FROM siswa s JOIN tagihan_daftar_ulang t ON t.no_induk=s.NO_INDUK AND t.unit_id=s.unit_id WHERE s.is_active=1 AND s.master_kelas_id IS NOT NULL AND t.nominal_tagihan>0 ORDER BY s.unit_id,s.id LIMIT 1");
    foreach(array_keys(report_registry()) as $template){
        $query=['template'=>$template,'unit'=>$unit?:'all','tanggal_awal'=>'2026-01-01','tanggal_akhir'=>'2026-10-20','tahun_ajaran'=>'2026/2027','siswa_status'=>'all','kategori'=>$template==='penerimaan'?'semua':'spp','kelas'=>'rombel:'.$student['master_kelas_id'],'q'=>$template==='tunggakan-siswa'?'':$student['NO_INDUK']];
        $filters=report_filters($koneksi,$query);$report=report_build($koneksi,$template,$filters);
        $matrix=global_report_matrix($report,$template,$unit);$rows=$matrix['rows']??$report['rows'];$names=[];
        foreach($rows as $row)if(!empty($row['nama']))$names[(string)$row['nama']]=true;
        $totals=$matrix['totals']??report_money_totals($report,$template);
        if($template==='riwayat-tagihan')$totals=array_values(array_filter($totals,static fn($t)=>($t['key']??'')==='tagihan'));
        $out[$unit]['reports'][$template]=['query'=>$query,'rows'=>count($rows),'title'=>$report['title'],'names'=>array_keys($names),'totals'=>array_map(static fn($t)=>['label'=>$t['label'],'value'=>number_format($t['value'],2,'.',''),'formatted'=>report_money($t['value'])],$totals)];
    }
    $out[$unit]['student']=['key'=>$student['unit_id'].'|'.$student['NO_INDUK'],'name'=>$student['NAMA'],'nis'=>$student['NO_INDUK']];
    $debtFilters=report_filters($koneksi,['template'=>'tunggakan-siswa','siswa_status'=>'all']);
    $debts=report_student_debt_groups($koneksi,$debtFilters,'',[],report_letter_today());
    if(!$debts)throw new RuntimeException('A source-proven overdue student is required for the letter test.');
    $debtor=$debts[0];$out[$unit]['parent_student']=['key'=>unit_student_key($debtor),'name'=>$debtor['nama']];
}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
