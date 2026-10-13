<?php
/** Shared progress display; this renderer does not access or change a database. */
function database_restore_progress_ui(): void { ?>
  <section id="restore-progress-panel" class="restore-progress-panel" aria-label="Tahapan pemulihan" hidden>
    <div class="restore-progress-heading"><strong id="restore-stage-label">Memeriksa SQL</strong><span id="restore-stage-count">Tahap 1 dari 4</span></div>
    <progress id="restore-progress-bar" max="4" aria-label="Tahapan pemulihan database"></progress>
    <ol class="restore-stage-list"><li data-restore-step="0">Periksa SQL</li><li data-restore-step="1">Backup pengaman</li><li data-restore-step="2">Pulihkan data</li><li data-restore-step="3">Verifikasi hasil</li></ol>
    <p id="restore-unavailable" role="status" aria-live="polite">File harus lolos pemeriksaan sebelum dikonfirmasi.</p>
  </section>
  <section id="restore-result" class="restore-result" role="status" aria-live="polite" hidden><strong id="restore-result-title"></strong><p id="restore-result-message"></p><span id="restore-result-time"></span></section>
<?php }
function database_restore_confirmation_ui(): void { ?>
  <div id="restore-confirm-fields" hidden>
    <label class="dbt-checkbox"><input type="checkbox" id="restore-understood"> Saya memahami bahwa seluruh data akan diganti</label>
    <label class="dbt-label" for="restore-confirmation">Ketik <strong>PULIHKAN DATABASE</strong></label>
    <input type="text" id="restore-confirmation" autocomplete="off" placeholder="PULIHKAN DATABASE">
  </div>
<?php }
