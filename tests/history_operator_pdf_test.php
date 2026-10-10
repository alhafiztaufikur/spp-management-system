<?php
/** Integration fixtures and HTTP checks only on an explicitly authorized disposable clone. */
if (PHP_SAPI!=='cli' || getenv('SPP_TEST_ALLOW_MUTATION')!=='1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))) exit(1);
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool { throw new ErrorException($message,0,$severity,$file,$line); });
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/registration_history.php';
require_once __DIR__.'/../includes/payment_archive.php';
require_once __DIR__.'/../includes/authorization_export_selection.php';
$base=(string)getenv('SPP_HTTP_BASE');$dir=(string)getenv('SPP_QA_DIR');
if (!in_array(parse_url($base,PHP_URL_HOST),['localhost','127.0.0.1'],true) || !is_dir($dir)) throw new RuntimeException('Local server and QA directory required.');
function hop_assert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function hop_http(string $cookie,string $path,?array $post=null): array {
    global $base;
    $options=['method'=>$post===null?'GET':'POST','ignore_errors'=>true,'follow_location'=>0,'header'=>'Cookie: PHPSESSID='.$cookie."\r\n"];
    if($post!==null){$options['header'].="Content-Type: application/x-www-form-urlencoded\r\n";$options['content']=http_build_query($post);}
    $body=file_get_contents($base.$path,false,stream_context_create(['http'=>$options]));$headers=$http_response_header??[];
    $status=preg_match('/\s(\d{3})\s/',$headers[0]??'',$m)?(int)$m[1]:0;$location='';
    foreach($headers as $header)if(str_starts_with(strtolower($header),'location:'))$location=trim(substr($header,9));
    return [$status,$body,$location];
}
function hop_cookie(int $id,string $role,int $unit): string {
    session_id('operatorpdf'.bin2hex(random_bytes(12)));session_start();
    $_SESSION=['admin_id'=>$id,'admin_role'=>$role,'active_unit_id'=>$unit,'csrf_transaction_authorization'=>'operator-pdf-csrf','csrf_payment'=>'operator-payment-csrf'];
    $cookie=session_id();session_write_close();return $cookie;
}
hop_assert((json_decode(hop_http('','/tests/browser_clone_identity.php')[1],true)['database']??null)===DB_NAME,'Server must use the disposable clone.');
unit_set_context($koneksi,1);
$super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$actorA=(int)$koneksi->query("SELECT id FROM admin WHERE role='kasir' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_row()[0];
$bill=$koneksi->query("SELECT t.*,s.id student_id,s.NAMA,s.NO_induk_diknas,s.KELAS student_class FROM tagihan_daftar_ulang t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id WHERE t.status='open' AND t.nominal_tagihan>100000 AND NOT EXISTS(SELECT 1 FROM pembayaran_aktivitas a WHERE a.note LIKE 'Operator QA PDF %' AND JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.payment.NO_INDUK'))=s.NO_INDUK) ORDER BY t.id LIMIT 1")->fetch_assoc();
hop_assert((bool)$bill,'Fixture bill required.');
$_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>1];
$before=registration_detail($koneksi,(int)$bill['id'],1,'active');$ids=[];$operatorTotal=0;
$koneksi->begin_transaction();
try {
    $name='Operator QA Tidak Aktif';$username='operatorqa'.bin2hex(random_bytes(5));$password=password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT);
    $stmt=$koneksi->prepare("INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,?,'kasir',1,0)");$stmt->bind_param('sss',$username,$password,$name);$stmt->execute();$actorB=(int)$koneksi->insert_id;$stmt->close();
    for($i=0;$i<32;$i++) {
        $owner=$i===1?$actorB:($i===2?null:$actorA);$amount=$i===0?300:($i===1?700:($i===2?500:100));$total=$amount+1000;
        $stmt=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,kelas_rombel_snapshot,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,U_PSB,U_LAIN,total_jumlah,payment_link_version) VALUES(?,?,?,'2026-10-10 09:00:00','10','2026',?,'Tunai',1000,?,?,1)");
        $legacy=(string)($owner??$actorA);$stmt->bind_param('ssssdd',$bill['no_induk'],$bill['student_class'],$bill['kelas_snapshot'],$legacy,$amount,$total);$stmt->execute();$id=(int)$koneksi->insert_id;$stmt->close();$ids[]=$id;
        $stmt=$koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,?,?)');$stmt->bind_param('iisssd',$id,$bill['id'],$bill['no_induk'],$bill['kelas_snapshot'],$bill['tahun_ajaran_snapshot'],$amount);$stmt->execute();$stmt->close();
        $snapshot=transaction_authorization_snapshot($koneksi,$id)['data'];
        payment_activity_record($koneksi,$id,$owner?'created':'baseline',$owner,null,$snapshot,'operator-pdf-create:'.$id,null,'', '2026-10-10 09:00:00');
        if($i===0)payment_activity_record($koneksi,$id,'created',$actorB,null,$snapshot,'operator-pdf-later-create:'.$id,null,'Later creation evidence','2026-10-10 09:30:00');
        payment_activity_record($koneksi,$id,'edited',$actorB,$snapshot,$snapshot,'operator-pdf-edit:'.$id,null,'Operator QA PDF '.$id,'2026-10-10 10:00:00');
        if($owner===$actorA)$operatorTotal+=$amount;
    }
    $archive=[];
    foreach([$actorA,$actorB,null] as $i=>$owner){
        $id=random_int(810000000,819999999);$amount=111*($i+1);
        $p=['id'=>$id,'unit_id'=>1,'NO_INDUK'=>$bill['no_induk'],'NAMA'=>$bill['NAMA'],'NO_induk_diknas'=>$bill['NO_induk_diknas'],'KELAS'=>$bill['kelas_snapshot'],'TGL_BYR'=>'2026-10-10 09:00:00','total_jumlah'=>$amount+1000,'U_LAIN'=>$amount,'payment_link_version'=>1];
        $snapshot=['payment'=>$p,'details'=>['bayar_du'=>[['tagihan_daftar_ulang_id'=>$bill['id'],'no_induk'=>$bill['no_induk'],'kelas'=>$bill['kelas_snapshot'],'th_ajaran'=>$bill['tahun_ajaran_snapshot'],'jumlah'=>$amount]]]];
        payment_activity_record($koneksi,$id,$owner?'created':'baseline',$owner,null,$snapshot,'operator-pdf-archive-create:'.$id,null,'','2026-10-10 09:00:00');
        payment_activity_record($koneksi,$id,'deleted',$actorB,$snapshot,null,'operator-pdf-delete:'.$id,null,'Operator QA PDF '.$id,'2026-10-10 11:00:00');$archive[]=$id;
    }
    $koneksi->commit();
}catch(Throwable $error){$koneksi->rollback();throw $error;}

