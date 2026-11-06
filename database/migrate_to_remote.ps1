<#
.SYNOPSIS
  Migrate local Advanced Pharmacy DBs to Hostinger (paperlesspharmac_*).

.DESCRIPTION
  Mapping:
    pharma_master  -> paperlesspharmac_advancedMaster
    pharma_ho      -> paperlesspharmac_advho
    pharma_ops     -> paperlesspharmac_advancedMaindb

  Prerequisites (Hostinger hPanel):
    1. Databases already created with the names above
    2. DB user granted ALL on all three databases
    3. Remote MySQL → allow your public IP (or %)
    4. Copy exact "MySQL hostname" from hPanel (often auth-dbXXXX.hstgr.io)

.EXAMPLE
  .\migrate_to_remote.ps1 -MysqlHost auth-db1443.hstgr.io
#>
param(
  [Parameter(Mandatory = $true)]
  [string] $MysqlHost,

  [Parameter(Mandatory = $true)]
  [string] $MysqlUser,
  [Parameter(Mandatory = $true)]
  [string] $MysqlPass,
  [int]    $MysqlPort = 3306,
  [string] $MysqlExe = 'C:\xampp\mysql\bin\mysql.exe',
  [string] $MysqldumpExe = 'C:\xampp\mysql\bin\mysqldump.exe',
  [switch] $SkipDump,
  [switch] $SkipEnvUpdate
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$dumpDir = Join-Path $root 'pharmacy-api\storage\migrate_remote'
$envFile = Join-Path $root 'pharmacy-api\config\.env'
$envProd = Join-Path $root 'pharmacy-api\config\.env.production'

New-Item -ItemType Directory -Force -Path $dumpDir | Out-Null

$map = @(
  @{ Local = 'pharma_master'; Remote = 'paperlesspharmac_advancedMaster'; File = '01_master.sql' },
  @{ Local = 'pharma_ho';     Remote = 'paperlesspharmac_advho';         File = '02_ho.sql' },
  @{ Local = 'pharma_ops';    Remote = 'paperlesspharmac_advancedMaindb'; File = '03_ops.sql' }
)

function Invoke-Mysql([string[]] $ExtraArgs, [string] $InputFile = $null) {
  $args = @(
    "-h$MysqlHost", "-P$MysqlPort", "-u$MysqlUser", "-p$MysqlPass",
    "--default-character-set=utf8mb4",
    "--connect-timeout=30"
  ) + $ExtraArgs
  if ($InputFile) {
    Get-Content -Raw -Path $InputFile | & $MysqlExe @args
  } else {
    & $MysqlExe @args
  }
  if ($LASTEXITCODE -ne 0) {
    throw "mysql failed (exit $LASTEXITCODE): $($ExtraArgs -join ' ')"
  }
}

Write-Host "==> Testing remote connection to $MysqlHost ..."
$test = & $MysqlExe "-h$MysqlHost" "-P$MysqlPort" "-u$MysqlUser" "-p$MysqlPass" --connect-timeout=15 -e "SELECT USER() AS u, VERSION() AS v; SHOW DATABASES;" 2>&1
if ($LASTEXITCODE -ne 0) {
  Write-Host $test
  throw @"
Cannot connect to remote MySQL.

Fix in Hostinger hPanel:
  1) Databases → copy exact MySQL hostname
  2) Remote MySQL → add your public IP (or enable remote access)
  3) Confirm user '$MysqlUser' has privileges on all three paperlesspharmac_* databases
"@
}
Write-Host $test

if (-not $SkipDump) {
  Write-Host "==> Dumping local databases (optimized) ..."
  $common = @(
    "--user=root",
    "--single-transaction", "--quick", "--hex-blob",
    "--default-character-set=utf8mb4", "--set-charset",
    "--routines", "--triggers", "--events",
    "--add-drop-table", "--disable-keys", "--extended-insert",
    "--no-tablespaces"
  )
  foreach ($m in $map) {
    $out = Join-Path $dumpDir $m.File
    Write-Host "  dumping $($m.Local) -> $($m.File)"
    & $MysqldumpExe @common $m.Local | Set-Content -Path $out -Encoding utf8
  }
}

$preamble = Join-Path $dumpDir '00_import_preamble.sql'
$postamble = Join-Path $dumpDir '99_import_postamble.sql'

