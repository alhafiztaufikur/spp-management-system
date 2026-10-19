<?php
$defaultYear=(int)($cfg['year_id']??0)?:lop_default_year($koneksi);
$defaultCutoff=str_replace(' ','T',substr(($cfg['cutoff']??'')?:date('Y-m-d H:i:00'),0,16));
?>
<section class="dbt-card">
    <h2>Migrasi mengikuti backup</h2>
    <p>Kelas, tarif SPP/POMG, pembayaran dan tabungan yang cocok disiapkan bersama. Data yang perlu diperbaiki dapat dilanjutkan kemudian.</p>
    <form method="post" class="lf-form">
        <?php lop_form_hidden('prepare_source'); ?>
        <div class="lful-grid">
            <label>Tahun ajaran<select name="year_id" required>
                <option value="">Pilih tahun ajaran</option>
                <?php foreach($years as $year): ?><option value="<?= (int)$year['id'] ?>" <?= $defaultYear===(int)$year['id']?'selected':'' ?>><?= loe($year['label']) ?></option><?php endforeach; ?>
            </select></label>
            <label>Mulai beralih ke SistemSPP (WIB)<input type="datetime-local" name="cutoff" required value="<?= loe($defaultCutoff) ?>" <?= $batch?'readonly':'' ?>></label>
        </div>
        <p>Tarif memakai nominal asli tiap siswa. Tunggakan yang belum jelas tetap dapat ditinjau tersendiri.</p>
        <button class="dbt-button dbt-button-primary" <?= in_array($draft['job_info']['state'],['ready','failed','imported'],true)?'':'disabled' ?>>Siapkan &amp; Periksa Hasil</button>
    </form>
</section>