$options=history_operator_options($koneksi);
hop_assert(isset($options[$actorB],$options[$actorA],$options[$super],$options['unknown']),'Inactive, active, Super Admin and unknown options.');
$query=['student_id'=>$bill['student_id'],'tahun_ajaran'=>[$bill['tahun_ajaran_snapshot']],'operator'=>[(string)$actorA],'per_page'=>100];
$f=registration_filters($koneksi,$query);$result=registration_page($koneksi,$f);
$after=registration_detail($koneksi,(int)$bill['id'],1,'active',[(string)$actorA]);
hop_assert(abs($after['paid']-($before['paid']+$operatorTotal+1200))<.001,'Full balance must include other operators.');
$ownPrior=0;
foreach($before['transactions'] as $transaction)if((int)($transaction['summary']['creator']['actor_id']??0)===$actorA)$ownPrior+=$transaction['jumlah'];
hop_assert(abs($after['operator_paid']-($ownPrior+$operatorTotal))<.001,'Operator amount uses DU component, not total transaction.');
foreach($after['transactions'] as $transaction)hop_assert((int)$transaction['summary']['creator']['actor_id']===$actorA,'Filtered detail excludes foreign creator despite last editor.');
hop_assert(abs($result['summary']['paid']-$after['paid'])<.001 && abs($result['summary']['operator_paid']-$after['operator_paid'])<.001,'Summary and detail reconcile.');
$both=registration_detail($koneksi,(int)$bill['id'],1,'active',[(string)$actorA,(string)$actorB]);
hop_assert(abs($both['operator_paid']-$after['operator_paid']-700)<.001,'Multiple operators use OR without duplicate amounts.');
$unknown=registration_detail($koneksi,(int)$bill['id'],1,'active',['unknown']);
hop_assert(count($unknown['transactions'])===1 && (int)$unknown['transactions'][0]['payment']['id']===$ids[2] && $unknown['operator_paid']==500.0,'Baseline does not invent a creator from mutable user_id.');
$deleted=registration_detail($koneksi,(int)$bill['id'],1,'deleted',[(string)$actorA]);
hop_assert($deleted['deleted_amount']===111.0 && count($deleted['transactions'])===1,'Deleted DU matches original creator, not deleter.');
$unknownArchive=registration_detail($koneksi,(int)$bill['id'],1,'deleted',['unknown']);
hop_assert($unknownArchive['deleted_amount']===333.0 && count($unknownArchive['transactions'])===1,'Unknown archived creator remains selectable.');
$arch=payment_archive_page($koneksi,'2026-10-10','2026-10-10',$bill['no_induk'],0,1,100,[(string)$actorA]);
hop_assert(in_array($archive[0],array_column($arch['rows'],'id'),true)&&!in_array($archive[1],array_column($arch['rows'],'id'),true),'Payment archive uses first creator.');

