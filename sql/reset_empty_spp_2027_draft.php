<?php
/** One-off metadata correction explicitly requested for SD 2027/2028. */
$target=(string)(getenv('SPP_DB_NAME')?:'db_spp');
if (PHP_SAPI!=='cli' || ($target!=='db_spp'&&!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',$target))) exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/spp_billing.php';
if (DB_NAME!==$target) throw new RuntimeException('Database target mismatch.');
unit_set_context($koneksi,1);$koneksi->begin_transaction();
try {
    $s=$koneksi->prepare("SELECT mst.id FROM master_spp_tahun mst JOIN tahun_ajaran ta ON ta.id=mst.tahun_ajaran_id WHERE mst.unit_id=1 AND ta.unit_id=1 AND ta.label='2027/2028' FOR UPDATE");
    $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if(count($rows)!==1)throw new RuntimeException('Expected exactly one SD 2027/2028 master.');
    $id=(int)$rows[0]['id'];$master=spp_master_state($koneksi,$id,true);$yearId=(int)$master['tahun_ajaran_id'];
    $s=$koneksi->prepare('SELECT id FROM siswa_tahun_ajaran WHERE tahun_ajaran_id=? FOR UPDATE');$s->bind_param('i',$yearId);$s->execute();$placements=$s->get_result()->num_rows;$s->close();
    $s=$koneksi->prepare('SELECT id FROM tagihan_spp WHERE master_spp_tahun_id=? FOR UPDATE');$s->bind_param('i',$id);$s->execute();$bills=$s->get_result()->num_rows;$s->close();
    $payments=$koneksi->query("SELECT id FROM bayar WHERE U_SPP>0 AND ((TAHUN='2027' AND BULAN IN('07','08','09','10','11','12','Juli','Agustus','September','Oktober','November','Desember')) OR (TAHUN='2028' AND BULAN IN('01','02','03','04','05','06','Januari','Februari','Maret','April','Mei','Juni'))) FOR UPDATE")->num_rows;
    if($bills||$payments||($placements&&!in_array('--preserve-existing-placements',$argv,true)))throw new RuntimeException('Correction stopped: related placements, bills or payments now exist.');
    $rates=spp_master_rates($koneksi,$id,true);
    if($master['status']==='draft'&&$master['published_at']===null&&$master['closed_at']===null){$koneksi->rollback();echo "Already draft; no changes.\n";exit;}
    if($master['status']!=='published')throw new RuntimeException('Unexpected master status; no changes.');
    if(!in_array('--apply',$argv,true)){$koneksi->rollback();echo "READY: $target SD 2027/2028 has no dependent data; existing rates preserved.\n";exit;}
    $s=$koneksi->prepare("UPDATE master_spp_tahun SET status='draft',published_at=NULL,closed_at=NULL WHERE id=? AND unit_id=1");$s->bind_param('i',$id);$s->execute();$s->close();
    spp_write_audit($koneksi,$id,null,'kembalikan_draft',$master,['status'=>'draft','published_at'=>null,'closed_at'=>null,'tarif'=>$rates],0);
    if(spp_master_rates($koneksi,$id)!==$rates)throw new RuntimeException('Rates changed unexpectedly.');
    $koneksi->commit();echo "OK: $target SD 2027/2028 returned to draft; rates and financial records unchanged.\n";
}catch(Throwable $error){$koneksi->rollback();fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
