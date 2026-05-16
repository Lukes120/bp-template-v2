@echo off
REM BP Template - Backup automatico DB SQLite.
REM Da schedulare con Task Scheduler di Windows (es. ogni notte alle 02:00).
REM Usage: backup.bat
REM
REM Output: data\backups\bp_template_YYYYMMDD_HHMM.db
REM Mantiene gli ultimi 30 file nella cartella backups, elimina i piu' vecchi.

setlocal enabledelayedexpansion

set ROOT=%~dp0
set DB=%ROOT%data\bp_template.db
set BACKUP_DIR=%ROOT%data\backups
set RETAIN=30

if not exist "%DB%" (
    echo [ERRORE] DB non trovato: %DB%
    exit /b 1
)

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

REM Timestamp YYYYMMDD_HHMM
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value ^| find "="') do set DT=%%I
set TS=%DT:~0,8%_%DT:~8,4%

set OUT=%BACKUP_DIR%\bp_template_%TS%.db

REM Copia atomica via VACUUM (fail-safe anche se DB e' in WAL)
REM Se sqlite3.exe e' nel PATH usa quello, altrimenti fallback a copy semplice
where sqlite3 >nul 2>nul
if %errorlevel%==0 (
    sqlite3 "%DB%" ".backup '%OUT%'"
) else (
    copy /Y "%DB%" "%OUT%" >nul
)

if not exist "%OUT%" (
    echo [ERRORE] Backup non creato.
    exit /b 1
)

echo [OK] Backup: %OUT%

REM Rotazione: tieni solo gli ultimi RETAIN file
set N=0
for /f "delims=" %%F in ('dir /b /o-d "%BACKUP_DIR%\bp_template_*.db" 2^>nul') do (
    set /a N+=1
    if !N! GTR %RETAIN% (
        del "%BACKUP_DIR%\%%F"
        echo [PURGE] %%F
    )
)

endlocal
