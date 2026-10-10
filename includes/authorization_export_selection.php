<?php

/** Unit and payment together identify an entry, including deleted transactions. */
function authorization_export_selection(string $json): array
{
    try { $rows=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new InvalidArgumentException('Pilihan transaksi tidak valid.'); }
    if (!is_array($rows) || !$rows || count($rows)>10000 || array_keys($rows)!==range(0,count($rows)-1)) {
        throw new InvalidArgumentException('Pilih minimal satu transaksi (maksimal 10.000).');
    }
    $keys=[];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['unit_id'],$row['payment_id'])
            || !is_int($row['unit_id']) || !in_array($row['unit_id'],[1,2,3],true)
            || !is_int($row['payment_id']) || $row['payment_id']<=0) {
            throw new InvalidArgumentException('Identitas transaksi terpilih tidak valid.');
        }
        $keys[$row['unit_id'].'|'.$row['payment_id']]=true;
    }
    return $keys;
}

function authorization_export_load_token($token, int $actor, int $scope): array
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/D',$token)) throw new InvalidArgumentException('Token cetak tidak valid.');
    $entry=$_SESSION['authorization_exports'][$token]??null;
    if (!$entry || $entry['expires']<time() || $entry['actor']!==$actor || $entry['scope']!==$scope) {
        throw new InvalidArgumentException('Pilihan cetak kedaluwarsa atau tidak tersedia pada akun/unit ini. Pilih kembali transaksi.');
    }
    return $entry;
}

function authorization_export_save_token(array $keys, array $query, int $actor, int $scope): string
{
    foreach ($_SESSION['authorization_exports']??[] as $token=>$entry) {
        if ($entry['expires']<time()) unset($_SESSION['authorization_exports'][$token]);
    }
    while (count($_SESSION['authorization_exports']??[])>=32) array_shift($_SESSION['authorization_exports']);
    $token=bin2hex(random_bytes(24));
    $_SESSION['authorization_exports'][$token]=['keys'=>$keys,'query'=>$query,'actor'=>$actor,'scope'=>$scope,'expires'=>time()+7200];
    return $token;
}
