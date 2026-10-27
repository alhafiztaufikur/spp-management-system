<?php
/** Independent source-cents oracle on a separately restored migrated database. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58470||!str_contains($koneksi->query('SELECT @@datadir')->fetch_row()[0],'global_audit_20261021'))throw new RuntimeException('Owned Global Report instance required.');
require_once __DIR__.'/../includes/reports.php';
$qa=(string)getenv('SPP_GLOBAL_QA_DIR');if(!$qa||!is_dir($qa))throw new RuntimeException('Private evidence directory required.');
$checks=[];$models=[];$source=['cash'=>[],'undated'=>[],'monthly'=>[],'categories'=>[],'bank'=>[]];
function gre_cents($v):int {$s=is_string($v)&&preg_match('/^-?\d+(?:\.\d{1,2})?$/D',$v)?$v:number_format((float)$v,2,'.','');$negative=str_starts_with($s,'-');$s=ltrim($s,'-');[$whole,$fraction]=array_pad(explode('.',$s),2,'0');$n=(int)$whole*100+(int)str_pad($fraction,2,'0');return $negative?-$n:$n;}
function gre_period(string $v):?array {
    if(preg_match('~^(\d{4})/(\d{4})$~D',$v,$m)&&(int)$m[2]===(int)$m[1]+1)return [$m[1].'-07-01',$m[2].'-06-30',$v];
    if(preg_match('~^(\d{1,2}) (\d{4})$~D',$v,$m)){$month=(int)$m[1];$year=(int)$m[2];}
    elseif(preg_match('~^(\d{4})-(\d{2})$~D',$v,$m)){$month=(int)$m[2];$year=(int)$m[1];}else return null;
    if($month<1||$month>12)return null;$start=sprintf('%04d-%02d-01',$year,$month);return [$start,date('Y-m-t',strtotime($start)),sprintf('%04d-%02d',$year,$month)];
}
function gre_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;echo 'PASS '.$label.PHP_EOL;}
function gre_sum(array $rows,string $key):int {$n=0;foreach($rows as $r)if(isset($r[$key]))$n+=gre_cents($r[$key]);return $n;}
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE is_active=1 AND role='super_admin' LIMIT 1")->fetch_row()[0];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try{
 foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$source['cash'][$unit]=0;$source['undated'][$unit]=0;$source['monthly'][$unit]=[];$source['categories'][$unit]=[];
    // Deliberately do not use the production evidence/parser/aggregation helpers here.
    $cursor=$koneksi->query("SELECT p.source_date,p.source_period,p.items,o.student_id,s.legacy_pending FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE p.cancelled=0 AND b.state='completed'",MYSQLI_USE_RESULT);
    while($p=$cursor->fetch_assoc()){
        $period=gre_period((string)$p['source_period']);$inDate=$p['source_date']!==null&&substr($p['source_date'],0,10)>='2026-10-01'&&substr($p['source_date'],0,10)<='2026-10-21';
        $undated=$p['source_date']===null&&$period&&$period[0]<='2026-10-21'&&$period[1]>='2026-10-01';
        foreach(json_decode($p['items'],true) as $item){$amount=gre_cents($item['amount']);$cat=$item['category'];if($inDate){$source['cash'][$unit]+=$amount;$source['categories'][$unit][$cat]=($source['categories'][$unit][$cat]??0)+$amount;}if($undated)$source['undated'][$unit]+=$amount;
            if($p['student_id']&&!$p['legacy_pending']&&$period&&preg_match('/^\d{4}-\d{2}$/D',$period[2]))$source['monthly'][$unit][$cat][$period[2]]=($source['monthly'][$unit][$cat][$period[2]]??0)+$amount;
        }
    }$cursor->free();
    $native=$koneksi->query("SELECT COALESCE(SUM(total_jumlah),0) n FROM bayar WHERE TGL_BYR>='2026-10-01' AND TGL_BYR<'2026-10-22'")->fetch_row()[0];$source['cash'][$unit]+=gre_cents($native);
    $bank=$koneksi->query("SELECT COALESCE(SUM(incoming),0) incoming,COALESCE(SUM(outgoing),0) outgoing FROM legacy_op_journals WHERE cancelled=0 AND origin<>'adjustment' AND source_date>='2026-10-01' AND source_date<'2026-10-22'")->fetch_assoc();
    $source['bank'][$unit]=['in'=>gre_cents($bank['incoming'])+gre_cents($koneksi->query("SELECT COALESCE(SUM(MASUK),0) FROM transaksi_m WHERE TANGGAL>='2026-10-01' AND TANGGAL<'2026-10-22'")->fetch_row()[0]),'out'=>gre_cents($bank['outgoing'])+gre_cents($koneksi->query("SELECT COALESCE(SUM(KELUAR),0) FROM transaksi_k WHERE TANGGAL>='2026-10-01' AND TANGGAL<'2026-10-22'")->fetch_row()[0])];
    foreach(global_report_templates() as $template){
        $f=report_filters($koneksi,['template'=>$template,'kategori'=>$template==='penerimaan'?'semua':'spp','tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-21','tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','bulan_akhir'=>'10','tahun_awal'=>'2026','tahun_akhir'=>'2026','siswa_status'=>'all']);
        $r=report_build($koneksi,$template,$f);$models[$unit][$template]=['rows'=>count($r['rows']),'totals'=>report_money_totals($r,$template)];
        gre_check(!str_contains($r['subtitle'],'histori Legacy tanpa tanggal'),"Unit $unit $template has a clean period subtitle");
        if($template==='penerimaan')gre_check(gre_sum($r['rows'],'total_penerimaan')===$source['cash'][$unit],"Unit $unit receipts equal independent dated source cents");
        if($template==='setoran')gre_check(gre_cents($r['total_setoran'])===$source['cash'][$unit],"Unit $unit settlement includes matching Legacy and native payments once");
        if($template==='per-item'||$template==='status')gre_check(gre_sum($r['rows'],$template==='status'?'terbayar':'total_bayar')===($source['monthly'][$unit]['spp']['2026-10']??0),"Unit $unit $template October SPP equals proven payment periods");
        if($template==='spp-tahunan'){$paid=0;foreach($source['monthly'][$unit]['spp']??[] as $period=>$n)if($period>='2026-07'&&$period<='2027-06')$paid+=$n;gre_check(gre_sum($r['rows'],'total_bayar')===$paid,"Unit $unit annual SPP includes advance and historical payments once");}
        if($template==='kas-tabungan')gre_check(gre_cents($r['total_masuk'])===$source['bank'][$unit]['in']&&gre_cents($r['total_keluar'])===$source['bank'][$unit]['out'],"Unit $unit savings cash matches dated journals and excludes adjustments");
        if($template==='tabungan-siswa')gre_check(gre_sum($r['rows'],'masuk')===$source['bank'][$unit]['in']&&gre_sum($r['rows'],'keluar')===$source['bank'][$unit]['out'],"Unit $unit savings transaction totals match the cash recap");
        if($template==='saldo-tabungan'){
            $positions=[];$expected=0;
            foreach($koneksi->query("SELECT o.id,o.student_id,s.NO_INDUK,a.balance FROM legacy_op_accounts a JOIN legacy_op_owners o ON o.id=a.owner_id AND o.unit_id=a.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE b.state='completed' AND o.state='ready'")->fetch_all(MYSQLI_ASSOC) as $p){$id=$p['student_id']?$p['NO_INDUK']:'rekening:'.$p['id'];$positions[$id]=gre_cents($p['balance']);$expected+=gre_cents($p['balance']);}
            foreach($r['rows'] as $row)if(isset($positions[$row['nis']]))gre_check(gre_cents($row['saldo_saat_ini'])===$positions[$row['nis']],"Unit $unit authoritative account balance ".count($checks));
            $extra=$koneksi->query('SELECT COALESCE(SUM(t.SALDO),0) FROM tabungan t JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id WHERE NOT EXISTS(SELECT 1 FROM legacy_op_owners o WHERE o.student_id=s.id AND o.unit_id=s.unit_id)')->fetch_row()[0];
            gre_check(gre_sum($r['rows'],'saldo_saat_ini')===$expected+gre_cents($extra),"Unit $unit total balances agree with authoritative accounts and unmatched native balances");
        }
        if($template==='riwayat-tagihan'){$f['tanggal_awal']=$f['tanggal_akhir']='';$r=report_build($koneksi,$template,$f);$spp=array_values(array_filter($r['rows'],static fn($r)=>$r['komponen_key']==='spp'));$expected=array_sum($source['monthly'][$unit]['spp']??[]);gre_check(gre_sum($spp,'terbayar')===$expected,"Unit $unit billing history retains proven SPP credits without inventing old bills");}
        if(in_array($template,['penerimaan','setoran'],true)){$f['include_undated']=true;$included=report_build($koneksi,$template,$f);$actual=$template==='setoran'?gre_cents($included['total_setoran']):gre_sum($included['rows'],'total_penerimaan');gre_check($actual===$source['cash'][$unit]+$source['undated'][$unit],"Unit $unit $template checkbox includes only matching undated obligation periods");}
    }
    foreach(report_categories($koneksi) as $category=>$label)foreach(['status','per-item','penerimaan'] as $template){
        $f=report_filters($koneksi,['template'=>$template,'kategori'=>$category,'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-21','bulan_awal'=>'10','bulan_akhir'=>'10','tahun_awal'=>'2026','tahun_akhir'=>'2026','tahun_ajaran'=>'2026/2027','siswa_status'=>'all']);$r=report_build($koneksi,$template,$f);
        gre_check(isset($r['columns'],$r['rows'])&&!str_contains($r['subtitle'],'histori Legacy tanpa tanggal'),"Unit $unit $template category $category is readable");
        if($template==='penerimaan')gre_check(gre_sum($r['rows'],'total_penerimaan')===($source['categories'][$unit][$category]??0),"Unit $unit receipt category $category equals independent source cents");
        if($template==='per-item'&&str_starts_with($category,'histori:'))gre_check(gre_sum($r['rows'],'terbayar')===($source['categories'][$unit][$category]??0),"Unit $unit history-only item $category retains dated payments");
        if($template==='per-item'&&str_starts_with($category,'biaya_lain:'))gre_check(gre_sum($r['rows'],'terbayar')===($source['categories'][$unit][$category]??0),"Unit $unit item $category retains allocated and unallocated dated payments");
    }
 }
 unit_set_context($koneksi,0);
 foreach(global_report_templates() as $template){$f=report_filters($koneksi,['template'=>$template,'kategori'=>$template==='penerimaan'?'semua':'spp','tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-21','tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','bulan_akhir'=>'10','tahun_awal'=>'2026','tahun_akhir'=>'2026','siswa_status'=>'all']);$r=report_build($koneksi,$template,$f);$expectedRows=array_sum(array_map(static fn($m)=>$m[$template]['rows'],$models));gre_check(count($r['rows'])===$expectedRows,"All-unit $template retains exactly the three scoped row sets");
    $totals=report_money_totals($r,$template);foreach($totals as $total){$key=$total['key']??$total['label'];$expected=0;foreach($models as $m)foreach($m[$template]['totals'] as $part)if(($part['key']??$part['label'])===$key)$expected+=gre_cents($part['value']);gre_check(gre_cents($total['value'])===$expected,"All-unit $template $key equals scoped totals");}
 }
}finally{$koneksi->rollback();}
file_put_contents($qa.'/reconciliation.json',json_encode(['checks'=>count($checks),'passed'=>$checks,'models'=>$models,'source'=>$source],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'OK: '.count($checks)." independent Global Report checks\n";
