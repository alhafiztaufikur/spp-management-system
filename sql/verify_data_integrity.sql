-- =========================================================
-- SistemSPP - Verifikasi integritas data finansial (read-only)
-- Database: wajib dipilih eksplisit oleh caller (`mysql --database=<target>`).
--
-- File ini HANYA menjalankan SELECT/CTE. Tidak ada INSERT, UPDATE,
-- DELETE, DDL, procedure, temporary table, backfill, atau rekonsiliasi.
-- Kontrak: documentation/audit/DATA_CONTRACT.md
-- =========================================================

SELECT
  DATABASE() AS database_name,
  VERSION() AS database_version,
  NOW() AS verified_at,
  @@tx_isolation AS transaction_isolation,
  @@sql_mode AS sql_mode;

WITH
du_by_payment AS (
  SELECT `bayar_id`, SUM(COALESCE(`jumlah`, 0)) AS total_du
  FROM `bayar_du`
  WHERE `bayar_id` IS NOT NULL
  GROUP BY `bayar_id`
),
other_by_payment AS (
  SELECT `bayar_id`, SUM(COALESCE(`nominal_snapshot`, 0)) AS total_other
  FROM `bayar_biaya_lain`
  GROUP BY `bayar_id`
),
initial_paid AS (
  SELECT
    `NO_INDUK`,
    SUM(COALESCE(`U_PANGKAL`, 0)) AS pangkal,
    SUM(COALESCE(`U_BANGUNAN`, 0)) AS bangunan,
    SUM(COALESCE(`U_SERAGAM`, 0)) AS seragam,
    SUM(COALESCE(`U_KEGIATAN`, 0)) AS kegiatan
  FROM `bayar`
  GROUP BY `NO_INDUK`
),
savings_ledger AS (
  SELECT `NO_INDUK`, SUM(`delta`) AS ledger_balance
  FROM (
    SELECT `NO_INDUK`, COALESCE(`MASUK`, 0) AS delta
    FROM `transaksi_m`
    WHERE `bayar_id` IS NULL
    UNION ALL
    SELECT `NO_INDUK`, -COALESCE(`KELUAR`, 0) AS delta
    FROM `transaksi_k`
  ) ledger_rows
  GROUP BY `NO_INDUK`
),
checks AS (
  SELECT
    'INV-001' AS invariant_id,
    'CRITICAL' AS severity,
    'Versi link pembayaran dan isolasi legacy valid' AS invariant_name,
    COUNT(*) AS issue_count,
    'Versi selain 0/1 atau header legacy memiliki child DU/tabungan ber-bayar_id.' AS evidence_rule
  FROM `bayar` b
  WHERE b.`payment_link_version` NOT IN (0, 1)
     OR (
       b.`payment_link_version` = 0
       AND (
         EXISTS (SELECT 1 FROM `bayar_du` d WHERE d.`bayar_id` = b.`id`)
         OR EXISTS (SELECT 1 FROM `transaksi_m` tm WHERE tm.`bayar_id` = b.`id`)
       )
     )

  UNION ALL

  SELECT
    'INV-002', 'CRITICAL', 'Total pembayaran sama dengan komponen resmi',
    COUNT(*),
    'Diperiksa untuk payment_link_version=1 dengan toleransi Rp0,01; U_LAIN tidak dihitung dua kali.'
  FROM `bayar` b
  LEFT JOIN du_by_payment d ON d.`bayar_id` = b.`id`
  LEFT JOIN other_by_payment o ON o.`bayar_id` = b.`id`
  WHERE b.`payment_link_version` = 1
    AND ABS(
      COALESCE(b.`total_jumlah`, 0) - (
        COALESCE(b.`U_PANGKAL`, 0) + COALESCE(b.`U_BANGUNAN`, 0)
        + COALESCE(b.`U_SERAGAM`, 0) + COALESCE(b.`U_KEGIATAN`, 0)
        + COALESCE(b.`U_SPP`, 0) + COALESCE(b.`U_MAKAN`, 0)
        + COALESCE(b.`U_SORGA`, 0) + COALESCE(b.`U_INFAQ`, 0)
        + COALESCE(b.`U_KOMITE`, 0) + COALESCE(d.`total_du`, 0)
        + COALESCE(o.`total_other`, 0) - COALESCE(b.`potong_spp`, 0)
      )
    ) > 0.01

  UNION ALL

  SELECT
    'INV-003', 'CRITICAL', 'Setiap pembayaran SPP mempunyai claim periode',
    COUNT(*),
    'Setiap bayar.U_SPP>0 harus mempunyai tepat satu bayar_spp_periode.'
  FROM `bayar` b
  WHERE COALESCE(b.`U_SPP`, 0) > 0.01
    AND (SELECT COUNT(*) FROM `bayar_spp_periode` p WHERE p.`bayar_id` = b.`id`) <> 1

  UNION ALL

  SELECT
    'INV-004', 'CRITICAL', 'Claim SPP cocok dengan identitas header',
    COUNT(*),
    'NIS, tahun, bulan ternormalisasi, dan nominal SPP harus konsisten.'
  FROM `bayar_spp_periode` p
  LEFT JOIN `bayar` b ON b.`id` = p.`bayar_id`
  WHERE b.`id` IS NULL
     OR COALESCE(b.`U_SPP`, 0) <= 0.01
     OR p.`no_induk` <> b.`NO_INDUK`
     OR p.`tahun` <> b.`TAHUN`
     OR p.`bulan` <> CASE LOWER(TRIM(COALESCE(b.`BULAN`, '')))
       WHEN 'januari' THEN '01' WHEN 'februari' THEN '02'
       WHEN 'maret' THEN '03' WHEN 'april' THEN '04'
       WHEN 'mei' THEN '05' WHEN 'juni' THEN '06'
       WHEN 'juli' THEN '07' WHEN 'agustus' THEN '08'
       WHEN 'september' THEN '09' WHEN 'oktober' THEN '10'
       WHEN 'november' THEN '11' WHEN 'desember' THEN '12'
       ELSE LPAD(CAST(COALESCE(b.`BULAN`, 0) AS UNSIGNED), 2, '0')
     END

  UNION ALL

  SELECT
    'INV-005', 'CRITICAL', 'Child DU cocok dengan header pembayaran aman',
    COUNT(*),
    'Child dengan bayar_id harus menunjuk header versi 1 dan NIS yang sama.'
  FROM `bayar_du` d
  LEFT JOIN `bayar` b ON b.`id` = d.`bayar_id`
  WHERE d.`bayar_id` IS NOT NULL
    AND (b.`id` IS NULL OR b.`payment_link_version` <> 1 OR d.`no_induk` <> b.`NO_INDUK`)

  UNION ALL

  SELECT
    'INV-006', 'CRITICAL', 'Child DU cocok dengan tagihan DU',
    COUNT(*),
    'DU modern wajib bertagihan; NIS, kelas, dan tahun snapshot harus sama.'
  FROM `bayar_du` d
  LEFT JOIN `tagihan_daftar_ulang` t ON t.`id` = d.`tagihan_daftar_ulang_id`
  WHERE (d.`bayar_id` IS NOT NULL AND d.`tagihan_daftar_ulang_id` IS NULL)
     OR (
       d.`tagihan_daftar_ulang_id` IS NOT NULL
       AND (
         t.`id` IS NULL OR d.`no_induk` <> t.`no_induk`
         OR d.`kelas` <> t.`kelas_snapshot`
         OR d.`th_ajaran` <> t.`tahun_ajaran_snapshot`
       )
     )

  UNION ALL

  SELECT
    'INV-007', 'CRITICAL', 'Tagihan DU tidak terbayar lebih',
    COUNT(*),
    'Jumlah seluruh child per tagihan tidak boleh melebihi nominal_tagihan.'
  FROM (
    SELECT t.`id`
    FROM `tagihan_daftar_ulang` t
    LEFT JOIN `bayar_du` d ON d.`tagihan_daftar_ulang_id` = t.`id`
    GROUP BY t.`id`, t.`nominal_tagihan`
    HAVING SUM(COALESCE(d.`jumlah`, 0)) > t.`nominal_tagihan` + 0.01
  ) overpaid_du

  UNION ALL

  SELECT
    'INV-008', 'CRITICAL', 'Detail Biaya Lain cocok dengan tagihan dan header',
    COUNT(*),
    'Detail bertagihan harus cocok pada siswa dan master biaya.'
  FROM `bayar_biaya_lain` d
  JOIN `bayar` b ON b.`id` = d.`bayar_id`
  LEFT JOIN `tagihan_biaya_lain` t ON t.`id` = d.`tagihan_biaya_lain_id`
  WHERE d.`tagihan_biaya_lain_id` IS NOT NULL
    AND (
      t.`id` IS NULL OR t.`no_induk` <> b.`NO_INDUK`
      OR d.`master_biaya_lain_id` IS NULL
      OR d.`master_biaya_lain_id` <> t.`master_biaya_lain_id`
    )

  UNION ALL

  SELECT
    'INV-009', 'CRITICAL', 'Tagihan Biaya Lain tidak terbayar lebih',
    COUNT(*),
    'Jumlah detail per tagihan tidak boleh melebihi nominal_tagihan.'
  FROM (
    SELECT t.`id`
    FROM `tagihan_biaya_lain` t
    LEFT JOIN `bayar_biaya_lain` d ON d.`tagihan_biaya_lain_id` = t.`id`
    GROUP BY t.`id`, t.`nominal_tagihan`
    HAVING SUM(COALESCE(d.`nominal_snapshot`, 0)) > t.`nominal_tagihan` + 0.01
  ) overpaid_other

  UNION ALL

  SELECT
    'INV-010', 'CRITICAL', 'Cache pembayaran awal sama dengan ledger bayar',
    COUNT(*),
    'PANGKAL/BANGUNAN/SERAGAM/KEGIATAN_BAYAR harus sama dengan SUM komponen.'
  FROM `siswa` s
  LEFT JOIN initial_paid p ON p.`NO_INDUK` = s.`NO_INDUK`
  WHERE ABS(COALESCE(s.`PANGKAL_BAYAR`, 0) - COALESCE(p.`pangkal`, 0)) > 0.01
     OR ABS(COALESCE(s.`BANGUNAN_BAYAR`, 0) - COALESCE(p.`bangunan`, 0)) > 0.01
     OR ABS(COALESCE(s.`SERAGAM_BAYAR`, 0) - COALESCE(p.`seragam`, 0)) > 0.01
     OR ABS(COALESCE(s.`KEGIATAN_BAYAR`, 0) - COALESCE(p.`kegiatan`, 0)) > 0.01

  UNION ALL

  SELECT
    'INV-011', 'CRITICAL', 'Saldo tabungan sama dengan ledger manual',
    COUNT(*),
    'SALDO harus sama dengan setoran manual dikurangi penarikan.'
  FROM `siswa` s
  LEFT JOIN `tabungan` t ON t.`NO_INDUK` = s.`NO_INDUK`
  LEFT JOIN savings_ledger l ON l.`NO_INDUK` = s.`NO_INDUK`
  WHERE ABS(COALESCE(t.`SALDO`, 0) - COALESCE(l.`ledger_balance`, 0)) > 0.01

  UNION ALL

  SELECT
    'INV-012', 'CRITICAL', 'Tidak ada tabungan terkait pembayaran',
    COUNT(*),
    'Alur aktif hanya menerima transaksi_m manual dengan bayar_id NULL.'
  FROM `transaksi_m`
  WHERE `bayar_id` IS NOT NULL

  UNION ALL

  SELECT
    'INV-013', 'CRITICAL', 'Domain nilai uang valid',
    COUNT(*),
    'Nilai utama tidak boleh NULL/negatif; jurnal harus satu arah; diskon SPP tidak melebihi U_SPP.'
  FROM (
    SELECT CONCAT('bayar:', b.`id`) AS entity_key
    FROM `bayar` b
    WHERE b.`U_PANGKAL` IS NULL OR b.`U_PANGKAL` < 0
       OR b.`U_BANGUNAN` IS NULL OR b.`U_BANGUNAN` < 0
       OR b.`U_SERAGAM` IS NULL OR b.`U_SERAGAM` < 0
       OR b.`U_KEGIATAN` IS NULL OR b.`U_KEGIATAN` < 0
       OR b.`U_SPP` IS NULL OR b.`U_SPP` < 0
       OR b.`U_MAKAN` IS NULL OR b.`U_MAKAN` < 0
       OR b.`U_SORGA` IS NULL OR b.`U_SORGA` < 0
       OR b.`U_INFAQ` IS NULL OR b.`U_INFAQ` < 0
       OR b.`U_KOMITE` IS NULL OR b.`U_KOMITE` < 0
       OR b.`U_LAIN` IS NULL OR b.`U_LAIN` < 0
       OR b.`potong_spp` IS NULL OR b.`potong_spp` < 0
       OR b.`potong_spp` > b.`U_SPP` + 0.01
       OR b.`total_jumlah` IS NULL OR b.`total_jumlah` < 0
    UNION ALL
    SELECT CONCAT('bayar_du:', d.`id`) FROM `bayar_du` d
    WHERE d.`jumlah` IS NULL OR d.`jumlah` <= 0
    UNION ALL
    SELECT CONCAT('bayar_biaya_lain:', d.`id`) FROM `bayar_biaya_lain` d
    WHERE d.`nominal_snapshot` IS NULL OR d.`nominal_snapshot` <= 0
    UNION ALL
    SELECT CONCAT('tagihan_du:', t.`id`) FROM `tagihan_daftar_ulang` t
    WHERE t.`nominal_awal` IS NULL OR t.`nominal_awal` < 0
       OR t.`nominal_tagihan` IS NULL OR t.`nominal_tagihan` < 0
       OR t.`nominal_tagihan` > t.`nominal_awal` + 0.01
    UNION ALL
    SELECT CONCAT('tagihan_biaya_lain:', t.`id`) FROM `tagihan_biaya_lain` t
    WHERE t.`nominal_tagihan` IS NULL OR t.`nominal_tagihan` <= 0
    UNION ALL
    SELECT CONCAT('tabungan:', t.`id`) FROM `tabungan` t
    WHERE t.`SALDO` IS NULL OR t.`SALDO` < 0
    UNION ALL
    SELECT CONCAT('transaksi_m:', tm.`id`) FROM `transaksi_m` tm
    WHERE tm.`MASUK` IS NULL OR tm.`MASUK` <= 0 OR COALESCE(tm.`KELUAR`, 0) <> 0
    UNION ALL
    SELECT CONCAT('transaksi_k:', tk.`id`) FROM `transaksi_k` tk
    WHERE tk.`KELUAR` IS NULL OR tk.`KELUAR` <= 0 OR COALESCE(tk.`MASUK`, 0) <> 0
  ) invalid_money

  UNION ALL

  SELECT
    'INV-014', 'CRITICAL', 'Siswa aktif tercakup tahun ajaran published berjalan',
    COUNT(*),
    'Setiap siswa aktif wajib memiliki penempatan aktif dan tagihan DU open pada tahun yang mencakup CURDATE().' 
  FROM `siswa` s
  WHERE s.`is_active` = 1
    AND NOT EXISTS (
      SELECT 1
      FROM `tahun_ajaran` ta
      JOIN `siswa_tahun_ajaran` sta
        ON sta.`tahun_ajaran_id` = ta.`id`
       AND sta.`no_induk` = s.`NO_INDUK`
       AND sta.`status` = 'aktif'
      JOIN `tagihan_daftar_ulang` t
        ON t.`penempatan_id` = sta.`id`
       AND t.`status` = 'open'
      WHERE ta.`status` = 'published'
        AND CURDATE() BETWEEN ta.`tanggal_mulai` AND ta.`tanggal_selesai`
    )

  UNION ALL

  SELECT
    'INV-015', 'CRITICAL', 'Snapshot tagihan DU cocok dengan penempatan',
    COUNT(*),
    'NIS, kelas, tahun, dan snapshot wajib sama dengan parent penempatan/tahun.'
  FROM `tagihan_daftar_ulang` t
  LEFT JOIN `siswa_tahun_ajaran` sta ON sta.`id` = t.`penempatan_id`
  LEFT JOIN `tahun_ajaran` ta ON ta.`id` = t.`tahun_ajaran_id`
  WHERE sta.`id` IS NULL OR ta.`id` IS NULL
     OR t.`tahun_ajaran_id` <> sta.`tahun_ajaran_id`
     OR t.`no_induk` <> sta.`no_induk`
     OR t.`kelas_snapshot` <> sta.`kelas`
     OR t.`tahun_ajaran_snapshot` <> ta.`label`
     OR TRIM(COALESCE(sta.`kelas_rombel_snapshot`, '')) = ''

  UNION ALL

  SELECT
    'INV-016', 'CRITICAL', 'Rentang tahun ajaran tidak tumpang tindih',
    (
      SELECT COUNT(*)
      FROM `tahun_ajaran` a
      JOIN `tahun_ajaran` b
        ON a.`id` < b.`id`
       AND a.`tanggal_mulai` <= b.`tanggal_selesai`
       AND b.`tanggal_mulai` <= a.`tanggal_selesai`
    ) + GREATEST((
      SELECT COUNT(*)
      FROM `tahun_ajaran` ta
      WHERE ta.`status` = 'published'
        AND CURDATE() BETWEEN ta.`tanggal_mulai` AND ta.`tanggal_selesai`
    ) - 1, 0),
    'Tidak boleh ada pasangan rentang overlap atau lebih dari satu tahun published aktif pada tanggal audit.'

  UNION ALL

  SELECT
    'INV-017', 'HIGH', 'Operator transaksi dapat dipetakan',
    (
      SELECT COUNT(*)
      FROM `bayar` b
      WHERE b.`payment_link_version` = 1
        AND (
          TRIM(COALESCE(b.`user_id`, '')) = ''
          OR TRIM(b.`user_id`) NOT REGEXP '^[0-9]+$'
          OR NOT EXISTS (SELECT 1 FROM `admin` a WHERE CAST(a.`id` AS CHAR) = TRIM(b.`user_id`))
        )
    ) + (
      SELECT COUNT(*)
      FROM `transaksi_m` tm
      WHERE TRIM(COALESCE(tm.`user_id`, '')) REGEXP '^[0-9]+$'
        AND NOT EXISTS (SELECT 1 FROM `admin` a WHERE CAST(a.`id` AS CHAR) = TRIM(tm.`user_id`))
    ) + (
      SELECT COUNT(*)
      FROM `transaksi_k` tk
      WHERE TRIM(COALESCE(tk.`user_id`, '')) REGEXP '^[0-9]+$'
        AND NOT EXISTS (SELECT 1 FROM `admin` a WHERE CAST(a.`id` AS CHAR) = TRIM(tk.`user_id`))
    ),
    'Pembayaran versi 1 wajib memakai admin.id; ID numerik jurnal tidak boleh yatim.'
)
SELECT
  `invariant_id`,
  `severity`,
  CASE WHEN `issue_count` = 0 THEN 'PASS' ELSE 'FAIL' END AS `status`,
  `issue_count`,
  `invariant_name`,
  `evidence_rule`
