<?php
$periodOptions = ['monthly'=>'1 Bulan','range'=>'Beberapa Bulan','academic'=>'Satu Tahun Ajaran'];
$periodMode = isset($periodOptions[$periodMode ?? '']) ? $periodMode : 'monthly';
$periodChoice = $periodChoice ?? '';
$periodEditId = $periodEditId ?? 0;
?>
<div class="payment-period-select">
  <label class="field-label" for="payment-period-mode">Periode Tagihan SPP &amp; Komite</label>
  <select class="field-input field-select" id="payment-period-mode" name="payment_plan"
      data-unit="<?= unit_active_id() ?>" data-edit-id="<?= (int)$periodEditId ?>"
      data-choice="<?= htmlspecialchars($periodChoice,ENT_QUOTES,'UTF-8') ?>">
    <?php foreach (($periodEditId ? array_slice($periodOptions,1,true) : $periodOptions) as $value=>$label): ?>
      <option value="<?= $value ?>"<?= $periodMode === $value ? ' selected' : '' ?>><?= $label ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="payment-range-fields" data-payment-period-controls hidden>
  <div class="payment-range-start" data-payment-period-start hidden>
    <span class="field-label">Bulan Awal</span>
    <div class="payment-range-start-value">
      <?php if (!$periodEditId): ?>
      <svg class="payment-control-icon payment-period-calendar" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg>
      <?php endif; ?>
      <output aria-label="Bulan Awal" data-payment-period-oldest>—</output>
    </div>
  </div>
  <?php if (!$periodEditId): ?>
  <span class="payment-range-direction" aria-hidden="true">→</span>
  <?php endif; ?>
  <div class="payment-range-end">
    <label class="field-label" for="payment-period-choice" data-payment-period-choice-label>Bulan Akhir</label>
    <div class="payment-period-choice-control">
      <?php if (!$periodEditId): ?>
      <svg class="payment-control-icon payment-period-calendar" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg>
      <?php endif; ?>
      <select class="field-input field-select" id="payment-period-choice" name="period_choice" data-dropdown-label="Periode Tagihan" disabled>
        <option value="">Pilih siswa dahulu</option>
      </select>
    </div>
  </div>
</div>
<input type="hidden" name="period_version" data-payment-period-version value="">
