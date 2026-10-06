<?php
// public/api/env-loader.php
declare(strict_types=1);

/**
 * Single accessor for every configuration value in the app.
 *
 * Endpoints never read a superglobal or a file directly:
 *     require_once __DIR__ . '/env-loader.php';
 *     $key = env('PAYU_MERCHANT_KEY');
 *
 * Where values come from, in priority order
 * -----------------------------------------
 * 1. Host environment (hPanel -> PHP -> Configuration, or `export`). Always wins,
 *    so a stale credential file cannot undo a setting corrected in the panel.
 * 2. APP_CREDENTIALS, defined in api/credential.php when that file is present.
 * 3. The default passed by the caller.
 *
 * Why there is no .env parsing
 * ----------------------------
 * Earlier versions searched for a .env file, parsed it, and exported the results
 * into the process environment. Hostinger's upload scanner reads that as
 * credential-stealing malware and emptied the file on write, which is why
 * api/_env.php and then api/env-loader.php kept arriving with no contents. This
 * version touches no file and exports nothing, so there is nothing for a
 * heuristic to match. Values are plain literals in api/credential.php, which is
 * the shape of every ordinary PHP config file.
 *
 * Note that this host runs variables_order=GPCS, so PHP never populates $_ENV by
 * itself. Only getenv() and the credential file are reliable, which is exactly
 * what env() reads.
 */

/* Blocked in the browser — this file is only ever included. */
if (isset($_SERVER['SCRIPT_FILENAME'])
    && @realpath((string)$_SERVER['SCRIPT_FILENAME']) === @realpath(__FILE__)) {
    http_response_code(404);
    exit('Not Found');
}

/**
 * Read api/credential.php once and return its values as key => value.
 *
 * Returns [] when the file is absent, so a deployment without credentials still
 * boots and reports what is missing instead of failing to load.
 */
function credentialValues(): array
{
    static $values = null;

    if ($values !== null) {
        return $values;
    }

    $values = [];
    $file = __DIR__ . '/credential.php';

    if (is_file($file) && is_readable($file)) {
        $loaded = include $file;

        if (is_array($loaded)) {
            $values = $loaded;
        } elseif (defined('APP_CREDENTIALS') && is_array(APP_CREDENTIALS)) {
            $values = APP_CREDENTIALS;
        }
    }

    return $values;
}

/** Every key the app reads, for diagnostics and the deploy check. */
function credentialKeys(): array
{
    return [
        'PAYU_MERCHANT_KEY',
        'PAYU_MERCHANT_SALT',
        'PAYU_MODE',
        'SITE_URL',
        'ADMIN_EMAIL',
        'SMTP_GMAIL_USER',
        'SMTP_GMAIL_PASS',
        'PUBLIC_SUPABASE_URL',
        'SUPABASE_SERVICE_KEY',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
    ];
}

/** The only way to read configuration in this app. */
function env(string $key, ?string $default = null): ?string
{
    // Host level first: a value corrected in hPanel cannot be overridden by a
    // credential file that was never updated.
    $fromHost = getenv($key);
    if ($fromHost !== false && $fromHost !== '') {
        return $fromHost;
    }

    $values = credentialValues();
    if (isset($values[$key]) && $values[$key] !== '' && $values[$key] !== null) {
        return (string)$values[$key];
    }

    return $default;
}

function envInt(string $key, int $default = 0): int
{
    $value = env($key);

    return ($value === null || !is_numeric($value)) ? $default : (int)$value;
}

function envBool(string $key, bool $default = false): bool
{
    $value = env($key);
    if ($value === null) {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Credential file path, or null when none was found. Used by the gateway guard
 * to tell "file missing" apart from "file present but incomplete".
 */
function credentialFile(): ?string
{
    $file = __DIR__ . '/credential.php';

    return is_file($file) && is_readable($file) ? $file : null;
}