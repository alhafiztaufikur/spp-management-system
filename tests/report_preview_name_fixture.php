<?php
if(PHP_SAPI!=='cli')exit(1);require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);
$root=getenv('SPP_QA_DIR');if(!$root||!is_dir($root))exit(1);$f=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/rombel-fixture.json'),true);$id=(int)$f['units'][1]['student_id'];$path=$root.'/preview-name-before.json';
if(($argv[1]??'')==='apply'){
    $s=$koneksi->prepare('SELECT NAMA FROM siswa_data WHERE id=? AND unit_id=1');$s->bind_param('i',$id);$s->execute();$before=$s->get_result()->fetch_row()[0];$s->close();file_put_contents($path,json_encode(['id'=>$id,'name'=>$before]));$name='QA Fresh Preview '.bin2hex(random_bytes(3));
}elseif(($argv[1]??'')==='restore'){$before=json_decode(file_get_contents($path),true);if($before['id']!==$id)throw new RuntimeException('Fixture mismatch');$name=$before['name'];}
else exit(1);
$s=$koneksi->prepare('UPDATE siswa_data SET NAMA=? WHERE id=? AND unit_id=1');$s->bind_param('si',$name,$id);$s->execute();$s->close();echo json_encode(['name'=>$name]);
