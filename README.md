# BP Template v2 — Valutazione Commesse

App PHP+JS interna Ecotel Italia per composizione offerte commerciali con calcolo
marginalita' realtime e integrazione Odoo (autocomplete commessa, lettura, allega
PDF/XLSX a `sale.order`).

## Architettura

```
bp-template-v2/
├── index.html              # shell HTML, importa css + 5 script JS
├── css/app.css
├── js/
│   ├── categorie.js        # tabella CCNL €/h
│   ├── calc.js             # calcAll, MARGINE_THRESHOLDS  ← source of truth JS
│   ├── api.js              # wrapper fetch verso /api/*
│   ├── views.js            # render UI (login/list/form/riepilogo/admin)
│   └── app.js              # stato globale, router, handler azioni
├── api/                    # endpoint PHP thin (auth + delega a core/)
├── core/
│   ├── config.php          # loader credentials.env
│   ├── bootstrap.php       # CORS/JSON, auth, CSRF, actor
│   ├── db.php              # SQLite + schema + audit + sessioni + rate limit
│   ├── calcoli.php         # bp_calc_all + BP_MARGINE_GREEN/YELLOW  ← source of truth PHP
│   ├── odoo.php            # XML-RPC client (admin + API key, keep-alive curl, cache uid)
│   ├── mailer.php          # PHPMailer (Aruba SMTPS 465)
│   ├── pdf.php             # Dompdf
│   └── excel.php           # PhpSpreadsheet (con conditional formatting margine)
├── config/
│   ├── credentials.env.template
│   └── credentials.env     # NON committare, NON sincronizzare in deploy
├── data/                   # bp_template.db (auto-creato al primo avvio) + backups/
├── tests/
│   └── calcoli.test.php    # parita' bp_calc_all (30 assert, ~10 ms)
├── tools/
│   ├── backfill_odoo_uid.php
│   └── schedule_backup_daily.ps1  # registra Task Scheduler giornaliero
├── migrations/
│   └── migrate_json_to_sqlite.php
├── .htaccess               # blocca file sensitive (.env/.db/.bak/.log)
├── manifest.json + service-worker.js   # PWA
├── reset.php               # reset emergenza (CLI o localhost + token)
├── backup.bat              # backup giornaliero SQLite con rotazione
├── deploy.bat              # deploy robocopy verso VM UTWGENWEB02
└── composer.json
```

## Setup sviluppo (locale Laragon)

```powershell
# 1. Configurare credenziali (richiede ODOO_API_KEY, SMTP_*, RESET_TOKEN)
copy config\credentials.env.template config\credentials.env
# poi editare i valori

# 2. Installare dipendenze PHP
composer install

# 3. Avviare Laragon (Apache + PHP 8.3)
# URL locale: http://bp-template-v2.test/
```

Al primo avvio il DB `data/bp_template.db` viene creato automaticamente. La password
admin viene generata casualmente e salvata in `data/INITIAL_ADMIN_PASSWORD.txt`
(permessi 0600). **Cambiarla al primo login**.

## Setup produzione (VM UTWGENWEB02)

- VM Windows `UTWGENWEB02` (10.1.2.122) — Laragon Full + Apache:8080 + PHP 8.3.30
- Path app: `C:\laragon\www\bp-template-v2\`
- URL canonico: `http://bptemplate.ecotelitalia.it:8080/` (LAN aziendale)
- HTTP only — TLS terminato da eventuale reverse proxy IT

Dipendenze runtime PHP: `pdo_sqlite`, `openssl`, `curl`, `simplexml`, `mbstring`,
`fileinfo`, `gd` (per dompdf).

## Deploy

Da PC dev sulla LAN aziendale (share `\\UTWGENWEB02\c$` raggiungibile):

```cmd
deploy.bat
```

Esegue `robocopy /MIR` con esclusioni standard (DB, vendor, credentials.env, log,
zip, backup, file temporanei `_smoke_*`/`_perf_*`/`__test*`). Vedi `deploy.log`
dopo l'esecuzione.

Su VM: opcache PHP disabilitato → modifiche attive subito senza restart Apache.
Se composer.json cambia, eseguire manualmente sulla VM:

```cmd
cd C:\laragon\www\bp-template-v2
composer install --no-dev
```

## Backup DB

Lo script `backup.bat` esegue `sqlite3 .backup` (atomico, WAL-safe) verso
`data/backups/bp_template_YYYYMMDD_HHMM.db` con rotazione 30 file.

Per schedularlo giornaliero (02:00) sulla VM, in PowerShell Admin:

```powershell
cd C:\laragon\www\bp-template-v2\tools
.\schedule_backup_daily.ps1
```

Verifica: `Get-ScheduledTask -TaskName "BP Template v2 - Backup DB"`. Run manuale:
`Start-ScheduledTask -TaskName "BP Template v2 - Backup DB"`.

## Recovery / restauro su nuova VM

1. Installare Laragon Full + PHP 8.3 con estensioni elencate sopra
2. Clonare `Lukes120/bp-template-v2` in `C:\laragon\www\bp-template-v2\`
3. Configurare `config/credentials.env` (NON committato — recuperare valori dalla VM precedente o da gestione segreti IT)
4. `composer install --no-dev`
5. Ripristinare ultimo backup `data/bp_template.db` dalla cartella `data/backups/`
6. Configurare vhost Apache per `bptemplate.ecotelitalia.it:8080` con DocumentRoot sulla cartella
7. Schedulare `backup.bat` (vedi sezione sopra)
8. Smoke test: `http://bptemplate.ecotelitalia.it:8080/` → login admin + autocomplete Odoo + allega

In caso di emergenza login admin perso: usare `reset.php` da localhost sulla VM
(o CLI: `php reset.php <RESET_TOKEN>`) — cancella tutti gli utenti e ricrea admin
con password random in `INITIAL_ADMIN_PASSWORD.txt`. **Operazione distruttiva**.

## Sicurezza (in produzione)

- Sessioni server-side via cookie `bp_session` httpOnly (TTL **24h sliding** rinnovata ad ogni request autenticata, `BP_SESSION_TTL`)
- Anti session fixation: vecchie sessioni utente cancellate al login
- CSRF double-submit cookie `bp_csrf` (SameSite=Strict) + header `X-Bp-Csrf` su tutti i POST/DELETE
- Password bcrypt cost 12
- Rate limit login: 5 tentativi falliti / 15min → blocco 30min (tabella `login_attempts`)
- Audit log su ogni mutazione (utenti, offerte, login, allega Odoo, csrf_fail, validation_fail)
- Range validation server su markup/spese/qta/costi (anti-tampering)
- Security headers: CSP, X-Frame-Options DENY, Referrer-Policy, Permissions-Policy, HSTS se HTTPS
- `.htaccess` root + `data/` + `config/` + `migrations/` bloccano accesso a `.env`/`.db`/`.bak`
- Integrazione Odoo: service account `admin` + API key (XML-RPC dal v=57). SSO utenti con password personale Odoo.

## TODO architetturali

- Pannello audit UI in admin (endpoint `api/audit.php` già pronto, manca view JS)
- Reverse proxy HTTPS con `X-Forwarded-For` per rate limit accurato dietro proxy
- Test JS calc.js parallelo a `tests/calcoli.test.php` (oggi solo PHP testato)
- Migrazione FK CASCADE su SQLite (richiede ricreazione tabelle, basso ROI)
