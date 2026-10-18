<?php
$fields=['x_top'=>'Kiri halaman atas','y_top'=>'Awal tabel atas','x_bottom'=>'Kiri halaman bawah','y_bottom'=>'Awal baris bawah','header'=>'Tinggi kepala tabel','row_height'=>'Tinggi baris','rows_top'=>'Baris halaman atas','rows_bottom'=>'Baris halaman bawah','text_offset'=>'Posisi teks dari atas baris','w_no'=>'Lebar No','w_date'=>'Lebar Tanggal','w_in'=>'Lebar Masuk','w_out'=>'Lebar Keluar','w_balance'=>'Lebar Jumlah','w_sign'=>'Lebar Paraf'];
$guideUrl='cetak_buku.php?'.http_build_query(['nis'=>$nis,'student_id'=>(int)$student['id'],'output'=>'guide']+$settings);
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html><html lang="id" data-palette="<?= book_escape(unit_palette_for_view((int)$student['unit_id'])) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cetak Buku Tabungan</title>
<link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/savings_book_print.css?v=<?= filemtime(__DIR__.'/../assets/css/savings_book_print.css') ?>">
<script>try{document.documentElement.dataset.theme=localStorage.getItem('spp_theme')||'light'}catch(e){}</script></head><body>
<main class="book-workspace" data-book-print data-storage-key="spp_plq20_layout_<?= (int)$student['unit_id'] ?>">
<header class="book-heading"><div><span class="book-eyebrow">EPSON PLQ-20 ESC/P2</span><h1>Cetak Buku Tabungan</h1><p><?= book_escape($student['NAMA']) ?> · NIS <?= book_escape($nis) ?> · <?= book_escape($student['kelas_label']??$student['KELAS']) ?> · <?= count($book['entries']) ?> transaksi</p><p>Saldo akhir <strong>Rp <?= book_escape(savings_book_money($book['balance'])) ?></strong></p></div><a class="book-button secondary" href="<?= book_escape($backUrl) ?>"><?= book_escape($backLabel) ?></a></header>
<section class="book-instructions"><strong>Cetak isi ke buku yang sudah bergaris</strong><p>Passbook <b>On</b> · Binding <b>Horizontal</b> · A5 potret <b>148 × 210 mm</b> · Skala <b>100% / Actual size</b>. Garis panduan pada preview tidak ikut tercetak. Cocokkan posisi dengan Cetak Uji Kalibrasi sebelum memakai buku.</p></section>
<form method="get" action="cetak_buku.php" data-book-form>
<input type="hidden" name="nis" value="<?= book_escape($nis) ?>"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
<section class="book-card"><h2>Transaksi dan posisi awal</h2><div class="book-fields">
<label>Transaksi pertama<input type="number" name="entry_from" min="<?= $book['entries']?1:0 ?>" max="<?= count($book['entries']) ?>" value="<?= $settings['entry_from'] ?>" required></label>
<label>Transaksi terakhir<input type="number" name="entry_to" min="<?= $book['entries']?1:0 ?>" max="<?= count($book['entries']) ?>" value="<?= $settings['entry_to'] ?>" required></label>
<label>Halaman awal<select name="start_side"><option value="top" <?= $settings['start_side']==='top'?'selected':'' ?>>Atas</option><option value="bottom" <?= $settings['start_side']==='bottom'?'selected':'' ?>>Bawah</option></select></label>
<label>Baris awal<input type="number" name="start_row" min="1" max="<?= $settings['layout']['rows_'.$settings['start_side']] ?>" value="<?= $settings['start_row'] ?>" required></label>
</div><p class="book-muted">Nomor dan saldo mengikuti seluruh jurnal. Pilih baris kosong berikutnya pada buku; cetak tidak mengubah riwayat rekening.</p>
<details class="book-calibration"><summary>Pengaturan kalibrasi <span aria-hidden="true">⌄</span></summary><div class="book-fields">
<?php foreach($fields as $key=>$label):$integer=str_starts_with($key,'rows_'); ?><label><?= book_escape($label) ?><?= $integer?'':' (mm)' ?><input type="number" step="<?= $integer?'1':'0.1' ?>" min="<?= $key==='text_offset'?'0':'1' ?>" max="<?= $integer?'40':'210' ?>" name="layout[<?= $key ?>]" value="<?= $settings['layout'][$key] ?>" data-layout-key="<?= $key ?>" data-default="<?= savings_book_layout_defaults()[$key] ?>" required></label><?php endforeach; ?>
</div><div class="book-actions"><button type="button" class="book-button secondary" data-book-save>Simpan Pengaturan</button><button type="button" class="book-button secondary" data-book-reset>Reset Kalibrasi</button></div><p class="book-muted">Pengaturan disimpan di browser ini untuk unit terpilih. Area dekat lipatan dan margin printer tetap dibatasi.</p></details>
<div class="book-actions"><button class="book-button" type="submit" name="output" value="preview">Terapkan Preview</button><button class="book-button secondary" type="submit" name="output" value="pdf" formtarget="_blank">Buka PDF untuk Dicetak</button><button class="book-button secondary" type="submit" name="calibration" value="1" formtarget="_blank" data-book-test>Cetak Uji Kalibrasi</button></div><p role="status" aria-live="polite" class="book-muted" data-book-status></p>
</section></form>
<section class="book-preview"><h2>Preview posisi cetak</h2><p class="book-muted">Garis abu-abu adalah panduan kotak buku. PDF produksi hanya berisi nomor, tanggal, dan nominal.</p><iframe src="<?= book_escape($guideUrl) ?>" title="Panduan posisi cetak buku tabungan"></iframe><p><a class="book-button secondary" href="<?= book_escape($guideUrl) ?>" target="_blank" rel="noopener">Buka Panduan PDF</a></p></section>
<script type="application/json" data-book-config><?= json_encode(['explicit_layout'=>isset($_GET['layout'])],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
</main><script src="../assets/js/savings_book_print.js?v=<?= filemtime(__DIR__.'/../assets/js/savings_book_print.js') ?>"></script></body></html>