foreach ($m in $map) {
  $sql = Join-Path $dumpDir $m.File
  if (-not (Test-Path $sql)) { throw "Missing dump: $sql" }
  Write-Host "==> Importing $($m.Local) -> $($m.Remote) ..."

  # Verify remote DB exists
  $exists = & $MysqlExe "-h$MysqlHost" "-P$MysqlPort" "-u$MysqlUser" "-p$MysqlPass" -N -e "SHOW DATABASES LIKE '$($m.Remote)';"
  if (-not $exists) {
    throw "Remote database '$($m.Remote)' not found or user has no access. Create it in hPanel first."
  }

  # Combined stream: preamble + dump + postamble
  $combined = Join-Path $dumpDir ("_load_" + $m.File)
  @(
    Get-Content -Raw $preamble
    "USE ``$($m.Remote)``;"
    Get-Content -Raw $sql
    Get-Content -Raw $postamble
  ) -join "`n" | Set-Content -Path $combined -Encoding utf8

  Get-Content -Raw $combined | & $MysqlExe "-h$MysqlHost" "-P$MysqlPort" "-u$MysqlUser" "-p$MysqlPass" --default-character-set=utf8mb4 --force
  if ($LASTEXITCODE -ne 0) {
    throw "Import failed for $($m.Remote)"
  }

  Write-Host "  optimizing tables on $($m.Remote) ..."
  $tables = & $MysqlExe "-h$MysqlHost" "-P$MysqlPort" "-u$MysqlUser" "-p$MysqlPass" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$($m.Remote)' AND table_type='BASE TABLE';"
  foreach ($t in ($tables -split "`n" | Where-Object { $_.Trim() -ne '' })) {
    & $MysqlExe "-h$MysqlHost" "-P$MysqlPort" "-u$MysqlUser" "-p$MysqlPass" -e "USE ``$($m.Remote)``; OPTIMIZE TABLE ``$($t.Trim())``;" | Out-Null
  }

  $cnt = & $MysqlExe "-h$MysqlHost" "-P$MysqlPort" "-u$MysqlUser" "-p$MysqlPass" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$($m.Remote)';"
  Write-Host "  OK $($m.Remote) tables=$cnt"
}

Write-Host "==> Row-count verification ..."
& $MysqlExe "-h$MysqlHost" "-P$MysqlPort" "-u$MysqlUser" "-p$MysqlPass" -e @"
SELECT 'master.products' AS src, COUNT(*) c FROM paperlesspharmac_advancedMaster.products
UNION ALL SELECT 'master.generics', COUNT(*) FROM paperlesspharmac_advancedMaster.generics
UNION ALL SELECT 'master.manufacturers', COUNT(*) FROM paperlesspharmac_advancedMaster.manufacturers
UNION ALL SELECT 'ho.users', COUNT(*) FROM paperlesspharmac_advho.users
UNION ALL SELECT 'ho.clients', COUNT(*) FROM paperlesspharmac_advho.clients
UNION ALL SELECT 'ops.product_bindings', COUNT(*) FROM paperlesspharmac_advancedMaindb.product_bindings;
"@

$envBody = @"
# ============================================================
# Advanced Pharmacy Suite - PRODUCTION (Hostinger)
# Generated by migrate_to_remote.ps1
# ============================================================

APP_NAME="Advanced Pharmacy Suite"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-DOMAIN
APP_TIMEZONE=Asia/Kolkata

CORS_ALLOWED_ORIGINS=https://YOUR-FRONTEND-DOMAIN

# ---------- Database (Hostinger) ----------
DB_HOST=$MysqlHost
DB_PORT=$MysqlPort
DB_USER=$MysqlUser
DB_PASS=$MysqlPass
DB_CHARSET=utf8mb4

DB_MASTER=paperlesspharmac_advancedMaster
DB_HO=paperlesspharmac_advho
DB_OPS=paperlesspharmac_advancedMaindb

# ---------- JWT / Security ----------
JWT_SECRET=CHANGE_ME_TO_A_LONG_RANDOM_64_PLUS_CHARS
JWT_ISSUER=advanced-pharmacy
JWT_ACCESS_TTL=28800
JWT_REFRESH_TTL=1209600

RATE_LIMIT_MAX_ATTEMPTS=5
RATE_LIMIT_DECAY_SECONDS=300

UPLOAD_MAX_MB=50
"@

Set-Content -Path $envProd -Value $envBody -Encoding utf8
Write-Host "==> Wrote $envProd"

if (-not $SkipEnvUpdate) {
  Write-Host @"

NOTE: Local XAMPP .env was NOT overwritten (keeps 127.0.0.1 for local work).
On the online server, copy pharmacy-api/config/.env.production → .env
and set APP_URL / CORS_ALLOWED_ORIGINS / JWT_SECRET.

To point THIS PC at remote DBs (not recommended for daily local work):
  Copy-Item '$envProd' '$envFile' -Force
"@
}

Write-Host "`nMigration finished."
