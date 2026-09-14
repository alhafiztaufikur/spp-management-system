USE `db_spp`;

START TRANSACTION;

INSERT INTO siswa (
  NO_INDUK, NO_induk_diknas, NAMA, KELAS, SPP_PERBULAN,
  PANGKAL, PSB, asal_psb, POMG, DAFTAR_ULANG, tot_pangkal, tot_du
) VALUES
('2026111', 'D260111', 'Andika Pratama Demo',    '1', 250000, 1000000, 0, 0, 100000, 1000000, 1000000, 1000000),
('2026112', 'D260112', 'Mira Aulia Demo',        '1', 250000, 1000000, 0, 0, 100000, 1000000, 1000000, 1000000),
('2026113', 'D260113', 'Rizky Ramadhan Demo',    '2', 260000, 1100000, 0, 0, 110000, 1100000, 1100000, 1100000),
('2026114', 'D260114', 'Tiara Safitri Demo',     '2', 260000, 1100000, 0, 0, 110000, 1100000, 1100000, 1100000),
('2026115', 'D260115', 'Gilang Saputra Demo',    '3', 275000, 1200000, 0, 0, 120000, 1200000, 1200000, 1200000),
('2026116', 'D260116', 'Putri Amelia Demo',      '3', 275000, 1200000, 0, 0, 120000, 1200000, 1200000, 1200000),
('2026117', 'D260117', 'Raka Firmansyah Demo',   '4', 290000, 1300000, 0, 0, 130000, 1300000, 1300000, 1300000),
('2026118', 'D260118', 'Zahra Nuraini Demo',     '4', 290000, 1300000, 0, 0, 130000, 1300000, 1300000, 1300000),
('2026119', 'D260119', 'Hafiz Alfarizi Demo',    '5', 305000, 1400000, 0, 0, 140000, 1400000, 1400000, 1400000),
('2026120', 'D260120', 'Laras Puspita Demo',     '5', 305000, 1400000, 0, 0, 140000, 1400000, 1400000, 1400000),
('2026121', 'D260121', 'Naufal Akbar Demo',      '5', 305000, 1400000, 0, 0, 140000, 1400000, 1400000, 1400000),
('2026122', 'D260122', 'Sabrina Fitri Demo',     '6', 320000, 1500000, 0, 0, 150000, 1500000, 1500000, 1500000),
('2026123', 'D260123', 'Arkan Maulana Demo',     '6', 320000, 1500000, 0, 0, 150000, 1500000, 1500000, 1500000),
('2026124', 'D260124', 'Nadya Khairunnisa Demo', '6', 320000, 1500000, 0, 0, 150000, 1500000, 1500000, 1500000)
ON DUPLICATE KEY UPDATE NO_INDUK = VALUES(NO_INDUK);

INSERT INTO siswa_tahun_ajaran (tahun_ajaran_id, no_induk, kelas, spp_covered_by_psb, status)
SELECT ta.id, s.NO_INDUK, s.KELAS, 0, 'aktif'
FROM siswa s
JOIN tahun_ajaran ta ON ta.label = '2026/2027'
WHERE s.NO_INDUK BETWEEN '2026111' AND '2026124'
ON DUPLICATE KEY UPDATE kelas = VALUES(kelas), spp_covered_by_psb=0, status = VALUES(status);

INSERT INTO tagihan_daftar_ulang (
  tahun_ajaran_id, penempatan_id, master_daftar_ulang_id,
  no_induk, kelas_snapshot, tahun_ajaran_snapshot, nominal_awal, nominal_tagihan
)
SELECT ta.id, sta.id, du.id, s.NO_INDUK, s.KELAS, ta.label,
       COALESCE(NULLIF(s.DAFTAR_ULANG, 0), du.Jumlah, 0),
       COALESCE(NULLIF(s.tot_du, 0), NULLIF(s.DAFTAR_ULANG - s.potong_du, 0), du.Jumlah, 0)
FROM siswa s
JOIN tahun_ajaran ta ON ta.label = '2026/2027'
JOIN siswa_tahun_ajaran sta ON sta.tahun_ajaran_id = ta.id AND sta.no_induk = s.NO_INDUK
LEFT JOIN Daftar_ulang du ON du.tahun_ajaran_id = ta.id AND du.kelas = s.KELAS
WHERE s.NO_INDUK BETWEEN '2026111' AND '2026124'
ON DUPLICATE KEY UPDATE tagihan_daftar_ulang.id = tagihan_daftar_ulang.id;

COMMIT;
