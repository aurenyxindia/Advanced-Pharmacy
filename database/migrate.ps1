<#
.SYNOPSIS
    Applies all SQL schema files (in order) to the local MariaDB/MySQL server.

.DESCRIPTION
    Runs every *.sql file in this folder alphabetically through the XAMPP
    mysql client. Files are idempotent (CREATE ... IF NOT EXISTS), so the
    script is safe to re-run.

.EXAMPLE
    .\migrate.ps1
    .\migrate.ps1 -MysqlExe "C:\xampp1\mysql\bin\mysql.exe" -User root
#>
param(
    [string]$MysqlExe = "C:\xampp1\mysql\bin\mysql.exe",
    [string]$DbHost   = "127.0.0.1",
    [string]$User     = "root",
    [string]$Password = ""
)

$ErrorActionPreference = 'Stop'
$here = $PSScriptRoot

if (-not (Test-Path $MysqlExe)) {
    throw "mysql client not found at $MysqlExe"
}

$files = Get-ChildItem -Path $here -Filter *.sql | Sort-Object Name
foreach ($file in $files) {
    Write-Host "Applying $($file.Name) ..." -ForegroundColor Cyan
    $args = @('-h', $DbHost, '-u', $User)
    if ($Password -ne '') { $args += "-p$Password" }
    Get-Content $file.FullName -Raw | & $MysqlExe @args
    if ($LASTEXITCODE -ne 0) {
        throw "Failed applying $($file.Name)"
    }
}

Write-Host "All migrations applied successfully." -ForegroundColor Green
