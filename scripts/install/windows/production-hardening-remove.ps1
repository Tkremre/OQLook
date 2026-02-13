Param(
  [string]$TaskPrefix = "OQLook",
  [switch]$SkipQueueWorker,
  [switch]$SkipWatchdog
)

$ErrorActionPreference = "Stop"

function Remove-OQLookTask {
  param(
    [Parameter(Mandatory = $true)][string]$Name
  )

  $task = Get-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue
  if (-not $task) {
    Write-Host "Task not found: $Name" -ForegroundColor DarkGray
    return
  }

  try {
    Stop-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue
  }
  catch {
    # Task may already be stopped.
  }

  Unregister-ScheduledTask -TaskName $Name -Confirm:$false
  Write-Host "Task removed: $Name" -ForegroundColor Green
}

Write-Host "== OQLook production hardening rollback (Windows) ==" -ForegroundColor Cyan
Write-Host "TaskPrefix: $TaskPrefix"

if (-not $SkipQueueWorker) {
  Remove-OQLookTask -Name "$TaskPrefix-QueueWorker"
}
else {
  Write-Host "Skipping queue worker task removal." -ForegroundColor DarkGray
}

if (-not $SkipWatchdog) {
  Remove-OQLookTask -Name "$TaskPrefix-Watchdog"
}
else {
  Write-Host "Skipping watchdog task removal." -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "Rollback complete." -ForegroundColor Green
