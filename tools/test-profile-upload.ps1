$ErrorActionPreference = 'Stop'
$login = Invoke-RestMethod -Uri 'https://pp.gmpserver.com/api/auth/login' -Method POST -ContentType 'application/json' -Body '{"email":"hoadmin@demo.pharmacy","password":"Demo@12345"}'
$token = $login.data.access_token
$auth = "Authorization: Bearer $token"
$png = Join-Path $env:TEMP 'test-profile.png'
[Convert]::FromBase64String('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') | Set-Content -Path $png -Encoding Byte

foreach ($type in @('stamp','logo','scanner','photo','dl')) {
  Write-Host "=== upload $type ===" -ForegroundColor Cyan
  $out = & curl.exe -sS -w "`nHTTP:%{http_code}`n" -X POST "https://pp.gmpserver.com/api/profile/upload/$type" -H $auth -F "file=@$png"
  Write-Host $out
}

Remove-Item $png -ErrorAction SilentlyContinue
