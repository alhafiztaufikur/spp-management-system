param(
    [string]$Database = 'db_spp_audit_20260820_090000',
    [int]$Port = 8099,
    [string]$DatabaseUser = $env:SPP_TEST_DB_USER,
    [string]$EvidenceRoot = 'C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0'
)

$ErrorActionPreference = 'Stop'
$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

if ($Database -notmatch '^db_spp_audit_[0-9_]+(?:_suite_[0-9_]+)?$') {
    throw 'Audit server refuses a database name outside the audit naming convention.'
}
if ($Database -match '_suite_' -and [string]$env:SPP_TEST_DISPOSABLE_SUITE -ne '1') {
    throw 'Disposable suite server requires SPP_TEST_DISPOSABLE_SUITE=1.'
}
if ([string]::IsNullOrWhiteSpace($DatabaseUser) -or $DatabaseUser -ne 'spp_audit_local') {
    throw 'Audit server requires the least-privilege spp_audit_local database user.'
}

$configJson = & C:\xampp\php\php.exe -r "`$config=require '$($workspace.Replace('\', '/'))/config/app.local.php'; echo json_encode(['user'=>`$config['db_user'],'pass'=>`$config['db_pass']]);"
$config = $configJson | ConvertFrom-Json
if ([string]::IsNullOrWhiteSpace([string]$config.user)) {
    throw 'Local audit database user could not be loaded.'
}

$env:SPP_APP_ENV = 'test'
$env:SPP_DB_HOST = '127.0.0.1'
$env:SPP_DB_PORT = '3306'
$env:SPP_DB_USER = $DatabaseUser
$env:SPP_DB_PASS = [string]$config.pass
$env:SPP_DB_NAME = $Database

$stdout = Join-Path $EvidenceRoot 'php-audit-server.stdout.log'
$stderr = Join-Path $EvidenceRoot 'php-audit-server.stderr.log'
$pidFile = Join-Path $EvidenceRoot 'php-audit-server.pid'

foreach ($path in @($stdout, $stderr, $pidFile)) {
    if (Test-Path -LiteralPath $path) {
        throw "Refusing to overwrite existing audit server artifact: $path"
    }
}

$process = Start-Process -FilePath 'C:\xampp\php\php.exe' `
    -ArgumentList @('-S', "127.0.0.1:$Port", '-t', $workspace, (Join-Path $PSScriptRoot 'router.php')) `
    -WorkingDirectory $workspace `
    -WindowStyle Hidden `
    -RedirectStandardOutput $stdout `
    -RedirectStandardError $stderr `
    -PassThru

$process.Id | Set-Content -LiteralPath $pidFile -Encoding ascii

$ready = $false
for ($attempt = 0; $attempt -lt 20; $attempt++) {
    Start-Sleep -Milliseconds 300
    try {
        $response = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/login.php" -UseBasicParsing -TimeoutSec 2
        if ([int]$response.StatusCode -eq 200) {
            $ready = $true
            break
        }
    } catch {
        # Continue polling until the bounded attempt count is exhausted.
    }
}

if (-not $ready) {
    Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
    throw "Audit server failed to become ready on port $Port."
}

Write-Output "AUDIT_SERVER_PID=$($process.Id)"
Write-Output "AUDIT_SERVER_URL=http://127.0.0.1:$Port"
Write-Output "AUDIT_DATABASE=$Database"
