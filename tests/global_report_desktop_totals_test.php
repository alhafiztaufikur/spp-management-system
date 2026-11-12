<?php
/** Money formatting and export opt-ins must survive the desktop-only totals change. */
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_presentation.php';
function desktop_total_check(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
$exportTemplates=['penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan'];
foreach(global_report_templates() as $template)desktop_total_check(global_report_compact_totals($template)===in_array($template,$exportTemplates,true),'Export scope changed: '.$template);
$totals=[
    ['label'=>'Komponen panjang <script>alert(1)</script> & rincian yang tetap lengkap','value'=>1234567890123.25],
    ['label'=>'Nominal negatif','value'=>-125.25],
    ['label'=>'Nilai nol','value'=>0],
    ['label'=>'Belum diketahui','value'=>null],
];
$before=serialize($totals);$html=global_report_totals_html($totals,'status');
desktop_total_check(serialize($totals)===$before,'Renderer mutated totals');
$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();
$xpath=new DOMXPath($doc);$values=[];
foreach($xpath->query('//tbody/tr/td') as $cell)$values[]=$cell->textContent;
desktop_total_check($values===['Rp 1.234.567.890.123,25','Rp -125,25','Rp 0','—'],'Large, signed, fractional, zero and unknown amounts');
desktop_total_check($xpath->query('//script')->length===0&&str_contains($html,'&lt;script&gt;'),'Labels must be escaped');
desktop_total_check(global_report_totals_html([],'psb')==='','Empty totals must not fabricate zero');
$cash=['total_masuk'=>125.25,'total_keluar'=>150.5,'mutasi_bersih'=>-25.25];
desktop_total_check(array_column(report_money_totals($cash,'kas-tabungan'),'value')===[125.25,150.5,-25.25],'Cash totals use their existing model');
desktop_total_check(billing_history_grand_amount(['known'=>0,'unknown'=>true])==='—','Unknown history grand total remains absent');
desktop_total_check(billing_history_grand_amount(['known'=>1,'unknown'=>true,'value'=>0])==='Rp 0','Known zero history total stays zero');
if($out=getenv('SPP_UI_OUTPUT')){
    if(!is_dir($out.'/desktop-totals'))mkdir($out.'/desktop-totals',0700,true);
    file_put_contents($out.'/desktop-totals/edge-summary.html',$html);
}
echo "PASS export scope, immutable totals, escaped labels, large/negative/fractional amounts, null and known zero.\n";
