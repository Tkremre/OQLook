$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path "$PSScriptRoot\..\..")

Write-Host '== OQLook rebuild assets ==' -ForegroundColor Cyan
npm install
npm run build
php artisan optimize:clear
Write-Host 'Assets rebuilt.' -ForegroundColor Green
