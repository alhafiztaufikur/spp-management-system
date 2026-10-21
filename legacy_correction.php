<?php
session_start();
require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/includes/auth.php';requireRole(['admin']);
require_once __DIR__.'/includes/legacy_directory_ui.php';
require_once __DIR__.'/includes/legacy_operational_corrections.php';
require_once __DIR__.'/includes/migrated_history.php';
header('Cache-Control: private, no-store');
$actor=(int)$_SESSION['admin_id'];$unit=unit_active_id();$super=unit_is_super();
$error='';$preview=null;$saved=false;$record=null;$payload=[];
if(empty($_SESSION['csrf_legacy_correction']))$_SESSION['csrf_legacy_correction']=bin2hex(random_bytes(32));
$kind=$_GET['kind']??$_POST['kind']??'';
$id=filter_var($_GET['id']??$_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
try{
    if(!$id||!is_string($kind)||!in_array($kind,['payment','journal'],true))throw new DomainException('Identitas transaksi tidak valid.',400);
    lop_correction_actor($koneksi,$actor,$unit,$kind);lop_require($koneksi);$record=lop_record($koneksi,$kind,$id);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!is_string($_POST['csrf_token']??null)||!hash_equals($_SESSION['csrf_legacy_correction'],$_POST['csrf_token']))throw new DomainException('Token CSRF tidak valid.',403);
        if(($_POST['_operational_unit']??'')!==(string)$unit)throw new DomainException('Unit formulir sudah berubah. Muat ulang sebelum menyimpan.',409);
        $version=(int)($_POST['version']??0);$action=$_POST['action']??'preview';
        if(in_array($action,['save','back'],true)){
            $payload=json_decode((string)($_POST['payload']??''),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($payload))throw new DomainException('Isian tidak valid.');
            if($action==='back')unset($_SESSION['legacy_correction_preview']);
            else{
                $token=$_SESSION['legacy_correction_preview']??[];
                if(($token['kind']??'')!==$kind||($token['id']??0)!==$id||($token['unit']??0)!==$unit||($token['version']??0)!==$version||($token['expires']??0)<time()||!hash_equals($token['digest']??'',(string)($_POST['digest']??'')))throw new DomainException('Konfirmasi berubah atau kedaluwarsa. Periksa ulang.');
                $payload['confirmation']=$_POST['confirmation']??'';
                lop_correction_apply($koneksi,$actor,$unit,$kind,$id,$version,$payload,(string)$_POST['digest'],(string)($token['key']??''));
                $saved=true;$record=lop_record($koneksi,$kind,$id);$payload=[];
            }
        }else{
            $payload=['owner_id'=>(int)($_POST['owner_id']??0),'date'=>$_POST['date']??null,'note'=>$_POST['note']??'','reason'=>$_POST['reason']??'','cancelled'=>($_POST['cancelled']??'')==='1','preview_expires'=>time()+300];
            if(isset($_POST['time_precision']))$payload['time_precision']=(string)$_POST['time_precision'];
            if($kind==='payment'){
                $payload['period']=$_POST['period']??'';$payload['items']=$_POST['items']??[];
                if(!is_array($payload['items']))throw new DomainException('Rincian tidak valid.');
                foreach($payload['items'] as &$item){if(!is_array($item))throw new DomainException('Rincian tidak valid.');if(isset($item['amount_display']))$item['amount']=lop_correction_amount((string)$item['amount_display']);unset($item['amount_display']);}unset($item);
            }else foreach(['incoming','outgoing'] as $field)$payload[$field]=isset($_POST[$field.'_display'])?lop_correction_amount((string)$_POST[$field.'_display']):(string)($_POST[$field]??'0');
            $preview=lop_correction_preview($koneksi,$actor,$unit,$kind,$id,$version,$payload);
            $_SESSION['legacy_correction_preview']=['kind'=>$kind,'id'=>$id,'unit'=>$unit,'version'=>$version,'digest'=>$preview['digest'],'expires'=>$preview['expires'],'key'=>bin2hex(random_bytes(32))];
        }
    }
}catch(Throwable $e){
    http_response_code($e instanceof DomainException&&in_array($e->getCode(),[400,403,404],true)?$e->getCode():409);
    $error=$e instanceof DomainException?$e->getMessage():'Koreksi gagal. Tidak ada perubahan diterapkan.';
}
function lce(mixed $v):string{return legacy_ui_e($v);}
function lc_number(mixed $v):string{try{$c=lf_cents((string)$v);return number_format($c/100,$c%100?2:0,',','.');}catch(Throwable $e){return (string)$v;}}
$owners=$record?lf_rows($koneksi,"SELECT o.id,o.name,o.source_identity,s.id student_id,mk.legacy_kind,mk.graduation_year,mk.kode_rombel FROM legacy_op_owners o LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id AND mk.unit_id=s.unit_id WHERE o.batch_id=? AND o.state='ready'".($super?'':' AND o.id='.(int)$record['owner_id']).' ORDER BY o.name',[$record['batch_id']]):[];
$obligations=$record&&$kind==='payment'?lf_rows($koneksi,'SELECT t.*,o.name FROM legacy_op_obligations t JOIN legacy_op_owners o ON o.id=t.owner_id AND o.unit_id=t.unit_id WHERE o.batch_id=? ORDER BY o.name,t.period',[$record['batch_id']]):[];
$historyReturn=$record?mh_return_url($_GET['hr']??null,$unit,(string)$kind,(int)$id):null;
$values=$payload?:($record?['owner_id'=>$record['owner_id'],'date'=>$record['source_date'],'period'=>$record['source_period']??'','note'=>$record['note'],'items'=>$record['items']??[],'incoming'=>$record['incoming']??'0','outgoing'=>$record['outgoing']??'0','cancelled'=>(bool)$record['cancelled']]:[]);
$owner=null;foreach($owners as $candidate)if((int)$candidate['id']===(int)($values['owner_id']??0))$owner=$candidate;
$recordPrecision=$record?($kind==='journal'?journal_time_precision($koneksi,-1000000000-$id,$record['origin'],$record['source_date']):(empty($record['source_date'])?'none':(substr($record['source_date'],11)==='00:00:00'?'date':'datetime'))):'none';
$back=$historyReturn?:($kind==='journal'?'tabungan/riwayat.php?all_periods=1':'pembayaran/lihat.php');
if(!$super&&$record)$back.=(str_contains($back,'?')?'&':'?').'id='.(-1000000000-$id).'&jenis='.(lf_cents($record['incoming'])>0?'masuk':'keluar');
function lc_hidden():void{global $record,$unit;?><input type="hidden" name="csrf_token" value="<?= lce($_SESSION['csrf_legacy_correction']) ?>"><input type="hidden" name="_operational_unit" value="<?= $unit ?>"><input type="hidden" name="version" value="<?= (int)$record['version'] ?>"><?php }
?>
<!doctype html><html lang="id" data-palette="<?= unit_palette_for_view() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Koreksi Transaksi Legacy</title>
<?php foreach(['style','backup_restore','legacy_finance','legacy_ui','legacy_full','legacy_workspace','menu_refinements'] as $css): ?><link rel="stylesheet" href="assets/css/<?= $css ?>.css?v=<?= filemtime(__DIR__.'/assets/css/'.$css.'.css') ?>"><?php endforeach; ?>
<script>document.documentElement.dataset.theme=localStorage.getItem('spp_theme')||'light';</script><link rel="stylesheet" href="assets/css/desktop_transaction_refinements.css?v=<?= filemtime(__DIR__.'/assets/css/desktop_transaction_refinements.css') ?>"></head><body><div class="layout"><?php legacy_ui_sidebar(); ?><main class="main-content dbt-main"><?php legacy_ui_topbar($kind==='journal'?'Koreksi Tabungan':'Koreksi Transaksi Legacy',$kind==='journal'?'Tabungan / Riwayat / Koreksi':'Pembayaran / Riwayat / Koreksi'); ?>
<div class="db-tools-page legacy-ui legacy-edit-workspace correction-refined<?= $kind==='journal'?' correction-date-refined':'' ?>">
<header class="correction-header"><div><small>KOREKSI <?= $kind==='journal'?'TABUNGAN':'PEMBAYARAN' ?></small><h1>Periksa koreksi sebelum disimpan</h1><p>Sumber asli dan operator tetap tersimpan. Perubahan diperiksa sebelum diterapkan.</p></div><div class="legacy-actions"><a class="dbt-button dbt-button-outline" href="<?= lce($back) ?>">Kembali ke Riwayat</a><?php if($super&&$record): ?><a class="dbt-button" href="<?= lce(legacy_ui_url('legacy_accounts.php',array_replace(legacy_directory_context($_GET),['batch'=>$record['batch_id'],'owner'=>$record['owner_id']])).'#rekening') ?>">Kembali ke Rekening</a><?php endif; ?></div></header>
<?php if($error): ?><p class="dbt-warning" role="alert"><?= lce($error) ?></p><?php endif; ?><?php if($saved): ?><p class="dbt-info" role="status">Koreksi tersimpan beserta jejak perubahannya.</p><?php endif; ?>
<?php if($record): ?>
<div class="correction-owner"><span class="master-workspace-icon"><?= legacy_ui_icon('users') ?></span><div><strong><?= lce($owner['name']??'Pemilik rekening') ?></strong><small><?= lce($owner['source_identity']??'') ?> &middot; <?= lce(unit_label($unit)) ?><?php if(($owner['legacy_kind']??'')==='alumni'): ?> &middot; <span class="savings-alumni-badge"><?= lce(!empty($owner['graduation_year'])?'Alumni '.(int)$owner['graduation_year']:$owner['kode_rombel']) ?></span><?php endif; ?></small></div><span class="correction-reference"><?= $kind==='journal'?'Jurnal':'Pembayaran' ?> #<?= $id ?></span></div>
<?php if($preview): ?>
<section id="full-confirm" data-confirm-label="Konfirmasi koreksi transaksi Legacy" class="dbt-card lful-confirm"><h2>Simpan koreksi ini?</h2><dl class="legacy-changes">
<div><dt>Pemilik tujuan</dt><dd><?= lce($owner['name']??'') ?></dd></div><div><dt>Tanggal / waktu</dt><dd><?= lce(spp_date_label($preview['before']['source_date'],true,$recordPrecision)) ?> &rarr; <?= lce(spp_date_label($preview['after']['source_date'],true,$preview['after']['_time_precision']??$recordPrecision)) ?></dd></div>
<?php if($kind==='payment'): ?><div><dt>Total pembayaran</dt><dd><?= lce(legacy_ui_money($preview['before']['total'])) ?> &rarr; <?= lce(legacy_ui_money($preview['after']['total'])) ?></dd></div><?php else: foreach(['incoming'=>'Masuk','outgoing'=>'Keluar'] as $field=>$label): ?><div><dt><?= $label ?></dt><dd><?= lce(legacy_ui_money($preview['before'][$field])) ?> &rarr; <?= lce(legacy_ui_money($preview['after'][$field])) ?></dd></div><?php endforeach; endif; ?>
<?php foreach($preview['effects'] as $effect): ?><div><dt><?= isset($effect['obligation_id'])?'Sisa kewajiban':'Saldo rekening' ?></dt><dd><?= lce(legacy_ui_money($effect['before']??$effect['balance_before'])) ?> &rarr; <?= lce(legacy_ui_money($effect['after']??$effect['balance_after'])) ?></dd></div><?php endforeach; ?><div><dt>Alasan</dt><dd><?= lce($preview['reason']) ?></dd></div></dl>
<form method="post"><?php lc_hidden(); ?><input type="hidden" name="payload" value="<?= lce(lf_json($payload)) ?>"><input type="hidden" name="digest" value="<?= lce($preview['digest']) ?>"><label class="legacy-check-row"><input type="checkbox" name="confirmation" value="SIMPAN KOREKSI" required> Saya mengonfirmasi pemilik, nominal, dan dampak koreksi.</label><div class="legacy-actions"><button name="action" value="back" formnovalidate class="dbt-button">Kembali Mengubah</button><button name="action" value="save" class="dbt-button dbt-button-primary">Simpan Koreksi</button></div></form></section>
<?php else: ?>
<section class="dbt-card"><form method="post" class="lf-form correction-form"><?php lc_hidden(); ?>
<label>Pemilik<?php if($super): ?><select name="owner_id"><?php foreach($owners as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (int)($values['owner_id']??0)===(int)$option['id']?'selected':'' ?>><?= lce($option['name'].' - '.$option['source_identity'].(($option['legacy_kind']??'')==='alumni'?' - '.$option['kode_rombel']:'')) ?></option><?php endforeach; ?></select><?php else: ?><input type="hidden" name="owner_id" value="<?= (int)$record['owner_id'] ?>"><input readonly value="<?= lce($owner['name']??'') ?>"><?php endif; ?></label>
<label>Tanggal / waktu WIB<input type="hidden" name="time_precision" value="<?= lce($payload['time_precision']??$recordPrecision) ?>"><input type="datetime-local" step="1" name="date"<?= $kind==='journal'?' data-correction-datetime':'' ?> value="<?= lce(str_replace(' ','T',(string)($values['date']??''))) ?>" <?= $kind==='journal'?'required':'' ?>><small><?= $recordPrecision==='date'?'Jam sumber belum tercatat. ':'' ?><?= $kind==='journal'?'Tanggal DD/MM/YYYY. Jam 24 jam; isi hanya jika diketahui.':'Isi jam hanya jika diketahui dari bukti transaksi.' ?></small></label>
<?php if($kind==='payment'): ?><label class="correction-wide">Periode<input name="period" maxlength="100" value="<?= lce($values['period']??'') ?>"></label><div class="dbt-table-scroll correction-wide"><table class="dbt-table"><thead><tr><th>Komponen</th><th>Jenis</th><th>Nominal</th><th>Kewajiban terkait</th></tr></thead><tbody>
<?php foreach($values['items']??[] as $n=>$item): ?><tr><td><input type="hidden" name="items[<?= $n ?>][column]" value="<?= lce($item['column']) ?>"><input name="items[<?= $n ?>][label]" maxlength="150" required value="<?= lce($item['label']) ?>"></td><td><select name="items[<?= $n ?>][category]"><?php foreach(['spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang','psb'=>'PSB',$item['category']=>$item['label']] as $category=>$label): ?><option value="<?= lce($category) ?>" <?= $category===$item['category']?'selected':'' ?>><?= lce($label) ?></option><?php endforeach; ?></select></td><td><input type="hidden" name="items[<?= $n ?>][amount]" value="<?= lce($item['amount']) ?>"><input name="items[<?= $n ?>][amount_display]" data-money-field inputmode="decimal" required value="<?= lce($error&&isset($_POST['items'][$n]['amount_display'])?$_POST['items'][$n]['amount_display']:lc_number($item['amount'])) ?>"></td><td><select name="items[<?= $n ?>][obligation_id]"><option value="0">Belum terhubung</option><?php foreach($obligations as $ob): ?><option value="<?= (int)$ob['id'] ?>" <?= (int)($item['obligation_id']??0)===(int)$ob['id']?'selected':'' ?>><?= lce($ob['name'].' - '.$ob['category'].' - '.$ob['period']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div><p class="legacy-note correction-wide">Kewajiban yang belum jelas disahkan pada peninjauan migrasi sebelum koreksi nominal diterapkan.</p>
<?php else: foreach(['incoming'=>'Masuk (Rp)','outgoing'=>'Keluar (Rp)'] as $field=>$label): ?><label><?= $label ?><input type="hidden" name="<?= $field ?>" value="<?= lce($values[$field]??'0') ?>"><input name="<?= $field ?>_display" data-money-field inputmode="decimal" required value="<?= lce(isset($_POST[$field.'_display'])&&$error?$_POST[$field.'_display']:lc_number($values[$field]??'0')) ?>"></label><?php endforeach; endif; ?>
<label class="correction-wide">Catatan<textarea name="note" maxlength="1000" rows="3"><?= lce($values['note']??'') ?></textarea></label><label class="correction-wide">Alasan / bukti koreksi<textarea name="reason" minlength="10" maxlength="1000" rows="3" required placeholder="Jelaskan alasan perubahan ini"><?= lce($values['reason']??'') ?></textarea></label>
<?php if($super): ?><label class="legacy-check-row correction-wide"><input type="checkbox" name="cancelled" value="1" <?= !empty($values['cancelled'])?'checked':'' ?>> Batalkan transaksi ini (sumber asli tetap tersimpan)</label><?php else: ?><input type="hidden" name="cancelled" value="<?= $record['cancelled']?'1':'0' ?>"><?php endif; ?>
<div class="legacy-actions correction-wide"><button class="dbt-button dbt-button-primary">Pratinjau Dampak Koreksi</button></div></form></section>
<?php endif; endif; ?></div></main></div><script src="assets/js/app.js?v=<?= filemtime(__DIR__.'/assets/js/app.js') ?>"></script><script src="assets/js/legacy_ui.js"></script><script src="assets/js/legacy_full.js"></script><script src="assets/js/money_fields.js?v=<?= filemtime(__DIR__.'/assets/js/money_fields.js') ?>"></script><script src="assets/js/correction_datetime.js?v=<?= filemtime(__DIR__.'/assets/js/correction_datetime.js') ?>"></script></body></html>
