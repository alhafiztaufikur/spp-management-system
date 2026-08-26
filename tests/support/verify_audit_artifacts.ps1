[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$required = @(
    'documentation/audit/EXECUTIVE_REPORT.md',
    'documentation/audit/SECURITY_FINDINGS.md',
    'documentation/audit/APPLICATION_MANIFEST.md',
    'documentation/audit/ROUTE_ROLE_MATRIX.md',
    'documentation/audit/DATA_CONTRACT.md',
    'documentation/audit/RECONCILIATION_REPORT.md',
    'documentation/audit/MIGRATION_MANIFEST.md',
    'documentation/audit/REPORT_EXPORT_PARITY.md',
    'documentation/audit/DEAD_CODE_INVENTORY.md',
    'documentation/audit/FRONTEND_ACCESSIBILITY.md',
    'documentation/audit/DEPENDENCY_DEPLOYMENT_REPORT.md',
    'documentation/audit/OPERATIONS_RUNBOOK.md',
    'documentation/audit/UAT_PACKAGE.md',
    'documentation/audit/RELEASE_MANIFEST.md',
    'documentation/PROGRESS.md',
    'documentation/PROJECT_CONTEXT.md',
    'documentation/AI_CHANGELOG.md',
    'documentation/RENCANA_AUDIT_FINAL_SISTEMSPP.md',
    'documentation/SOP_ALUR_KASIR_SISTEMSPP.html',
    'documentation/SOP_ALUR_KASIR_SISTEMSPP.pdf',
    'documentation/FLOWCHART_ALUR_KASIR_SISTEMSPP.html',
    'documentation/FLOWCHART_ALUR_KASIR_SISTEMSPP.pdf'
)

Push-Location $workspace
try {
    $missing = @($required | Where-Object { -not (Test-Path -LiteralPath $_ -PathType Leaf) })
    "REQUIRED_AUDIT_ARTIFACTS=$($required.Count)"
    "MISSING_AUDIT_ARTIFACTS=$($missing.Count)"
    if ($missing) { $missing | ForEach-Object { "MISSING=$_" } }

    $routes = @(Get-ChildItem -Recurse -File -Filter '*.php' |
        Where-Object {
            $_.FullName -notmatch '\\(includes|tests|vendor|config)\\' -and $_.Name -ne 'koneksi.php'
        })
    $sqlFiles = @(Get-ChildItem -LiteralPath 'sql' -File -Filter '*.sql')
    $trackedPhp = @(& git ls-files '*.php')
    $migrationManifest = Get-Content -LiteralPath 'documentation/audit/MIGRATION_MANIFEST.md' -Raw
    $missingMigrations = @(
        $sqlFiles |
            Where-Object { $_.Name -like 'add_*.sql' -and $migrationManifest -notmatch [regex]::Escape($_.Name) }
    )
    "ROUTE_FILES=$($routes.Count)"
    "SQL_FILES=$($sqlFiles.Count)"
    "TRACKED_PHP_FILES=$($trackedPhp.Count)"
    "MIGRATIONS_MISSING_FROM_MANIFEST=$($missingMigrations.Count)"
    if ($missingMigrations) { $missingMigrations | ForEach-Object { "MISSING_MIGRATION=$($_.Name)" } }

    $fail = $missing.Count -gt 0 -or $routes.Count -ne 29 -or $sqlFiles.Count -ne 25 -or
        $trackedPhp.Count -ne 63 -or $missingMigrations.Count -gt 0
    if ($fail) {
        'AUDIT_ARTIFACT_CHECK=FAIL'
        exit 1
    }
    'AUDIT_ARTIFACT_CHECK=PASS'
} finally {
    Pop-Location
}
