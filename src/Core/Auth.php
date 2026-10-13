<?php
declare(strict_types=1);

namespace Dwell\Core;

/**
 * Sign-in with a signed, HttpOnly cookie (no session files). Changing a password, disabling a
 * user or changing their role bumps session_version, which signs them out everywhere.
 * Google Workspace sign-in (GoogleSignIn) issues the same cookie once Google confirms the email.
 */
final class Auth
{
    private const COOKIE = 'dwell_central';
    private const LIFETIME = 7 * 86400;

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $ip = Http::ip();
        if (RateLimit::count("login:$ip", 900) >= 10 || RateLimit::count('login:' . $email, 900) >= 10) {
            throw new AppError('Too many sign-in attempts. Please wait 15 minutes and try again.', 429);
        }
        $user = Db::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
        if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
            RateLimit::hit("login:$ip", PHP_INT_MAX, 900);
            RateLimit::hit('login:' . $email, PHP_INT_MAX, 900);
            throw new AppError('That email and password don’t match an account.', 401, 'password', 'bad_login');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
        }
        RateLimit::clear('login:' . $email);
        return self::startSession($user);
    }

    /** Sign a known, active user in (after a password check or Google confirmation). */
    public static function startSession(array $user): array
    {
        Db::update('users', ['last_login_at' => Time::nowDb()], 'id = :id', ['id' => $user['id']]);
        self::issueCookie($user);
        self::$user = $user;
        self::$loaded = true;
        Audit::log('sign_in', 'user', (int) $user['id'], $user['email'] . ' signed in');
        return $user;
    }

    public static function logout(): void
    {
        self::setCookie('', time() - 3600);
        self::$user = null;
        self::$loaded = true;
    }

    public static function current(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        $parts = is_string($cookie) ? explode('.', $cookie) : [];
        if (count($parts) !== 2 || !hash_equals(Util::sign('user|' . $parts[0]), $parts[1])) {
            return null;
        }
        $data = json_decode(Util::base64urlDecode($parts[0]), true);
        if (!is_array($data) || ($data['exp'] ?? 0) < time()) {
            return null;
        }
        $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) ($data['id'] ?? 0)]);
        if (!$user || (int) $user['session_version'] !== (int) ($data['v'] ?? -1)) {
            return null;
        }
        return self::$user = $user;
    }

    /** Tests and CLI tools act as a given user. */
    public static function actAs(?array $user): void
    {
        self::$user = $user;
        self::$loaded = true;
    }

    public static function require(): array
    {
        $user = self::current();
        if (!$user) {
            throw new AppError('Please sign in.', 401, null, 'unauthenticated');
        }
        return $user;
    }

    /** Token the app sends back in the X-CSRF-Token header on every change. */
    public static function csrfToken(): string
    {
        return Util::sign('csrf|' . ($_COOKIE[self::COOKIE] ?? ''));
    }

    public static function checkCsrf(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || !hash_equals(self::csrfToken(), $sent)) {
            throw new AppError('Your session has expired. Please refresh the page.', 403, null, 'csrf');
        }
    }

    public static function issueCookie(array $user): void
    {
        $payload = Util::base64url(json_encode([
            'id'  => (int) $user['id'],
            'v'   => (int) $user['session_version'],
            'exp' => time() + self::LIFETIME,
        ]));
        $value = $payload . '.' . Util::sign('user|' . $payload);
        self::setCookie($value, time() + self::LIFETIME);
        $_COOKIE[self::COOKIE] = $value; // so csrfToken() matches in this same response
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        // Scoped to this app's folder (e.g. /dash/) so other apps on the domain never receive it.
        $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => $path,
            'secure'   => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax', // Lax so the Google sign-in redirect can return to the app
        ]);
    }

    public static function hashPassword(string $password): string
    {
        if (mb_strlen($password) < 10) {
            throw new AppError('Passwords need at least 10 characters.', 400, 'password');
        }
        if (mb_strlen($password) > 200) {
            throw new AppError('That password is too long.', 400, 'password');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function setPassword(int $userId, string $password): void
    {
        Db::run(
            'UPDATE users SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?',
            [self::hashPassword($password), Time::nowDb(), $userId]
        );
    }

    // ---------------------------------------------------------------- invitation / reset links

    /** Create a single-use link token; returns the raw token (only its hash is stored). */
    public static function createToken(int $userId, string $purpose, int $hours): string
    {
        Db::run('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [Time::nowDb(), $userId, $purpose]);
        $raw = Util::token(24);
        Db::insert('user_tokens', [
            'user_id'    => $userId,
            'purpose'    => $purpose,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => Time::now()->modify("+$hours hours")->format('Y-m-d H:i:s'),
            'created_at' => Time::nowDb(),
        ]);
        return $raw;
    }

    /** The user a valid, unused token belongs to (or null). */
    public static function tokenUser(string $raw, string $purpose): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $raw)) {
            return null;
        }
        return Db::one(
            'SELECT u.*, t.id AS token_id FROM user_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.purpose = ? AND t.used_at IS NULL AND t.expires_at > ? AND u.is_active = 1',
            [hash('sha256', $raw), $purpose, Time::nowDb()]
        );
    }

    /** Set a password from an invitation or reset link, then sign in. */
    public static function redeemToken(string $raw, string $purpose, string $password): array
    {
        $ip = Http::ip();
        if (!RateLimit::hit("token:$ip", 20, 3600)) {
            throw new AppError('Too many attempts. Please wait an hour and try again.', 429);
        }
        $user = self::tokenUser($raw, $purpose);
        if (!$user) {
            throw new AppError('This link has expired or was already used. Ask an administrator for a new one.', 400, null, 'bad_token');
        }
        self::setPassword((int) $user['id'], $password);
        Db::update('user_tokens', ['used_at' => Time::nowDb()], 'id = :id', ['id' => $user['token_id']]);
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        Audit::log($purpose === 'invite' ? 'invite_accepted' : 'password_reset', 'user', (int) $user['id'], $user['email'] . ($purpose === 'invite' ? ' accepted their invitation' : ' reset their password'));
        return self::startSession($user);
    }
}
