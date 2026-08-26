[CmdletBinding()]
param(
    [string]$MySqlExe = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$MySqlDumpExe = 'C:\xampp\mysql\bin\mysqldump.exe',
    [string]$DbHost = '127.0.0.1',
    [ValidateRange(1, 65535)]
    [int]$DbPort = 3306,
    [string]$AdminUser = 'root',
    [AllowEmptyString()]
    [string]$AdminPassword = $env:SPP_AUDIT_ADMIN_DB_PASS,
    [string]$AuditDatabase = '',
    [switch]$AllowEmptyPassword,
    [switch]$KeepAuditDatabase
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$sqlRoot = Join-Path $workspace 'sql'
$auditDatabasePattern = '^db_spp_audit_migration_[0-9]{8}_[0-9]{6}_[0-9]+_[a-f0-9]{8}$'
$baselineDatabase = 'db_spp'
$databaseCreated = $false
$createdDatabase = $null
$previousMySqlPassword = [Environment]::GetEnvironmentVariable('MYSQL_PWD', 'Process')

$canonicalMigrations = @(
    'add_role_management.sql',
    'add_security_controls.sql',
    'add_financial_audit_log.sql',
    'add_mutation_idempotency.sql',
    'add_master_biaya_lain.sql',
    'add_student_advanced.sql',
    'add_payment_references.sql',
    'add_payment_method.sql',
    'add_payment_updated_at.sql',
    'add_master_daftar_ulang.sql',
    'add_academic_year_billing.sql',
    'add_student_optional_fees.sql',
    'add_annual_payment_receipts.sql',
    'allow_spp_installments.sql',
    'activate_legacy_fields.sql',
    'normalize_transaction_operators.sql',
    'sync_student_initial_fee_paid_totals.sql',
    'remove_payment_linked_savings.sql',
    'add_modular_global_reports.sql'
)

function Assert-AuditDatabaseName {
    param([Parameter(Mandatory = $true)][string]$Name)

    if ($Name -eq $baselineDatabase -or $Name -notmatch $auditDatabasePattern) {
        throw "Refusing database '$Name'. Expected a unique name matching $auditDatabasePattern and never '$baselineDatabase'."
    }
}

function Get-MySqlArguments {
    param([switch]$SelectAuditDatabase)

    $arguments = @(
        '--protocol=tcp',
        "--host=$DbHost",
        "--port=$DbPort",
        "--user=$AdminUser",
        '--default-character-set=utf8mb4',
        '--init-command=SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        '--batch',
        '--raw',
        '--skip-column-names'
    )
    if ($SelectAuditDatabase) {
        $arguments += "--database=$createdDatabase"
    }
    return $arguments
}

function Invoke-MySqlText {
    param(
        [Parameter(Mandatory = $true)][string]$Sql,
        [switch]$SelectAuditDatabase,
        [string]$Label = 'SQL'
    )

    $arguments = Get-MySqlArguments -SelectAuditDatabase:$SelectAuditDatabase
    $output = @($Sql | & $MySqlExe @arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with mysql exit code $LASTEXITCODE.`n$($output -join [Environment]::NewLine)"
    }
    return $output
}

function Get-RewrittenSql {
    param([Parameter(Mandatory = $true)][string]$Path)

    $content = Get-Content -LiteralPath $Path -Raw
    $escapedDatabase = $createdDatabase.Replace('`', '``')
    $content = [regex]::Replace(
        $content,
        '(?im)^\s*CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+(?:`db_spp`|db_spp\b)',
        "CREATE DATABASE IF NOT EXISTS ``$escapedDatabase``"
    )
    $content = [regex]::Replace(
        $content,
        '(?im)^\s*USE\s+(?:`db_spp`|db_spp\b)\s*;',
        "USE ``$escapedDatabase``;"
    )

    if ($content -match '(?im)^\s*(?:CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS|USE)\s+(?:`db_spp`|db_spp\b)') {
        throw "Database target rewrite was incomplete for $Path."
    }
    return $content
}

function Invoke-SqlFile {
    param(
        [Parameter(Mandatory = $true)][string]$RelativePath,
        [string]$Label = $RelativePath
    )

    $path = Join-Path $workspace $RelativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required SQL file is missing: $RelativePath"
    }
    $sql = Get-RewrittenSql -Path $path
    return Invoke-MySqlText -Sql $sql -SelectAuditDatabase -Label $Label
}

function Assert-CanonicalMigrationInventory {
    $classified = @($canonicalMigrations | ForEach-Object { $_.ToLowerInvariant() })
    $discovered = @(
        Get-ChildItem -LiteralPath $sqlRoot -Filter '*.sql' -File |
            Where-Object { $_.BaseName -match '^(add_|allow_|activate_|normalize_|remove_|sync_)' } |
            ForEach-Object { $_.Name.ToLowerInvariant() }
    )
    $unknown = @($discovered | Where-Object { $_ -notin $classified })
    $missing = @($classified | Where-Object { $_ -notin $discovered })
    if ($unknown.Count -gt 0) {
        throw "Unclassified migration SQL found: $($unknown -join ', '). Review its dependencies and update MIGRATION_MANIFEST.md plus this harness before running."
    }
    if ($missing.Count -gt 0) {
        throw "Canonical migration SQL missing from workspace: $($missing -join ', ')."
    }
}

function Assert-SchemaVerifier {
    param([string]$Stage)

    $output = @(Invoke-SqlFile -RelativePath 'sql\verify_schema.sql' -Label "verify_schema ($Stage)")
    $missing = @($output | Where-Object { $_ -match "\tMISSING$" })
    if ($missing.Count -gt 0) {
        throw "Schema verification failed at '$Stage':`n$($missing -join [Environment]::NewLine)"
    }
    Write-Output "PASS schema verifier: $Stage"
}

function Assert-DataVerifier {
    param([string]$Stage)

    $output = @(Invoke-SqlFile -RelativePath 'sql\verify_data_integrity.sql' -Label "verify_data_integrity ($Stage)")
    $summary = @($output | Where-Object { $_ -match '^INV-[0-9]{3}\t(?:CRITICAL|HIGH)\t(?:PASS|FAIL)\t' })
    if ($summary.Count -ne 17) {
        throw "Data verifier returned $($summary.Count) summary rows at '$Stage'; expected exactly 17."
    }
    $failed = @($summary | Where-Object { $_ -match '^INV-[0-9]{3}\t(?:CRITICAL|HIGH)\tFAIL\t' })
    if ($failed.Count -gt 0) {
        throw "Data verification failed at '$Stage':`n$($failed -join [Environment]::NewLine)"
    }
    Write-Output "PASS data verifier: $Stage"
}

function Get-DatabaseFingerprint {
    param(
        [string]$Stage,
        [switch]$NormalizeAutoIncrement
    )

    $arguments = @(
        '--protocol=tcp',
        "--host=$DbHost",
        "--port=$DbPort",
        "--user=$AdminUser",
        '--default-character-set=utf8mb4',
        '--skip-comments',
        '--compact',
        '--hex-blob',
        '--order-by-primary',
        '--routines=false',
        '--events=false',
        '--triggers',
        $createdDatabase
    )
    $dump = @(& $MySqlDumpExe @arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "mysqldump failed at '$Stage' with exit code $LASTEXITCODE.`n$($dump -join [Environment]::NewLine)"
    }

    $dumpText = $dump -join "`n"
    if ($NormalizeAutoIncrement) {
        # INSERT IGNORE dapat mengonsumsi allocator walau tidak menambah baris.
        # Matrix membandingkan schema+data logis dan melaporkan raw drift terpisah.
        $dumpText = [regex]::Replace($dumpText, '(?i)\sAUTO_INCREMENT=[0-9]+', ' AUTO_INCREMENT=<normalized>')
    }
    $bytes = [Text.Encoding]::UTF8.GetBytes($dumpText)
    $sha256 = [Security.Cryptography.SHA256]::Create()
    try {
        $hashBytes = $sha256.ComputeHash($bytes)
    } finally {
        $sha256.Dispose()
    }
    return ([BitConverter]::ToString($hashBytes)).Replace('-', '').ToLowerInvariant()
}

function Remove-CreatedAuditDatabase {
    if (-not $databaseCreated -or [string]::IsNullOrWhiteSpace($createdDatabase)) {
        return
    }
    Assert-AuditDatabaseName -Name $createdDatabase
    if ($createdDatabase -ne $createdDatabaseAtCreation) {
        throw 'Cleanup guard failed because the current audit database does not equal the database created by this process.'
    }

    $exists = @(
        Invoke-MySqlText -Sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$createdDatabase';" -Label 'audit database cleanup preflight'
    )
    if ($exists.Count -eq 0) {
        $databaseCreated = $false
        return
    }
    if ($exists.Count -ne 1 -or $exists[0] -ne $createdDatabase) {
        throw 'Cleanup preflight returned an unexpected schema identity; refusing DROP DATABASE.'
    }

    Invoke-MySqlText -Sql "DROP DATABASE ``$createdDatabase``;" -Label 'audit database cleanup' | Out-Null
    $databaseCreated = $false
    Write-Output "CLEANED audit database: $createdDatabase"
}

try {
    if (-not (Test-Path -LiteralPath $MySqlExe -PathType Leaf)) {
        throw "mysql executable not found: $MySqlExe"
    }
    if (-not (Test-Path -LiteralPath $MySqlDumpExe -PathType Leaf)) {
        throw "mysqldump executable not found: $MySqlDumpExe"
    }
    if ([string]::IsNullOrEmpty($AdminPassword) -and -not $AllowEmptyPassword) {
        throw 'Empty database admin password refused. Supply SPP_AUDIT_ADMIN_DB_PASS or explicitly pass -AllowEmptyPassword for an isolated local XAMPP instance.'
    }

    if ([string]::IsNullOrWhiteSpace($AuditDatabase)) {
        $timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
        $randomSuffix = [Guid]::NewGuid().ToString('N').Substring(0, 8)
        $AuditDatabase = "db_spp_audit_migration_${timestamp}_$PID`_$randomSuffix"
    }
    Assert-AuditDatabaseName -Name $AuditDatabase
    $createdDatabase = $AuditDatabase
    $createdDatabaseAtCreation = $createdDatabase

    if ([string]::IsNullOrEmpty($AdminPassword)) {
        [Environment]::SetEnvironmentVariable('MYSQL_PWD', $null, 'Process')
    } else {
        [Environment]::SetEnvironmentVariable('MYSQL_PWD', $AdminPassword, 'Process')
    }

    Assert-CanonicalMigrationInventory

    $existing = @(
        Invoke-MySqlText -Sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$createdDatabase';" -Label 'audit database existence check'
    )
    if ($existing.Count -gt 0) {
        throw "Audit database already exists and will not be reused: $createdDatabase"
    }

    Invoke-MySqlText -Sql "CREATE DATABASE ``$createdDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -Label 'create audit database' | Out-Null
    $databaseCreated = $true
    Write-Output "CREATED audit database: $createdDatabase"

    Invoke-SqlFile -RelativePath 'sql\schema.sql' -Label 'fresh schema' | Out-Null
    Assert-SchemaVerifier -Stage 'fresh schema'
    Assert-DataVerifier -Stage 'fresh schema'

    foreach ($migration in $canonicalMigrations) {
        Invoke-SqlFile -RelativePath (Join-Path 'sql' $migration) -Label "migration pass 1: $migration" | Out-Null
        Write-Output "PASS migration pass 1: $migration"
    }
    Assert-SchemaVerifier -Stage 'after migration pass 1'
    Assert-DataVerifier -Stage 'after migration pass 1'
    $firstRawFingerprint = Get-DatabaseFingerprint -Stage 'after migration pass 1 (raw)'
    $firstFingerprint = Get-DatabaseFingerprint -Stage 'after migration pass 1' -NormalizeAutoIncrement

    $previousFingerprint = $firstFingerprint
    foreach ($migration in $canonicalMigrations) {
        Invoke-SqlFile -RelativePath (Join-Path 'sql' $migration) -Label "migration pass 2: $migration" | Out-Null
        $currentFingerprint = Get-DatabaseFingerprint -Stage "after migration pass 2: $migration" -NormalizeAutoIncrement
        if ($currentFingerprint -ne $previousFingerprint) {
            throw "Migration is not data/schema-idempotent on pass 2: $migration. before=$previousFingerprint after=$currentFingerprint"
        }
        $previousFingerprint = $currentFingerprint
        Write-Output "PASS migration pass 2: $migration"
    }
    Assert-SchemaVerifier -Stage 'after migration pass 2'
    Assert-DataVerifier -Stage 'after migration pass 2'
    $secondFingerprint = $previousFingerprint
    $secondRawFingerprint = Get-DatabaseFingerprint -Stage 'after migration pass 2 (raw)'

    if ($firstFingerprint -ne $secondFingerprint) {
        throw "Migration pass 2 changed the disposable database fingerprint. pass1=$firstFingerprint pass2=$secondFingerprint"
    }

    if ($firstRawFingerprint -ne $secondRawFingerprint) {
        Write-Warning "Raw dump fingerprint changed only in normalized allocator metadata. pass1=$firstRawFingerprint pass2=$secondRawFingerprint"
    }
    Write-Output "PASS reproducible fingerprint: $secondFingerprint"
    Write-Output 'MIGRATION_MATRIX_STATUS=PASS'
} finally {
    if ($KeepAuditDatabase) {
        if ($databaseCreated) {
            Write-Warning "Audit database retained by request: $createdDatabase"
        }
    } else {
        Remove-CreatedAuditDatabase
    }
    [Environment]::SetEnvironmentVariable('MYSQL_PWD', $previousMySqlPassword, 'Process')
}
