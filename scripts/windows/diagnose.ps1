$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path "$PSScriptRoot\..\..")

Write-Host '== OQLook diagnose ==' -ForegroundColor Cyan
Write-Host "PHP: $(php -v | Select-Object -First 1)"
Write-Host "Node: $(node -v)"
Write-Host "NPM : $(npm -v)"
Write-Host '---- where php ----'
where.exe php
Write-Host '---- where node ----'
where.exe node
Write-Host '---- where npm ----'
where.exe npm
