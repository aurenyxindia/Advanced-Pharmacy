$root = Join-Path $PSScriptRoot "..\app\src\main\java"
Get-ChildItem -Path $root -Filter *.kt -Recurse | ForEach-Object {
    $c = [IO.File]::ReadAllText($_.FullName)
    $n = $c -replace '\.int\("', '.jInt("'
    if ($n -ne $c) {
        [IO.File]::WriteAllText($_.FullName, $n)
        Write-Output $_.Name
    }
}
