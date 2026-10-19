<?php
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin']);
http_response_code(410);
echo 'Alur koreksi PSB sudah dihapus. Ubah Kelas/Rombel langsung melalui Data Siswa.';
