<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);
$f=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/alignment-fixture.json'),true,512,JSON_THROW_ON_ERROR);unit_set_context($koneksi,1);$id=(int)$f['history_students'][1]['numeric'];$nis=$f['history_nis'];
$row=$koneksi->query('SELECT MASUK,user_id FROM transaksi_m WHERE id='.$id)->fetch_assoc();$q=$koneksi->prepare('SELECT SALDO FROM tabungan WHERE NO_INDUK=?');$q->bind_param('s',$nis);$q->execute();$balance=$q->get_result()->fetch_row()[0];$q->close();
if($row)$row['MASUK']=(float)$row['MASUK'];
$request=$koneksi->query("SELECT id,status FROM tabungan_otorisasi WHERE jenis='masuk' AND transaction_id=$id ORDER BY id DESC LIMIT 1")->fetch_assoc();echo json_encode(['row'=>$row,'balance'=>(float)$balance,'request'=>$request],JSON_THROW_ON_ERROR);
