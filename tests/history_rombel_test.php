<?php
/** Read/projection checks and reversible membership changes on a verified clone. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/history_class_filter.php';require_once __DIR__.'/../includes/payment_archive.php';require_once __DIR__.'/../includes/authorization_presentation.php';require_once __DIR__.'/../includes/payment_return.php';
require_once __DIR__.'/../includes/pagination.php';
$f=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/rombel-fixture.json'),true);$checks=0;$cashTotals=[];
function hr_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function hr_http(string $route):array{global $f;$c=curl_init(getenv('SPP_TEST_BASE_URL').'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$f['sessions']['super_admin']['id'],CURLOPT_TIMEOUT=>30]);$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,$body];}
function hr_switch(int $unit):void{global $f;$c=curl_init(getenv('SPP_TEST_BASE_URL').'/unit_switch.php');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$f['sessions']['super_admin']['id'],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['unit_id'=>$unit,'csrf_token'=>$f['sessions']['super_admin']['csrf'],'next'=>'/dashboard.php'])]);curl_exec($c);curl_close($c);}
foreach([1,2,3] as $unit){
    $_SESSION=['admin_id'=>$f['sessions']['super_admin']['actor'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);hr_switch($unit);$item=$f['units'][$unit];$nis=$item['nis'];$custom=(int)$item['custom_id'];
    $filter=history_class_filter($koneksi,['rombel'=>(string)$custom]);hr_check($filter['values']===[(string)$custom],'Scalar filter compatibility');
    hr_check(filter_is_all(history_class_filter($koneksi,[])['values']),'Old URLs select all');
    foreach([[$f['units'][$unit===3?1:3]['custom_id']],[['invalid']],['*',(string)$custom]] as $invalid){try{history_class_filter($koneksi,['rombel'=>$invalid]);throw new RuntimeException('Invalid rombel accepted');}catch(InvalidArgumentException $e){$checks++;}}
    $q=$koneksi->query('SELECT COUNT(*) FROM bayar p JOIN siswa s ON s.unit_id=p.unit_id AND s.NO_INDUK=p.NO_INDUK WHERE 1=1'.history_class_where($filter));$paymentCount=(int)$q->fetch_row()[0];hr_check($paymentCount>=3,'Current custom membership includes historic regular payments');
    $multi=history_class_filter($koneksi,['rombel'=>[(string)$custom,(string)$item['out_id']]]);hr_check(count($multi['values'])===2,'OR selection');
    ob_start();history_class_field(history_class_filter($koneksi,[]),'default-rombel');$field=filter_choices_markup(ob_get_clean());hr_check(str_contains($field,'value="*" selected'),'All rombel is the default native selection');
    $return=payment_return_create((int)$item['payment_id'],$unit,['rombel'=>$multi['values'],'page'=>2],'lihat.php');parse_str((string)parse_url(payment_return_url($return,(int)$item['payment_id']),PHP_URL_QUERY),$back);hr_check($back['rombel']===$multi['values']&&$back['page']==='2','Edit return preserves membership and pagination');
    $params=['rombel'=>[$custom],'tanggal_awal'=>'2020-01-01','tanggal_akhir'=>'2035-12-31'];
    foreach(['pembayaran/lihat.php','tabungan/riwayat.php'] as $route){
        [$code,$html]=hr_http($route.'?'.http_build_query($params));hr_check($code===200&&!preg_match('/Fatal error|Warning:|Parse error/',$html),'Filtered HTTP '.$route);hr_check(str_contains($html,'name="rombel[]"')&&str_contains($html,'value="'.$custom.'" selected'),'Selected multi rombel rendered');
        $dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);$cards=$xp->query($route==='pembayaran/lihat.php'?'//*[@data-payment-record]':'//*[contains(concat(" ",@class," ")," sw-transaction-select ")]');hr_check($cards->length===($route==='pembayaran/lihat.php'?min(10,$paymentCount):2),'Only current custom account transactions are listed: '.$route.' actual '.$cards->length.' payments '.$paymentCount);foreach($cards as $card)hr_check(str_contains($card->textContent,$nis),'History account matches current rombel');
        $empty=$params;$empty[$route==='pembayaran/lihat.php'?'search':'nis']='not-a-student-000';[$code,$none]=hr_http($route.'?'.http_build_query($empty));hr_check($code===200&&!str_contains($none,$route==='pembayaran/lihat.php'?'data-payment-record':'class="sw-transaction-select"'),'Student query AND rombel filter');
        if($route==='tabungan/riwayat.php')hr_check(str_contains($html,'name="return_rombel[]" value="'.$custom.'"'),'Savings correction return preserves current rombel');
        $archive=payment_archive_page($koneksi,'2020-01-01','2035-12-31','',0,1,10,['*'],$filter);foreach($archive['rows'] as $archived)hr_check($archived['NO_INDUK']===$nis,'Archive membership follows current account');
        $bad=$params;$bad['rombel']=[$f['units'][$unit===3?1:3]['custom_id']];[$code]=hr_http($route.'?'.http_build_query($bad));hr_check($code===400,'Cross-unit class rejected');
        $archive=$params;$archive['view']=$route==='pembayaran/lihat.php'?'deleted':'archive';[$code,$html]=hr_http($route.'?'.http_build_query($archive));hr_check($code===200&&!str_contains($html,'Fatal error'),'Archive supports membership filter');
    }
    $rf=report_filters($koneksi,['q'=>$nis,'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-31']);$before=$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row();
    $all=report_savings_transactions($koneksi,'2026-10-01 00:00:00','2026-11-01 00:00:00',$rf);$school=$rf;$school['_school_savings_cash']=true;
    $account=static fn($rows)=>array_values(array_filter($rows,static fn($r)=>$r['NO_INDUK']===$nis));$schoolRows=report_savings_transactions($koneksi,'2026-10-01 00:00:00','2026-11-01 00:00:00',$school);
    hr_check(count($account($all))===2,'Dedicated savings ledger retains custom entries');hr_check(count($account($schoolRows))===0,'School cash excludes custom in and out');
    $cash=report_build($koneksi,'kas-tabungan',$rf);hr_check($cash['total_masuk']==array_sum(array_column($schoolRows,'masuk'))&&$cash['total_keluar']==array_sum(array_column($schoolRows,'keluar')),'Closing cash uses the same scope');
    $cashTotals[]=$cash;
    $balances=report_build($koneksi,'saldo-tabungan',$rf);hr_check(count($balances['rows'])===1,'Balance report keeps custom account');
    $koneksi->begin_transaction();try{
        $regular=$koneksi->query("SELECT id,tingkat FROM master_kelas WHERE jenis='reguler' AND is_active=1 LIMIT 1")->fetch_assoc();$cid=(int)$regular['id'];$level=$regular['tingkat'];$q=$koneksi->prepare('UPDATE siswa SET master_kelas_id=?,KELAS=? WHERE NO_INDUK=?');$q->bind_param('iss',$cid,$level,$nis);$q->execute();
        hr_check(count($account(report_savings_transactions($koneksi,'2026-10-01 00:00:00','2026-11-01 00:00:00',$school)))===2,'Cash classification follows current membership');
        $q=$koneksi->prepare("UPDATE siswa SET master_kelas_id=?,KELAS='CUSTOM' WHERE NO_INDUK=?");$q->bind_param('is',$custom,$nis);$q->execute();$koneksi->query('UPDATE master_kelas SET is_active=0 WHERE id='.$custom);hr_check(in_array($custom,array_column(history_class_filter($koneksi,[])['rows'],'id')),'Inactive class with members remains selectable');
    }finally{$koneksi->rollback();}
    hr_check($koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()===$before,'Membership projection never changes balance');
    // Audit-only baseline for browser coverage in every unit on this verified clone.
    $id=(int)$koneksi->query("SELECT id FROM transaksi_m WHERE NO_INDUK='$nis' LIMIT 1")->fetch_row()[0];
    [$tx,$ledger]=savings_target_locked($koneksi,'masuk',$id);
    $snapshot=savings_snapshot($tx,$ledger['student']);savings_ensure_baseline($koneksi,$tx,$snapshot['data']);
    $note='QA histori rombel: catatan audit panjang untuk memeriksa pembungkusan teks tanpa mengubah nominal dan waktu transaksi.';
    if($tx['keterangan']!==$note)savings_correct($koneksi,'direct_edit','masuk',(int)$tx['id'],['expected_hash'=>$snapshot['hash'],'nominal'=>(string)$tx['MASUK'],'tanggal'=>$tx['TANGGAL'],'keterangan'=>$note,'reason'=>$note],$f['sessions']['super_admin']['actor']);
}
unit_set_context($koneksi,0);$_SESSION['active_unit_id']=0;$all=history_class_filter($koneksi,[]);hr_check(count(array_unique(array_column($all['rows'],'unit_id')))===3,'All-unit options contain all three units');
$combined=report_build($koneksi,'kas-tabungan',report_filters($koneksi,['tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-31']));hr_check($combined['total_masuk']==array_sum(array_column($cashTotals,'total_masuk'))&&$combined['total_keluar']==array_sum(array_column($cashTotals,'total_keluar')),'Combined school cash matches isolated unit totals');
$model=['reference'=>'TB-M-1-000001','student'=>'Uji <script>','nis'=>'000001','unit'=>'SD','request'=>null,'events'=>[]];
foreach(['created','edited','deleted','request_edit','approved'] as $action)$model['events'][]=['action'=>$action,'label'=>$action,'time'=>'18/10/2026 12:00:00 WIB','name'=>'Operator','username'=>'operator','role'=>'admin','proposed'=>$action==='request_edit','reconstructed'=>false,'note'=>str_repeat('Alasan perubahan. ',8),'changes'=>[['label'=>'Nominal','before'=>'Rp 0','after'=>'Rp 100.000'],['label'=>'Keterangan','before'=>'','after'=>'<script>']]];
ob_start();authorization_render_savings($model);$html=ob_get_clean();hr_check(substr_count($html,'<li class="auth-savings-event')===5,'Every activity appears in order');hr_check(str_contains($html,'Rp 0')&&str_contains($html,'Tidak tercatat')&&!str_contains($html,'<script>'),'Zero, empty and escaped audit values');hr_check(!str_contains($html,'<form'),'Read-only chronology has no financial mutation forms');
echo "PASS $checks history membership, scope, archive, school cash and audit presentation checks.\n";
