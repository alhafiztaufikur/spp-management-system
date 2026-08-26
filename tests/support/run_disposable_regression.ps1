param(
    [string]$SourceDatabase = 'db_spp_audit_20260820_090000',
    [string]$SuiteDatabase = ('db_spp_audit_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '_suite_' + (Get-Random -Minimum 1000 -Maximum 9999)),
    [string]$DatabaseUser = 'spp_audit_local',
    [string]$BaseUrl = 'http://127.0.0.1:8099',
    [string]$AuditUsername = $env:SPP_TEST_ADMIN_USERNAME,
    [string]$AuditPassword = $env:SPP_TEST_ADMIN_PASSWORD,
    [string]$EvidenceRoot = ('C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0\regression-' + (Get-Date -Format 'yyyyMMdd_HHmmss')),
    [switch]$GenerateTemporaryAdmin
)

$ErrorActionPreference = 'Stop'
$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe'
$php = 'C:\xampp\php\php.exe'
$powershell = 'powershell.exe'
$serverPid = 0
$databaseCreated = $false
$suiteCompleted = $false
$grantCreated = $false
$generatedAdminUsername = ''
$generatedAdminPassword = ''

if ($SourceDatabase -notmatch '^db_spp_audit_[0-9_]+$') {
    throw 'Source database must be a named audit database.'
}
if ($SuiteDatabase -notmatch '^db_spp_audit_[0-9_]+_suite_[0-9_]+$') {
    throw 'Suite database must match db_spp_audit_<digits>_suite_<digits>.'
}
if ($SuiteDatabase -eq $SourceDatabase) {
    throw 'Source and disposable suite database must differ.'
}
if (([string]::IsNullOrWhiteSpace($AuditUsername) -or [string]::IsNullOrWhiteSpace($AuditPassword)) -and -not $GenerateTemporaryAdmin) {
    throw 'Audit credentials must be supplied through SPP_TEST_ADMIN_USERNAME and SPP_TEST_ADMIN_PASSWORD.'
}
if ($DatabaseUser -ne 'spp_audit_local') {
    throw 'Disposable regression wrapper requires spp_audit_local.'
}
New-Item -ItemType Directory -Path $EvidenceRoot -Force | Out-Null

function Invoke-MySqlRoot([string]$sql, [string]$label) {
    $result = $sql | & $mysql -u root --batch --skip-column-names --raw
    if ($LASTEXITCODE -ne 0) {
        throw "$label failed."
    }
    return $result
}

try {
    $sourceProbe = Invoke-MySqlRoot "SELECT DATABASE();" 'source probe'
    if ($sourceProbe -join '' -ne '') {
        # The command has no default schema; the explicit probe below is authoritative.
    }
    $sourceExists = Invoke-MySqlRoot "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$SourceDatabase';" 'source existence probe'
    if (($sourceExists -join '').Trim() -ne $SourceDatabase) {
        throw 'Source audit database was not found.'
    }

    $dumpPath = Join-Path $EvidenceRoot 'source-clone-dump.sql'
    & $mysqldump -u root --single-transaction --routines --triggers --databases $SourceDatabase --result-file=$dumpPath
    if ($LASTEXITCODE -ne 0) { throw 'Source audit dump failed.' }
    $dumpHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $dumpPath).Hash
    $gitCommit = (& git -C $workspace rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Git commit probe failed.' }
    $gitDirty = if ((& git -C $workspace status --porcelain).Count -gt 0) { '1' } else { '0' }
    $phpVersion = (& $php -r 'echo PHP_VERSION;').Trim()
    if ($LASTEXITCODE -ne 0) { throw 'PHP version probe failed.' }
    $mysqlVersion = (& $mysql --version).Trim()
    if ($LASTEXITCODE -ne 0) { throw 'MySQL version probe failed.' }
    $nodeVersion = (& node --version).Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Node version probe failed.' }
    $schemaHash = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $workspace 'sql\schema.sql')).Hash
    $migrationManifestHash = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $workspace 'documentation\audit\MIGRATION_MANIFEST.md')).Hash
    @(
        "RUN_STARTED_WIB=$((Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz'))",
        "GIT_COMMIT=$gitCommit",
        "GIT_WORKTREE_DIRTY=$gitDirty",
        "PHP_VERSION=$phpVersion",
        "MYSQL_CLIENT_VERSION=$mysqlVersion",
        "NODE_VERSION=$nodeVersion",
        "APP_TIMEZONE=Asia/Jakarta",
        "SCHEMA_SHA256=$schemaHash",
        "MIGRATION_MANIFEST_SHA256=$migrationManifestHash",
        "SOURCE_DATABASE=$SourceDatabase",
        "SUITE_DATABASE=$SuiteDatabase",
        "SOURCE_DUMP_SHA256=$dumpHash"
    ) | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'run-metadata.txt')

    Invoke-MySqlRoot "CREATE DATABASE ``$SuiteDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 'suite database creation' | Out-Null
    $databaseCreated = $true

    $dumpSql = Get-Content -Raw -LiteralPath $dumpPath
    $sourceIdentifier = [string]::Concat('`', $SourceDatabase, '`')
    $suiteIdentifier = [string]::Concat('`', $SuiteDatabase, '`')
    $dumpSql = $dumpSql.Replace($sourceIdentifier, $suiteIdentifier)
    $rewrittenDump = Join-Path $EvidenceRoot 'suite-import.sql'
    $dumpSql | Set-Content -LiteralPath $rewrittenDump -Encoding utf8
    Get-Content -Raw -LiteralPath $rewrittenDump | & $mysql -u root
    if ($LASTEXITCODE -ne 0) { throw 'Suite database import failed.' }

    Get-Content -Raw -LiteralPath (Join-Path $workspace 'sql\add_mutation_idempotency.sql') |
        & $mysql -u root --database=$SuiteDatabase
    if ($LASTEXITCODE -ne 0) { throw 'Idempotency migration failed on disposable regression suite.' }

    if ($GenerateTemporaryAdmin) {
        $generatedAdminUsername = 'audit_runner_' + [Guid]::NewGuid().ToString('N').Substring(0, 12)
        $generatedAdminPassword = 'Run-' + [Guid]::NewGuid().ToString('N') + '-Aa9!'
        $env:SPP_TEMP_ADMIN_PASSWORD = $generatedAdminPassword
        $generatedHash = (& $php -r 'echo password_hash(getenv(''SPP_TEMP_ADMIN_PASSWORD''), PASSWORD_DEFAULT);').Trim()
        if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($generatedHash)) { throw 'Temporary audit admin hash failed.' }
        $fixtureAdminSql = "INSERT INTO admin (username,password,nama,role,session_version,password_reset_required) VALUES ('$generatedAdminUsername','$generatedHash','Disposable Audit Runner','admin',1,0);"
        $fixtureAdminSql | & $mysql -u root --database=$SuiteDatabase
        if ($LASTEXITCODE -ne 0) { throw 'Temporary audit admin creation failed.' }
        $AuditUsername = $generatedAdminUsername
        $AuditPassword = $generatedAdminPassword
    }

    $grantSql = @"
