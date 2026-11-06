Add-Type -AssemblyName System.Drawing

function New-PaperlessFaviconBitmap([int]$Size) {
  $bmp = New-Object System.Drawing.Bitmap $Size, $Size
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
  $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
  $g.Clear([System.Drawing.Color]::Transparent)

  $s = $Size / 32.0
  $radius = 8 * $s

  $bgPath = New-Object System.Drawing.Drawing2D.GraphicsPath
  $bgPath.AddArc(0, 0, ($radius * 2), ($radius * 2), 180, 90)
  $bgPath.AddArc($Size - ($radius * 2), 0, ($radius * 2), ($radius * 2), 270, 90)
  $bgPath.AddArc($Size - ($radius * 2), $Size - ($radius * 2), ($radius * 2), ($radius * 2), 0, 90)
  $bgPath.AddArc(0, $Size - ($radius * 2), ($radius * 2), ($radius * 2), 90, 90)
  $bgPath.CloseFigure()

  $brushBg = New-Object System.Drawing.Drawing2D.LinearGradientBrush(
    (New-Object System.Drawing.RectangleF 0, 0, $Size, $Size),
    [System.Drawing.Color]::FromArgb(255, 20, 184, 166),
    [System.Drawing.Color]::FromArgb(255, 15, 118, 110),
    45
  )
  $g.FillPath($brushBg, $bgPath)
  $brushBg.Dispose()
  $bgPath.Dispose()

  $white = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::White)
  $red = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 239, 68, 68))

  $RoundRect = {
    param($x, $y, $w, $h, $r)
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $path.AddArc($x, $y, ($r * 2), ($r * 2), 180, 90)
    $path.AddArc($x + $w - ($r * 2), $y, ($r * 2), ($r * 2), 270, 90)
    $path.AddArc($x + $w - ($r * 2), $y + $h - ($r * 2), ($r * 2), ($r * 2), 0, 90)
    $path.AddArc($x, $y + $h - ($r * 2), ($r * 2), ($r * 2), 90, 90)
    $path.CloseFigure()
    $g.FillPath($white, $path)
    $path.Dispose()
  }

  & $RoundRect (13 * $s) (7 * $s) (6 * $s) (18 * $s) (1.2 * $s)
  & $RoundRect (7 * $s) (13 * $s) (18 * $s) (6 * $s) (1.2 * $s)

  $dotR = 2.2 * $s
  $g.FillEllipse($red, (24 * $s - $dotR), (8 * $s - $dotR), ($dotR * 2), ($dotR * 2))

  $white.Dispose()
  $red.Dispose()
  $g.Dispose()
  return $bmp
}

$outDir = Join-Path $PSScriptRoot '..\pharmacy-frontend\public'
foreach ($px in @(16, 32, 48, 64, 180)) {
  $bmp = New-PaperlessFaviconBitmap $px
  $path = Join-Path $outDir ("favicon-{0}.png" -f $px)
  $bmp.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
  $bmp.Dispose()
  Write-Host "Wrote $path"
}

$icon32Path = Join-Path $outDir 'favicon-32.png'
$icon32 = [System.Drawing.Icon]::FromHandle((New-Object System.Drawing.Bitmap $icon32Path).GetHicon())
$icoPath = Join-Path $outDir 'favicon.ico'
$fs = [System.IO.File]::Create($icoPath)
$icon32.Save($fs)
$fs.Close()
$icon32.Dispose()
Write-Host "Wrote $icoPath"