FROM checks
ORDER BY CAST(SUBSTRING(`invariant_id`, 5) AS UNSIGNED);

-- Antrean compatibility/legacy berikut tidak mengubah status INV. Nilai lebih
-- dari nol berarti rekonsiliasi manusia masih diperlukan sebelum mutasi data.
SELECT
  queue_name,
  item_count,
  CASE WHEN item_count = 0 THEN 'CLEAR' ELSE 'REVIEW' END AS review_status
FROM (
  SELECT 'payment_link_version_0' AS queue_name, COUNT(*) AS item_count
  FROM `bayar` WHERE `payment_link_version` = 0
  UNION ALL
  SELECT 'bayar_du_without_bayar_id', COUNT(*)
  FROM `bayar_du` WHERE `bayar_id` IS NULL
  UNION ALL
  SELECT 'bayar_du_without_bill_id', COUNT(*)
  FROM `bayar_du` WHERE `tagihan_daftar_ulang_id` IS NULL
  UNION ALL
  SELECT 'other_fee_detail_without_bill_id', COUNT(*)
  FROM `bayar_biaya_lain` WHERE `tagihan_biaya_lain_id` IS NULL
  UNION ALL
  SELECT 'savings_operator_legacy_text', COUNT(*)
  FROM (
    SELECT `user_id` FROM `transaksi_m`
    UNION ALL
    SELECT `user_id` FROM `transaksi_k`
  ) savings_operators
  WHERE TRIM(COALESCE(`user_id`, '')) <> ''
    AND TRIM(`user_id`) NOT REGEXP '^[0-9]+$'
) legacy_queue
ORDER BY queue_name;

