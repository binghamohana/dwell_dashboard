<?php
declare(strict_types=1);

namespace Dwell\Core;

/**
 * "Sign in with Google" for Dwell's Google Workspace (OpenID Connect, authorization-code flow).
 *
 * Off until config.php has google.client_id / client_secret (an OAuth client created in a
 * Dwell-owned Google Cloud project; see docs/GOOGLE.md). Google only proves who someone is:
 * they still need an active Dwell Central account with the same email, created by an
 * administrator. With google.hosted_domain set, only accounts from that Workspace domain work.
 */
final class GoogleSignIn
{
    private const COOKIE = 'dwell_central_g';

    public static function enabled(): bool
    {
        return (string) App::config('google.client_id', '') !== '' && (string) App::config('google.client_secret', '') !== '';
    }

    private static function redirectUri(): string
    {
        return App::url('google.php');
    }

    /** Send the browser to Google. */
    public static function start(): void
    {
        $state = Util::token(16);
        $nonce = Util::token(16);
        $payload = Util::base64url(Util::json(['s' => $state, 'n' => $nonce, 'exp' => time() + 600]));
        setcookie(self::COOKIE, $payload . '.' . Util::sign('g|' . $payload), [
            'expires' => time() + 600, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
            'secure' => Http::isHttps(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $params = [
            'client_id'     => App::config('google.client_id'),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'nonce'         => $nonce,
            'prompt'        => 'select_account',
        ];
        if ($hd = (string) App::config('google.hosted_domain', '')) {
            $params['hd'] = $hd;
        }
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    }

    /** Handle Google's redirect back. Returns the signed-in user or throws AppError. */
    public static function callback(array $query): array
    {
        if (!RateLimit::hit('google:' . Http::ip(), 30, 900)) {
            throw new AppError('Too many sign-in attempts. Please wait a few minutes.');
        }
        if (!empty($query['error'])) {
            throw new AppError('Google sign-in was cancelled.');
        }
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/']);
        $parts = explode('.', $cookie);
        $data = count($parts) === 2 && hash_equals(Util::sign('g|' . $parts[0]), $parts[1]) ? Util::decode(Util::base64urlDecode($parts[0]), null) : null;
        if (!is_array($data) || ($data['exp'] ?? 0) < time() || !hash_equals((string) $data['s'], (string) ($query['state'] ?? ''))) {
            throw new AppError('That sign-in link expired. Please try again.');
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            throw new AppError('Google didn’t return a sign-in code.');
        }

        // Exchange the code directly with Google over TLS; the ID token in the reply is trusted
        // because it came from Google's token endpoint (OpenID Connect Core §3.1.3.7).
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'code'          => $code,
                'client_id'     => App::config('google.client_id'),
                'client_secret' => App::config('google.client_secret'),
                'redirect_uri'  => self::redirectUri(),
                'grant_type'    => 'authorization_code',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $token = is_string($body) ? Util::decode($body, []) : [];
        if ($status !== 200 || empty($token['id_token'])) {
            App::log('Google token exchange failed: HTTP ' . $status);
            throw new AppError('Google sign-in didn’t complete. Please try again.');
        }
        $jwt = explode('.', (string) $token['id_token']);
        $claims = count($jwt) === 3 ? Util::decode(Util::base64urlDecode($jwt[1]), []) : [];
        $hd = (string) App::config('google.hosted_domain', '');
        if (!in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            || ($claims['aud'] ?? '') !== App::config('google.client_id')
            || (int) ($claims['exp'] ?? 0) < time()
            || !hash_equals((string) $data['n'], (string) ($claims['nonce'] ?? ''))
            || empty($claims['email_verified']) || empty($claims['sub'])) {
            throw new AppError('Google sign-in couldn’t be verified. Please try again.');
        }
        if ($hd !== '' && ($claims['hd'] ?? '') !== $hd) {
            throw new AppError("Please sign in with your @$hd Google account.");
        }
        $email = strtolower((string) $claims['email']);
        $user = Db::one('SELECT * FROM users WHERE google_sub = ? AND is_active = 1', [$claims['sub']])
            ?? Db::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
        if (!$user || ($user['google_sub'] && $user['google_sub'] !== $claims['sub'])) {
            Audit::log('sign_in_refused', null, null, "Google sign-in refused for $email (no matching account)");
            throw new AppError("There’s no Dwell Central account for $email. Ask an administrator to add you.");
        }
        if (!$user['google_sub']) {
            Db::update('users', ['google_sub' => (string) $claims['sub'], 'updated_at' => Time::nowDb()], 'id = :id', ['id' => $user['id']]);
        }
        return Auth::startSession($user);
    }
}
