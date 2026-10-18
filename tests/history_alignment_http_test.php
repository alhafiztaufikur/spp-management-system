<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/savings_authorization.php';require_once __DIR__.'/../includes/savings_operator.php';require_once __DIR__.'/../includes/reports.php';
$root=getenv('SPP_SAVINGS_QA');$f=json_decode(file_get_contents($root.'/fixture.json'),true,512,JSON_THROW_ON_ERROR);$checks=0;
function ha_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function ha_http(string $sid,string $url,?array $post=null):array{$c=curl_init(getenv('SPP_TEST_BASE_URL').'/'.$url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIE=>'PHPSESSID='.$sid,CURLOPT_TIMEOUT=>45]);if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,$body];}
function ha_rows(mysqli $db,string $nis):array{$q=$db->prepare("SELECT id,unit_id,NO_INDUK,TANGGAL,MASUK nominal,KELUAR keluar,user_id,'masuk' jenis FROM transaksi_m WHERE NO_INDUK=? UNION ALL SELECT id,unit_id,NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,'keluar' FROM transaksi_k WHERE NO_INDUK=?");$q->bind_param('ss',$nis,$nis);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();return savings_enrich_rows($db,$rows);}
function ha_add(mysqli $db,string $nis,int $amount,string $owner,string $kind='masuk',?int $id=null):int{$table=$kind==='masuk'?'transaksi_m':'transaksi_k';$in=$kind==='masuk'?$amount:0;$out=$kind==='keluar'?$amount:0;$q=$db->prepare("INSERT INTO $table(id,NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES(?,?,'2026-10-10 09:00:00',?,?,?,'Uji operator riwayat')");$q->bind_param('isiis',$id,$nis,$in,$out,$owner);$q->execute();$new=(int)$db->insert_id;$q->close();return $id??$new;}
function ha_proof(mysqli $db,int $id,int $actor):void{$key=bin2hex(random_bytes(16));$unit=unit_active_id();$q=$db->prepare("INSERT INTO keuangan_request(request_key,unit_id,aksi,operator_id,referensi_id) VALUES(?,?,'tabungan_masuk',?,?)");$q->bind_param('siii',$key,$unit,$actor,$id);$q->execute();$q->close();}
$nis='HA'.bin2hex(random_bytes(3));$f['history_nis']=$nis;$f['history_students']=[];
unit_set_context($koneksi,0);$username='qa.history.inactive.'.bin2hex(random_bytes(4));$koneksi->query("INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES('$username','!disabled!','Operator Nonaktif QA','kasir',1,0)");$inactive=(int)$koneksi->insert_id;
foreach([1,2,3] as $unit){
 $_SESSION=['admin_id'=>$f['actors']['super_admin']['id'],'admin_role'=>'super_admin','active_unit_id'=>$unit];
 unit_set_context($koneksi,$unit);$class=$koneksi->query('SELECT id,tingkat FROM master_kelas WHERE is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 1')->fetch_assoc();$name='Siswa Riwayat '.$unit;$level=(string)$class['tingkat'];$cid=(int)$class['id'];$q=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id) VALUES(?,?,?,?)');$q->bind_param('sssi',$nis,$name,$level,$cid);$q->execute();$studentId=(int)$koneksi->insert_id;$q->close();
 $kasir=$f['actors']['kasir']['id'];$admin=$f['actors']['admin']['id'];
 $numeric=ha_add($koneksi,$nis,100,(string)$kasir);$text=ha_add($koneksi,$nis,200,'Nama sama');$proof=ha_add($koneksi,$nis,300,'Nama lama');ha_proof($koneksi,$proof,$admin);
 $conflict=ha_add($koneksi,$nis,400,(string)$kasir);ha_proof($koneksi,$conflict,$admin);ha_proof($koneksi,$conflict,$kasir);$inactiveTx=ha_add($koneksi,$nis,500,(string)$inactive);
 $sameId=max((int)$koneksi->query('SELECT MAX(id) FROM transaksi_m')->fetch_row()[0],(int)$koneksi->query('SELECT MAX(id) FROM transaksi_k')->fetch_row()[0])+10;
 ha_add($koneksi,$nis,1000,(string)$admin,'masuk',$sameId);ha_add($koneksi,$nis,100,(string)$kasir,'keluar',$sameId);
 $koneksi->query("INSERT INTO tabungan(NO_INDUK,SALDO) VALUES('$nis',2400)");
 $_SESSION=['admin_id'=>$f['actors']['super_admin']['id'],'admin_role'=>'super_admin','active_unit_id'=>$unit];
 savings_correct($koneksi,'direct_hapus','masuk',$text,['reason'=>'Arsip uji operator tidak tercatat.'],$f['actors']['super_admin']['id']);
 $rows=ha_rows($koneksi,$nis);$owners=array_column($rows,'owner_id','id');$byKey=[];foreach($rows as $r)$byKey[$r['jenis'].'|'.$r['id']]=$r['owner_id'];
 ha_check($byKey['masuk|'.$proof]===$admin&&$byKey['masuk|'.$conflict]===0&&$byKey['masuk|'.$sameId]===$admin&&$byKey['keluar|'.$sameId]===$kasir,'Evidence, conflicting proof and equal journal IDs');
 $options=savings_operator_options($koneksi);ha_check(isset($options[(string)$inactive],$options['unknown']),'Inactive and unknown options');
 $session=$f['sessions']['super_admin'];ha_http($session['id'],'unit_switch.php',['unit_id'=>$unit,'csrf_token'=>$session['csrf'],'next'=>'/tabungan/riwayat.php']);
 foreach([[(string)$kasir],[(string)$admin],['unknown'],[(string)$inactive],[(string)$kasir,(string)$admin],['*']] as $operators){
  $expected=array_values(array_filter($rows,static fn($r)=>$operators===['*']||in_array($r['owner_id']===0?'unknown':(string)$r['owner_id'],$operators,true)));
  $params=['nis'=>$nis,'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-31','operator'=>$operators];[$code,$html]=ha_http($session['id'],'tabungan/riwayat.php?'.http_build_query($params));
  ha_check($code===200&&!str_contains($html,'Fatal error')&&substr_count($html,'class="sw-transaction ')===count($expected),'Filtered history count');
  foreach($expected as $r)ha_check(str_contains($html,'data-kind="'.$r['jenis'].'" data-id="'.$r['id'].'"'),'Filtered history identity');
  $reportParams=['template'=>'tabungan-siswa','tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-31','q'=>$nis,'operator'=>$operators,'siswa_status'=>'all'];$filters=report_filters($koneksi,$reportParams);$report=report_build($koneksi,'tabungan-siswa',$filters);
  ha_check(count($report['rows'])===count($expected),'Report uses identical ownership filter');
  foreach(['nominal'=>'masuk','keluar'=>'keluar'] as $source=>$target)ha_check((float)array_sum(array_column($report['rows'],$target))===(float)array_sum(array_column($expected,$source)),'Filtered money totals');
  if($operators===[(string)$inactive]){$reportParams['operator']=(string)$inactive;$report=report_build($koneksi,'tabungan-siswa',report_filters($koneksi,$reportParams));ha_check(count($report['rows'])===1,'Scalar inactive operator');}
 }
 [$code,$archive]=ha_http($session['id'],'tabungan/riwayat.php?'.http_build_query(['view'=>'archive','nis'=>$nis,'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-31','operator'=>['unknown']]));ha_check($code===200&&str_contains($archive,'data-id="'.$text.'"')&&str_contains($archive,'Total Masuk (Arsip)'),'Archive filtered before summary and pagination');
 foreach(['super_admin','admin','kasir','bendahara'] as $role){
  if($role==='bendahara'&&$unit!==1)continue;$ses=$f['sessions'][$role];if($role!=='bendahara')ha_http($ses['id'],'unit_switch.php',['unit_id'=>$unit,'csrf_token'=>$ses['csrf'],'next'=>'/tabungan/riwayat.php']);
  [$code,$body]=ha_http($ses['id'],'tabungan/aktivitas.php?jenis=masuk&id='.$numeric);$data=json_decode($body,true);ha_check($code===200&&$data['ok']&&!str_contains($data['html'],'<form')&&!str_contains($data['html'],'approve'),'Read-only activity for '.$role);
  [$code,$body]=ha_http($ses['id'],'tabungan/detail.php?jenis=masuk&id='.$numeric.'&operator[]='.$kasir);$data=json_decode($body,true);ha_check($code===200&&str_contains($data['html'],'data-savings-activity'),'Role detail has activity action');
  ha_check(str_contains($data['html'],'data-savings-open="edit"')===($role!=='bendahara'),'Role mutation controls');
  if($role==='admin'||$role==='kasir')ha_check(str_contains($data['html'],'value="request_edit"')&&!str_contains($data['html'],'value="direct_edit"'),'Proposal only');
 }
 $du=$koneksi->query("SELECT b.id,b.NO_INDUK,bd.tagihan_daftar_ulang_id bill_id,bd.th_ajaran FROM bayar b JOIN bayar_du bd ON bd.bayar_id=b.id JOIN tagihan_daftar_ulang t ON t.id=bd.tagihan_daftar_ulang_id WHERE t.status='open' LIMIT 1")->fetch_assoc();
 $f['history_students'][$unit]=compact('studentId','numeric','proof','conflict','inactiveTx','sameId','text','du');
}
$super=$f['sessions']['super_admin'];ha_http($super['id'],'unit_switch.php',['unit_id'=>0,'csrf_token'=>$super['csrf'],'next'=>'/dashboard.php']);[$code,$body]=ha_http($super['id'],'tabungan/detail.php?jenis=masuk&id='.$numeric);$data=json_decode($body,true);ha_check($code===200&&!str_contains($data['html'],'data-savings-open'),'All Units read-only');
foreach(['jenis=x&id=1','jenis=masuk&id[]=1'] as $bad){[$code]=ha_http($super['id'],'tabungan/aktivitas.php?'.$bad);ha_check($code===400,'Invalid activity identity');}
[$code]=ha_http($super['id'],'tabungan/aktivitas.php',['jenis'=>'masuk','id'=>$numeric]);ha_check($code===405,'Activity GET only');
$f['inactive_operator']=$inactive;file_put_contents($root.'/alignment-fixture.json',json_encode($f,JSON_THROW_ON_ERROR));echo "PASS $checks history/operator/role/report checks.\n";
