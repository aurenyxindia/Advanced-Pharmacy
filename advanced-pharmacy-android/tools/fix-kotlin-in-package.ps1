$root = Join-Path $PSScriptRoot "..\app\src\main\java"
$bad = [char]0x60 + [char]0x60 + 'in' + [char]0x60 + [char]0x60
$good = [char]0x60 + 'in' + [char]0x60
Get-ChildItem -Path $root -Filter *.kt -Recurse | ForEach-Object {
    $c = [IO.File]::ReadAllText($_.FullName)
    $n = $c.Replace($bad, $good)
    $n = $n.Replace('package in.', "package ${good}.")
    $n = $n.Replace('import in.', "import ${good}.")
    if ($n -ne $c) {
        [IO.File]::WriteAllText($_.FullName, $n)
        Write-Output $_.FullName
    }
}
