param(
    [string]$SourceDatabase = 'db_spp_audit_20260820_090000',
    [int]$Port = 8133,
    [string]$EvidenceRoot = ('C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0\release-rehearsal-' + (Get-Date -Format 'yyyyMMdd_HHmmss')),
    [string]$ProvisionDatabase = ('db_spp_audit_migration_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '_' + $PID + '_' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
)

$ErrorActionPreference = 'Stop'
$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$php = 'C:\xampp\php\php.exe'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$composer = (Get-Command composer -ErrorAction Stop).Source
$serverPid = 0
$stageCreated = $false
$databaseCreated = $false
$grantCreated = $false
$fixtureAdminUsername = ''
$fixtureAdminPassword = ''

if ($SourceDatabase -notmatch '^db_spp_audit_[0-9_]+$') {
    throw 'Release rehearsal hanya boleh memakai database audit bernama.'
}
if ($Port -lt 1024 -or $Port -gt 65535) {
    throw 'Port rehearsal berada di luar rentang aman.'
}
if ($ProvisionDatabase -notmatch '^db_spp_audit_migration_[0-9]{8}_[0-9]{6}_[0-9]+_[a-f0-9]{8}$') {
    throw 'Provision database harus memakai namespace migration audit yang unik.'
}
if (-not (Test-Path -LiteralPath $mysql -PathType Leaf)) {
    throw 'MySQL client tidak ditemukan pada path XAMPP yang diharapkan.'
}
$evidenceParent = Split-Path -Parent $EvidenceRoot
New-Item -ItemType Directory -Path $evidenceParent -Force | Out-Null
New-Item -ItemType Directory -Path $EvidenceRoot -Force | Out-Null
$stageCreated = $true

function Invoke-RootSql([string]$sql, [string]$label) {
    $result = $sql | & $mysql -u root --batch --skip-column-names --raw 2>&1
    if ($LASTEXITCODE -ne 0) { throw "$label gagal." }
    return @($result)
}

try {
    $stage = Join-Path $EvidenceRoot 'package'
    New-Item -ItemType Directory -Path $stage -Force | Out-Null

    & robocopy $workspace $stage /E /R:1 /W:1 /XD '.git' 'tmp' 'config' 'documentation' 'sql' 'tests' /XF '.env' '.env.*' 2>&1 | Out-Null
    if ($LASTEXITCODE -gt 7) { throw 'Penyalinan paket staging gagal.' }
    New-Item -ItemType Directory -Path (Join-Path $stage 'config') -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $workspace 'config\app.example.php') -Destination (Join-Path $stage 'config\app.example.php') -Force

    # Verifikasi isi filesystem, bukan hanya respons HTTP: artefak internal
    # tidak boleh pernah tersalin ke paket runtime publik.
    foreach ($forbiddenRelative in @('.git', 'sql', 'tests', 'documentation', 'config\app.local.php', '.env', '.env.local')) {
        $forbiddenPath = Join-Path $stage $forbiddenRelative
        if (Test-Path -LiteralPath $forbiddenPath) {
            throw "Artefak internal tersalin ke paket runtime: $forbiddenRelative"
        }
    }

    $configJson = & $php -r "`$config=require '$($workspace.Replace('\', '/'))/config/app.local.php'; echo json_encode(['pass'=>`$config['db_pass']]);"
    if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi audit lokal tidak dapat dibaca.' }
    $config = $configJson | ConvertFrom-Json
    if ([string]::IsNullOrWhiteSpace([string]$config.pass) -and [string]::IsNullOrWhiteSpace([string]$env:SPP_DB_PASS)) {
        throw 'Password audit hanya boleh diberikan melalui konfigurasi lokal atau environment.'
    }

    $migrationLog = Join-Path $EvidenceRoot 'migration-rehearsal.txt'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $workspace 'tests\support\run_migration_matrix.ps1') `
        -AuditDatabase $ProvisionDatabase -AllowEmptyPassword -KeepAuditDatabase 2>&1 | Tee-Object -FilePath $migrationLog
    if ($LASTEXITCODE -ne 0) { throw 'Provision schema/migrasi untuk release rehearsal gagal.' }
    $databaseCreated = $true

    Invoke-RootSql "GRANT SELECT, INSERT, UPDATE, DELETE ON ``$ProvisionDatabase``.* TO 'spp_audit_local'@'localhost'; FLUSH PRIVILEGES;" 'runtime privilege grant' | Out-Null
    $grantCreated = $true
    $fixtureAdminUsername = 'release_runner_' + [Guid]::NewGuid().ToString('N').Substring(0, 10)
    $fixtureAdminPassword = 'Release-' + [Guid]::NewGuid().ToString('N') + '-Aa9!'
    $env:SPP_RELEASE_FIXTURE_PASSWORD = $fixtureAdminPassword
    $fixtureHash = (& $php -r 'echo password_hash(getenv(''SPP_RELEASE_FIXTURE_PASSWORD''), PASSWORD_DEFAULT);').Trim()
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($fixtureHash)) { throw 'Hash akun rehearsal gagal dibuat.' }
    $fixtureSql = "INSERT INTO ``$ProvisionDatabase``.admin (username,password,nama,role,session_version,password_reset_required) VALUES ('$fixtureAdminUsername','$fixtureHash','Release Rehearsal','admin',1,0);"
    Invoke-RootSql $fixtureSql 'release fixture admin creation' | Out-Null

    $env:SPP_APP_ENV = 'test'
    $env:SPP_DB_HOST = '127.0.0.1'
    $env:SPP_DB_PORT = '3306'
    $env:SPP_DB_USER = 'spp_audit_local'
    $env:SPP_DB_PASS = [string]$config.pass
    $env:SPP_DB_NAME = $ProvisionDatabase

    $stdout = Join-Path $EvidenceRoot 'server.stdout.log'
    $stderr = Join-Path $EvidenceRoot 'server.stderr.log'
    $process = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$Port", '-t', $stage) `
        -WorkingDirectory $stage -WindowStyle Hidden -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr -PassThru
    $serverPid = $process.Id

    $baseUrl = "http://127.0.0.1:$Port"
    $ready = $false
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        Start-Sleep -Milliseconds 300
        try {
            $response = Invoke-WebRequest -Uri ($baseUrl + '/login.php') -UseBasicParsing -TimeoutSec 2
            if ([int]$response.StatusCode -eq 200) { $ready = $true; break }
        } catch { }
    }
    if (-not $ready) { throw 'Paket staging tidak dapat menyajikan login.php.' }

    $webSession = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $loginPage = Invoke-WebRequest -Uri ($baseUrl + '/login.php') -WebSession $webSession -UseBasicParsing -TimeoutSec 5
    if (-not [regex]::Match($loginPage.Content, 'name="csrf_token"\s+value="([^"]+)"').Success) {
        throw 'Token login tidak ditemukan pada paket rehearsal.'
    }
    $loginToken = [regex]::Match($loginPage.Content, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
    $loginResponse = Invoke-WebRequest -Uri ($baseUrl + '/login.php') -Method Post -WebSession $webSession `
        -Body @{ csrf_token = [System.Net.WebUtility]::HtmlDecode($loginToken); username = $fixtureAdminUsername; password = $fixtureAdminPassword } `
        -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
    if ([int]$loginResponse.StatusCode -ne 200) { throw "Login fixture rehearsal tidak mencapai halaman tujuan (HTTP $([int]$loginResponse.StatusCode))." }
    $dashboard = Invoke-WebRequest -Uri ($baseUrl + '/dashboard.php') -WebSession $webSession -UseBasicParsing -TimeoutSec 5
    if ([int]$dashboard.StatusCode -ne 200 -or $dashboard.Content -notmatch 'Dashboard Closing') { throw 'Dashboard tidak dapat dibuka setelah login pada schema baru.' }

    $paths = @('/login.php', '/assets/js/app.js', '/.git/HEAD', '/sql/schema.sql', '/tests/security_regression_test.php', '/documentation/PROJECT_CONTEXT.md', '/config/app.local.php')
    $results = @()
    foreach ($path in $paths) {
        $status = 0
        try {
            $result = Invoke-WebRequest -Uri ($baseUrl + $path) -UseBasicParsing -MaximumRedirection 0 -ErrorAction Stop
            $status = [int]$result.StatusCode
        } catch {
            if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
        }
        $results += [pscustomobject]@{ Path = $path; Status = $status }
    }
    $results | Export-Csv -LiteralPath (Join-Path $EvidenceRoot 'http-checks.csv') -NoTypeInformation

    $publicExpected = @('/login.php=200', '/assets/js/app.js=200')
    foreach ($expected in $publicExpected) {
        $parts = $expected -split '='
        $actual = ($results | Where-Object Path -eq $parts[0]).Status
        if ($actual -ne [int]$parts[1]) { throw "Endpoint publik $($parts[0]) tidak sesuai." }
    }
    foreach ($blocked in @('/.git/HEAD', '/sql/schema.sql', '/tests/security_regression_test.php', '/documentation/PROJECT_CONTEXT.md', '/config/app.local.php')) {
        $actual = ($results | Where-Object Path -eq $blocked).Status
        if ($actual -notin @(403, 404)) { throw "Artefak internal $blocked mengembalikan HTTP $actual." }
    }

    $composerErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $validate = & $composer validate --strict --working-dir=$stage 2>&1
    $validateExit = $LASTEXITCODE
    $platform = & $composer check-platform-reqs --no-dev --working-dir=$stage 2>&1
    $platformExit = $LASTEXITCODE
    $ErrorActionPreference = $composerErrorAction
    if ($validateExit -ne 0) { throw 'composer validate staging gagal.' }
    if ($platformExit -ne 0) { throw 'composer check-platform-reqs staging gagal.' }
    @(
        "SOURCE_DATABASE=$SourceDatabase",
        "PROVISION_DATABASE=$ProvisionDatabase",
        "STAGE_FILE_COUNT=$((Get-ChildItem -LiteralPath $stage -Recurse -File).Count)",
        "HTTP_REHEARSAL=PASS",
        "COMPOSER_REHEARSAL=PASS",
        "SCHEMA_MIGRATION_REHEARSAL=PASS",
        "AUTH_SMOKE=PASS"
    ) | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'rehearsal-summary.txt')
    Write-Output 'RELEASE_REHEARSAL=PASS'
    Write-Output "EVIDENCE_ROOT=$EvidenceRoot"
} finally {
    if ($serverPid -gt 0) { Stop-Process -Id $serverPid -Force -ErrorAction SilentlyContinue }
    Remove-Item Env:SPP_RELEASE_FIXTURE_PASSWORD -ErrorAction SilentlyContinue
    if ($databaseCreated) {
        $identity = Invoke-RootSql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$ProvisionDatabase';" 'pre-drop provision identity probe'
        if (($identity -join '').Trim() -ne $ProvisionDatabase) { throw 'Refusing release provision database drop because identity did not match.' }
        if ($fixtureAdminUsername -ne '') {
            Invoke-RootSql "DELETE FROM ``$ProvisionDatabase``.admin WHERE username='$fixtureAdminUsername';" 'release fixture admin cleanup' | Out-Null
        }
        Invoke-RootSql "DROP DATABASE ``$ProvisionDatabase``;" 'release provision database cleanup' | Out-Null
        if ($grantCreated) {
            Invoke-RootSql "REVOKE ALL PRIVILEGES ON ``$ProvisionDatabase``.* FROM 'spp_audit_local'@'localhost'; FLUSH PRIVILEGES;" 'release privilege cleanup' | Out-Null
        }
        "PROVISION_DATABASE_DROPPED=$ProvisionDatabase" | Add-Content -LiteralPath (Join-Path $EvidenceRoot 'rehearsal-summary.txt')
    }
    if ($stageCreated) {
        $resolvedRoot = [IO.Path]::GetFullPath($EvidenceRoot)
        if ($resolvedRoot -notmatch '^C:\\xampp\\sistemspp-audit-evidence\\20260820-090000-wave0\\release-rehearsal-[0-9_]+$') {
            throw 'Refusing to remove an unvalidated rehearsal path.'
        }
        $stagePath = Join-Path $resolvedRoot 'package'
        if (Test-Path -LiteralPath $stagePath) { Remove-Item -LiteralPath $stagePath -Recurse -Force -ErrorAction SilentlyContinue }
    }
}
