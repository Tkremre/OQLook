Param(
  [string]$AppDir = (Resolve-Path "$PSScriptRoot\..\..\..").Path,
  [string]$PhpBin = "php",
  [string]$Queue = "default",
  [int]$Tries = 1,
  [int]$Sleep = 3,
  [int]$Timeout = 0,
  [int]$Memory = 512,
  [int]$MaxTime = 3600,
  [int]$RestartDelaySeconds = 5
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $AppDir)) {
  throw "App directory not found: $AppDir"
}

Set-Location $AppDir

while ($true) {
  Write-Host "Starting queue worker at $(Get-Date -Format s)" -ForegroundColor Cyan

  & $PhpBin artisan queue:work --queue=$Queue --tries=$Tries --sleep=$Sleep --timeout=$Timeout --memory=$Memory --max-time=$MaxTime
  $exitCode = $LASTEXITCODE

  if ($exitCode -ne 0) {
    Write-Host "queue:work exited with code $exitCode. Restarting in $RestartDelaySeconds s..." -ForegroundColor Yellow
  }
  else {
    Write-Host "queue:work exited cleanly. Restarting in $RestartDelaySeconds s..." -ForegroundColor DarkGray
  }

  Start-Sleep -Seconds $RestartDelaySeconds
}
