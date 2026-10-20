<?php
session_start();require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/auth.php';requireRole(['admin','kasir']);require __DIR__.'/savings_owner_entry.php';
