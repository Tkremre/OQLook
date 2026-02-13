Param(
  [switch]$SkipComposer,
  [switch]$SkipNpm,
  [switch]$SkipMigrate
)

$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path "$PSScriptRoot\..\..")

Write-Host '== OQLook setup (Windows) ==' -ForegroundColor Cyan

if (-not $SkipComposer) {
  Write-Host 'Installing PHP dependencies...' -ForegroundColor Yellow
  composer install
}

if (-not $SkipNpm) {
  Write-Host 'Installing Node dependencies...' -ForegroundColor Yellow
  npm install
  Write-Host 'Building assets...' -ForegroundColor Yellow
  npm run build
}

Write-Host 'Generating app key...' -ForegroundColor Yellow
php artisan key:generate --force

Write-Host 'Clearing caches...' -ForegroundColor Yellow
php artisan optimize:clear

if (-not $SkipMigrate) {
  Write-Host 'Running migrations...' -ForegroundColor Yellow
  php artisan migrate --force
}

Write-Host 'Done.' -ForegroundColor Green
