$ErrorActionPreference = 'Stop'

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

$routeFiles = @(
    'index.php',
    'login.php',
    'logout.php',
    'dashboard.php',
    'role_management.php',
    'master_kelas.php',
    'master_biaya_lain.php',
    'master_daftar_ulang.php',
    'siswa/daftar.php',
    'pembayaran/form.php',
    'pembayaran/lihat.php',
    'pembayaran/edit.php',
    'pembayaran/proses.php',
    'pembayaran/riwayat_daftar_ulang.php',
    'tabungan/masuk.php',
    'tabungan/keluar.php',
    'tabungan/proses.php',
    'tabungan/riwayat.php',
    'tabungan/get_saldo.php',
    'laporan/index.php',
    'laporan/global.php',
    'laporan/template.php',
    'laporan/export_global.php',
    'laporan/rekap_kelas.php',
    'laporan/detail_siswa.php',
    'laporan/cetak_struk.php',
    'laporan/cetak_struk_tahunan.php',
    'laporan/export_excel.php',
    'laporan/export_pdf.php'
)

$roleGuardRoutes = $routeFiles | Where-Object {
    $_ -notin @('index.php', 'login.php', 'logout.php')
}

$literalRequirements = @(
    @{ File = 'login.php'; Patterns = @("security_csrf_token('login')", "security_csrf_is_valid('login'", 'security_rotate_after_login', 'login_rate_limit_lock') },
    @{ File = 'logout.php'; Patterns = @('security_require_post()', "security_require_csrf('logout')", 'security_destroy_session()') },
    @{ File = 'role_management.php'; Patterns = @("security_csrf_token('role-management')", "security_csrf_is_valid('role-management'") },
    @{ File = 'master_kelas.php'; Patterns = @("security_csrf_token('master-class')", "security_csrf_is_valid('master-class'") },
    @{ File = 'master_biaya_lain.php'; Patterns = @("security_csrf_token('master-other-fees')", "security_csrf_is_valid('master-other-fees'") },
    @{ File = 'master_daftar_ulang.php'; Patterns = @("security_csrf_token('master-registration')", "security_csrf_is_valid('master-registration'") },
    @{ File = 'siswa/daftar.php'; Patterns = @("security_csrf_token('student-master')", "security_csrf_is_valid('student-master'") },
    @{ File = 'pembayaran/form.php'; Patterns = @("security_csrf_token('payment')") },
    @{ File = 'pembayaran/edit.php'; Patterns = @("security_csrf_token('payment')") },
    @{ File = 'pembayaran/lihat.php'; Patterns = @("security_csrf_token('payment')") },
    @{ File = 'pembayaran/proses.php'; Patterns = @('security_require_post()', "security_require_csrf('payment')", 'security_input_scalar($_POST, ''aksi'')') },
    @{ File = 'tabungan/masuk.php'; Patterns = @("security_csrf_token('savings')") },
    @{ File = 'tabungan/keluar.php'; Patterns = @("security_csrf_token('savings')") },
    @{ File = 'tabungan/proses.php'; Patterns = @('security_require_post()', "security_require_csrf('savings')") },
    @{ File = 'tabungan/get_saldo.php'; Patterns = @("requireRoleJson(['admin', 'kasir'])", "`$_SERVER['REQUEST_METHOD']") }
)

$failures = [System.Collections.Generic.List[string]]::new()

foreach ($relative in $routeFiles) {
    $path = Join-Path $workspace $relative
    if (-not (Test-Path -LiteralPath $path)) {
        $failures.Add("missing route: $relative")
        continue
    }

    $source = Get-Content -Raw -LiteralPath $path
    if ($source -notmatch 'security_bootstrap_session\s*\(') {
        $failures.Add("missing security bootstrap: $relative")
    }
}

foreach ($relative in $roleGuardRoutes) {
    $path = Join-Path $workspace $relative
    if (-not (Test-Path -LiteralPath $path)) { continue }
    $source = Get-Content -Raw -LiteralPath $path
    if ($source -notmatch 'requireRole(?:Json)?\s*\(') {
        $failures.Add("missing backend role guard: $relative")
    }
}

foreach ($requirement in $literalRequirements) {
    $path = Join-Path $workspace $requirement.File
    if (-not (Test-Path -LiteralPath $path)) { continue }
    $source = Get-Content -Raw -LiteralPath $path
    foreach ($pattern in $requirement.Patterns) {
        if (-not $source.Contains($pattern)) {
            $failures.Add("missing contract literal in $($requirement.File): $pattern")
        }
    }
}

Write-Output "ROUTE_CONTRACT_EXPECTED=$($routeFiles.Count)"
Write-Output "ROUTE_CONTRACT_ROLE_GUARDED=$($roleGuardRoutes.Count)"
Write-Output "ROUTE_CONTRACT_LITERAL_GROUPS=$($literalRequirements.Count)"

if ($failures.Count -gt 0) {
    $failures | ForEach-Object { Write-Output "ROUTE_CONTRACT_FAILURE=$_" }
    Write-Output 'ROUTE_SECURITY_CONTRACT=FAIL'
    exit 1
}

Write-Output 'ROUTE_SECURITY_CONTRACT=PASS'
