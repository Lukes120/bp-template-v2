# BP Template — Valutazione Commesse

Refactor di `bp-template` con architettura modulare: SQLite + audit log, calcoli unificati,
credenziali in `.env`, password hashate, frontend split in moduli.

## Struttura

```
BpTemplate/
├── index.html              # shell HTML, importa css + js
├── css/app.css             # tutti gli stili
├── js/
│   ├── categorie.js        # tabella CCNL €/h
│   ├── calc.js             # calcAll, fmt, pf, mc, emptyForm  ← source of truth JS
│   ├── api.js              # wrapper fetch verso /api/*
│   ├── views.js            # renderLogin/List/Form/Riepilogo/AdminUsers/Profilo + chart
│   └── app.js              # stato globale, router, handler azioni
├── api/                    # endpoint PHP sottili (chiamano core/*)
├── core/
│   ├── config.php          # loader credentials.env
│   ├── db.php              # SQLite + schema + audit + CRUD utenti/offerte
│   ├── calcoli.php         # bp_calc_all  ← source of truth PHP
│   ├── odoo.php            # auth + call_kw helpers
│   ├── mailer.php          # PHPMailer wrapper
│   ├── pdf.php             # Dompdf (offerta + archivio)
│   ├── excel.php           # PhpSpreadsheet (offerta + archivio)
│   └── bootstrap.php       # require comuni + helper json + actor
├── config/
│   ├── credentials.env.template
│   └── credentials.env     # NON committare
├── data/                   # bp_template.db creato al primo avvio
├── migrations/
│   └── migrate_json_to_sqlite.php
├── manifest.json + service-worker.js   # PWA
├── reset.php               # reset DB con token
├── deploy.bat              # WinSCP sync
└── composer.json
```

## Setup

### 1. Configurare credenziali

```bash
cp config/credentials.env.template config/credentials.env
# poi editare e popolare ODOO_*, SMTP_*, RESET_TOKEN
```

### 2. Installare dipendenze PHP (sul server)

```bash
composer install --no-dev
```

### 3. Migrazione dati legacy (se esiste un'installazione bp-template)

```bash
php migrations/migrate_json_to_sqlite.php /var/www/html/bp-template/api/data
```

Il DB `data/bp_template.db` viene creato al primo accesso anche senza migrazione,
con account default `admin / admin123`.

### 4. Permessi cartella data

Il webserver deve poter scrivere in `data/`:

```bash
chown -R www-data:www-data data
```

## Cosa è cambiato rispetto a bp-template

| Tema | bp-template | BpTemplate |
|------|-------------|------------|
| Storage | JSON files | SQLite + WAL |
| Audit | Nessuno | Tabella `audit_log` su tutte le mutazioni |
| Password | Plaintext in JSON | `password_hash` (bcrypt) |
| Login | Confronto JS lato client | Endpoint `api/login.php` con `password_verify` |
| Credenziali | Hardcoded nei PHP | `config/credentials.env` |
| Frontend | 1 file `index.html` 920 righe | shell + 5 moduli JS + CSS separato |
| Backend PHP | Logica nei singoli endpoint | `core/` riusabile, `api/` thin |
| Calcoli | Duplicati JS+PHP in più posti | 1 file JS + 1 file PHP, marcati come "source of truth" |
| Path autoload | Hardcoded `/var/www/...` | `__DIR__`-based (portabile locale/server) |

## Funzionalità (parità completa con bp-template)

- Login multi-ruolo (admin / supervisore / user) con primo accesso → cambio password
- Dashboard offerte con KPI (totale, ricavi, margine €, margine %)
- Filtro per utente (admin/supervisore vedono tutto)
- CRUD offerta: 5 sezioni (Manodopera, Materiali, Servizi, Manutenzione, Trasferte)
- Calcolo live di costo/markup/prezzo vendita/margine
- Spese generali e Sconto Direzione (% o EUR) con flusso approvazione
- Notifica email a supervisori per richiesta sconto
- Riepilogo con grafico a barre per voce
- Export PDF / Excel singola offerta
- Export PDF / Excel archivio (con filtri applicati)
- Integrazione Odoo:
  - autocomplete numero ordine
  - lettura cliente/tipo/descrizione
  - upload PDF + XLSX come allegati a `sale.order`
  - deep-link `?nOrdine=Sxxxxx`
- Admin: CRUD utenti, reinvio mail credenziali
- Profilo: cambio nome/username + cambio password
- PWA installabile, service-worker per asset offline

## TODO architetturali (non bloccanti per la parità)

- Token di sessione lato server (oggi gli endpoint si fidano del client per `X-Actor-*`)
- CSRF token sulle POST mutanti
- Backup automatico schedulato del file SQLite
- Test automatici (unit per `calcoli.php`/`calc.js`, end-to-end per i flussi)
