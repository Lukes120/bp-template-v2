<?php
/**
 * Helper per chiamate XML-RPC ad Odoo 14 (v=57+).
 *
 * Architettura post-migrazione:
 *  - bp_odoo_call_kw_safe(): chiamate di servizio (cerca/leggi/allega) con admin + ODOO_API_KEY
 *  - bp_odoo_authenticate(): SSO utente con la sua password personale (NON la API key)
 *
 * XML-RPC non ha sessioni: l'auth e' un singolo round-trip a /xmlrpc/2/common.
 * L'uid del service account viene cachato in memoria per la durata della request PHP
 * (static var dentro bp_odoo_service_uid).
 *
 * Niente piu' cookie/sessione SQLite/JSON-RPC. La tabella `odoo_session` in DB
 * resta storica ma non viene piu' scritta/letta.
 *
 * Compatibilita': bp_odoo_call_kw_safe() restituisce ['result' => ...] o ['error' => ...]
 * cosi' come faceva il vecchio backend JSON-RPC, in modo che gli endpoint chiamanti
 * (cerca_odoo, leggi_odoo, allega_odoo) non vadano modificati.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

const BP_ODOO_UID_CACHE_TTL = 18000; // 5 ore

/* ==================== CLIENT XML-RPC ==================== */

/**
 * Serializza un valore PHP in XML-RPC <value>...</value>.
 * Tipi gestiti: bool, int, float, string, array numerico, array associativo (struct), null.
 */
function bp_odoo_xml_encode_value($v): string {
    if (is_bool($v))   return '<value><boolean>' . ($v ? '1' : '0') . '</boolean></value>';
    if (is_int($v))    return '<value><int>' . $v . '</int></value>';
    if (is_float($v))  return '<value><double>' . $v . '</double></value>';
    if (is_null($v))   return '<value><string></string></value>';
    if (is_string($v)) return '<value><string>' . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</string></value>';
    if (is_array($v)) {
        // Distinguo array numerico (array XML-RPC) da array associativo (struct).
        $isAssoc = !empty($v) && array_keys($v) !== range(0, count($v) - 1);
        if ($isAssoc) {
            $out = '<value><struct>';
            foreach ($v as $k => $val) {
                $out .= '<member><name>' . htmlspecialchars((string)$k, ENT_XML1, 'UTF-8') . '</name>' . bp_odoo_xml_encode_value($val) . '</member>';
            }
            return $out . '</struct></value>';
        }
        $out = '<value><array><data>';
        foreach ($v as $item) $out .= bp_odoo_xml_encode_value($item);
        return $out . '</data></array></value>';
    }
    return '<value><string></string></value>';
}

/**
 * Decodifica un nodo <value> XML-RPC in un valore PHP nativo.
 */
function bp_odoo_xml_decode_value(SimpleXMLElement $value) {
    foreach ($value->children() as $type => $node) {
        switch ($type) {
            case 'int':
            case 'i4':       return (int)(string)$node;
            case 'boolean':  return ((string)$node) === '1';
            case 'double':   return (float)(string)$node;
            case 'string':   return (string)$node;
            case 'base64':   return base64_decode((string)$node);
            case 'dateTime.iso8601': return (string)$node;
            case 'nil':      return null;
            case 'array':
                $out = [];
                if (isset($node->data->value)) {
                    foreach ($node->data->value as $item) $out[] = bp_odoo_xml_decode_value($item);
                }
                return $out;
            case 'struct':
                $out = [];
                foreach ($node->member as $m) {
                    $out[(string)$m->name] = bp_odoo_xml_decode_value($m->value);
                }
                return $out;
        }
    }
    return (string)$value;
}

/**
 * Effettua una chiamata XML-RPC a un endpoint Odoo.
 *
 * @param string $path    'common' oppure 'object' (suffisso di /xmlrpc/2/)
 * @param string $method  Nome del methodCall (es. 'authenticate', 'execute_kw')
 * @param array  $params  Parametri posizionali (ognuno serializzato come <param>)
 * @return mixed Valore decodificato dalla risposta
 * @throws RuntimeException su errore di rete o fault XML-RPC
 */
