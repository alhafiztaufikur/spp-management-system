<?php
/** Presentation contract: keep source evidence, amounts, row identity and output scope. */
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
require_once __DIR__.'/../includes/global_report_print.php';
require_once __DIR__.'/../includes/excel.php';
function presentation_check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$base=['title'=>'SPP','subtitle'=>'','columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['tahun_ajaran','Tahun Ajaran'],['m1','Jul 2026','html'],['m2','Agu 2026','html'],['m3','Sep 2026','html'],['total_tagihan','Total Tagihan','money_optional'],['total_bayar','Total Bayar','money'],['status','Status','status']],
    'rows'=>[['nis'=>'000123','nama'=>'Nama Contoh','kelas'=>'1A','tahun_ajaran'=>'2026/2027','m1'=>['text'=>'Lunas','sub'=>'Rp 520.000'],'m2'=>['text'=>'Belum Bayar','sub'=>'Rp 0'],'m3'=>['text'=>'Belum Diketahui','sub'=>''],'total_tagihan'=>null,'total_bayar'=>520000,'status'=>'Lunas']]];
$original=serialize($base);
foreach(['screen','print','excel'] as $surface){
    $year=global_report_present($base,'spp-tahunan',$surface);$r=$year['rows'][0];
    presentation_check($r['m1']['text']===''&&$r['m1']['sub']==='Rp 520.000'&&$r['m2']['sub']==='Rp 0'&&$r['m3']['sub']==='—','Annual cells retain paid values and absent evidence');
    presentation_check($r['total_tagihan']===null&&$r['status']==='Lunas','Unknown obligations and separate status survive');
    $item=global_report_present($base,'per-item',$surface);
    presentation_check($item['rows'][0]['m1']['text']===($surface==='print'?'':'Lunas'),'Per-item output scope');
    presentation_check($item['rows'][0]['m3']['text']==='Belum Diketahui','Per-item uncertainty remains');
    $status=global_report_present($base,'status',$surface);
    presentation_check($status['rows']===$base['rows'],'Status cell values are unchanged');
    presentation_check(global_report_short_subtotal('status',$surface)===($surface==='print'),'Status subtotal scope');
}
presentation_check(serialize($base)===$original,'Source report cannot be mutated');
$annual=global_report_present($base,'spp-tahunan','excel');$annualMatrix=global_report_matrix($annual,'spp-tahunan',1);$annualDoc=global_report_clean_excel_document(global_report_matrix_excel($annualMatrix,$annual,'spp-tahunan',1),'spp-tahunan');
$annualSection=$annualDoc['sheets'][0]['sections'][0];
presentation_check($annualSection['rows'][0]['g0_m1']===520000.0&&$annualSection['rows'][0]['g0_m2']===0.0&&$annualSection['rows'][0]['g0_m3']===null,'Annual Excel monthly numbers and nulls retain their types');
$multi=$base;$multi['rows'][]=array_replace($base['rows'][0],['tahun_ajaran'=>'2025/2026','total_bayar'=>250000]);
$matrix=global_report_matrix(global_report_present($multi,'per-item'),'per-item',1);
$paid=array_filter($matrix['subtotals'],static fn($t)=>$t['key']==='total_bayar');
presentation_check(count($paid)===2&&array_sum(array_column($paid,'value'))===770000.0,'Splitting years must not duplicate whole-section totals');
$receipts=['title'=>'Penerimaan','subtitle'=>'','columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['other','Biaya Lain Buku Tabungan','money'],['total_penerimaan','Total Penerimaan','money']], 'rows'=>[['nis'=>'001','nama'=>'Nama','kelas'=>'1A','other'=>123456789012.25,'total_penerimaan'=>123456789012.25]]];
$shown=global_report_present($receipts,'penerimaan','excel');
presentation_check($shown['columns'][3][1]==='Buku Tabungan'&&$shown['columns'][4][1]==='Total','Receipt headings');
presentation_check(report_money_totals($shown,'penerimaan')[0]['label']==='Total Buku Tabungan','Receipt summary labels');
presentation_check(global_report_present_label('Nama Biaya Lain Buku','penerimaan')==='Nama Biaya Lain Buku','Only a fee prefix may change');
presentation_check(global_report_present_label('Biaya Lain Buku','status')==='Biaya Lain Buku','No global fee formatter change');
$savings=['title'=>'Tabungan','subtitle'=>'','columns'=>[['nis','NIS'],['operator','Operator'],['masuk','Masuk','money'],['keluar','Keluar','money'],['penyesuaian','Penyesuaian','money'],['saldo_saat_ini','Saldo Berjalan','money_optional'],['keterangan','Keterangan']], 'rows'=>[
    ['nis'=>'000123','operator'=>'Legacy · SD · 001aB','_operator_identity'=>'legacy:1:'.bin2hex('001aB'),'masuk'=>1000,'keluar'=>0,'penyesuaian'=>-25.25,'saldo_saat_ini'=>974.75,'keterangan'=>'Legacy ·'],
    ['nis'=>'000124','operator'=>'Petugas Native','_operator_identity'=>'7','masuk'=>0,'keluar'=>125.25,'penyesuaian'=>0,'saldo_saat_ini'=>null,'keterangan'=>'Catatan']]];
$before=serialize($savings);$s=global_report_present($savings,'tabungan-siswa','excel');
presentation_check(!array_intersect(['penyesuaian','keterangan'],array_column($s['columns'],0)),'Hidden savings columns');
presentation_check($s['rows'][0]['operator']==='001aB'&&$s['rows'][1]['operator']==='Petugas Native','Exact operator identity and native name');
presentation_check($s['rows'][0]['saldo_saat_ini']===974.75&&$s['rows'][1]['saldo_saat_ini']===null,'Savings evidence preserved');
presentation_check(!in_array('penyesuaian',array_column(report_money_totals($s,'tabungan-siswa'),'key'),true),'No adjustment summary');
presentation_check(serialize($savings)===$before,'Original savings model remains intact');
$doc=spp_excel_report_document($s,'tabungan-siswa',1);$doc['presentation']='global';$doc=global_report_clean_excel_document($doc,'tabungan-siswa');
$book=spp_excel_workbook($doc);$sheet=$book->getActiveSheet();$found=[];
foreach($sheet->getRowIterator() as $row)foreach($row->getCellIterator() as $cell){
    $v=$cell->getValue();if($v==='001aB'||$v==='000123')presentation_check($cell->getDataType()==='s','Codes are literal strings');
    if($v==='Total Rupiah'){$found[]=$cell->getCoordinate();presentation_check($cell->getStyle()->getFont()->getSize()===10.0,'Summary title uses its own readable size');}
    if($v===-125.25||$v===125.25)presentation_check($cell->getDataType()==='n','Signed decimal money is numeric');
}
presentation_check(count($found)===1,'Summary heading is part of its block');$book->disconnectWorksheets();
$amount=['value'=>125.25,'known'=>1,'unknown'=>true];
presentation_check(billing_history_grand_amount($amount)==='Rp 125,25'&&billing_history_amount($amount)==='Rp 125,25','Mixed totals display the known amount');
presentation_check(billing_history_grand_amount(['known'=>0,'unknown'=>true])==='-','An unknown grand total displays a dash');
$matrix=global_report_matrix(global_report_present($base,'per-item','print'),'per-item',1);$matrix['currency_prefix']=false;
$html=global_report_clean_table($matrix);
presentation_check(str_contains($html,'report-header-nowrap')&&!str_contains($html,'seluruh hasil filter')&&!str_contains($html,'Lunas<br>'),'Print cell and header cleanup');
presentation_check(!str_contains($html,'Belum Diketahui')&&str_contains($html,'>520.000</span>')&&str_contains($html,'>0</span>'),'Per-item print hides the unknown label and retains known amounts');
presentation_check(global_report_clean_header('Januari 2027',false,'spp-tahunan')==='JAN 2027'
    &&global_report_clean_header('Saldo Berjalan',false,'tabungan-siswa')==='SALDO'
    &&global_report_clean_header('Saldo Saat Ini',false,'saldo-tabungan')==='SALDO','A4 headers follow the report labels');
$component=['g0_tagihan','Tagihan','money_optional','source'=>'tagihan','group'=>0];
presentation_check(global_report_print_cell(null,$component,[],false,false,'status')===''
    &&global_report_print_cell('—',$component,[],false,false,'status')===''
    &&global_report_print_cell(0,$component,[],false,false,'status')==='0'
    &&global_report_print_cell(-125.25,$component,[],false,false,'status')==='-125,25','Only empty status component placeholders are hidden');
presentation_check(global_report_print_cell(null,$component,[],false)==='—'
    &&global_report_print_template(['report_template'=>'status','presentation_surface'=>'excel'])==='','Document styling stays scoped to PDF and print');
$du=['title'=>'Daftar Ulang','component_key'=>'daftar_ulang','columns'=>[
    ['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['tagihan','Tagihan','money_optional'],
    ['terbayar','Terbayar','money'],['sisa','Sisa','money_optional'],['status','Status','status'],
],'rows'=>[]];
foreach([
    ['Lunas',400000,400000,0],['Tercakup PSB',null,0,0],['Cicilan',300000,100000,200000],['Belum Diketahui',null,0,null],
] as $index=>[$status,$bill,$paid,$balance]){
    $du['rows'][]=['nis'=>(string)($index+1),'nama'=>'Contoh','kelas'=>'1A','status'=>$status,'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>$balance];
}
$psb=array_replace($du,['title'=>'Uang PSB','component_key'=>'psb','rows'=>[$du['rows'][0]]]);
$report=['title'=>'Status Pembayaran','sections'=>[$psb,$du]];
$matrix=global_report_matrix(global_report_present($report,'status','print'),'status',1);
$matrix['currency_prefix']=false;
$document=new DOMDocument();
$document->loadHTML('<meta charset="UTF-8">'.global_report_clean_table($matrix));
$xpath=new DOMXPath($document);
$duBills=$xpath->query('//tbody/tr/td[@data-column="tagihan"][2]');
presentation_check(array_map(static fn($cell)=>$cell->textContent,iterator_to_array($duBills))===['0','0','300.000',''],'DU zero applies to settled rows and PSB coverage');
presentation_check($xpath->evaluate('string(//tbody/tr[1]/td[@data-column="tagihan"][1])')==='400.000','The paid PSB bill keeps its amount');
presentation_check($xpath->evaluate('string(//tbody/tr[1]/td[@data-column="terbayar"][2])')==='400.000'
    &&$matrix['subtotals']['g1_tagihan']['value']===700000.0
    &&$matrix['rows'][0]['g1_tagihan']===400000,'DU paid figures, subtotals and source principal remain intact');
$saldo=['title'=>'Rekap Saldo Tabungan','columns'=>[['nis','Identitas','nis'],['nama','Nama Pemilik'],['kelas','Kelas / Kelompok','kelas'],['saldo_saat_ini','Saldo Saat Ini','money']],'rows'=>[['nis'=>'000123','nama'=>'Contoh','kelas'=>'1A','saldo_saat_ini'=>1000]]];
$matrix=global_report_flat_print_matrix(global_report_present($saldo,'saldo-tabungan','print'));
$matrix['report_template']='saldo-tabungan';
$matrix['flat_header']=true;
$document->loadHTML('<meta charset="UTF-8">'.global_report_clean_table($matrix));
$xpath=new DOMXPath($document);
presentation_check($xpath->evaluate('string(//th[@data-column="nis"])')==='NIS'
    &&$xpath->evaluate('string(//td[@data-column="nis"])')==='000123','Savings balance PDF shows the NIS heading and original number');
presentation_check(global_report_print_cell('Tercakup PSB',['status','Status'])==='<span class="report-header-nowrap">Tercakup PSB</span>','Whole status phrase');
echo "PASS presentation scope, immutable evidence, yearly splitting, receipt names, exact operator codes, savings balances and typed right-aligned Excel totals.\n";
