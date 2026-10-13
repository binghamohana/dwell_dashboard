<?php
/**
 * Dwell Central configuration.
 *
 * Copy this file to config/config.php and fill it in. config.php is ignored by Git, so
 * passwords and keys never end up in the repository.
 */

$env = static function (string $key, $default = null) {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};

return [
    // Public address of the app, no trailing slash (used in email links and Google sign-in).
    'base_url' => $env('DC_BASE_URL', 'http://localhost:8090'),

    // Long random string that signs sign-in cookies. Generate one with:
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Changing it signs everyone out.
    'app_secret' => $env('DC_APP_SECRET', 'CHANGE-ME-to-a-long-random-string'),

    // Show detailed PHP errors in API responses. Keep false in production.
    'debug' => (bool) $env('DC_DEBUG', false),

    'db' => [
        'host'     => $env('DC_DB_HOST', 'localhost'),
        'port'     => (int) $env('DC_DB_PORT', 3306),
        'name'     => $env('DC_DB_NAME', 'dwell_central'),
        'user'     => $env('DC_DB_USER', 'dwell_central'),
        'password' => $env('DC_DB_PASSWORD', ''),
    ],

    'mail' => [
        // Sent with PHPMailer (bundled in lib/PHPMailer).
        // 'log' writes messages to storage/logs/mail.log instead of sending (the safe default),
        // 'smtp' sends through the account below. Celebration emails additionally stay in
        // TEST mode until an administrator switches them to live inside the app.
        'transport'  => $env('DC_MAIL_TRANSPORT', 'log'),
        'host'       => $env('DC_SMTP_HOST', 'smtp.gmail.com'),
        'port'       => (int) $env('DC_SMTP_PORT', 465),
        'encryption' => $env('DC_SMTP_ENCRYPTION', 'ssl'),   // 'ssl' (port 465), 'tls' (STARTTLS, 587) or ''
        'username'   => $env('DC_SMTP_USERNAME', ''),
        'password'   => $env('DC_SMTP_PASSWORD', ''),        // a Google app password for Gmail/Workspace
        'from_email' => $env('DC_MAIL_FROM', ''),            // must be the SMTP account (or an alias it may send as)
        'from_name'  => $env('DC_MAIL_FROM_NAME', 'Dwell Central'),
        'reply_to'   => $env('DC_MAIL_REPLY_TO', ''),
    ],

    // "Sign in with Google" (optional). Needs an OAuth client in a Dwell-owned Google Cloud
    // project with the redirect URI {base_url}/google.php — see docs/GOOGLE.md.
    'google' => [
        'client_id'     => $env('DC_GOOGLE_CLIENT_ID', ''),
        'client_secret' => $env('DC_GOOGLE_CLIENT_SECRET', ''),
        'hosted_domain' => $env('DC_GOOGLE_DOMAIN', ''),     // your Workspace domain: only accounts from it may sign in
    ],

    // Only true behind a reverse proxy you control (so rate limits see real visitor IPs).
    'trust_proxy_headers' => (bool) $env('DC_TRUST_PROXY', false),
];