function bp_odoo_xmlrpc_call(string $path, string $method, array $params) {
    // Keep-alive: riuso lo stesso curl handle per tutte le chiamate XML-RPC della request.
    // Risparmia ~1.5-2s di TLS handshake per ogni round-trip successivo al primo.
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
    }

    $url = rtrim(bp_env('ODOO_URL'), '/') . '/xmlrpc/2/' . $path;

    $body = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>';
    foreach ($params as $p) {
        $body .= '<param>' . bp_odoo_xml_encode_value($p) . '</param>';
    }
    $body .= '</params></methodCall>';

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 180,
        // Accetta risposte compresse (gzip/deflate). curl decomprime automaticamente.
        // Per request comprimere a Odoo richiederebbe Content-Encoding manuale e supporto server
        // (Odoo XML-RPC accetta gzip in input solo se il reverse proxy lo gestisce). Per ora
        // attiviamo SOLO la decompressione delle risposte, che e' sicura al 100%.
        CURLOPT_ENCODING       => '',
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // NB: niente curl_close($ch) — handle persistente per riusare la connessione TLS

    if ($raw === false) {
        throw new RuntimeException('Odoo XML-RPC: errore curl - ' . $err);
    }
    if ($code !== 200) {
        throw new RuntimeException('Odoo XML-RPC: HTTP ' . $code);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if ($xml === false) {
        throw new RuntimeException('Odoo XML-RPC: risposta non e\' XML valido');
    }

    if (isset($xml->fault)) {
        $fault = bp_odoo_xml_decode_value($xml->fault->value);
        $msg = is_array($fault) ? (($fault['faultString'] ?? 'unknown') . ' (code ' . ($fault['faultCode'] ?? '?') . ')') : 'unknown fault';
        throw new RuntimeException('Odoo fault: ' . $msg);
    }

    if (!isset($xml->params->param->value)) {
        throw new RuntimeException('Odoo XML-RPC: methodResponse senza value');
    }
    return bp_odoo_xml_decode_value($xml->params->param->value);
}

/* ==================== AUTH SERVICE ACCOUNT ==================== */

/**
 * Ritorna l'uid Odoo del service account (admin + ODOO_API_KEY).
 *  - Livello 1: cache `static $uid` per la durata della request PHP (0 ms)
 *  - Livello 2: cache persistente in tabella odoo_session (id='service_uid') cross-request, TTL 5h
 *  - Livello 3: fallback authenticate fresh contro Odoo (~2s di TLS handshake + auth)
 * Se la cache contiene un uid stale (es. key Odoo rigenerata), le execute_kw successive falliscono e bisogna
 * invalidare manualmente la riga: DELETE FROM odoo_session WHERE id='service_uid'.
 */
