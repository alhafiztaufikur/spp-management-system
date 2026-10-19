<?php
if(PHP_SAPI!=='cli')exit(1);ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_finance.php';require_once __DIR__.'/legacy_finance_support.php';
$base=rtrim(getenv('SPP_TEST_BASE_URL'),'/');spp_test_assert_http_clone($base,DB_NAME);$actor=(int)lf_one($koneksi,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")['id'];
$f=lft_seed($koneksi,$actor,1);$before=lf_review_load($koneksi,$f['id']);$key=hash('sha256','additional review '.$f['id']);
lf_query($koneksi,"INSERT INTO legacy_finance_rows(unit_id,source_id,source_table,row_key,row_hash,occurrence,source_nis,amounts,raw_data,reference_only) VALUES(?,?,'tb_total',?,?,1,?,?,?,1)",[1,$f['source'],$key,$key,$f['nis'],lf_json(['SALDO'=>'999.00']),lf_json(['NO_INDUK'=>$f['nis'],'SALDO'=>'999.00'])]);$fp=lf_source_fingerprint($koneksi,1,$f['source']);lf_query($koneksi,'UPDATE legacy_finance_sources SET row_count=?,fingerprint=? WHERE id=?',[$fp['count'],$fp['fingerprint'],$f['source']]);
$after=lf_review_load($koneksi,$f['id']);lft_assert($before['savings_result']===$after['savings_result']&&count($after['additional_savings'])===1,'Reference ledger changed primary reconciliation');
unit_set_context($koneksi,2);lft_reject(fn()=>lf_source_fingerprint($koneksi,1,$f['source']),'Fingerprint crossed unit context');unit_set_context($koneksi,1);lft_assert(lf_source_fingerprint($koneksi,1,$f['source'])===$fp,'Indexed fingerprint changed row order/hash');
$sid='latestreview'.bin2hex(random_bytes(10));session_id($sid);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];session_write_close();
$html=file_get_contents($base.'/legacy_finance.php?tab=reviews&review='.$f['id'],false,stream_context_create(['http'=>['header'=>'Cookie: PHPSESSID='.$sid,'ignore_errors'=>true,'timeout'=>60]]));lft_assert(str_contains($http_response_header[0],' 200 ')&&str_contains($html,'Tabungan tambahan')&&str_contains($html,'Nominalnya tidak dijumlahkan ke saldo utama'),'Finance review omitted separate source ledger');
echo "PASS: additional archived ledger visible in finance review; original primary balance/journal unchanged.\n";
