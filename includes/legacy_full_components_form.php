<?php
// Included only after the page has authenticated the actor and loaded this unit's draft.
require_once __DIR__.'/legacy_full_proposal_summary.php';
$componentMaps=array_replace($cfg['components'],$posted['components']??[]);
$newMasterInputs=array_replace($cfg['new_masters']??[],$posted['new_masters']??[]);
$proposalChoices=[];$proposalOrigins=[];
foreach($catalog['components'] as $component)if(empty($component['savings'])){
    $key=$component['proposal_key'];$proposalChoices[$key]=$newMasterInputs[$key]['name']??$component['name'];$proposalOrigins[$key]=$component['table'].'.'.$component['column'];
}
foreach($newMasterInputs as $key=>$proposal)$proposalChoices[$key]=$proposal['name']??$key;
$componentSearch=trim(is_string($_GET['component_q']??null)?$_GET['component_q']:'');$componentFilter=is_string($_GET['component_filter']??null)?$_GET['component_filter']:'';$componentPage=max(1,(int)($_GET['component_page']??1));
$visibleComponents=array_values(array_filter($catalog['components'],static function($c)use($componentSearch,$componentFilter,$componentMaps){if($componentSearch!==''&&!str_contains(mb_strtolower($c['name'].' '.$c['label']),mb_strtolower($componentSearch)))return false;$target=$componentMaps[$c['key']]['category']??'';return match($componentFilter){'pending'=>$target==='','history'=>str_starts_with($target,'histori:'),'new'=>str_starts_with($target,'biaya_baru:'),default=>true};}));$componentTotal=count($visibleComponents);$visibleComponents=array_slice($visibleComponents,($componentPage-1)*6,6);$visibleProposals=[];foreach($visibleComponents as $c){$visibleProposals[$c['proposal_key']]=true;$target=$componentMaps[$c['key']]['category']??'';if(str_starts_with($target,'biaya_baru:'))$visibleProposals[substr($target,11)]=true;}
?>
<section class="dbt-card" id="legacy-components">
  <h2>Komponen Legacy</h2>
  <p>Histori Saja menyimpan pembayaran lama tanpa membuat tagihan baru. Master baru dibuat nonaktif setelah migrasi dikonfirmasi.</p><a href="master_biaya_lain.php" target="_blank" rel="noopener">Buka Master Biaya Lain</a><p class="legacy-note">Simpan draf sebelum membuka master, lalu kembali dan Periksa Ulang.</p>
    <form method="get" class="lf-search"><input type="hidden" name="id" value="<?= lful_e($id) ?>"><input type="hidden" name="step" value="exceptions"><input type="hidden" name="section" value="components"><label>Cari komponen<input name="component_q" maxlength="100" value="<?= lful_e($componentSearch) ?>" placeholder="Nama komponen"></label><label>Status<select name="component_filter"><?php foreach([''=>'Semua komponen','pending'=>'Belum dipetakan','history'=>'Histori Saja','new'=>'Master baru'] as $key=>$label): ?><option value="<?= $key ?>" <?= $componentFilter===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><button class="dbt-button dbt-button-outline">Cari</button></form><p><?= $componentTotal ?> komponen ditemukan.</p><div class="lful-components">
    <?php foreach($visibleComponents as $c): $map=$componentMaps[$c['key']]??[];$target=$map['category']??''; ?>
      <article class="lful-component">
        <form method="post" class="lf-form"><fieldset <?= $locked?'disabled':'' ?>><?php lful_hidden($d,'component'); ?>
        <h3><?= lful_e($c['name']) ?></h3>
        <details><summary>Rincian sumber</summary><p class="lful-source"><?= lful_e($c['table'].'.'.$c['column'].' · '.$c['label']) ?></p></details>
        <dl class="lful-component-stats">
          <div><dt>Transaksi sumber</dt><dd><?= (int)$c['count'] ?></dd></div>
          <div><dt>Total histori sumber</dt><dd><?= $c['total']===null?'Perlu pemeriksaan':lful_e(lf_ui_amount($c['total'])) ?></dd></div>
          <div><dt>Rentang nominal</dt><dd><?= lful_e(lf_ui_amount($c['minimum']).' – '.lf_ui_amount($c['maximum'])) ?></dd></div>
        </dl>
        <small>Total histori sumber bukan tarif dan belum memperhitungkan hubungan total/rincian.</small>
        <?php if($c['invalid_count']): ?><p class="dbt-warning"><?= (int)$c['invalid_count'] ?> nominal sumber belum valid.</p><?php endif; ?>
        <?php if($c['suggestion']&&$target===''): ?><p>Usulan pasangan: <strong><?= lful_e($categories[$c['suggestion']]) ?></strong>. Pilih untuk mengonfirmasi.</p><?php endif; ?>
        <label>Pasangan komponen
          <select name="components[<?= lful_e($c['key']) ?>][category]" class="lful-component-target">
            <option value="">Belum dipetakan</option>
            <?php if($c['savings']): ?>
              <option value="savings_reference" <?= $target==='savings_reference'?'selected':'' ?>>Tabungan · referensi jurnal</option>
            <?php else: ?>
              <option value="<?= lful_e(lful_history_category($c['key'])) ?>" <?= $target===lful_history_category($c['key'])?'selected':'' ?>>Histori Saja · <?= lful_e($c['name']) ?></option>
              <optgroup label="Gunakan Komponen / Master Existing">
              <?php foreach($categories as $key=>$label): if(str_starts_with($key,'biaya_baru:'))continue; ?>
                <option value="<?= lful_e($key) ?>" <?= $target===$key?'selected':'' ?>><?= lful_e($label) ?></option>
              <?php endforeach; ?></optgroup>
              <optgroup label="Siapkan Master Baru · Nonaktif">
              <?php foreach($proposalChoices as $key=>$label): ?>
                <option value="<?= lful_e(lful_new_category($key)) ?>" <?= $target===lful_new_category($key)?'selected':'' ?>><?= lful_e($label.(isset($proposalOrigins[$key])?' · '.$proposalOrigins[$key]:'')) ?></option>
              <?php endforeach; ?></optgroup>
            <?php endif; ?>
          </select>
        </label>
        <label>Alasan / bukti pemetaan<textarea name="components[<?= lful_e($c['key']) ?>][reason]" minlength="10" maxlength="1000"><?= lful_e($map['reason']??($c['column']==='U_SORGA'?(lcs_komite_enabled($cfg)?'U_SORGA adalah pembayaran Komite sesuai pengesahan sumber Legacy.':'Aturan arsip pekerjaan lama: dipertahankan sebagai histori tanpa tagihan baru.'):'')) ?></textarea></label>
        <?php if($c['savings']): ?><label><input type="checkbox" name="components[<?= lful_e($c['key']) ?>][confirmed]" value="1" <?= !empty($map['confirmed'])?'checked':'' ?>> Saya sudah memeriksa hubungan nominal ini dengan jurnal tabungan; tidak dihitung sebagai pembayaran biaya.</label><?php endif; ?>
        <details><summary>Contoh bukti sumber</summary><ul>
        <?php foreach($c['evidence'] as $e): ?><li>NIS <?= lful_e($e['student']??'Tidak tercatat') ?> · <?= lful_e($e['date']?spp_date_label($e['date'],true):'Tanggal tidak tercatat') ?> · <?= lful_e($e['amount']) ?><?php if($e['row_key']): ?><br><small><?= lful_e($e['row_key']) ?></small><?php endif; ?></li><?php endforeach; ?>
        </ul></details>
        <button class="dbt-button dbt-button-outline">Simpan Pasangan</button>
        </fieldset></form>
      </article>
    <?php endforeach; ?>
    </div>
    <nav class="legacy-actions" aria-label="Halaman komponen"><?php foreach([-1=>'Sebelumnya',1=>'Berikutnya'] as $delta=>$label):$targetPage=$componentPage+$delta;if($targetPage<1||($targetPage-1)*6>=$componentTotal)continue; ?><a class="dbt-button dbt-button-outline" href="?<?= lful_e(http_build_query(array_replace($_GET,['id'=>$id,'step'=>'exceptions','section'=>'components','component_page'=>$targetPage]))) ?>"><?= $label ?></a><?php endforeach; ?></nav>
    <h3>Usulan Master Biaya Lain</h3>
    <p>Lengkapi hanya usulan yang dipilih di atas. Usulan yang tidak dipakai tidak dibuat. Tarif master diisi berdasarkan bukti; nominal histori tetap utuh.</p>
    <?php foreach($proposalChoices as $key=>$label): if(!isset($visibleProposals[$key]))continue;$p=$newMasterInputs[$key]??[]; ?>
    <details class="lful-proposal" id="proposal-<?= lful_e($key) ?>" <?= isset($newMasterInputs[$key])||in_array(lful_new_category($key),array_column($componentMaps,'category'),true)?'open':'' ?>>
      <summary><?= lful_e($label) ?> <span class="dbt-badge">Nonaktif</span></summary>
      <?php if(isset($proposalOrigins[$key])): ?><small><?= lful_e($proposalOrigins[$key]) ?></small><?php endif; ?>
      <form method="post" class="lf-form"><fieldset <?= $locked?'disabled':'' ?>><?php lful_hidden($d,'proposal'); ?>
      <div class="lful-grid">
        <label>Nama master<input name="new_masters[<?= lful_e($key) ?>][name]" maxlength="100" value="<?= lful_e($p['name']??$label) ?>"></label>
        <label>Tarif master (Rp)<input name="new_masters[<?= lful_e($key) ?>][nominal]" inputmode="decimal" placeholder="Isi tarif yang sah, tanpa pemisah ribuan" value="<?= lful_e($p['nominal']??'') ?>"></label>
      </div>
      <label>Alasan / bukti tarif<textarea name="new_masters[<?= lful_e($key) ?>][reason]" minlength="10" maxlength="1000"><?= lful_e($p['reason']??'') ?></textarea></label>
      <label><input type="checkbox" name="new_masters[<?= lful_e($key) ?>][confirmed]" value="1" <?= !empty($p['confirmed'])?'checked':'' ?>> Nama dan tarif sudah diperiksa. Master dibuat nonaktif tanpa menerbitkan tagihan.</label>
      <button class="dbt-button dbt-button-outline">Simpan Usulan Master</button>
      </fieldset></form>
    </details>
    <?php endforeach; ?>
</section>