function bp_odoo_service_uid(): int {
    static $uid = null;
    if ($uid !== null) return $uid;

    // Livello 2: cache persistente
    try {
        $row = bp_db()->query("SELECT session_id FROM odoo_session WHERE id = 'service_uid' AND expires_at > datetime('now') LIMIT 1")->fetch();
        if ($row && is_numeric($row['session_id']) && (int)$row['session_id'] > 0) {
            $uid = (int)$row['session_id'];
            return $uid;
        }
    } catch (Throwable $e) { /* fall through al fresh auth */ }

    // Livello 3: fresh authenticate
    $db   = bp_env('ODOO_DB');
    $user = bp_env('ODOO_USERNAME');
    $key  = bp_env('ODOO_API_KEY');
    if (!$db || !$user || !$key) {
        throw new RuntimeException('Credenziali Odoo mancanti in credentials.env (ODOO_DB, ODOO_USERNAME, ODOO_API_KEY)');
    }
    $res = bp_odoo_xmlrpc_call('common', 'authenticate', [$db, $user, $key, []]);
    if (!is_int($res) || $res <= 0) {
        throw new RuntimeException('Login Odoo XML-RPC fallito (uid non ricevuto). Verifica ODOO_USERNAME/ODOO_API_KEY.');
    }
    $uid = $res;

    // Persiste in cache (non bloccante: se fallisce, restiamo solo su cache in-memory)
    try {
        $expiresAt = date('Y-m-d H:i:s', time() + BP_ODOO_UID_CACHE_TTL);
        $stmt = bp_db()->prepare("
            INSERT INTO odoo_session (id, session_id, expires_at, updated_at)
            VALUES ('service_uid', :uid, :exp, datetime('now'))
            ON CONFLICT(id) DO UPDATE SET
                session_id = excluded.session_id,
                expires_at = excluded.expires_at,
                updated_at = datetime('now')
        ");
        $stmt->execute(['uid' => (string)$uid, 'exp' => $expiresAt]);
    } catch (Throwable $e) { /* non bloccante */ }

    return $uid;
}

/* ==================== CHIAMATE DI SERVIZIO ==================== */

/**
 * Esegue una chiamata Odoo execute_kw via XML-RPC usando il service account.
 * Wrapping in ['result' => ...] / ['error' => ...] per compatibilita' con i chiamanti
 * (cerca_odoo, leggi_odoo, allega_odoo) che leggono $res['result'].
 *
 * @param string $model   es. 'sale.order', 'ir.attachment'
 * @param string $method  es. 'search_read', 'create', 'read'
 * @param array  $args    parametri posizionali (domain, vals, ids, ...)
 * @param array  $kwargs  parametri nominati (fields, limit, order, context, ...)
 */
function bp_odoo_call_kw_safe(string $model, string $method, array $args = [], array $kwargs = []): ?array {
    try {
        $uid = bp_odoo_service_uid();
        $db  = bp_env('ODOO_DB');
        $key = bp_env('ODOO_API_KEY');
        $result = bp_odoo_xmlrpc_call('object', 'execute_kw', [$db, $uid, $key, $model, $method, $args, $kwargs]);
        return ['result' => $result];
    } catch (Throwable $e) {
        return ['error' => ['message' => $e->getMessage(), 'data' => ['name' => 'BpOdooXmlRpcError']]];
    }
}

/* ==================== SSO UTENTE ==================== */

/**
 * Verifica le credenziali utente Odoo via flow web HTML (replica del form /web/login).
 * Supporta utenti con 2FA. Il 2° submit (con codice TOTP) puo' opzionalmente passare
 * un pending_token che salta GET/POST /web/login (risparmio ~2-3s, vedi odoo_pending_2fa).
 *
 * @return array|null
 *   - ['uid' => int, 'login' => str, 'name' => str, 'email' => str] in caso di successo
 *   - ['totp_required' => true, 'pending_token' => str] se 2FA attivo e codice non fornito
 *   - null se credenziali errate o errore di rete
 */
function bp_odoo_authenticate(string $login, string $password, ?string $totpCode = null, ?string $pendingToken = null): ?array {
    // DIAGNOSTICA TEMPORANEA (aggiunto 21/05/2026 per caso fgiacomet): logging
    // [BP_SSO] a ogni stage del flow, scrive su C:\laragon\tmp\php_errors.log.
    // Reintroduce in forma compatta i log che erano presenti prima del refactor v=57.
    // RIMUOVERE dopo aver chiuso il caso fgiacomet (cerca "[BP_SSO]" e taglia).
    $db = bp_env('ODOO_DB');
    if (!$db) { error_log("[BP_SSO] FAIL login=$login ODOO_DB mancante in env"); return null; }

    $odoo = rtrim(bp_env('ODOO_URL'), '/');
    $cookieFile = tempnam(sys_get_temp_dir(), 'bp_sso_');
    if (!$cookieFile) { error_log("[BP_SSO] FAIL login=$login tempnam fallito"); return null; }

    error_log("[BP_SSO] START login=$login totp=" . ($totpCode !== null && $totpCode !== '' ? 'PRESENT(' . strlen($totpCode) . ')' : 'NO') . " pending=" . ($pendingToken ? 'YES' : 'NO'));

    try {
        $hasTotp = ($totpCode !== null && $totpCode !== '');
        $totpUrl = null; // popolato in Stage B (o caricato da pending ticket)

        // ============================================================
        // FAST PATH: secondo submit con pending_token -> salta Stage A+B
        // ============================================================
        if ($hasTotp && $pendingToken) {
            $totpUrl = bp_odoo_pending_2fa_load($pendingToken, $login, $cookieFile);
            if ($totpUrl) {
                error_log("[BP_SSO] login=$login fast-path pending token OK, skip a stage_c");
                goto stage_c;
            }
            error_log("[BP_SSO] login=$login pending token invalido/scaduto, fallback flow normale");
        }

        // ============================================================
        // Stage A — GET /web/login: estraggo csrf_token iniziale
        // ============================================================
        $r1 = bp_odoo_web_call($odoo . '/web/login?db=' . urlencode($db), $cookieFile);
        error_log("[BP_SSO] login=$login A GET /web/login -> HTTP {$r1['code']}");
        if ($r1['code'] !== 200) return null;
        $csrf1 = bp_odoo_extract_csrf($r1['body']);
        if (!$csrf1) { error_log("[BP_SSO] login=$login A csrf_token NON trovato nel HTML"); return null; }
        error_log("[BP_SSO] login=$login A csrf1=" . substr($csrf1, 0, 16) . '...');

        // ============================================================
        // Stage B — POST /web/login con password
        // ============================================================
        $r2 = bp_odoo_web_call($odoo . '/web/login', $cookieFile, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'csrf_token' => $csrf1,
                'db'         => $db,
                'login'      => $login,
                'password'   => $password,
                'redirect'   => '',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $loc2 = bp_odoo_extract_location($r2['headers']);
        error_log("[BP_SSO] login=$login B POST /web/login -> HTTP {$r2['code']} loc=" . ($loc2 ?: '(none)'));
        if ($r2['code'] === 303 && $loc2 && stripos($loc2, '/login/totp') !== false) {
            // 2FA richiesto
            $totpUrl = (strpos($loc2, 'http') === 0) ? $loc2 : $odoo . $loc2;
            if (!$hasTotp) {
                $token = bp_odoo_pending_2fa_save($login, $cookieFile, $totpUrl);
                error_log("[BP_SSO] login=$login B totp_required, pending token salvato (no code yet)");
                return ['totp_required' => true, 'pending_token' => $token];
            }
            stage_c:
            // Stage C: prelevo csrf2 dalla pagina TOTP
            $r3 = bp_odoo_web_call($totpUrl, $cookieFile);
            error_log("[BP_SSO] login=$login C GET /login/totp -> HTTP {$r3['code']}");
            if ($r3['code'] !== 200) return null;
            $csrf2 = bp_odoo_extract_csrf($r3['body']);
            if (!$csrf2) { error_log("[BP_SSO] login=$login C csrf2 NON trovato"); return null; }
            $r4 = bp_odoo_web_call($odoo . '/web/login/totp', $cookieFile, [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'csrf_token' => $csrf2,
                    'totp_token' => $totpCode,
                    'remember'   => 'false',
                    'redirect'   => '',
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Referer: ' . $totpUrl,
                    'Origin: ' . $odoo,
                ],
            ]);
            $loc4 = bp_odoo_extract_location($r4['headers']);
            error_log("[BP_SSO] login=$login C POST /login/totp -> HTTP {$r4['code']} loc=" . ($loc4 ?: '(none)'));
            if ($r4['code'] !== 303 || !$loc4 || stripos($loc4, '/login') !== false) {
                error_log("[BP_SSO] login=$login C TOTP rejected (code errato o scaduto)");
                return null;
            }
            if ($pendingToken) bp_odoo_pending_2fa_delete($pendingToken);
        } elseif ($r2['code'] === 303 && $loc2 && stripos($loc2, '/login') === false) {
            // No 2FA, login OK direttamente
            error_log("[BP_SSO] login=$login B no-2FA, login diretto OK (no TOTP attivo su utente)");
        } else {
            // 200 con alert HTML = credenziali errate, o altro errore
            $hint = '';
            if ($r2['code'] === 200) {
                if (stripos($r2['body'], 'wrong login') !== false || stripos($r2['body'], 'credentials') !== false || stripos($r2['body'], 'invalid') !== false) {
                    $hint = ' (HTML contiene "wrong/invalid" - credenziali errate)';
                } elseif (stripos($r2['body'], 'login') !== false) {
                    $hint = ' (HTTP 200 con form login - probabile credenziali errate)';
                }
            }
            error_log("[BP_SSO] login=$login B FAIL credenziali (no redirect 303)$hint");
            return null;
        }

        // ============================================================
        // Stage D — sessione full-auth, leggi info utente
        // ============================================================
        $r5 = bp_odoo_web_call($odoo . '/web/session/get_session_info', $cookieFile, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => 'call', 'params' => []]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $j = json_decode($r5['body'], true);
        if (!$j || empty($j['result']) || empty($j['result']['uid'])) {
            error_log("[BP_SSO] login=$login D get_session_info FAIL: " . substr($r5['body'] ?? '', 0, 200));
            return null;
        }
        $uid = (int)$j['result']['uid'];
        $usernameFromOdoo = $j['result']['username'] ?? $login;
        error_log("[BP_SSO] login=$login D OK uid=$uid email=$usernameFromOdoo");
        return [
            'uid'   => $uid,
            'login' => $usernameFromOdoo,
            'name'  => $j['result']['partner_display_name'] ?? ($j['result']['name'] ?? $login),
            'email' => $usernameFromOdoo,
        ];

    } catch (Throwable $e) {
        error_log('[BP_SSO] login=' . $login . ' EXCEPTION ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        return null;
    } finally {
        @unlink($cookieFile);
    }
}

/**
 * Helper HTTP per il flow web /web/login: gestisce cookie jar condiviso e header parsing.
 */
function bp_odoo_web_call(string $url, string $cookieFile, array $opts = []): array {
    // Rollback keep-alive (2026-05-19 sera): il static $ch riusato sporcava lo state tra GET
    // e POST (Odoo restituiva HTTP 400 sul GET successivo a un POST). Curl handle fresco a
    // ogni chiamata, costo ~1-2s TLS handshake per step. Mantengo lo skip XML-RPC res.users
    // che da solo risparmia ~1-2s sulla auth.
    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        // User-Agent "browser" required: Odoo auth_totp rifiuta UA troppo minimal (HTTP 500)
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) BPTemplate/1.0',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + $defaults);
    $raw     = curl_exec($ch);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'code'    => $code,
        'headers' => substr($raw ?: '', 0, $hdrSize),
        'body'    => substr($raw ?: '', $hdrSize),
    ];
}

