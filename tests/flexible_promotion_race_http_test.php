<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_/',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/kelas.php';
$_SESSION['active_unit_id']=1;unit_set_context($koneksi,1);$root=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');$second=rtrim((string)getenv('SPP_TEST_SECOND_BASE_URL'),'/');
function pr_http(string $url,?array $data,array &$cookies):array{
 $headers=['X-SPP-Test-Current-Year: 2095/2096'];if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));if($data!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
 $body=file_get_contents($url,false,stream_context_create(['http'=>['method'=>$data===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$data===null?'':http_build_query($data),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]));
 $status=0;foreach($http_response_header as $h){if(preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m))$status=(int)$m[1];if(preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i',$h,$m))$cookies[$m[1]]=$m[2];}return [$status,$body];
}
$nis='00'.(string)random_int(86000000,86999999);$year=class_ensure_academic_year($koneksi,'2095/2096');
$from=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=5 AND kode_rombel='A'")->fetch_row()[0];$to=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=6 AND kode_rombel='A'")->fetch_row()[0];
$koneksi->query("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES('$nis','TEST CONCURRENT PROMOTION','5',$from,1)");
$id=(int)$koneksi->insert_id;$koneksi->query("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES($year,'$nis','5',$from,'5A','aktif')");
$placement=(int)$koneksi->insert_id;$password=trim(file_get_contents(getenv('SPP_TEST_ADMIN_PASSWORD_FILE')));$requests=[];
foreach([[$root,'kasir1'],[$second,'kasir2']] as [$base,$username]){
 $q=$koneksi->prepare("SELECT id FROM admin WHERE username=? AND role='kasir' AND is_active=1 AND unit_id=1");$q->bind_param('s',$username);$q->execute();$actor=(int)$q->get_result()->fetch_row()[0];$q->close();if(!$actor)throw new RuntimeException('Cashier missing');
 $hash=password_hash($password,PASSWORD_DEFAULT);$q=$koneksi->prepare('UPDATE admin SET password=? WHERE id=?');$q->bind_param('si',$hash,$actor);$q->execute();$q->close();
 $cookies=[];if(pr_http($base.'/login.php',['username'=>$username,'password'=>$password],$cookies)[0]!==302)throw new RuntimeException('Login failed');
 [$status,$page]=pr_http($base.'/master_kelas.php?source_year_id='.$year.'&source_level=5',null,$cookies);
 if($status!==200||!preg_match('/<form[^>]+id="promotion-batch-form"[^>]*>(.*?)<\/form>/s',$page,$form)||!preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$form[1],$token))throw new RuntimeException('Context form unavailable');
 $requests[]=[$base.'/master_kelas.php',['aksi'=>'proses_siswa_batch','csrf_token'=>$token[1],'source_year_id'=>$year,'source_level'=>5,'target_tahun_ajaran'=>'2096/2097','selected_students'=>[$nis],'source_placement_id'=>[$nis=>$placement],'target_master_kelas_id'=>[$nis=>$to]],$cookies];
}
$multi=curl_multi_init();$handles=[];foreach($requests as [$url,$data,$cookies]){$h=curl_init($url);$pairs=[];foreach($cookies as $k=>$v)$pairs[]=$k.'='.$v;curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['X-SPP-Test-Current-Year: 2095/2096','Cookie: '.implode('; ',$pairs)]]);curl_multi_add_handle($multi,$h);$handles[]=$h;}
do{$status=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.1);}while($running&&$status===CURLM_OK);
foreach($handles as $h){if(curl_getinfo($h,CURLINFO_RESPONSE_CODE)!==302)throw new RuntimeException('Concurrent response failed');curl_multi_remove_handle($multi,$h);curl_close($h);}curl_multi_close($multi);
if((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='$nis'")->fetch_row()[0]!==2)throw new RuntimeException('Duplicate destination');
if((int)$koneksi->query("SELECT COUNT(*) FROM siswa_audit_log WHERE siswa_id=$id AND aksi='naik_kelas'")->fetch_row()[0]!==1)throw new RuntimeException('Duplicate success audit');
if($koneksi->query("SELECT status FROM siswa_tahun_ajaran WHERE id=$placement")->fetch_row()[0]!=='pindah')throw new RuntimeException('Source not completed');
echo "PASS: two cashier sessions/two servers/same source; one destination and one success audit\n";

