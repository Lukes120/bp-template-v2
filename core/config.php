<?php
/**
 * Carica config/credentials.env nelle variabili d'ambiente.
 * Formato: KEY=VALUE per riga, righe vuote e # commento ignorate.
 */

function bp_load_env(string $path = null): array {
    $path = $path ?? __DIR__ . '/../config/credentials.env';
    $env = [];
    if (!is_readable($path)) return $env;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        // strip optional surrounding quotes
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
            $val = substr($val, 1, -1);
        }
        $env[$key] = $val;
        if (getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
    return $env;
}

function bp_env(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $_ENV[$key] ?? $default;
    }
    return $v;
}

bp_load_env();
