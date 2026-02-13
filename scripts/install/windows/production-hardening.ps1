Param(
  [string]$AppDir = (Resolve-Path "$PSScriptRoot\..\..\..").Path,
  [string]$PhpBin = "php",
  [string]$TaskPrefix = "OQLook",
  [string]$Queue = "default",
  [int]$QueueTries = 1,
  [int]$QueueSleep = 3,
  [int]$QueueTimeout = 0,
  [int]$QueueMemory = 512,
  [int]$QueueMaxTime = 3600,
  [int]$WatchdogEveryMinutes = 2,
  [int]$WatchdogLimit = 100,
  [switch]$SkipQueueWorker,
  [switch]$SkipWatchdog
)

$ErrorActionPreference = "Stop"

function New-RepeatingTrigger {
  param(
    [Parameter(Mandatory = $true)][int]$EveryMinutes
  )

  $start = (Get-Date).AddMinutes(1)
  return New-ScheduledTaskTrigger -Once -At $start -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes) -RepetitionDuration (New-TimeSpan -Days 3650)
}

function Register-OQLookTask {
  param(
    [Parameter(Mandatory = $true)][string]$Name,
    [Parameter(Mandatory = $true)]$Action,
    [Parameter(Mandatory = $true)]$Trigger,
    [string]$Description = ""
  )

  $principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
  $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)

  Register-ScheduledTask -TaskName $Name -Action $Action -Trigger $Trigger -Principal $principal -Settings $settings -Description $Description -Force | Out-Null
  Write-Host "Task registered: $Name" -ForegroundColor Green
}

Write-Host "== OQLook production hardening (Windows) ==" -ForegroundColor Cyan
Write-Host "AppDir: $AppDir"
Write-Host "TaskPrefix: $TaskPrefix"

if (-not (Test-Path $AppDir)) {
  throw "App directory not found: $AppDir"
}

$loopScript = Join-Path $PSScriptRoot "queue-worker-loop.ps1"
if (-not (Test-Path $loopScript)) {
  throw "Missing script: $loopScript"
}

if (-not $SkipQueueWorker) {
  $queueTaskName = "$TaskPrefix-QueueWorker"
  $queueArgList = @(
    "-NoProfile",
    "-ExecutionPolicy", "Bypass",
    "-File", ('"{0}"' -f $loopScript),
    "-AppDir", ('"{0}"' -f $AppDir),
    "-PhpBin", ('"{0}"' -f $PhpBin),
    "-Queue", ('"{0}"' -f $Queue),
    "-Tries", $QueueTries,
    "-Sleep", $QueueSleep,
    "-Timeout", $QueueTimeout,
    "-Memory", $QueueMemory,
    "-MaxTime", $QueueMaxTime
  ) -join " "

  $queueAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $queueArgList
  $queueTrigger = New-ScheduledTaskTrigger -AtStartup
  Register-OQLookTask -Name $queueTaskName -Action $queueAction -Trigger $queueTrigger -Description "OQLook queue worker loop"
}
else {
  Write-Host "Skipping queue worker task." -ForegroundColor DarkGray
}

if (-not $SkipWatchdog) {
  $watchdogTaskName = "$TaskPrefix-Watchdog"
  $watchdogCommand = 'Set-Location "{0}"; & "{1}" artisan oqlike:watchdog --limit={2}' -f $AppDir, $PhpBin, $WatchdogLimit
  $watchdogArgList = @(
    "-NoProfile",
    "-ExecutionPolicy", "Bypass",
    "-Command", ('"{0}"' -f $watchdogCommand)
  ) -join " "

  $watchdogAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $watchdogArgList
  $watchdogTrigger = New-RepeatingTrigger -EveryMinutes $WatchdogEveryMinutes
  Register-OQLookTask -Name $watchdogTaskName -Action $watchdogAction -Trigger $watchdogTrigger -Description "OQLook scan watchdog"
}
else {
  Write-Host "Skipping watchdog task." -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host "Validate tasks:"
Write-Host "  Get-ScheduledTask -TaskName '$TaskPrefix-*' | Format-Table TaskName,State"
Write-Host "Run immediately (optional):"
Write-Host "  Start-ScheduledTask -TaskName '$TaskPrefix-QueueWorker'"
Write-Host "  Start-ScheduledTask -TaskName '$TaskPrefix-Watchdog'"
