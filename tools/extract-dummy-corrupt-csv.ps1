$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$src = Join-Path $repo 'pharmacy-api\storage\review_products_for_deletion.csv'
$dst = Join-Path $repo 'pharmacy-api\storage\review_dummy_corrupt_only.csv'
$rows = Import-Csv $src
$dummy = $rows | Where-Object { $_.category -eq 'dummy_corrupt' }
$dummy | Export-Csv -Path $dst -NoTypeInformation -Encoding UTF8
Write-Host "Rows: $($dummy.Count)"
Write-Host "File: $dst"