function bp_odoo_extract_csrf(string $html): ?string {
    if (preg_match('/<input[^>]*\bname=["\']csrf_token["\'][^>]*\bvalue=["\']([^"\']+)["\']/i', $html, $m)) return $m[1];
    if (preg_match('/<input[^>]*\bvalue=["\']([^"\']+)["\'][^>]*\bname=["\']csrf_token["\']/i', $html, $m)) return $m[1];
    if (preg_match('/odoo\.csrf_token\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) return $m[1];
    return null;
}

function bp_odoo_extract_location(string $headers): ?string {
    foreach (explode("\n", $headers) as $l) {
        if (preg_match('/^location:\s*(.+)/i', trim($l), $m)) return trim($m[1]);
    }
    return null;
}

/* ==================== PENDING 2FA TICKET ==================== */

/**
 * Salva il cookie file + URL pagina TOTP in DB con un token random. Ritorna il token.
 * Il frontend lo riceve e lo passa al 2° submit con il codice TOTP -> bp_odoo_authenticate
 * salta GET/POST /web/login (-2-3s). Cleanup opportunistico: pending > 5 min vengono cancellati.
 */
function bp_odoo_pending_2fa_save(string $username, string $cookieFile, string $totpUrl): string {
    $token = bin2hex(random_bytes(32));
    $blob  = @file_get_contents($cookieFile) ?: '';
    $stmt = bp_db()->prepare("INSERT INTO odoo_pending_2fa (token, username, cookie_blob, totp_url) VALUES (:t, :u, :b, :url)");
    $stmt->bindValue(':t',   $token);
    $stmt->bindValue(':u',   $username);
    $stmt->bindValue(':b',   $blob, PDO::PARAM_LOB);
    $stmt->bindValue(':url', $totpUrl);
    $stmt->execute();
    // Cleanup opportunistico (1/100 inserimenti): rimuovi pending > 5 min
    if (random_int(1, 100) === 1) {
        bp_db()->exec("DELETE FROM odoo_pending_2fa WHERE created_at < datetime('now', '-5 minutes')");
    }
    return $token;
}

/**
 * Ripristina cookie da DB nel file. Ritorna URL pagina TOTP, o null se token invalido/scaduto/wrong user.
 * Rimuove SEMPRE il record dopo il read (one-shot, anti-replay).
 */
function bp_odoo_pending_2fa_load(string $token, string $username, string $cookieFile): ?string {
    $stmt = bp_db()->prepare(
        "SELECT cookie_blob, totp_url FROM odoo_pending_2fa
         WHERE token = :t AND username = :u AND created_at > datetime('now', '-5 minutes')"
    );
    $stmt->execute(['t' => $token, 'u' => $username]);
    $row = $stmt->fetch();
    bp_db()->prepare("DELETE FROM odoo_pending_2fa WHERE token = :t")->execute(['t' => $token]);
    if (!$row) return null;
    @file_put_contents($cookieFile, $row['cookie_blob']);
    return $row['totp_url'];
}

function bp_odoo_pending_2fa_delete(string $token): void {
    bp_db()->prepare("DELETE FROM odoo_pending_2fa WHERE token = :t")->execute(['t' => $token]);
}
