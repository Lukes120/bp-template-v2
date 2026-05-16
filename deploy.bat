@echo off
chcp 65001 >nul
title BP Template v2 - Deploy parallelo su upd.utterson.it

set SERVER=upd.utterson.it
set USER=root
set REMOTE_PATH=/var/www/html/bp-template-v2
set LOCAL_PATH=%~dp0

set WINSCP="C:\Program Files (x86)\WinSCP\WinSCP.com"

echo.
echo  +======================================+
echo  ^|   BP Template - Deploy automatico   ^|
echo  +======================================+
echo.
echo  Server : %SERVER%
echo  Utente : %USER%
echo  Remoto : %REMOTE_PATH%
echo  Locale : %LOCAL_PATH%
echo.

if not exist %WINSCP% (
    echo  [ERRORE] WinSCP non trovato in %WINSCP%
    pause
    exit /b 1
)

echo  Avvio sincronizzazione...
echo  NOTA: data\bp_template.db, vendor\, config\credentials.env
echo        sono esclusi (vedi .winscp_exclude)
echo.

set SCRIPT=%TEMP%\bp_deploy.txt
(
echo open sftp://%USER%@%SERVER%
echo option confirm off
echo option exclude "data/*.db; data/*.db-journal; vendor/; config/credentials.env; *.log; deploy.log; .git/"
echo synchronize remote -delete "%LOCAL_PATH%\" "%REMOTE_PATH%"
echo exit
) > "%SCRIPT%"

%WINSCP% /script="%SCRIPT%" /log="%LOCAL_PATH%deploy.log"

if %errorlevel% equ 0 (
    echo.
    echo  [OK] Deploy completato!
    echo  Sito: https://servizi.utterson.it:8443/bp-template-v2/
    echo.
    echo  Sul server, ricordati di:
    echo    cd %REMOTE_PATH%
    echo    composer install --no-dev    # solo la prima volta o se composer.json cambia
    echo    chown -R www-data:www-data data
) else (
    echo.
    echo  [ERRORE] Controlla deploy.log per dettagli.
)

del "%SCRIPT%" >nul 2>&1
echo.
pause
