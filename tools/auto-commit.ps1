<#
.SYNOPSIS
    Automatic local Git versioning for the Advanced Pharmacy Suite.

.DESCRIPTION
    Watches the repository and commits changes automatically on a fixed
    interval. Intended for local development version management only.

.PARAMETER IntervalSeconds
    Seconds between commit checks. Default 300 (5 minutes).

.PARAMETER Once
    Commit current changes a single time and exit (useful for hooks / manual runs).

.EXAMPLE
    .\tools\auto-commit.ps1                 # watch & auto-commit every 5 min
    .\tools\auto-commit.ps1 -Once           # one-shot commit
    .\tools\auto-commit.ps1 -IntervalSeconds 120
#>
param(
    [int]$IntervalSeconds = 300,
    [switch]$Once
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

function Invoke-AutoCommit {
    $status = git status --porcelain
    if ([string]::IsNullOrWhiteSpace($status)) {
        Write-Host "[$(Get-Date -Format 'HH:mm:ss')] No changes to commit." -ForegroundColor DarkGray
        return
    }
    git add -A | Out-Null
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $files = (git diff --cached --name-only | Measure-Object).Count
    $msg = "chore(auto): local checkpoint $stamp ($files file(s))"
    git commit -m $msg | Out-Null
    Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Committed: $msg" -ForegroundColor Green
}

if ($Once) {
    Invoke-AutoCommit
    return
}

Write-Host "Auto-commit watcher started (every $IntervalSeconds s). Press Ctrl+C to stop." -ForegroundColor Cyan
while ($true) {
    try { Invoke-AutoCommit } catch { Write-Warning $_.Exception.Message }
    Start-Sleep -Seconds $IntervalSeconds
}
