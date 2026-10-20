<?php
session_start();if(empty($_SESSION['admin_id'])){header('Location: ../login.php');exit;}
$id=(int)($_GET['id']??0);if($id<=0){http_response_code(400);exit('Transaksi tidak valid.');}header('Location: cetak_struk.php?'.http_build_query(array_replace($_GET,['id'=>-$id])));exit;
