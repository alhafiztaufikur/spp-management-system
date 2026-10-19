<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/../includes/legacy_additional_savings_view.php';
require_once __DIR__.'/legacy_finance_support.php';
$catalog=['students'=>[['identity'=>'101','name'=>'Siswa Contoh','source_count'=>1]],'additional_savings'=>[]];
for($i=0;$i<14;$i++)$catalog['additional_savings']['k'.$i]=['identity'=>(string)(101+$i),'count'=>3,'fingerprint'=>hash('sha256',(string)$i),'duplicate_proven'=>$i===1,'retired_zero_proven'=>$i===2,'reconciliation'=>['balance'=>'700.00','incoming'=>'500.00','outgoing'=>'100.00']];
$settings=['additional_savings'=>['k1'=>['confirmed'=>true,'fingerprint'=>$catalog['additional_savings']['k1']['fingerprint'],'reason'=>'duplicate','evidence'=>'Bukti pemilik rekening cocok']]];
$v=legacy_ledger_view($catalog,$settings,[]);
lft_assert($v['counts']===['all'=>14,'blocked'=>12,'pending'=>1,'reviewed'=>1]&&count($v['rows'])===6&&$v['pages']===3,'Status/pagination');
lft_assert($v['selected']['name']==='Siswa Contoh'&&$v['selected']['difference']==='300.00','Source identity and exact difference');
$v=legacy_ledger_view($catalog,$settings,['ledger_q'=>'contoh']);lft_assert($v['total']===1,'Name search');
$v=legacy_ledger_view($catalog,$settings,['ledger_page'=>2,'ledger'=>'k8']);lft_assert($v['selected']['key']==='k8','Selection on second page');
$v=legacy_ledger_view($catalog,$settings,['ledger_page'=>999,'ledger_q'=>'not found']);lft_assert($v['selected']===null&&$v['page']===1,'Empty/clamped pagination');
$v=legacy_ledger_view($catalog,$settings,['ledger_filter'=>'reviewed']);lft_assert($v['total']===1&&$v['selected']['key']==='k1','Saved filter');
$settings['additional_savings']['k1']['fingerprint']='stale';$v=legacy_ledger_view($catalog,$settings,[]);lft_assert($v['counts']['reviewed']===0,'Stale decision marked reviewed');
$catalog['students'][]=$catalog['students'][0];$v=legacy_ledger_view($catalog,[],[]);lft_assert($v['selected']['name']==='Pemilik belum teridentifikasi','Ambiguous source got student name');
function lful_e(mixed $v): string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function lful_checked(mixed $v): bool{return in_array($v,[true,1,'1','on'],true);}
function lful_hidden(array $d,string $action): void{echo '<input type="hidden" name="action" value="'.lful_e($action).'">';}
$_GET=['ledger'=>'k1'];$cfg=[];$id='preview-fixture';$d=[];$locked=true;
$posted=['action'=>'additional_savings','ledger_key'=>'k1','ledger_reason'=>'duplicate','ledger_evidence'=>'Bukti <script>tetap teks</script>','ledger_confirmed'=>'on'];
ob_start();require __DIR__.'/../includes/legacy_additional_savings_form.php';$html=ob_get_clean();
lft_assert(str_contains($html,'fieldset disabled')&&str_contains($html,'value="duplicate" selected')&&str_contains($html,'Bukti &lt;script&gt;tetap teks&lt;/script&gt;')&&str_contains($html,'required checked'),'Locked/error input restoration and escaping');
lft_assert(str_contains($html,'name="ledger_fingerprint"')&&str_contains($html,'name="ledger_key"'),'Existing form contract lost');
echo "PASS: preview status, signed amounts, search, pagination, selection, stale decisions and ambiguous identities.\n";