-- Detail dua drift yang langsung memengaruhi saldo/status laporan. Query ini
-- tetap read-only dan sengaja menampilkan identitas minimum untuk rekonsiliasi.
SELECT
  'INV-003' AS invariant_id,
  b.`id` AS entity_id,
  b.`NO_INDUK`,
  CONCAT('SPP ', COALESCE(b.`BULAN`, ''), ' ', COALESCE(b.`TAHUN`, ''),
         ' = ', FORMAT(COALESCE(b.`U_SPP`, 0), 2)) AS detail
FROM `bayar` b
LEFT JOIN `bayar_spp_periode` p ON p.`bayar_id` = b.`id`
WHERE COALESCE(b.`U_SPP`, 0) > 0.01
  AND p.`bayar_id` IS NULL
ORDER BY b.`id`;

WITH initial_paid_detail AS (
  SELECT
    `NO_INDUK`,
    SUM(COALESCE(`U_PANGKAL`, 0)) AS pangkal,
    SUM(COALESCE(`U_BANGUNAN`, 0)) AS bangunan,
    SUM(COALESCE(`U_SERAGAM`, 0)) AS seragam,
    SUM(COALESCE(`U_KEGIATAN`, 0)) AS kegiatan
  FROM `bayar`
  GROUP BY `NO_INDUK`
)
SELECT
  'INV-010' AS invariant_id,
  s.`id` AS entity_id,
  s.`NO_INDUK`,
  CONCAT(
    'cache=', FORMAT(COALESCE(s.`PANGKAL_BAYAR`, 0) + COALESCE(s.`BANGUNAN_BAYAR`, 0)
      + COALESCE(s.`SERAGAM_BAYAR`, 0) + COALESCE(s.`KEGIATAN_BAYAR`, 0), 2),
    '; ledger=', FORMAT(COALESCE(p.`pangkal`, 0) + COALESCE(p.`bangunan`, 0)
      + COALESCE(p.`seragam`, 0) + COALESCE(p.`kegiatan`, 0), 2)
  ) AS detail
FROM `siswa` s
LEFT JOIN initial_paid_detail p ON p.`NO_INDUK` = s.`NO_INDUK`
WHERE ABS(COALESCE(s.`PANGKAL_BAYAR`, 0) - COALESCE(p.`pangkal`, 0)) > 0.01
   OR ABS(COALESCE(s.`BANGUNAN_BAYAR`, 0) - COALESCE(p.`bangunan`, 0)) > 0.01
   OR ABS(COALESCE(s.`SERAGAM_BAYAR`, 0) - COALESCE(p.`seragam`, 0)) > 0.01
   OR ABS(COALESCE(s.`KEGIATAN_BAYAR`, 0) - COALESCE(p.`kegiatan`, 0)) > 0.01
ORDER BY s.`NO_INDUK`;
