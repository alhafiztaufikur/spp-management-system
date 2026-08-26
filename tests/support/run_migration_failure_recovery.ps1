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
    [string]$EvidenceRoot = ('C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0\migration-failure-' + (Get-Date -Format 'yyyyMMdd_HHmmss')),
    [switch]$AllowEmptyPassword,
    [switch]$KeepAuditDatabase
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$databasePattern = '^db_spp_audit_failure_[0-9]{8}_[0-9]{6}_[0-9]+_[a-f0-9]{8}$'
$databaseCreated = $false
$createdDatabase = ''
$previousMysqlPassword = [Environment]::GetEnvironmentVariable('MYSQL_PWD', 'Process')

$migrations = @(
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

function Assert-AuditDatabaseName([string]$Name) {
    if ($Name -notmatch $databasePattern -or $Name -eq 'db_spp') {
        throw "Refusing database '$Name'. Expected a disposable failure-recovery database."
    }
}

function Get-MySqlArguments([switch]$SelectDatabase) {
    $args = @(
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
    if ($SelectDatabase) { $args += "--database=$createdDatabase" }
    return $args
}

function Invoke-MySqlText([string]$Sql, [switch]$SelectDatabase, [string]$Label = 'SQL') {
    $output = @($Sql | & $MySqlExe @(Get-MySqlArguments -SelectDatabase:$SelectDatabase) 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with mysql exit code $LASTEXITCODE.`n$($output -join [Environment]::NewLine)"
    }
    return $output
}

function Invoke-ExpectedFailure([string]$Sql, [string]$Label) {
    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @($Sql | & $MySqlExe @(Get-MySqlArguments -SelectDatabase) 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorAction
    }
    if ($exitCode -eq 0) {
        throw "$Label unexpectedly succeeded; fault injection was not observed."
    }
    return [pscustomobject]@{ ExitCode = $exitCode; Output = ($output -join [Environment]::NewLine) }
}

function Rewrite-Sql([string]$Path) {
    $sql = Get-Content -LiteralPath $Path -Raw
    $escaped = $createdDatabase.Replace('`', '``')
    $sql = [regex]::Replace($sql, '(?im)^\s*CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+(?:`db_spp`|db_spp\b)', "CREATE DATABASE IF NOT EXISTS ``$escaped``")
    $sql = [regex]::Replace($sql, '(?im)^\s*USE\s+(?:`db_spp`|db_spp\b)\s*;', "USE ``$escaped``;")
    if ($sql -match '(?im)^\s*(?:CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS|USE)\s+(?:`db_spp`|db_spp\b)') {
        throw "Database rewrite incomplete for $Path."
    }
    return $sql
}

function Remove-CreatedDatabase {
    if ($KeepAuditDatabase -or -not $databaseCreated) { return }
    Assert-AuditDatabaseName $createdDatabase
    $exists = @(Invoke-MySqlText "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$createdDatabase';" -Label 'cleanup preflight')
    if ($exists.Count -ne 1 -or $exists[0] -ne $createdDatabase) {
        throw 'Cleanup preflight did not identify exactly the created database.'
    }
    Invoke-MySqlText "DROP DATABASE ``$createdDatabase``;" -Label 'cleanup' | Out-Null
    $script:databaseCreated = $false
}

try {
    if (-not (Test-Path -LiteralPath $MySqlExe -PathType Leaf)) { throw "mysql executable not found: $MySqlExe" }
    if (-not (Test-Path -LiteralPath $MySqlDumpExe -PathType Leaf)) { throw "mysqldump executable not found: $MySqlDumpExe" }
    if ([string]::IsNullOrEmpty($AdminPassword) -and -not $AllowEmptyPassword) {
        throw 'Empty database admin password refused; pass -AllowEmptyPassword only on isolated local XAMPP.'
    }
    New-Item -ItemType Directory -Path $EvidenceRoot -Force | Out-Null
    if ([string]::IsNullOrWhiteSpace($AuditDatabase)) {
        $timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
        $suffix = [Guid]::NewGuid().ToString('N').Substring(0, 8)
        $AuditDatabase = "db_spp_audit_failure_${timestamp}_$PID`_$suffix"
    }
    Assert-AuditDatabaseName $AuditDatabase
    $createdDatabase = $AuditDatabase
    if ([string]::IsNullOrEmpty($AdminPassword)) {
        [Environment]::SetEnvironmentVariable('MYSQL_PWD', $null, 'Process')
    } else {
        [Environment]::SetEnvironmentVariable('MYSQL_PWD', $AdminPassword, 'Process')
    }

    Invoke-MySqlText "CREATE DATABASE ``$createdDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -Label 'database creation' | Out-Null
    $databaseCreated = $true
    $schema = Rewrite-Sql (Join-Path $workspace 'sql\schema.sql')
    Invoke-MySqlText $schema -SelectDatabase -Label 'schema install' | Out-Null

    $results = [System.Collections.Generic.List[object]]::new()
    for ($index = 0; $index -lt $migrations.Count; $index++) {
        $name = $migrations[$index]
        $path = Join-Path $workspace ('sql\' + $name)
        $original = Rewrite-Sql $path
        $fault = $original + "`nTHIS_STATEMENT_IS_INTENTIONALLY_INVALID_FOR_RECOVERY_TEST;`n"
        $failure = Invoke-ExpectedFailure $fault "fault injection $name"
        Invoke-MySqlText $original -SelectDatabase -Label "recovery rerun $name" | Out-Null
        $results.Add([pscustomobject]@{
            Sequence = $index + 1
            Migration = $name
            FaultExitCode = $failure.ExitCode
            Recovery = 'PASS'
        })
        Write-Output "PASS failure/recovery: $name"
    }

    $results | Export-Csv -LiteralPath (Join-Path $EvidenceRoot 'failure-recovery.csv') -NoTypeInformation -Encoding utf8
    $schemaVerify = @(Invoke-MySqlText (Rewrite-Sql (Join-Path $workspace 'sql\verify_schema.sql')) -SelectDatabase -Label 'schema verifier')
    $missing = @($schemaVerify | Where-Object { $_ -match "\tMISSING$" })
    if ($missing.Count -gt 0) { throw "Schema verifier reported missing objects: $($missing -join ', ')" }
    $dataVerify = @(Invoke-MySqlText (Rewrite-Sql (Join-Path $workspace 'sql\verify_data_integrity.sql')) -SelectDatabase -Label 'data verifier')
    $failed = @($dataVerify | Where-Object { $_ -match '^INV-[0-9]{3}\t(?:CRITICAL|HIGH)\tFAIL\t' })
    if ($failed.Count -gt 0) { throw "Data verifier reported failures: $($failed -join ', ')" }
    @(
        "DATABASE_PROBE=$createdDatabase",
        "MIGRATION_COUNT=$($results.Count)",
        "FAILURE_INJECTIONS=$($results.Count)",
        "RECOVERY_STATUS=PASS"
    ) | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'summary.txt') -Encoding utf8
    Write-Output "DATABASE_PROBE=$createdDatabase"
    Write-Output "MIGRATION_COUNT=$($results.Count)"
    Write-Output "FAILURE_INJECTIONS=$($results.Count)"
    Write-Output 'RECOVERY_STATUS=PASS'
} finally {
    try { Remove-CreatedDatabase } finally {
        [Environment]::SetEnvironmentVariable('MYSQL_PWD', $previousMysqlPassword, 'Process')
    }
}
