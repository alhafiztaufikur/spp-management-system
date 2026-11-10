-- Read-only checks after the CLI installer. Every conflict count should be zero.
SELECT COUNT(*) AS invalid_open_periods
FROM student_out_periods_data o
LEFT JOIN siswa_data s ON s.id=o.student_id AND s.unit_id=o.unit_id
WHERE s.id IS NULL OR DAY(o.first_free_month)<>1
   OR (o.returned_at IS NULL AND o.resume_month IS NOT NULL)
   OR (o.returned_at IS NOT NULL AND (o.resume_month IS NULL OR o.returned_at<o.departed_at));

SELECT unit_id,COUNT(*) AS periods,SUM(returned_at IS NULL) AS currently_out
FROM student_out_periods_data GROUP BY unit_id ORDER BY unit_id;

SELECT 'spp' AS component,COUNT(*) AS invalid_cancellation
FROM tagihan_spp_data t JOIN student_out_periods_data o ON o.id=t.out_period_id
JOIN siswa_data s ON s.id=o.student_id AND s.unit_id=o.unit_id
WHERE t.unit_id<>o.unit_id OR t.no_induk<>s.NO_INDUK OR t.status<>'cancelled'
UNION ALL
SELECT 'komite',COUNT(*) FROM tagihan_komite_data t
JOIN student_out_periods_data o ON o.id=t.out_period_id
JOIN siswa_data s ON s.id=o.student_id AND s.unit_id=o.unit_id
WHERE t.unit_id<>o.unit_id OR t.no_induk<>s.NO_INDUK OR t.status<>'cancelled'
UNION ALL
SELECT 'du',COUNT(*) FROM tagihan_daftar_ulang_data t
JOIN student_out_periods_data o ON o.id=t.out_period_id
JOIN siswa_data s ON s.id=o.student_id AND s.unit_id=o.unit_id
WHERE t.unit_id<>o.unit_id OR t.no_induk<>s.NO_INDUK OR t.status<>'cancelled';
