Param(
  [string]$AppDir = (Resolve-Path "$PSScriptRoot\..\..\..").Path,
  [switch]$InstallDeps,
  [switch]$SkipBuild,
  [switch]$SkipMigrate,
  [switch]$InstallPostgres,
  [switch]$InstallRedis,
  [string]$PhpBin = "php",
  [string]$ComposerBin = "composer",
  [string]$NpmBin = "npm"
)

$ErrorActionPreference = "Stop"

function Test-CommandExists {
  param([Parameter(Mandatory = $true)][string]$Name)
  return $null -ne (Get-Command $Name -ErrorAction SilentlyContinue)
}

function Invoke-WingetInstall {
  param(
    [Parameter(Mandatory = $true)][string]$Id,
    [string]$Label = $Id
  )

  Write-Host "Installing $Label ($Id)..." -ForegroundColor Yellow
  winget install --id $Id --exact --accept-source-agreements --accept-package-agreements --silent
}

Write-Host "== OQLook bootstrap (Windows) ==" -ForegroundColor Cyan
Write-Host "AppDir: $AppDir"

if (-not (Test-Path $AppDir)) {
  throw "App directory not found: $AppDir"
}

if ($InstallDeps) {
  if (-not (Test-CommandExists "winget")) {
    throw "winget not found. Install dependencies manually or rerun without -InstallDeps."
  }

  Invoke-WingetInstall -Id "OpenJS.NodeJS.LTS" -Label "Node.js LTS"
  Invoke-WingetInstall -Id "Composer.Composer" -Label "Composer"

  if ($InstallPostgres) {
    Invoke-WingetInstall -Id "PostgreSQL.PostgreSQL" -Label "PostgreSQL"
  }

  if ($InstallRedis) {
    Invoke-WingetInstall -Id "Memurai.MemuraiDeveloper" -Label "Memurai (Redis-compatible)"
  }

  Write-Host "Dependency install step finished." -ForegroundColor Green
}

Set-Location $AppDir

if (-not (Test-Path ".env") -and (Test-Path ".env.example")) {
  Copy-Item ".env.example" ".env"
  Write-Host "Created .env from .env.example" -ForegroundColor Green
}

if (-not (Test-CommandExists $ComposerBin)) {
  throw "Composer command not found: $ComposerBin"
}

if (-not (Test-CommandExists $PhpBin)) {
  throw "PHP command not found: $PhpBin"
}

Write-Host "Installing PHP dependencies..." -ForegroundColor Yellow
& $ComposerBin install --no-interaction --prefer-dist

if (-not $SkipBuild) {
  if (-not (Test-CommandExists $NpmBin)) {
    throw "npm command not found: $NpmBin"
  }

  Write-Host "Installing frontend dependencies..." -ForegroundColor Yellow
  if (Test-Path "package-lock.json") {
    & $NpmBin ci
  }
  else {
    & $NpmBin install
  }

  Write-Host "Building frontend..." -ForegroundColor Yellow
  & $NpmBin run build
}

Write-Host "Generating app key..." -ForegroundColor Yellow
& $PhpBin artisan key:generate --force

if (-not $SkipMigrate) {
  Write-Host "Running database migrations..." -ForegroundColor Yellow
  & $PhpBin artisan migrate --force
}

Write-Host "Clearing cache..." -ForegroundColor Yellow
& $PhpBin artisan optimize:clear

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host "Next steps:"
Write-Host "  1) Configure .env (DB, APP_URL, OQLIKE_*)"
Write-Host "  2) Optional worker: $PhpBin artisan queue:work --queue=default --tries=1"
Write-Host "  3) Configure web server to point to: $AppDir\public"

