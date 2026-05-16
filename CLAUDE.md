# BpTemplate — Ecotel Italia

App PHP+JS per la valutazione commesse e l'integrazione con Odoo. Refactor architetturale di
`bp-template` con storage SQLite, audit log, credenziali in `.env`, password hashate e
codice spezzato in moduli core/api/frontend.

Questo file dà a chi arriva sul progetto (umano o AI) il contesto per operarci senza ricostruirlo.

---

## 1. Scopo e funzionalità

App interna Ecotel per:
- comporre offerte commerciali con 5 sezioni di costo (Manodopera CCNL, Materiali, Servizi, Manutenzione, Trasferte)
- calcolare costi/markup/prezzi vendita/margini in tempo reale
- gestire flusso "richiesta sconto direzione" (user → supervisore via email)
- esportare PDF/Excel per il cliente e per l'archivio
- caricare PDF+Excel come allegati su `sale.order` in Odoo via JSON-RPC
- precompilare l'offerta da numero ordine Odoo (deep-link `?nOrdine=Sxxxxx`)

Stato: in produzione (dopo parità funzionale verificata con bp-template).

---

## 2. Architettura

```
BpTemplate/
├── index.html              # shell HTML, carica css + 5 script JS in ordine
├── css/app.css
├── js/
│   ├── categorie.js        # tabella CCNL → € per categoria (15 voci)
│   ├── calc.js             # calcAll(), uid(), pf(), fmt(), mc(), emptyForm()
│   ├── api.js              # wrapper fetch + helper fbSave*/fbDel*
│   ├── views.js            # renderLogin/List/Form/Riepilogo/AdminUsers/Profilo + chart
│   └── app.js              # stato globale, init, router, handler azioni
├── api/                    # endpoint thin: ognuno fa require_once core/* e chiama una funzione
│   ├── login.php           # POST -> verifica password, ritorna user
│   ├── utenti.php          # GET/POST/DELETE
│   ├── offerte.php         # GET/POST/DELETE
│   ├── cerca_odoo.php      # autocomplete sale.order
│   ├── leggi_odoo.php      # carica cliente/tipo/descrizione da Odoo
│   ├── allega_odoo.php     # upload PDF+XLSX come ir.attachment
│   ├── genera_pdf.php / genera_excel.php       # singola offerta
│   ├── archivio_pdf.php / archivio_excel.php   # lista filtrata
│   ├── notifica_sconto.php # email a supervisori/admin
│   └── reinvia_mail.php    # rinvio credenziali
├── core/
│   ├── config.php          # bp_load_env() + bp_env(); legge config/credentials.env
│   ├── db.php              # bp_db() PDO SQLite + bp_db_migrate() + CRUD utenti/offerte + bp_audit()
│   ├── calcoli.php         # bp_calc_all($form): SOURCE OF TRUTH PHP
│   ├── odoo.php            # bp_odoo_login()/bp_odoo_call_kw(): auth + JSON-RPC
│   ├── mailer.php          # bp_mailer() + bp_mail_credenziali() + bp_mail_richiesta_sconto()
│   ├── pdf.php             # bp_pdf_offerta() + bp_pdf_archivio() (Dompdf)
│   ├── excel.php           # bp_xlsx_offerta() + bp_xlsx_archivio() (PhpSpreadsheet)
│   └── bootstrap.php       # require di config+db+calcoli, helper json io, bp_actor()
├── config/
│   ├── credentials.env.template
│   └── credentials.env     # NON committare (vedi .gitignore)
├── data/
│   └── bp_template.db      # SQLite (auto-creato + auto-migrato al primo accesso)
├── migrations/
│   └── migrate_json_to_sqlite.php   # one-shot da bp-template legacy
├── manifest.json + service-worker.js   # PWA
├── reset.php               # azzera utenti+offerte (richiede ?token= configurato)
├── deploy.bat              # WinSCP sync verso upd.utterson.it
└── composer.json           # dompdf, phpspreadsheet, phpmailer
```

### Flusso request tipico

```
browser  →  api/<endpoint>.php
              ├── require_once core/bootstrap.php   (config, db, calcoli, helper)
              ├── require_once core/<altro>.php     (mailer/odoo/pdf/excel se serve)
              ├── bp_cors_json()                    (header CORS+JSON)
              ├── bp_json_input()                   (decodifica POST body)
              ├── bp_actor()                        (id utente da X-Actor-Id)
              ├── bp_<azione>(...)                  (chiamata a core)
              ├── bp_audit(...)                     (traccia su audit_log)
              └── bp_json_out([...])                (risposta + exit)
```

---

## 3. Convenzioni critiche

### 3.1 Source of truth duplicato: calcAll

La funzione di calcolo esiste in due posti:
- **`js/calc.js` → `calcAll(f)`** (per UI live)
- **`core/calcoli.php` → `bp_calc_all($f)`** (per PDF/Excel/allegati Odoo)

