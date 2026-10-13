<?php
// Manage sign-in accounts from the server (for the first administrator, or a lockout).
//   php bin/admin.php list
//   php bin/admin.php create you@example.com "Your Name"     (asks for a password)
//   php bin/admin.php password you@example.com                 (asks for a new password)
//   php bin/admin.php disable someone@example.com
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Dwell\Core\Auth;
use Dwell\Core\Db;
use Dwell\Core\Time;

function ask(string $prompt): string
{
    echo $prompt;
    if (DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
        system('stty -echo');
        $v = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
        return $v;
    }
    return trim((string) fgets(STDIN));
}

$cmd = $argv[1] ?? '';
$email = strtolower(trim($argv[2] ?? ''));
try {
    switch ($cmd) {
        case 'list':
            foreach (Db::all('SELECT email, name, role, is_active, last_login_at FROM users ORDER BY name') as $u) {
                printf("%-36s %-24s %-14s %s %s\n", $u['email'], $u['name'], $u['role'], $u['is_active'] ? 'active  ' : 'DISABLED', $u['last_login_at'] ?? 'never signed in');
            }
            break;
        case 'create':
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Usage: php bin/admin.php create you@example.com "Your Name"');
            }
            if (Db::value('SELECT id FROM users WHERE email = ?', [$email])) {
                throw new RuntimeException('That account already exists. Use "password" to reset it.');
            }
            $pw = ask('Password (10+ characters): ');
            $hash = Auth::hashPassword($pw);
            Db::insert('users', ['email' => $email, 'name' => $argv[3] ?? $email, 'role' => 'system_admin', 'password_hash' => $hash,
                'alerts' => 1, 'created_at' => Time::nowDb(), 'updated_at' => Time::nowDb()]);
            echo "System administrator $email created.\n";
            break;
        case 'password':
            $id = Db::value('SELECT id FROM users WHERE email = ?', [$email]);
            if (!$id) {
                throw new RuntimeException('No account with that email.');
            }
            Auth::setPassword((int) $id, ask('New password (10+ characters): '));
            Db::run('UPDATE users SET is_active = 1 WHERE id = ?', [$id]);
            echo "Password updated; all other sessions for $email are signed out.\n";
            break;
        case 'disable':
            $n = Db::run('UPDATE users SET is_active = 0, session_version = session_version + 1 WHERE email = ?', [$email])->rowCount();
            echo $n ? "Disabled $email.\n" : "No account with that email.\n";
            break;
        default:
            echo "Usage: php bin/admin.php list | create EMAIL \"NAME\" | password EMAIL | disable EMAIL\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
