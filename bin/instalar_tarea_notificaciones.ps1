$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$powershell = (Get-Command powershell.exe -ErrorAction Stop).Source
$php = (Get-Command php -ErrorAction Stop).Source
$worker = Join-Path $PSScriptRoot 'run_notificaciones.ps1'
$taskName = 'Arena7-Notificaciones'

$action = New-ScheduledTaskAction -Execute $powershell -Argument ('-NoProfile -File "' + $worker + '" -PhpPath "' + $php + '"') -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -StartWhenAvailable

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Write-Host "Tarea $taskName instalada para procesar correos cada minuto mientras este usuario tenga sesión iniciada."
