<?php
// ============================================
// login.php — Split Layout (referensi myEdlinks)
// ============================================
session_start();
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once 'koneksi.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $koneksi->prepare("SELECT id, nama, password, role FROM admin WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin  = $result->fetch_assoc();
        $stmt->close();

        $passwordValid = false;
        $legacyMd5     = false;

        if ($admin) {
            $storedPassword = (string)$admin['password'];
            $passwordInfo   = password_get_info($storedPassword);

            if (!empty($passwordInfo['algo'])) {
                $passwordValid = password_verify($password, $storedPassword);
            } elseif (preg_match('/^[a-f0-9]{32}$/i', $storedPassword)) {
                // Kompatibilitas akun lama. Hash akan langsung ditingkatkan setelah login.
                $passwordValid = hash_equals(strtolower($storedPassword), md5($password));
                $legacyMd5     = $passwordValid;
            }
        }

        if ($admin && $passwordValid) {
            if ($legacyMd5 || password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $update  = $koneksi->prepare("UPDATE admin SET password = ? WHERE id = ?");
                $update->bind_param('si', $newHash, $admin['id']);
                $update->execute();
                $update->close();
            }

            session_regenerate_id(true);
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_nama'] = $admin['nama'];
            $_SESSION['admin_role'] = $admin['role'];

            if ($admin['role'] === 'kasir') {
                $loginRedirect = 'tabungan/masuk.php';
            } elseif ($admin['role'] === 'bendahara') {
                $loginRedirect = 'laporan/index.php';
            } else {
                $loginRedirect = 'dashboard.php';
            }
            header('Location: ' . $loginRedirect);
            exit;
        } else {
            $error = 'Username atau password salah!';
        }
    } else {
        $error = 'Username dan password wajib diisi!';
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png?v=2" />
  <meta name="description" content="Login admin sistem pembayaran SPP sekolah — kelola pembayaran siswa dengan mudah." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="assets/css/style.css?v=9.6" />
  <link rel="stylesheet" href="assets/css/login.css?v=3.5" />
</head>
<body class="login-split-body">

  <!-- ── FLOATING CARD ─────────────────── -->
  <div class="login-card-wrap">

  <!-- ── LEFT PANEL ──────────────────────── -->
  <div class="login-left" id="login-left">

    <!-- Top badge -->
    <div class="left-topbar">
      <div class="left-brand">
        <div class="brand-icon brand-logo-wrap">
          <img src="assets/img/school-logo.png" alt="Logo Mutiara Hikmah" class="brand-logo-img" />
        </div>
        <span class="brand-name-left">SistemSPP</span>
      </div>
      <span class="left-version-badge">v2.0</span>
    </div>

    <!-- School portal introduction -->
    <div class="left-hero">
      <div class="left-feature-icon" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          <polyline points="9 12 11 14 15 10"/>
        </svg>
      </div>
      <div class="left-tagline">
        <h1>
          Portal Pembayaran<br/>
          <span class="tagline-accent">SPP Sekolah</span>
        </h1>
        <p class="left-desc">
          Kelola pembayaran siswa, tabungan, dan laporan keuangan sekolah dalam satu sistem yang aman dan terintegrasi.
        </p>
      </div>

      <div class="left-school-card">
        <img src="assets/img/school-logo.png" alt="Logo Mutiara Hikmah" class="left-school-logo" />
        <div>
          <strong>SistemSPP</strong>
          <span>Portal administrasi sekolah</span>
        </div>
      </div>
    </div>

    <p class="left-footer">&copy; <?= date('Y') ?> SistemSPP. Sistem administrasi sekolah.</p>

  </div><!-- /login-left -->

  <!-- ── RIGHT PANEL ─────────────────────── -->
  <div class="login-right" id="login-right">

    <!-- Theme toggle -->
    <button class="login-theme-btn" id="login-theme-btn" onclick="toggleTheme()" title="Ganti tema">
      <span class="theme-icon" id="login-theme-icon">☀️</span>
      <span id="login-theme-label">Mode Terang</span>
    </button>

    <div class="right-inner">

      <!-- Brand (mobile only) -->
      <div class="right-brand-mobile">
        <div class="brand-icon brand-logo-wrap">
          <img src="assets/img/school-logo.png" alt="Logo Mutiara Hikmah" class="brand-logo-img" />
        </div>
        <span class="brand-name">SistemSPP</span>
      </div>

      <!-- Greeting -->
      <div class="right-greeting">
        <h2>Hai, selamat datang! 👋</h2>
        <p>Belum punya akun? <a href="#" class="link-accent" onclick="return false">Hubungi Admin</a></p>
      </div>

      <!-- Error Alert -->
      <?php if ($error): ?>
      <div class="alert alert-error right-alert" id="flash-msg">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <!-- Form -->
      <form method="POST" action="login.php" class="right-form" id="form-login">

        <div class="rfield-group">
          <label class="rfield-label" for="username">Username</label>
          <div class="rfield-wrap">
            <svg class="rfield-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input class="rfield-input" type="text" id="username" name="username"
              placeholder="Contoh: admin@sekolah.com"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              required autocomplete="username" />
          </div>
        </div>

        <div class="rfield-group">
          <div class="rfield-label-row">
            <label class="rfield-label" for="password">Password</label>
            <a href="#" class="link-muted" onclick="return false">Lupa kata sandi?</a>
          </div>
          <div class="rfield-wrap">
            <svg class="rfield-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input class="rfield-input" type="password" id="password" name="password"
              placeholder="Masukkan kata sandi"
              required autocomplete="current-password" />
            <button type="button" class="rfield-eye" onclick="togglePw()" id="btn-toggle-pw" title="Tampilkan password">
              <svg id="pw-eye-open" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg id="pw-eye-closed" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
        </div>

        <!-- Remember -->
        <label class="remember-row" for="remember">
          <input type="checkbox" id="remember" name="remember" class="remember-chk" />
          <span class="remember-custom"></span>
          <span class="remember-label">Ingat perangkat ini</span>
        </label>

        <button type="submit" class="btn-login-main" id="btn-login">
          <span>Masuk</span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
        </button>

      </form>

      <!-- Terms -->
      <p class="terms-text">
        Dengan melanjutkan, kamu menerima
        <a href="#" class="link-accent" onclick="return false">Syarat Penggunaan</a> dan
        <a href="#" class="link-accent" onclick="return false">Kebijakan Privasi</a> kami.
      </p>

      <!-- Hint -->
      <p class="login-hint-bottom">Default: <code>admin</code> / <code>admin123</code></p>

    </div><!-- /right-inner -->
  </div><!-- /login-right -->

  </div><!-- /login-card-wrap -->

  <script src="assets/js/app.js?v=2.8"></script>
  <script>
    // Override togglePw for split layout
    function togglePw() {
      const pw   = document.getElementById('password');
      const open = document.getElementById('pw-eye-open');
      const cls  = document.getElementById('pw-eye-closed');
      if (pw.type === 'password') {
        pw.type = 'text';
        open.style.display = 'none';
        cls.style.display  = 'block';
      } else {
        pw.type = 'password';
        open.style.display = 'block';
        cls.style.display  = 'none';
      }
    }

  </script>

</body>
</html>