$cookies=[];
foreach([1,2,3,0] as $scope)$cookies['super'.$scope]=hop_cookie($super,'super_admin',$scope);
foreach(['admin','kasir','bendahara'] as $role){$id=(int)$koneksi->query("SELECT id FROM admin WHERE role='$role' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_row()[0];$cookies[$role]=hop_cookie($id,$role,1);}
$cookie=$cookies['super1'];
$paymentQuery=['student_id'=>$bill['student_id'],'search'=>$bill['no_induk'],'tanggal_awal'=>'2026-10-10','tanggal_akhir'=>'2026-10-10','operator'=>[(string)$actorA],'per_page'=>50];
[$status,$html]=hop_http($cookie,'/pembayaran/lihat.php?'.http_build_query($paymentQuery));
hop_assert($status===200 && str_contains($html,'name="operator[]"') && str_contains($html,'data-select-search="true"'),'Payment multiselect rendered.');
foreach([$ids[1],$ids[2]] as $foreign)hop_assert(!str_contains($html,'data-payment-record data-id="'.$foreign.'"'),'Foreign/unknown payment excluded.');
hop_assert(str_contains($html,'data-payment-record data-id="'.$ids[0].'"'),'Original creator retained after edit by another operator.');
[$status,$json]=hop_http($cookie,'/pembayaran/detail_daftar_ulang.php?'.http_build_query($query+['tagihan_id'=>$bill['id'],'unit_id'=>1]));
$ajax=json_decode($json,true,512,JSON_THROW_ON_ERROR);hop_assert($status===200 && $ajax['ok'],'Filtered AJAX detail.');
foreach([$ids[1],$ids[2]] as $foreign)hop_assert(!str_contains($ajax['html'],'data-du-transaction="'.$foreign.'"'),'AJAX details remain filtered.');
[$status,$paged]=hop_http($cookie,'/pembayaran/lihat.php?'.http_build_query(array_merge($paymentQuery,['per_page'=>10,'page'=>2])));
hop_assert($status===200 && str_contains($paged,'operator%5B0%5D='),'Pagination preserves operator.');
[$status,$unknownPayment]=hop_http($cookie,'/pembayaran/lihat.php?'.http_build_query(array_merge($paymentQuery,['operator'=>['unknown']])));
hop_assert($status===200 && str_contains($unknownPayment,'data-payment-record data-id="'.$ids[2].'"') && !str_contains($unknownPayment,'data-payment-record data-id="'.$ids[0].'"'),'Unknown payment filter.');
[$status,$allPayment]=hop_http($cookie,'/pembayaran/lihat.php?'.http_build_query(array_merge($paymentQuery,['operator'=>['*']])));
hop_assert($status===200 && str_contains($allPayment,'data-payment-record data-id="'.$ids[1].'"'),'All operators includes inactive accounts.');
foreach(['operator[]=999999999','operator[0][]=1','operator[]=*%26operator[]=1'] as $invalid){
    hop_assert(hop_http($cookie,'/pembayaran/lihat.php?'.$invalid)[0]===400,'Invalid payment operator rejected.');
}
hop_assert(hop_http($cookie,'/pembayaran/detail_daftar_ulang.php?tagihan_id='.$bill['id'].'&unit_id=1&operator[]=999999999')[0]===400,'Invalid AJAX operator returns JSON error.');

$scopeBefore=unit_active_id();$_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>1];
$return=payment_return_create($ids[0],1,$paymentQuery,'lihat.php');hop_assert(str_starts_with(payment_return_url($return,$ids[0]),'lihat.php?') && str_contains(payment_return_url($return,$ids[0]),'operator%5B0%5D='),'Payment return retains operator.');
$return=payment_return_create($ids[0],1,registration_filter_query($f));hop_assert(str_starts_with(payment_return_url($return,$ids[0]),'riwayat_daftar_ulang.php?') && str_contains(payment_return_url($return,$ids[0]),'operator%5B0%5D='),'DU return retains operator.');

$picked=[['unit_id'=>1,'payment_id'=>$ids[0]],['unit_id'=>1,'payment_id'=>$archive[0]]];
$post=['csrf_token'=>'operator-pdf-csrf','transactions'=>json_encode(array_merge($picked,[$picked[0]])),'q'=>$bill['no_induk'],'kind'=>['*'],'status'=>['*']];
[$status,,$location]=hop_http($cookie,'/otorisasi_export_pdf.php',$post);hop_assert($status===303,'Selected PDF redirects to immutable selection token.');
[$status,$preview]=hop_http($cookie,'/'.$location);hop_assert($status===200&&str_contains($preview,'2 transaksi terpilih'),'Preview includes only deduplicated choices.');
hop_assert(!str_contains($preview,'TRX-'.str_pad((string)$ids[1],6,'0',STR_PAD_LEFT)),'Unselected transaction absent from preview.');
[$status,$pdf]=hop_http($cookie,'/'.$location.'&output=pdf');hop_assert($status===200&&str_starts_with($pdf,'%PDF-'),'Selected PDF download.');
$tokenQuery=[];parse_str((string)parse_url($location,PHP_URL_QUERY),$tokenQuery);
hop_assert(hop_http($cookies['super2'],'/'.$location)[0]===400,'Token cannot cross unit.');
hop_assert(hop_http($cookies['super1'],'/otorisasi_export_pdf.php?selection_token=invalid')[0]===400,'Invalid token rejected.');
hop_assert(hop_http($cookie,'/otorisasi_export_pdf.php',array_merge($post,['transactions'=>'[]']))[0]===400,'Empty selection never becomes print all.');
hop_assert(hop_http($cookie,'/otorisasi_export_pdf.php',array_merge($post,['transactions'=>'oops']))[0]===400,'Malformed JSON rejected.');
hop_assert(hop_http($cookie,'/otorisasi_export_pdf.php',array_merge($post,['transactions'=>json_encode([['unit_id'=>2,'payment_id'=>$ids[0]]])]))[0]===400,'Forged unit/payment pair rejected.');
hop_assert(hop_http($cookie,'/otorisasi_export_pdf.php',array_merge($post,['q'=>'unmatched-search']))[0]===400,'Selection must belong to current filter.');
hop_assert(hop_http($cookie,'/otorisasi_export_pdf.php',array_merge($post,['csrf_token'=>'invalid']))[0]===403,'CSRF enforced.');
foreach(['admin','kasir','bendahara'] as $role){
    hop_assert(hop_http($cookies[$role],'/otorisasi_export_pdf.php')[0]===403 && hop_http($cookies[$role],'/otorisasi_export_pdf.php',$post)[0]===403,'Export denied for '.$role);
}
hop_assert(hop_http($cookies['super0'],'/otorisasi_export_pdf.php',$post)[0]===303,'All-unit selection POST is a permitted report read.');
session_id($cookie);session_start();$_SESSION['authorization_exports'][$tokenQuery['selection_token']]['expires']=time()-1;session_write_close();
hop_assert(hop_http($cookie,'/'.$location)[0]===400,'Expired token rejected.');
file_put_contents($dir.'/operator-fixture.json',json_encode(['database'=>DB_NAME,'cookies'=>$cookies,'actorA'=>$actorA,'actorB'=>$actorB,'bill'=>$bill,'ids'=>$ids,'archive'=>$archive,'operator_total'=>$after['operator_paid']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "OK: creator filters, mixed DU components/full balances, inactive accounts, archives, AJAX, return contexts, selected preview/PDF, token expiry, scoped selection and role/CSRF access.\n";
