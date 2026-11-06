$root = Join-Path $PSScriptRoot "..\app\src\main\java"
$replacements = @(
    @('PharmaOutlinedButton("Back", onBack)', 'PharmaOutlinedButton("Back", onClick = onBack)'),
    @('PharmaPrimaryButton("Customise PO", onCustomise)', 'PharmaPrimaryButton("Customise PO", onClick = onCustomise)'),
    @('PharmaOutlinedButton("Auto PO", onAuto)', 'PharmaOutlinedButton("Auto PO", onClick = onAuto)'),
    @('PharmaPrimaryButton("Go", onSearch)', 'PharmaPrimaryButton("Go", onClick = onSearch)'),
    @('PharmaPrimaryButton("Go", { searchSupplier() })', 'PharmaPrimaryButton("Go") { searchSupplier() }'),
    @('PharmaPrimaryButton("Go", { searchProduct() })', 'PharmaPrimaryButton("Go") { searchProduct() }'),
    @('PharmaPrimaryButton("Go", { searchProducts() })', 'PharmaPrimaryButton("Go") { searchProducts() }'),
    @('PharmaPrimaryButton("Add", { scanProduct() })', 'PharmaPrimaryButton("Add") { scanProduct() }'),
    @('PharmaOutlinedButton("Go", { searchSupplier() })', 'PharmaOutlinedButton("Go") { searchSupplier() }')
)
Get-ChildItem -Path $root -Filter *.kt -Recurse | ForEach-Object {
    $c = [IO.File]::ReadAllText($_.FullName)
    $n = $c
    foreach ($pair in $replacements) {
        $n = $n.Replace($pair[0], $pair[1])
    }
    if ($n -ne $c) {
        [IO.File]::WriteAllText($_.FullName, $n)
        Write-Output $_.FullName
    }
}
