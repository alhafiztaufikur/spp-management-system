[CmdletBinding()]
param(
    [switch]$FailOnCandidate
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$patterns = @(
    '-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----',
    '\bAKIA[0-9A-Z]{16}\b',
    '\bgh[pousr]_[A-Za-z0-9]{20,}\b',
    '\bgithub_pat_[A-Za-z0-9_]{20,}\b',
    '\b(?:sk|rk)-(?:live|prod)-[A-Za-z0-9_-]{12,}\b',
    '\b[0-9a-f]{32}\b',
    '\bMD5\s*\(\s*(?:\x22|\x27)[^\x22\x27]+(?:\x22|\x27)\s*\)',
    'mysql(?:\+[^:]+)?://[^\s:/]+:[^\s@]+@',
    '(?:password|passwd|db_pass|api[_-]?key|client[_-]?secret)\s*(?:\x22|\x27)?\s*(?:=>|=|:)\s*(?:\x22|\x27)[^\x22\x27$<>{}\s]{6,}(?:\x22|\x27)'
)
$combinedPattern = '(?:' + ($patterns -join '|') + ')'

Push-Location $workspace
try {
    & git rev-parse --is-inside-work-tree 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Workspace is not a Git worktree.'
    }

    $commits = @(& git rev-list --all)
    if ($LASTEXITCODE -ne 0 -or $commits.Count -eq 0) {
        throw 'Unable to enumerate Git history.'
    }

    $candidateKeys = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    foreach ($commit in $commits) {
        $locations = @(
            & git grep -I -i -l -P -e $combinedPattern $commit -- . ':(exclude)vendor/**' 2>$null
        )
        if ($LASTEXITCODE -notin @(0, 1)) {
            throw "git grep failed while scanning commit $commit."
        }
        foreach ($location in $locations) {
            $separator = $location.IndexOf(':')
            $path = if ($separator -ge 0) { $location.Substring($separator + 1) } else { $location }
            [void]$candidateKeys.Add("$commit`t$path")
        }
    }

    $paths = @(
        $candidateKeys |
            ForEach-Object { ($_ -split "`t", 2)[1] } |
            Sort-Object -Unique
    )

    Write-Output "COMMITS_SCANNED=$($commits.Count)"
    Write-Output "CANDIDATE_REVISIONS=$($candidateKeys.Count)"
    Write-Output "CANDIDATE_PATHS=$($paths.Count)"
    foreach ($path in $paths) {
        Write-Output "REVIEW_PATH=$path"
    }
    Write-Output 'NOTE=Only commit and path metadata is emitted; candidate values are intentionally redacted.'

    if ($FailOnCandidate -and $candidateKeys.Count -gt 0) {
        throw 'Potential historical secret material requires manual triage and credential rotation.'
    }
} finally {
    Pop-Location
}
