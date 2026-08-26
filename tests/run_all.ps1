param(
    [Parameter(Mandatory = $true)]
    [string]$Database,
    [string]$BaseUrl = 'http://127.0.0.1:8099',
    [string]$DatabaseUser = $env:SPP_TEST_DB_USER,
    [string]$AuditUsername = $env:SPP_TEST_ADMIN_USERNAME,
    [string]$AuditPassword = $env:SPP_TEST_ADMIN_PASSWORD
)

$ErrorActionPreference = 'Stop'
$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

if ($Database -notmatch '^db_spp_audit_[0-9_]+_suite_[0-9_]+$') {
    throw 'Full regression runner only accepts a disposable database name matching db_spp_audit_<digits>_suite_<digits>.'
}
if ([string]$env:SPP_TEST_DISPOSABLE_SUITE -ne '1') {
    throw 'Full regression runner requires SPP_TEST_DISPOSABLE_SUITE=1 from the isolated wrapper.'
}
if ([string]::IsNullOrWhiteSpace($AuditUsername) -or [string]::IsNullOrWhiteSpace($AuditPassword)) {
    throw 'Audit application credentials must be supplied through SPP_TEST_ADMIN_USERNAME and SPP_TEST_ADMIN_PASSWORD.'
}
if ([string]::IsNullOrWhiteSpace($DatabaseUser) -or $DatabaseUser -ne 'spp_audit_local') {
    throw 'Full regression runner requires the least-privilege spp_audit_local database user.'
}

$dbConfigJson = & C:\xampp\php\php.exe -r "`$config=require '$($workspace.Replace('\', '/'))/config/app.local.php'; echo json_encode(['user'=>`$config['db_user'],'pass'=>`$config['db_pass']]);"
$dbConfig = $dbConfigJson | ConvertFrom-Json
if ([string]::IsNullOrWhiteSpace([string]$dbConfig.user)) {
    throw 'Local audit database user could not be loaded.'
}

$env:SPP_APP_ENV = 'test'
$env:SPP_DB_HOST = '127.0.0.1'
$env:SPP_DB_PORT = '3306'
$env:SPP_DB_USER = $DatabaseUser
$env:SPP_DB_PASS = [string]$dbConfig.pass
$env:SPP_DB_NAME = $Database
$env:SPP_TEST_BASE_URL = $BaseUrl.TrimEnd('/')
$env:SPP_TEST_ADMIN_USERNAME = $AuditUsername
$env:SPP_TEST_ADMIN_PASSWORD = $AuditPassword

try {
    $response = Invoke-WebRequest -Uri ($env:SPP_TEST_BASE_URL + '/login.php') -UseBasicParsing -TimeoutSec 5
    if ([int]$response.StatusCode -ne 200) {
        throw "Audit application returned HTTP $([int]$response.StatusCode)."
    }
} catch {
    throw "Audit application is not ready at $($env:SPP_TEST_BASE_URL): $($_.Exception.Message)"
}

$tests = Get-ChildItem -LiteralPath $PSScriptRoot -File -Filter '*_test.php' | Sort-Object Name
if (-not $tests) {
    throw 'No regression tests were discovered.'
}

$failures = @()
$startedAt = Get-Date

foreach ($test in $tests) {
    Write-Output "[RUN] $($test.Name)"
    & C:\xampp\php\php.exe $test.FullName
    if ($LASTEXITCODE -ne 0) {
        $failures += $test.Name
        Write-Output "[FAIL] $($test.Name)"
    } else {
        Write-Output "[PASS] $($test.Name)"
    }
}

$duration = [math]::Round(((Get-Date) - $startedAt).TotalSeconds, 2)
Write-Output "TEST_DATABASE=$Database"
Write-Output "TEST_COUNT=$($tests.Count)"
Write-Output "FAILURE_COUNT=$($failures.Count)"
Write-Output "DURATION_SECONDS=$duration"

if ($failures) {
    Write-Error ('Regression failures: ' + ($failures -join ', '))
    exit 1
}

Write-Output 'REGRESSION_SUITE=PASS'
