<?php
/** Coverage and opening-debt proof on the owned migrated clone, always rolled back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58470)throw new RuntimeException('Owned report instance required.');
require_once __DIR__.'/../includes/reports.php';
$checks=[];function gv_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;echo 'PASS '.$label.PHP_EOL;}
function gv_sql(string $sql,array $params=[]):int{global $koneksi;lf_query($koneksi,$sql,$params);return (int)$koneksi->insert_id;}
function gv_row(string $nis):array{global $koneksi;return report_build($koneksi,'status',report_filters($koneksi,['template'=>'status','kategori'=>'spp','q'=>$nis,'tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','siswa_status'=>'all']))['rows'][0];}
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];
$koneksi->begin_transaction();
try{foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);
    $s=hp_one($koneksi,"SELECT f.*,s.NO_INDUK,s.master_kelas_id,s.KELAS FROM legacy_full_students f JOIN siswa s ON s.id=f.student_id AND s.unit_id=f.unit_id WHERE s.legacy_pending=0 AND EXISTS(SELECT 1 FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id WHERE o.student_id=s.id AND p.source_period='10 2026' AND p.items LIKE '%spp%' AND p.cancelled=0) LIMIT 1");
    if(!$s)throw new RuntimeException('Missing migrated coverage candidate.');$before=gv_row($s['NO_INDUK']);
    $koneksi->query('SAVEPOINT coverage_case');
    gv_sql("INSERT INTO legacy_full_coverage(unit_id,batch_id,student_id,category,period,disposition,evidence) VALUES(?,?,?,'spp','2026-10','settled','QA proof')",[$unit,$s['batch_id'],$s['student_id']]);
    $r=gv_row($s['NO_INDUK']);gv_check($r['tagihan']===null&&$r['sisa']===0.0&&$r['status']==='Lunas'&&$r['terbayar']===$before['terbayar'],"Unit $unit explicit settlement retains payments without inventing principal");
    $koneksi->query('ROLLBACK TO SAVEPOINT coverage_case');gv_sql("INSERT INTO legacy_full_coverage(unit_id,batch_id,student_id,category,period,disposition,evidence) VALUES(?,?,?,'spp','2026-10','not_applicable','QA proof')",[$unit,$s['batch_id'],$s['student_id']]);$r=gv_row($s['NO_INDUK']);gv_check($r['tagihan']===0.0&&$r['sisa']===0.0&&$r['terbayar']===$before['terbayar'],"Unit $unit explicit uncharged coverage keeps any proven receipt");
    $koneksi->query('ROLLBACK TO SAVEPOINT coverage_case');gv_sql("INSERT INTO legacy_full_coverage(unit_id,batch_id,student_id,category,period,disposition,evidence) VALUES(?,?,?,'spp','2026-10','legacy_debt','QA proof')",[$unit,$s['batch_id'],$s['student_id']]);$r=gv_row($s['NO_INDUK']);gv_check($r['tagihan']===null&&$r['sisa']===null,"Unit $unit missing opening-debt proof remains unknown");
    $master=gv_sql("INSERT INTO master_biaya_lain(nama,nominal,is_active) VALUES('QA opening debt',1000.25,0)");$key=hash('sha256','global-coverage-'.$unit);
    gv_sql("INSERT INTO legacy_debt_masters(unit_id,id,component,period,obligation_key) VALUES(?,?,'SPP','2026-10',?)",[$unit,$master,$key]);
    // Same narrowly scoped insertion context used by lf_create_debt; fixtures only.
    $koneksi->query('SET @legacy_finance_apply=1');try{$bill=gv_sql("INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,status,created_by) VALUES(?,?,'QA opening debt',1000.25,'open',?)",[$master,$s['NO_INDUK'],$actor]);}finally{$koneksi->query('SET @legacy_finance_apply=0');}
    gv_sql("INSERT INTO legacy_debts(unit_id,student_id,review_id,bill_id,master_id,obligation_key,amount,effective_date,evidence,source_row_key,applied_by) VALUES(?,?,?,?,?,?,1000.25,'2026-10-01','QA proof',?,?)",[$unit,$s['student_id'],$s['review_id'],$bill,$master,$key,$key,$actor]);
    $payment=gv_sql("INSERT INTO bayar(NO_INDUK,KELAS,U_LAIN,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran) VALUES(?,?,100.13,100.13,'2026-10-21','10','2026',?,'Tunai')",[$s['NO_INDUK'],$s['KELAS'],(string)$actor]);
    gv_sql("INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot) VALUES(?,?,?,'QA opening debt',100.13)",[$payment,$master,$bill]);
    $r=gv_row($s['NO_INDUK']);gv_check($r['tagihan']===null&&abs($r['sisa']-900.12)<.001&&abs($r['terbayar']-$before['terbayar']-100.13)<.001,"Unit $unit native opening repayment and old receipts count once with proven remainder");
    gv_sql("UPDATE tagihan_biaya_lain SET status='cancelled',cancel_reason='QA cancellation' WHERE id=?",[$bill]);$r=gv_row($s['NO_INDUK']);gv_check($r['status']==='Dibatalkan'&&$r['sisa']===0.0,"Unit $unit cancelled opening debt has no outstanding remainder");
}}finally{$koneksi->rollback();}
file_put_contents(getenv('SPP_GLOBAL_QA_DIR').'/coverage-tests.json',json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT));echo 'OK: '.count($checks)." coverage checks, all writes rolled back\n";
