<?php
/** Compare displayed identities and amounts with independent source queries on a clone. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_/D', (string)getenv('SPP_DB_NAME'))) exit(1);
ob_start();
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/http_form_scope.php';
$base=rtrim(getenv('SPP_HTTP_BASE'),'/');spp_test_assert_http_clone($base,DB_NAME);
$sid='dropdownaudit'.bin2hex(random_bytes(10));
$admin=$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
function dropdown_get(string $path,array $query=[],int $status=200): DOMXPath {
    global $base,$sid;
    $html=file_get_contents($base.'/'.$path.'?'.http_build_query($query),false,stream_context_create(['http'=>[
        'header'=>'Cookie: PHPSESSID='.$sid,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>60]]));
    if (!str_contains($http_response_header[0]??'', ' '.$status.' ')) throw new RuntimeException('HTTP '.$path.' '.$http_response_header[0]);
    if (preg_match('/Fatal error|Warning:|Array to string|Unknown column/',$html)) throw new RuntimeException('Runtime error '.$path);
    $doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($doc);
}
function dropdown_same(array $actual,array $expected,string $label):void {
    sort($actual);sort($expected);
    if ($actual!==$expected || count($actual)!==count(array_unique($actual))) throw new RuntimeException($label.' identity mismatch actual='.count($actual).' expected='.count($expected));
}
function dropdown_pages(string $path,array $query,string $attribute,array $expected,int $size,string $pageKey='page'):array {
    $ids=[];$rowNodes=[];
    for($page=1;$page<=max(1,(int)ceil(count($expected)/$size));$page++) {
        $xpath=dropdown_get($path,$query+[$pageKey=>$page]);
        foreach($xpath->query('//tr[@'.$attribute.']') as $node){$ids[]=(int)$node->getAttribute($attribute);$rowNodes[]=$node;}
    }
    dropdown_same($ids,$expected,$path);return $rowNodes;
}
function dropdown_money(DOMNode $row,string $label):float {
    foreach($row->childNodes as $cell)if($cell instanceof DOMElement&&$cell->getAttribute('data-label')===$label)
        return (float)str_replace(['Rp',' ','.'], '',trim($cell->textContent));
    throw new RuntimeException('Missing money column '.$label);
}
$fixtures=[];$legacyIds=[];$cases=0;unit_set_context($koneksi,0);$globalClasses=$koneksi->query('SELECT id,unit_id FROM master_kelas')->fetch_all(MYSQLI_ASSOC);
try {
    foreach([1,2,3,0] as $unit) {
        session_id($sid);session_start();$_SESSION=['admin_id'=>(int)$admin['id'],'admin_role'=>'super_admin','active_unit_id'=>$unit];session_write_close();
        unit_set_context($koneksi,$unit);
        $classes=$koneksi->query('SELECT id,tingkat FROM master_kelas ORDER BY tingkat,id')->fetch_all(MYSQLI_ASSOC);
        $students=$koneksi->query('SELECT id,unit_id,NO_INDUK,KELAS,master_kelas_id,is_active,legacy_pending FROM siswa')->fetch_all(MYSQLI_ASSOC);
        $legacyIds[$unit]=(int)(array_values(array_filter($students,fn($s)=>(int)$s['legacy_pending']===1))[0]['id']??0);
        $populated=array_values(array_unique(array_column($students,'master_kelas_id')));$chosen=array_slice(array_filter($populated,fn($id)=>(int)$id>0),0,2);
        foreach([['active','archived'],['active','legacy'],['*']] as $statuses) {
            $expected=[];foreach($students as $student){$state=$student['legacy_pending']?'legacy':($student['is_active']?'active':'archived');
                if(in_array($student['master_kelas_id'],$chosen)&&($statuses===['*']||in_array($state,$statuses,true)))$expected[]=(int)$student['id'];}
            dropdown_pages('siswa/daftar.php',['kelas'=>array_map('strval',$chosen),'status'=>$statuses,'per_page'=>50],'data-student-id',$expected,50);$cases++;
        }
        $bills=$koneksi->query("SELECT t.id,t.unit_id,t.no_induk,ta.label,t.nominal_tagihan,s.master_kelas_id,COALESCE(mk.tingkat,s.KELAS,t.kelas_snapshot) level,
            COALESCE((SELECT SUM(bd.jumlah) FROM bayar_du bd WHERE bd.tagihan_daftar_ulang_id=t.id),0) paid
            FROM tagihan_daftar_ulang t JOIN tahun_ajaran ta ON ta.id=t.tahun_ajaran_id
            JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE t.status='open'")->fetch_all(MYSQLI_ASSOC);
        $years=array_slice(array_values(array_unique(array_column($bills,'label'))),0,2);
        $levels=array_slice(array_values(array_unique(array_filter(array_column($bills,'level'),fn($v)=>(int)$v>0))),0,2);
        $overlap=array_values(array_filter($bills,fn($b)=>in_array($b['level'],$levels)))[0]??null;
        $selectedClasses=array_map(fn($v)=>'tingkat:'.$v,$levels);if($overlap)$selectedClasses[]='rombel:'.$overlap['master_kelas_id'];
        if($years&&$selectedClasses)foreach([['lunas','cicilan'],['lunas'],['cicilan']] as $statuses){
            $expected=[];foreach($bills as $bill){$state=(float)$bill['nominal_tagihan']-(float)$bill['paid']<=.001?'lunas':'cicilan';
                if(in_array($bill['label'],$years,true)&&(in_array($bill['level'],$levels)||($overlap&&$bill['master_kelas_id']===$overlap['master_kelas_id']))&&in_array($state,$statuses,true))$expected[(int)$bill['id']]=$bill;}
            $rows=dropdown_pages('pembayaran/riwayat_daftar_ulang.php',['kelas'=>$selectedClasses,'tahun_ajaran'=>$years,'status'=>$statuses,'per_page'=>25],'data-bill-id',array_keys($expected),25);
            foreach($rows as $row){$bill=$expected[(int)$row->getAttribute('data-bill-id')];foreach(['Tagihan'=>(float)$bill['nominal_tagihan'],'Terbayar'=>(float)$bill['paid'],'Sisa'=>max(0,(float)$bill['nominal_tagihan']-(float)$bill['paid'])] as $label=>$value)
                if(abs(dropdown_money($row,$label)-$value)>.001)throw new RuntimeException('DU amount '.$label);
                if(!str_contains($row->textContent,$bill['label']))throw new RuntimeException('Missing DU period');}$cases++;
        }
        if($unit){$all=$koneksi->query('SELECT id,tingkat,is_active,is_placeholder FROM master_kelas')->fetch_all(MYSQLI_ASSOC);
            $selectedLevels=array_slice(array_values(array_unique(array_column($all,'tingkat'))),0,3);
            foreach([['aktif','nonaktif'],['aktif','placeholder'],['*']] as $statuses){$expected=[];
                foreach($all as $class){$matches=$statuses===['*']||(in_array('aktif',$statuses,true)&&$class['is_active']&&!$class['is_placeholder'])||(in_array('nonaktif',$statuses,true)&&!$class['is_active'])||(in_array('placeholder',$statuses,true)&&$class['is_placeholder']);
                    if(in_array($class['tingkat'],$selectedLevels)&&$matches)$expected[]=(int)$class['id'];}
                dropdown_pages('master_kelas.php',['tingkat_kelas'=>array_map(fn($v)=>(int)$v===0?'psb':(string)$v,$selectedLevels),'status_kelas'=>$statuses,'class_per_page'=>10],'data-class-id',$expected,10,'class_page');$cases++;}
        }
        $savings=$koneksi->query('SELECT s.id,s.unit_id,s.KELAS,COALESCE(t.SALDO,0) saldo FROM siswa s LEFT JOIN tabungan t ON t.NO_INDUK=s.NO_INDUK AND t.unit_id=s.unit_id WHERE s.is_active=1')->fetch_all(MYSQLI_ASSOC);
        $xpath=dropdown_get('tabungan/riwayat.php');$actual=[];
        foreach($xpath->query('//tr[@data-student-id]') as $row)$actual[]=(int)$row->getAttribute('data-student-id');
        dropdown_same($actual,array_map('intval',array_column($savings,'id')),'Savings source');
        $fixtures[$unit]=$savings;$cases++;
        if($unit){$foreign=array_values(array_filter($globalClasses,fn($c)=>(int)$c['unit_id']!==$unit))[0]??null;if($foreign){dropdown_get('siswa/daftar.php',['kelas'=>[(string)$foreign['id']]],400);dropdown_get('pembayaran/riwayat_daftar_ulang.php',['kelas'=>['rombel:'.$foreign['id']]],400);}}
        foreach([['kelas'=>['not-a-class']],['status'=>['not-a-status']]] as $invalid)dropdown_get('siswa/daftar.php',$invalid,400);
        echo 'PASS unit '.$unit.' identities across pagination, OR/AND, overlap, DU periods/amounts and savings source'.PHP_EOL;
    }
    if(getenv('SPP_XLSX_TEST_OUTPUT')){file_put_contents(rtrim(getenv('SPP_XLSX_TEST_OUTPUT'),'/\\').'/dropdown-legacy.json',json_encode($legacyIds,JSON_THROW_ON_ERROR));file_put_contents(rtrim(getenv('SPP_XLSX_TEST_OUTPUT'),'/\\').'/dropdown-source.json',json_encode($fixtures,JSON_THROW_ON_ERROR));}
    echo 'PASS '.$cases.' source result cases'.PHP_EOL;ob_end_flush();
} catch(Throwable $error){ob_end_flush();fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
