# BP Template v2 - Registrazione task Scheduler giornaliero per backup.bat
# Da eseguire UNA volta in PowerShell come Amministratore sulla VM UTWGENWEB02.
#
# Uso (RDP sulla VM, PowerShell Admin):
#   cd C:\laragon\www\bp-template-v2\tools
#   .\schedule_backup_daily.ps1
#
# Crea un task "BP Template v2 - Backup DB" che gira ogni giorno alle 02:00.
# Il task esegue backup.bat che produce un file in data\backups\ con rotazione 30 file.

$taskName  = "BP Template v2 - Backup DB"
$batPath   = "C:\laragon\www\bp-template-v2\backup.bat"
$workDir   = "C:\laragon\www\bp-template-v2"

if (-not (Test-Path $batPath)) {
    Write-Host "[ERRORE] backup.bat non trovato in $batPath" -ForegroundColor Red
    exit 1
}

# Rimuovi vecchio task se esiste (idempotenza)
$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Task '$taskName' gia' esistente, lo rimuovo per ricrearlo..." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

# Registra task: gira ogni giorno alle 02:00 come SYSTEM (no password richiesta)
$action    = New-ScheduledTaskAction -Execute $batPath -WorkingDirectory $workDir
$trigger   = New-ScheduledTaskTrigger -Daily -At "02:00"
$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
$settings  = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "Backup giornaliero DB SQLite BP Template v2 (rotazione 30 file)" | Out-Null

Write-Host "[OK] Task '$taskName' registrato. Esegue ogni giorno alle 02:00." -ForegroundColor Green
Write-Host ""
Write-Host "Per testarlo subito senza aspettare le 02:00:"
Write-Host "  Start-ScheduledTask -TaskName '$taskName'"
Write-Host "  Get-ChildItem C:\laragon\www\bp-template-v2\data\backups\"
Write-Host ""
Write-Host "Per vederlo nella GUI: Task Scheduler -> Libreria Task Scheduler -> '$taskName'"
