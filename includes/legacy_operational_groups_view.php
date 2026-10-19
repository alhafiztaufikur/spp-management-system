<?php
require_once __DIR__.'/legacy_operational_read.php';
require_once __DIR__.'/legacy_class_links.php';
require_once __DIR__.'/legacy_directory_ui.php';
$lgOpen=($_GET['lg_open']??'')==='1'||isset($_GET['lg_q'])||isset($_GET['lg_kind'])||isset($_GET['lg_page']);
if(!$lgOpen): ?>
<section class="main-card master-modern-card legacy-groups" id="legacy-groups"><div class="class-panel-heading"><span class="class-workspace-icon"><?= legacy_ui_icon('users') ?></span><div><h2>Kelompok dari Legacy</h2><p>Telusuri sumber migrasi saat diperlukan. Daftar kelompok belum dimuat.</p></div><a class="btn btn-ghost" href="master_kelas.php?lg_open=1#legacy-groups">Telusuri Kelompok Legacy</a></div></section>
<?php return; endif;
if(lop_ready($koneksi)):
    $lgFilters=legacy_directory_filters($_GET,'lg_');$lgKinds=legacy_directory_kinds();
    $lgWhere="b.state='completed'";$lgArgs=[];
    if($lgFilters['q']!==''){$lgWhere.=' AND (g.label LIKE ? OR g.code LIKE ? OR g.aliases LIKE ?)';$term='%'.$lgFilters['q'].'%';$lgArgs=[$term,$term,$term];}
    if($lgFilters['kind']!==''){$lgWhere.=' AND g.kind=?';$lgArgs[]=$lgFilters['kind'];}
    $lgJoin=' FROM legacy_op_groups g JOIN legacy_op_owners o ON o.group_id=g.id AND o.unit_id=g.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE '.$lgWhere;
    $lgCounter=$koneksi->prepare('SELECT COUNT(*) n FROM (SELECT g.id,b.id batch_id'.$lgJoin.' GROUP BY g.id,b.id) directory');if($lgArgs)$lgCounter->bind_param(str_repeat('s',count($lgArgs)),...$lgArgs);$lgCounter->execute();$lgCount=$lgCounter->get_result()->fetch_assoc();$lgCounter->close();
    $lgTotal=(int)$lgCount['n'];$lgPage=min($lgFilters['page'],max(1,(int)ceil($lgTotal/25)));
    $lgStmt=$koneksi->prepare('SELECT g.*,b.id batch_id,COUNT(o.id) owners'.$lgJoin.' GROUP BY g.id,b.id ORDER BY g.unit_id,g.label,g.id,b.id LIMIT 25 OFFSET '.(($lgPage-1)*25));
    if($lgArgs)$lgStmt->bind_param(str_repeat('s',count($lgArgs)),...$lgArgs);
    $lgStmt->execute();$lgRows=$lgStmt->get_result()->fetch_all(MYSQLI_ASSOC);$lgStmt->close();
    $lgContext=['lg_open'=>'1','lg_q'=>$lgFilters['q'],'lg_kind'=>$lgFilters['kind'],'lg_page'=>$lgPage];
?>
<section class="main-card master-modern-card legacy-groups" id="legacy-groups" aria-labelledby="legacy-groups-title">
  <div class="class-panel-heading"><span class="class-workspace-icon"><?= legacy_ui_icon('users') ?></span><div><h2 id="legacy-groups-title">Kelompok dari Legacy</h2><p>Temukan siswa, alumni, dan rekening khusus beserta transaksi hasil migrasinya. Kelompok nonakademik tidak mengikuti kenaikan kelas.</p></div><span class="legacy-directory-count"><?= number_format($lgTotal,0,',','.') ?> kelompok</span></div>
  <a class="btn btn-ghost" href="master_kelas.php#legacy-groups">Tutup Penelusuran Legacy</a>
  <form method="get" action="master_kelas.php#legacy-groups" class="legacy-directory-search"><input type="hidden" name="lg_open" value="1">
    <label>Cari kelompok<input name="lg_q" value="<?= legacy_ui_e($lgFilters['q']) ?>" maxlength="100" placeholder="Nama atau kode kelompok"></label>
    <label>Jenis kelompok<select name="lg_kind"><option value="">Semua jenis</option><?php foreach($lgKinds as $key=>$label): ?><option value="<?= $key ?>" <?= $key===$lgFilters['kind']?'selected':'' ?>><?= legacy_ui_e($label) ?></option><?php endforeach; ?></select></label>
    <div class="legacy-inline-actions"><button class="btn btn-primary">Cari Kelompok</button><a class="btn btn-ghost" href="master_kelas.php?lg_open=1#legacy-groups">Reset</a></div>
  </form>
  <?php if(!$lgRows): ?><div class="legacy-directory-empty"><span class="class-workspace-icon"><?= legacy_ui_icon('users') ?></span><h3><?= $lgFilters['q']!==''||$lgFilters['kind']!==''?'Kelompok tidak ditemukan':'Belum ada kelompok hasil migrasi' ?></h3><p><?= $lgFilters['q']!==''||$lgFilters['kind']!==''?'Coba kata kunci lain atau reset filter.':'Kelompok akan muncul setelah hasil migrasi diterapkan dan diverifikasi.' ?></p></div>
  <?php else: ?><div class="legacy-directory-table-wrap"><table class="legacy-directory-table"><thead><tr><th>Kelompok</th><th>Jenis</th><th>Jumlah identitas</th><th>Tindakan</th></tr></thead><tbody>
    <?php foreach($lgRows as $lgRow): $lgLabel=legacy_class_label($koneksi,$lgRow);$lgAliases=array_values(array_unique(array_filter(array_merge([$lgRow['code']],json_decode($lgRow['aliases'],true)??[]),static fn($alias)=>strcasecmp(trim((string)$alias),trim($lgLabel))!==0))); ?>
    <tr><td data-label="Kelompok"><strong><?= legacy_ui_e($lgLabel) ?></strong><?php if(unit_all_readonly()): ?><small><?= legacy_ui_e(unit_label((int)$lgRow['unit_id'])) ?></small><?php endif; ?><?php if($lgAliases): ?><details class="legacy-directory-aliases"><summary>Ejaan sumber</summary><small><?= legacy_ui_e(implode(', ',$lgAliases)) ?></small></details><?php endif; ?></td><td data-label="Jenis"><span class="legacy-kind-badge" data-kind="<?= legacy_ui_e($lgRow['kind']) ?>"><?= legacy_ui_e($lgKinds[$lgRow['kind']]??'Kelompok Legacy') ?></span></td><td data-label="Jumlah identitas"><span><span class="legacy-directory-number"><?= number_format((int)$lgRow['owners'],0,',','.') ?></span> <span class="legacy-muted">identitas</span></span></td><td data-label="Tindakan"><a class="btn btn-ghost" href="<?= legacy_ui_e(legacy_ui_url('legacy_accounts.php',$lgContext+['batch'=>$lgRow['batch_id'],'group'=>$lgRow['id']])) ?>">Lihat Rekening &amp; Transaksi <?= legacy_ui_icon('next') ?></a></td></tr>
    <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php legacy_directory_pager('master_kelas.php',$lgContext,$lgPage,$lgTotal,'lg_page','#legacy-groups'); ?>
</section>
<?php endif; ?>
