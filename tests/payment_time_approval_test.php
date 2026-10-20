<?php
/** Committed approval/replay integration; the dedicated database is restored afterwards. */
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58360'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')exit(1);
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/legacy_transactions.php';
$fixture=json_decode(file_get_contents(getenv('SPP_TIME_FIXTURE')),true,512,JSON_THROW_ON_ERROR);$checks=[];
$assert=static function(bool $ok,string $name)use(&$checks){if(!$ok)throw new RuntimeException($name);$checks[]=$name;echo "PASS $name\n";};
foreach([1,2,3] as $unit){
 unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;
 lf_query($koneksi,"INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,?,'admin',?,1)",['clock_qa_'.bin2hex(random_bytes(8)),password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'QA waktu pada salinan',$unit]);$actor=(int)$koneksi->insert_id;
 $row=null;foreach($fixture['resolved'] as $candidate)if((int)$candidate['unit_id']===$unit&&$candidate['id']!==$fixture['units'][$unit]['known']['id']){$row=$candidate;break;}
 if(!$row)throw new RuntimeException('Independent approval fixture missing');$id=-(int)$row['id']-1000000000;$before=lop_record($koneksi,'payment',$id);$key=bin2hex(random_bytes(32));
 $input=['date'=>substr($row['TGL_BYR'],0,10).' 00:00:00','time_precision'=>'datetime','items'=>$before['items'],'reason'=>'Menguji persetujuan koreksi waktu asli','note'=>$before['note']];
 $_SESSION['admin_id']=$actor;$_SESSION['admin_role']='admin';$pending=lt_submit($koneksi,$actor,$unit,$id,(int)$before['version'],$input,$key);
 $assert(isset($pending['request_id'])&&lop_record($koneksi,'payment',$id)===$before,"Unit $unit pending time proposal changes no payment");
 $assert(lt_model($koneksi,$id)['payment']['updated_at']===null,"Unit $unit pending proposal is not an applied update timestamp");
 $retry=lt_submit($koneksi,$actor,$unit,$id,(int)$before['version'],$input,$key);$assert($retry['replayed']&&$retry['request_id']===$pending['request_id'],"Unit $unit pending resubmission creates one request");
 try{lt_approve($koneksi,$pending['request_id'],$actor,'Uji penolakan hak akses');throw new LogicException('Admin could approve');}catch(RuntimeException $e){$assert(!($e instanceof LogicException),"Unit $unit requester cannot approve own time proposal");}
 $_SESSION['admin_id']=1;$_SESSION['admin_role']='super_admin';lt_approve($koneksi,$pending['request_id'],1,'Koreksi waktu diperiksa pada salinan');
 $shown=lt_model($koneksi,$id)['payment'];$assert($shown['TGL_BYR']===$input['date']&&$shown['time_precision']==='datetime'&&$shown['updated_at']!==null,"Unit $unit approved explicit midnight takes precedence");
 $version=lop_record($koneksi,'payment',$id)['version'];lt_approve($koneksi,$pending['request_id'],1,'Pengiriman ulang pada salinan');
 $assert(lop_record($koneksi,'payment',$id)['version']===$version,"Unit $unit approval replay has one applied correction");
}
if($file=getenv('SPP_TIME_APPROVAL_RESULT'))file_put_contents($file,json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
