@echo off
chcp 65001 >nul
title BP Template v2 - Deploy verso UTWGENWEB02 (Windows)

REM Deploy via SMB share verso la VM UTWGENWEB02 (10.1.2.122) Windows Laragon.
REM Sostituisce il vecchio deploy.bat che puntava a upd.utterson.it Linux (defunto da v28, 2026-05-02).
REM
REM Esegui questo script dalla cartella locale di sviluppo (C:\laragon\www\bp-template-v2\) sul PC dev.
REM Requisiti: il PC dev deve essere sulla LAN aziendale, lo share \\UTWGENWEB02\c$ accessibile in scrittura.

set REMOTE=\\UTWGENWEB02\c$\laragon\www\bp-template-v2
set LOCAL=%~dp0

echo.
echo  +======================================+
echo  ^|  BP Template v2 - Deploy verso VM   ^|
echo  +======================================+
echo.
echo  Locale : %LOCAL%
echo  Remoto : %REMOTE%
echo.

REM Test raggiungibilita' share
if not exist "%REMOTE%\" (
    echo  [ERRORE] Share remoto non raggiungibile: %REMOTE%
    echo  Verifica: rete LAN aziendale, share admin abilitato, credenziali.
    pause
    exit /b 1
)

echo  Avvio robocopy con esclusioni standard...
echo.

REM Esclusioni:
REM   /XF: file (DB SQLite, credenziali env, log, zip, backup, test/debug temporanei)
REM   /XD: cartelle (vendor=rigenerato con composer, .git, node_modules, backup locali, data/backups)
robocopy "%LOCAL%" "%REMOTE%" /MIR ^
    /XF "*.db" "*.db-journal" "*.db-shm" "*.db-wal" "credentials.env" "*.log" "*.zip" "_smoke_*.php" "_perf_*.php" "_unlock_*.php" "_opcache_*.php" "_check_*.php" "__test*.php" ^
    /XD vendor .git node_modules "_backup_*" "data\backups" ^
    /R:1 /W:1 /NP /TEE /LOG:"%LOCAL%deploy.log"

set RC=%ERRORLEVEL%

echo.
if %RC% LSS 8 (
    echo  [OK] Deploy completato. URL: http://bptemplate.ecotelitalia.it:8080/
    echo.
    echo  Promemoria:
    echo    - opcache su VM e' disabilitato, modifiche attive subito
    echo    - se composer.json e' cambiato, su VM esegui: composer install --no-dev
    echo    - se config\credentials.env e' cambiato in dev, NON viene sincronizzato (escluso)
    echo      aggiornare manualmente su VM tramite RDP o cmd Admin
) else (
    echo  [ERRORE] robocopy ha restituito codice %RC%. Vedi deploy.log
)

echo.
pause
