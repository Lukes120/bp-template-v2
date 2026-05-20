<?php
/**
 * SQLite (PDO) + schema + audit log.
 * Crea data/bp_template.db al primo accesso, applica migrazioni idempotenti,
 * inserisce account admin di default.
 */

function bp_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dbPath = __DIR__ . '/../data/bp_template.db';
    if (!is_dir(dirname($dbPath))) mkdir(dirname($dbPath), 0775, true);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    bp_db_migrate($pdo);
    bp_db_seed_admin($pdo);
    return $pdo;
}

function bp_db_migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS utenti (
            id            TEXT PRIMARY KEY,
            nome          TEXT NOT NULL,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            ruolo         TEXT NOT NULL CHECK(ruolo IN ('admin','supervisore','viewer','user')),
            email         TEXT,
            odoo_uid      INTEGER,
            first_login   INTEGER NOT NULL DEFAULT 1,
            created_at    TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    // Migrazione idempotente: aggiunge odoo_uid se manca (DB esistenti)
    try {
        $cols = $pdo->query("PRAGMA table_info(utenti)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('odoo_uid', $names, true)) {
            $pdo->exec("ALTER TABLE utenti ADD COLUMN odoo_uid INTEGER");
        }
    } catch (Throwable $e) { /* ignore */ }
    // Migrazione CHECK constraint: SQLite non supporta ALTER COLUMN CHECK.
    // Se la tabella esistente ammette solo (admin,supervisore,user), ricreiamola con
    // CHECK aggiornato che include 'viewer'. Idempotente: skip se viewer e' gia' valido.
    try {
        $row = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='utenti' LIMIT 1")->fetch();
        if ($row && stripos($row['sql'], "'viewer'") === false) {
            $pdo->exec("BEGIN");
            $pdo->exec("CREATE TABLE utenti_new (
                id            TEXT PRIMARY KEY,
                nome          TEXT NOT NULL,
                username      TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                ruolo         TEXT NOT NULL CHECK(ruolo IN ('admin','supervisore','viewer','user')),
                email         TEXT,
                odoo_uid      INTEGER,
                first_login   INTEGER NOT NULL DEFAULT 1,
                created_at    TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
            )");
            $pdo->exec("INSERT INTO utenti_new SELECT id, nome, username, password_hash, ruolo, email, odoo_uid, first_login, created_at, updated_at FROM utenti");
            $pdo->exec("DROP TABLE utenti");
            $pdo->exec("ALTER TABLE utenti_new RENAME TO utenti");
            $pdo->exec("COMMIT");
        }
    } catch (Throwable $e) {
        @$pdo->exec("ROLLBACK");
        error_log("bp_db_migrate ruolo viewer fail: " . $e->getMessage());
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offerte (
            id              TEXT PRIMARY KEY,
            user_id         TEXT,
            user_name       TEXT,
            nome            TEXT NOT NULL,
            cliente         TEXT,
            tipo            TEXT,
            n_ordine_odoo   TEXT,
            data            TEXT,
            note            TEXT,
            spese_generali  TEXT DEFAULT '5',
            sconto_stato    TEXT,
            sconto_tipo     TEXT,
            sconto_valore   REAL,
            sconto_nota     TEXT,
            allegata_odoo   TEXT,
            prezzo_imposto_attivo INTEGER NOT NULL DEFAULT 0,
            prezzo_imposto_valore REAL,
            overmarkup      INTEGER NOT NULL DEFAULT 0,
            payload_json    TEXT NOT NULL,
            created_at      TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    // Migrazione idempotente: aggiunge le colonne se mancano (per DB esistenti)
    try {
        $cols = $pdo->query("PRAGMA table_info(offerte)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('allegata_odoo', $names, true)) {
            $pdo->exec("ALTER TABLE offerte ADD COLUMN allegata_odoo TEXT");
        }
        if (!in_array('prezzo_imposto_attivo', $names, true)) {
            $pdo->exec("ALTER TABLE offerte ADD COLUMN prezzo_imposto_attivo INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('prezzo_imposto_valore', $names, true)) {
            $pdo->exec("ALTER TABLE offerte ADD COLUMN prezzo_imposto_valore REAL");
        }
        if (!in_array('overmarkup', $names, true)) {
            $pdo->exec("ALTER TABLE offerte ADD COLUMN overmarkup INTEGER NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) { /* ignore */ }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offerte_user ON offerte(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offerte_odoo ON offerte(n_ordine_odoo)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            ts        TEXT NOT NULL DEFAULT (datetime('now')),
            user_id   TEXT,
            user_name TEXT,
            action    TEXT NOT NULL,
            entity    TEXT NOT NULL,
            entity_id TEXT,
            detail    TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            token       TEXT PRIMARY KEY,
            user_id     TEXT NOT NULL,
            created_at  TEXT NOT NULL DEFAULT (datetime('now')),
            expires_at  TEXT NOT NULL,
            user_agent  TEXT,
            ip          TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_exp ON sessions(expires_at)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS odoo_session (
            id          TEXT PRIMARY KEY,
            session_id  TEXT NOT NULL,
            expires_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    // Pending 2FA tickets: dopo il 1° submit del flow web Odoo (password OK + TOTP richiesto)
    // salviamo cookie+URL pagina TOTP in DB con un token random. Al 2° submit l'utente manda
    // il token e BP salta GET/POST /web/login (-2-3s sul secondo round-trip). TTL 5 min, one-shot.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS odoo_pending_2fa (
            token        TEXT PRIMARY KEY,
            username     TEXT NOT NULL,
            cookie_blob  BLOB NOT NULL,
            totp_url     TEXT NOT NULL,
            created_at   TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pending2fa_created ON odoo_pending_2fa(created_at)");

    // Login attempts: usata per rate limit. Chiave logica (ip, username, ts).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            ip        TEXT NOT NULL,
            username  TEXT NOT NULL,
            ts        TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_ts ON login_attempts(ip, ts)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_user_ts ON login_attempts(username, ts)");
}

/* ============== RATE LIMIT LOGIN ============== */

const BP_LOGIN_MAX_ATTEMPTS = 5;
const BP_LOGIN_WINDOW_MIN   = 15;
const BP_LOGIN_LOCKOUT_SEC  = 1800;
const BP_SESSION_TTL        = 604800; // 7 giorni

/**
 * Ritorna ['ok' => true] se l'utente/IP può tentare il login,
 * ['ok' => false, 'retryAfter' => N] altrimenti (in secondi).
 */
function bp_login_throttle_check(string $ip, string $username): array {
    $stmt = bp_db()->prepare(
        "SELECT COUNT(*) AS n FROM login_attempts
         WHERE (ip = :ip OR username = :u)
           AND ts > datetime('now', '-' || :win || ' minutes')"
    );
    $stmt->execute(['ip' => $ip, 'u' => $username, 'win' => BP_LOGIN_WINDOW_MIN]);
    $n = (int)($stmt->fetch()['n'] ?? 0);
    if ($n < BP_LOGIN_MAX_ATTEMPTS) return ['ok' => true];
    return ['ok' => false, 'retryAfter' => BP_LOGIN_LOCKOUT_SEC];
}

function bp_login_record_failure(string $ip, string $username): void {
    $stmt = bp_db()->prepare("INSERT INTO login_attempts (ip, username) VALUES (:ip, :u)");
    $stmt->execute(['ip' => $ip, 'u' => $username]);
}

function bp_login_clear_failures(string $username): void {
    $stmt = bp_db()->prepare("DELETE FROM login_attempts WHERE username = :u");
    $stmt->execute(['u' => $username]);
}

function bp_login_purge_old(): void {
    bp_db()->exec("DELETE FROM login_attempts WHERE ts < datetime('now', '-1 hour')");
}

/**
 * Purga audit_log piu' vecchio di N anni (default 2). Mantiene la tabella in dimensione gestibile
 * senza perdere lo storico recente per investigazioni. Da chiamare opportunisticamente (1/1000 audit).
 */
function bp_audit_purge_old(int $keepYears = 2): void {
    bp_db()->exec("DELETE FROM audit_log WHERE ts < datetime('now', '-' || " . (int)$keepYears . " || ' years')");
}

/* ============== SESSIONI ============== */

function bp_session_create(string $userId, int $ttlSeconds = BP_SESSION_TTL): string {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + $ttlSeconds);
    $stmt = bp_db()->prepare("
        INSERT INTO sessions (token, user_id, expires_at, user_agent, ip)
        VALUES (:token, :uid, :exp, :ua, :ip)
    ");
    $stmt->execute([
        'token' => $token, 'uid' => $userId, 'exp' => $expires,
        'ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    return $token;
}

function bp_session_resolve(?string $token): ?array {
    if (!$token) return null;
    // Cleanup opportunistico delle sessioni scadute: 1/100 richieste per evitare
    // crescita indefinita della tabella sessions. Costo trascurabile.
    if (random_int(1, 100) === 1) {
        bp_session_purge_expired();
    }
    $stmt = bp_db()->prepare("
        SELECT u.id AS id, u.nome, u.username, u.ruolo, u.email, u.odoo_uid, u.first_login
        FROM sessions s JOIN utenti u ON u.id = s.user_id
        WHERE s.token = :t AND s.expires_at > datetime('now')
    ");
    $stmt->execute(['t' => $token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return [
        'id'         => $row['id'],
        'nome'       => $row['nome'],
        'username'   => $row['username'],
        'ruolo'      => $row['ruolo'],
        'email'      => $row['email'],
        'odoo_uid'   => $row['odoo_uid'] !== null ? (int)$row['odoo_uid'] : null,
        'firstLogin' => (bool)$row['first_login'],
    ];
}

function bp_session_destroy(string $token): void {
    $stmt = bp_db()->prepare("DELETE FROM sessions WHERE token = :t");
    $stmt->execute(['t' => $token]);
}

function bp_session_purge_expired(): void {
    bp_db()->exec("DELETE FROM sessions WHERE expires_at <= datetime('now')");
}

function bp_db_seed_admin(PDO $pdo): void {
    $row = $pdo->query("SELECT COUNT(*) AS n FROM utenti")->fetch();
    if ((int)$row['n'] > 0) return;
    // Genera password admin random al primo bootstrap. Salva il valore in chiaro
    // UNA volta in INITIAL_ADMIN_PASSWORD.txt fuori dal DocumentRoot servibile
    // (la cartella data/ è protetta da .htaccess Require all denied).
    $plain = bin2hex(random_bytes(8)); // 16 char hex
    $stmt  = $pdo->prepare("
        INSERT INTO utenti (id, nome, username, password_hash, ruolo, first_login)
        VALUES ('u0', 'Administrator', 'admin', :hash, 'admin', 1)
    ");
    $stmt->execute(['hash' => password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12])]);
    $secretPath = __DIR__ . '/../data/INITIAL_ADMIN_PASSWORD.txt';
    @file_put_contents($secretPath,
        "Password iniziale admin (cambiala al primo accesso):\n" .
        "Username: admin\n" .
        "Password: $plain\n" .
        "Generata: " . date('Y-m-d H:i:s') . "\n"
    );
    @chmod($secretPath, 0600);
}

function bp_audit(string $action, string $entity, ?string $entityId = null, ?string $detail = null, ?array $actor = null): void {
    try {
        $stmt = bp_db()->prepare("
            INSERT INTO audit_log (user_id, user_name, action, entity, entity_id, detail)
            VALUES (:uid, :uname, :action, :entity, :eid, :detail)
        ");
        $stmt->execute([
            'uid'    => $actor['id']   ?? null,
            'uname'  => $actor['nome'] ?? null,
            'action' => $action,
            'entity' => $entity,
            'eid'    => $entityId,
            'detail' => $detail,
        ]);
        // Cleanup opportunistico: 1/1000 audit purga le righe oltre 2 anni
        if (random_int(1, 1000) === 1) bp_audit_purge_old(2);
    } catch (Throwable $e) {
        // audit non deve mai bloccare la richiesta
        error_log('audit failed: ' . $e->getMessage());
    }
}

/* ============== UTENTI ============== */

function bp_utenti_all(): array {
    return bp_db()->query("SELECT id, nome, username, ruolo, email, first_login FROM utenti ORDER BY id")->fetchAll();
}

function bp_utente_by_username(string $username): ?array {
    $stmt = bp_db()->prepare("SELECT * FROM utenti WHERE username = :u LIMIT 1");
    $stmt->execute(['u' => $username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bp_utente_by_id(string $id): ?array {
    $stmt = bp_db()->prepare("SELECT * FROM utenti WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bp_utente_upsert(array $u, ?string $plainPassword = null, ?array $actor = null): void {
    $existing = bp_utente_by_id($u['id']);
    $hash = $existing['password_hash'] ?? null;
    if ($plainPassword !== null && $plainPassword !== '') {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    }
    if (!$hash) {
        throw new RuntimeException('Password obbligatoria per nuovo utente');
    }

    // odoo_uid: se passato esplicitamente lo aggiorno, altrimenti preservo l'esistente
    $odooUid = array_key_exists('odoo_uid', $u)
        ? ($u['odoo_uid'] !== null ? (int)$u['odoo_uid'] : null)
        : ($existing['odoo_uid'] ?? null);

    if ($existing) {
        $stmt = bp_db()->prepare("
            UPDATE utenti SET nome=:nome, username=:username, password_hash=:hash,
                              ruolo=:ruolo, email=:email, odoo_uid=:odoo_uid, first_login=:fl,
                              updated_at=datetime('now')
            WHERE id=:id
        ");
        $stmt->execute([
            'nome'     => $u['nome'],
            'username' => $u['username'],
            'hash'     => $hash,
            'ruolo'    => $u['ruolo'],
            'email'    => $u['email']        ?? null,
            'odoo_uid' => $odooUid,
            'fl'       => !empty($u['firstLogin']) ? 1 : 0,
            'id'       => $u['id'],
        ]);
        bp_audit('update', 'utenti', $u['id'], $u['username'], $actor);
    } else {
        $stmt = bp_db()->prepare("
            INSERT INTO utenti (id, nome, username, password_hash, ruolo, email, odoo_uid, first_login)
            VALUES (:id, :nome, :username, :hash, :ruolo, :email, :odoo_uid, :fl)
        ");
        $stmt->execute([
            'id'       => $u['id'],
            'nome'     => $u['nome'],
            'username' => $u['username'],
            'hash'     => $hash,
            'ruolo'    => $u['ruolo'],
            'email'    => $u['email']        ?? null,
            'odoo_uid' => $odooUid,
            'fl'       => isset($u['firstLogin']) ? (int)$u['firstLogin'] : 1,
        ]);
        bp_audit('create', 'utenti', $u['id'], $u['username'], $actor);
    }
}

function bp_utente_delete(string $id, ?array $actor = null): void {
    if ($id === 'u0') throw new RuntimeException('Account principale non eliminabile');
    $stmt = bp_db()->prepare("DELETE FROM utenti WHERE id = :id");
    $stmt->execute(['id' => $id]);
    bp_audit('delete', 'utenti', $id, null, $actor);
}

/* ============== OFFERTE ============== */

function bp_offerte_all(): array {
    $rows = bp_db()->query("SELECT * FROM offerte ORDER BY created_at ASC")->fetchAll();
    return array_map('bp_offerta_hydrate', $rows);
}

function bp_offerta_hydrate(array $row): array {
    $payload = json_decode($row['payload_json'] ?? '{}', true) ?: [];
    // i campi colonna sovrascrivono il payload (sono il source of truth)
    $payload['id']            = $row['id'];
    $payload['userId']        = $row['user_id'];
    $payload['userName']      = $row['user_name'];
    $payload['nome']          = $row['nome'];
    $payload['cliente']       = $row['cliente']        ?? '';
    $payload['tipo']          = $row['tipo']           ?? '';
    $payload['nOrdineOdoo']   = $row['n_ordine_odoo']  ?? '';
    $payload['data']          = $row['data']           ?? '';
    $payload['note']          = $row['note']           ?? '';
    $payload['speseGenerali'] = $row['spese_generali'] ?? '5';
    if ($row['sconto_stato']  !== null) $payload['scontoStato']  = $row['sconto_stato'];
    if ($row['sconto_tipo']   !== null) $payload['scontoTipo']   = $row['sconto_tipo'];
    if ($row['sconto_valore'] !== null) $payload['scontoValore'] = (float)$row['sconto_valore'];
    if ($row['sconto_nota']   !== null) $payload['scontoNota']   = $row['sconto_nota'];
    $payload['allegataOdoo'] = $row['allegata_odoo'] ?? null;  // ISO datetime se allegata, null altrimenti
    $payload['prezzoImpostoAttivo'] = !empty($row['prezzo_imposto_attivo']);
    $payload['prezzoImpostoValore'] = $row['prezzo_imposto_valore'] !== null ? (float)$row['prezzo_imposto_valore'] : 0.0;
    $payload['overmarkup'] = isset($row['overmarkup']) ? (int)$row['overmarkup'] : 0;
    return $payload;
}

function bp_offerta_upsert(array $o, ?array $actor = null): void {
    $payload = $o;
    // togli i campi colonna dal payload per ridurre duplicazione (verranno re-iniettati a lettura)
    foreach (['id','userId','userName','nome','cliente','tipo','nOrdineOdoo','data','note','speseGenerali','scontoStato','scontoTipo','scontoValore','scontoNota','allegataOdoo','prezzoImpostoAttivo','prezzoImpostoValore','overmarkup'] as $k) {
        unset($payload[$k]);
    }
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $existing = bp_db()->prepare("SELECT id FROM offerte WHERE id = :id");
    $existing->execute(['id' => $o['id']]);
    $isUpdate = (bool)$existing->fetch();

    $params = [
        'id'             => $o['id'],
        'user_id'        => $o['userId']        ?? null,
        'user_name'      => $o['userName']      ?? null,
        'nome'           => $o['nome']          ?? '',
        'cliente'        => $o['cliente']       ?? null,
        'tipo'           => $o['tipo']          ?? null,
        'n_ordine_odoo'  => $o['nOrdineOdoo']   ?? null,
        'data'           => $o['data']          ?? null,
        'note'           => $o['note']          ?? null,
        'spese_generali' => $o['speseGenerali'] ?? '5',
        'sconto_stato'   => $o['scontoStato']   ?? null,
        'sconto_tipo'    => $o['scontoTipo']    ?? null,
        'sconto_valore'  => isset($o['scontoValore']) ? (float)$o['scontoValore'] : null,
        'sconto_nota'    => $o['scontoNota']    ?? null,
        'allegata_odoo'  => $o['allegataOdoo']  ?? null,
        'prezzo_imposto_attivo' => !empty($o['prezzoImpostoAttivo']) ? 1 : 0,
        'prezzo_imposto_valore' => isset($o['prezzoImpostoValore']) ? (float)$o['prezzoImpostoValore'] : null,
        'overmarkup'     => isset($o['overmarkup']) ? (int)$o['overmarkup'] : 0,
        'payload_json'   => $payloadJson,
    ];

    if ($isUpdate) {
        $stmt = bp_db()->prepare("
            UPDATE offerte SET user_id=:user_id, user_name=:user_name, nome=:nome,
                cliente=:cliente, tipo=:tipo, n_ordine_odoo=:n_ordine_odoo, data=:data,
                note=:note, spese_generali=:spese_generali, sconto_stato=:sconto_stato,
                sconto_tipo=:sconto_tipo, sconto_valore=:sconto_valore, sconto_nota=:sconto_nota,
                allegata_odoo=:allegata_odoo,
                prezzo_imposto_attivo=:prezzo_imposto_attivo,
                prezzo_imposto_valore=:prezzo_imposto_valore,
                overmarkup=:overmarkup,
                payload_json=:payload_json, updated_at=datetime('now')
            WHERE id=:id
        ");
        $stmt->execute($params);
        bp_audit('update', 'offerte', $o['id'], $o['nome'] ?? null, $actor);
    } else {
        $stmt = bp_db()->prepare("
            INSERT INTO offerte (id, user_id, user_name, nome, cliente, tipo, n_ordine_odoo, data,
                note, spese_generali, sconto_stato, sconto_tipo, sconto_valore, sconto_nota, allegata_odoo,
                prezzo_imposto_attivo, prezzo_imposto_valore, overmarkup, payload_json)
            VALUES (:id, :user_id, :user_name, :nome, :cliente, :tipo, :n_ordine_odoo, :data,
                :note, :spese_generali, :sconto_stato, :sconto_tipo, :sconto_valore, :sconto_nota, :allegata_odoo,
                :prezzo_imposto_attivo, :prezzo_imposto_valore, :overmarkup, :payload_json)
        ");
        $stmt->execute($params);
        bp_audit('create', 'offerte', $o['id'], $o['nome'] ?? null, $actor);
    }
}

/**
 * Marca un'offerta come allegata a Odoo (timestamp ISO ora).
 * Chiamato da api/allega_odoo.php dopo l'upload riuscito di PDF+XLSX.
 */
function bp_offerta_set_allegata(string $id, ?array $actor = null): void {
    $stmt = bp_db()->prepare("UPDATE offerte SET allegata_odoo = datetime('now'), updated_at = datetime('now') WHERE id = :id");
    $stmt->execute(['id' => $id]);
    bp_audit('attach_odoo_done', 'offerte', $id, null, $actor);
}

function bp_offerta_delete(string $id, ?array $actor = null): void {
    $stmt = bp_db()->prepare("DELETE FROM offerte WHERE id = :id");
    $stmt->execute(['id' => $id]);
    bp_audit('delete', 'offerte', $id, null, $actor);
}