Le due implementazioni **devono restare allineate**. Se cambi una formula, cambia entrambe nello stesso commit.

Convenzioni:
- input chiavi camelCase (`scontoStato`, `nOrdineOdoo`, `speseGenerali`)
- numeri parsati con `pf()`/`bp_pf()` per tollerare stringhe vuote e null
- output identico: stesse chiavi, stessi totali, stesso ordine di calcolo

### 3.2 Audit log

Ogni mutazione (create/update/delete) di utenti/offerte e ogni evento di auth viene loggato in `audit_log`:

```sql
ts, user_id, user_name, action, entity, entity_id, detail
```

`action` valori usati: `create`, `update`, `delete`, `login`, `login_fail`, `attach_odoo`, `mail_sconto`, `mail_credenziali`, `reset`.

L'attore è preso dall'header `X-Actor-Id` (impostato dal frontend dopo il login). **Non è autenticazione forte** — un client malintenzionato può falsificarla. Vedi TODO §5.

### 3.3 Storage

SQLite con WAL. Schema:
- `utenti` (id, nome, username UNIQUE, password_hash, ruolo, email, first_login, created_at, updated_at)
- `offerte` (colonne searchable + `payload_json` con il resto)
- `audit_log`

Il DB viene creato + migrato automaticamente al primo accesso (`bp_db()` chiama `bp_db_migrate()` e `bp_db_seed_admin()`). Account di default: `admin / admin123`.

### 3.4 Credenziali

Tutto via `config/credentials.env`:
- `ODOO_URL`, `ODOO_DB`, `ODOO_USERNAME`, `ODOO_PASSWORD`
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME`
- `APP_URL`, `RESET_TOKEN`

Niente hardcoded nei PHP. Path autoload Composer è `__DIR__/../vendor/autoload.php` (relativo, portabile).

### 3.5 Password

Mai in chiaro in DB. `password_hash($plain, PASSWORD_DEFAULT)` in scrittura, `password_verify()` in login. `bp_utente_upsert()` accetta opzionalmente `$plainPassword`: se fornita la rihasha, altrimenti mantiene l'hash esistente.

### 3.6 Endpoint thin

Gli endpoint in `api/` non contengono logica business — solo:
- header CORS/JSON
- decodifica input
- delega a funzioni `bp_*` in `core/`
- audit
- risposta

Se aggiungi un endpoint, segui questo pattern. Niente SQL diretto in `api/`, niente HTML diretto, niente curl diretto a Odoo.

---

## 4. Deploy

### Locale (sviluppo)

```powershell
# php -S 127.0.0.1:8000   (oppure WAMP / XAMPP)
cd "C:\Users\lranalletta\Documents\Progetti CLAUDE\BpTemplate"
composer install
# config/credentials.env già popolato per dev locale
```

### Server (produzione)

`deploy.bat` sincronizza via WinSCP escludendo `data/*.db`, `vendor/`, `config/credentials.env`, log.

Sul server (Linux, `/var/www/html/bp-template`):
```bash
composer install --no-dev   # solo se composer.json cambia
chown -R www-data:www-data data
```

URL pubblico: `https://servizi.utterson.it:8443/bp-template/`

### Migrazione da bp-template legacy

```bash
php migrations/migrate_json_to_sqlite.php /var/www/html/bp-template/api/data
```

Le password legacy (in chiaro) vengono rihashate. Gli utenti continuano ad accedere con le stesse credenziali. **Idempotente** — può essere rilanciato.

---

## 5. TODO / backlog architetturale

1. **Sessioni server-side**: oggi il client manda `X-Actor-Id` per l'audit, ma gli endpoint non lo verificano contro una sessione. Implementare token JWT o `$_SESSION` PHP per autenticazione vera + autorizzazione per ruolo lato server.
2. **CSRF token** sulle POST mutanti (utenti.php, offerte.php).
3. **Test automatici**: unit per `bp_calc_all` ↔ `calcAll` (parità con fixture comuni); smoke E2E per login/save/export.
4. **Backup giornaliero** di `data/bp_template.db` (cron + rotazione).
5. **Service account Odoo** dedicato (oggi usato l'account personale `lranalletta@ecotelitalia.it`).
6. **Pannello audit** in admin per consultare gli ultimi eventi.

---

## 6. Stile collaborazione

- Comunicazione in **italiano**
- Modifiche **chirurgiche** con `Edit` mirati. Non rigenerare interi file se basta una riga.
- Quando tocchi `calcAll` aggiorna **entrambe** le implementazioni nello stesso commit.
- Prima di aggiungere un'astrazione, controlla se esiste già in `core/`.
- Niente nuovi script di test/diagnostici sparsi in root: usano `tests/` (da creare quando serve).