GRANT SELECT, INSERT, UPDATE, DELETE ON ``$SuiteDatabase``.* TO 'spp_audit_local'@'localhost';
FLUSH PRIVILEGES;
"@
    Invoke-MySqlRoot $grantSql 'suite privilege grant' | Out-Null
    $grantCreated = $true

    $env:SPP_TEST_DISPOSABLE_SUITE = '1'
    $env:SPP_TEST_DB_USER = $DatabaseUser
    $env:SPP_APP_ENV = 'test'
    $env:SPP_DB_HOST = '127.0.0.1'
    $env:SPP_DB_PORT = '3306'
    $env:SPP_DB_USER = $DatabaseUser
    $env:SPP_DB_NAME = $SuiteDatabase
    $serverStdout = Join-Path $EvidenceRoot 'php-audit-server.stdout.log'
    $serverStderr = Join-Path $EvidenceRoot 'php-audit-server.stderr.log'
    $serverProcess = Start-Process -FilePath 'C:\xampp\php\php.exe' `
        -ArgumentList @('-S', '127.0.0.1:8099', '-t', $workspace, (Join-Path $workspace 'tests\support\router.php')) `
        -WorkingDirectory $workspace -WindowStyle Hidden `
        -RedirectStandardOutput $serverStdout -RedirectStandardError $serverStderr -PassThru
    $serverPid = $serverProcess.Id
    $serverPid | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'php-audit-server.pid') -Encoding ascii
    $ready = $false
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        Start-Sleep -Milliseconds 300
        try {
            $response = Invoke-WebRequest -Uri ($BaseUrl.TrimEnd('/') + '/login.php') -UseBasicParsing -TimeoutSec 2
            if ([int]$response.StatusCode -eq 200) { $ready = $true; break }
        } catch {
            # Continue bounded readiness polling.
        }
    }
    if (-not $ready) { throw 'Audit server failed to become ready.' }
    @(
        "AUDIT_SERVER_PID=$serverPid",
        "AUDIT_SERVER_URL=$BaseUrl",
        "AUDIT_DATABASE=$SuiteDatabase"
    ) | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'server-start.txt')
    $pidPath = Join-Path $EvidenceRoot 'php-audit-server.pid'
    if (-not (Test-Path -LiteralPath $pidPath)) { throw 'Audit server PID artifact missing.' }
    $serverPid = [int](Get-Content -Raw -LiteralPath $pidPath).Trim()

    & $powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $workspace 'tests\run_all.ps1') -Database $SuiteDatabase -DatabaseUser $DatabaseUser -BaseUrl $BaseUrl -AuditUsername $AuditUsername -AuditPassword $AuditPassword | Tee-Object -FilePath (Join-Path $EvidenceRoot 'regression-suite.txt')
    if ($LASTEXITCODE -ne 0) { throw 'Regression suite failed.' }

    if ($generatedAdminUsername -ne '') {
        Invoke-MySqlRoot "DELETE FROM ``$SuiteDatabase``.admin WHERE username='$generatedAdminUsername';" 'temporary audit admin cleanup' | Out-Null
    }

    $cleanupSql = @"
SELECT 'fixture_students', COUNT(*) FROM ``$SuiteDatabase``.siswa WHERE NAMA LIKE 'UJI %' OR NO_INDUK REGEXP '^(96|99)';
SELECT 'fixture_accounts', COUNT(*) FROM ``$SuiteDatabase``.admin WHERE username LIKE 'role_audit_%' OR username LIKE 'legacy_audit_%' OR username LIKE 'account_audit_%';
SELECT 'fixture_master_fees', COUNT(*) FROM ``$SuiteDatabase``.master_biaya_lain WHERE nama LIKE 'UJI %';
SELECT 'fixture_mutation_requests', COUNT(*) FROM ``$SuiteDatabase``.mutation_request WHERE scope = 'savings';
SELECT 'audit_events_evidence', COUNT(*) FROM ``$SuiteDatabase``.audit_event;
"@
    $cleanupResult = Invoke-MySqlRoot $cleanupSql 'post-suite cleanup probe'
    $cleanupResult | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'post-suite-cleanup.tsv')
    $studentsClean = @($cleanupResult | Select-String -Pattern '^fixture_students\s+0$').Count
    $accountsClean = @($cleanupResult | Select-String -Pattern '^fixture_accounts\s+0$').Count
    $feesClean = @($cleanupResult | Select-String -Pattern '^fixture_master_fees\s+0$').Count
    $mutationRequestsClean = @($cleanupResult | Select-String -Pattern '^fixture_mutation_requests\s+0$').Count
    if (($studentsClean -ne 1) -or ($accountsClean -ne 1) -or ($feesClean -ne 1) -or ($mutationRequestsClean -ne 1)) {
        throw 'Disposable suite domain fixture cleanup failed.'
    }
    $suiteCompleted = $true
} finally {
    Remove-Item Env:SPP_TEMP_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    if ($serverPid -gt 0) {
        Stop-Process -Id $serverPid -Force -ErrorAction SilentlyContinue
    }
    if ($databaseCreated) {
        $identity = Invoke-MySqlRoot "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$SuiteDatabase';" 'pre-drop identity probe'
        if (($identity -join '').Trim() -ne $SuiteDatabase) {
            throw 'Refusing disposable database drop because schema identity did not match.'
        }
        Invoke-MySqlRoot "DROP DATABASE ``$SuiteDatabase``;" 'disposable database cleanup' | Out-Null
        if ($grantCreated) {
            Invoke-MySqlRoot "REVOKE ALL PRIVILEGES ON ``$SuiteDatabase``.* FROM 'spp_audit_local'@'localhost'; FLUSH PRIVILEGES;" 'disposable privilege cleanup' | Out-Null
        }
        "SUITE_DATABASE_DROPPED=$SuiteDatabase" | Add-Content -LiteralPath (Join-Path $EvidenceRoot 'run-metadata.txt')
    }
}

if (-not $suiteCompleted) {
    throw 'Disposable regression suite did not complete.'
}
Write-Output "DISPOSABLE_REGRESSION=PASS"
Write-Output "EVIDENCE_ROOT=$EvidenceRoot"
