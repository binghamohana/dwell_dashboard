<?php
// Health check: configuration, database, PHP extensions, folders, background tasks, email.
//   sudo -u www-data php bin/check.php
//   sudo -u www-data php bin/check.php --send-test=you@dwellcommunities.com
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Dwell\Core\App;
use Dwell\Core\Db;
use Dwell\Core\Settings;

$problems = 0;
$line = static function (bool $ok, string $msg, bool $warnOnly = false) use (&$problems): void {
    echo ($ok ? '  ok    ' : ($warnOnly ? '  warn  ' : '  FAIL  ')) . $msg . "\n";
    if (!$ok && !$warnOnly) {
        $problems++;
    }
};

echo "Dwell Central health check\n\nConfiguration\n";
$line(is_file(DC_ROOT . '/config/config.php'), 'config/config.php exists');
$secret = (string) App::config('app_secret', '');
$line($secret !== '' && !str_starts_with($secret, 'CHANGE-ME') && strlen($secret) >= 32, 'app_secret is set (32+ characters)');
$base = App::baseUrl();
$line($base !== '' && !str_contains($base, 'localhost'), "base_url is $base");
$line(!App::config('debug'), 'debug is off', true);

echo "\nPHP " . PHP_VERSION . "\n";
$line(PHP_VERSION_ID >= 80100, 'PHP 8.1 or newer');
foreach (['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'openssl' => true, 'simplexml' => true, 'zip' => false, 'gd' => false, 'curl' => false, 'intl' => false] as $ext => $required) {
    $line(extension_loaded($ext), "$ext extension" . ($required ? '' : ' (needed for ' . ['zip' => '.xlsx imports', 'gd' => 'staff photos', 'curl' => 'Google sign-in', 'intl' => 'accent-insensitive name matching'][$ext] . ')'), !$required);
}

echo "\nDatabase\n";
try {
    Db::pdo();
    $line(true, 'connected to ' . App::config('db.name'));
    $tables = Db::all('SHOW TABLES');
    $line(count($tables) >= 15, count($tables) . ' tables (run php bin/install.php if this is low)');
    $line((int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'system_admin' AND is_active = 1") > 0, 'at least one active system administrator');
    echo '        ' . Db::value("SELECT COUNT(*) FROM employees WHERE status <> 'terminated'") . " current people on record\n";
} catch (Throwable $e) {
    $line(false, 'database: ' . $e->getMessage());
}

echo "\nFolders\n";
foreach (['storage/logs', 'storage/photos'] as $dir) {
    $path = DC_ROOT . '/' . $dir;
    $line(is_dir($path) && is_writable($path), "$dir is writable", $dir === 'storage/backups');
}

echo "\nBackground tasks and email\n";
try {
    $last = (string) Settings::get('cron_last_run');
    $line($last !== '' && strtotime($last . ' UTC') > time() - 20 * 60, 'cron ran in the last 20 minutes' . ($last ? " (last: $last UTC)" : ' (never ran)'));
    $mode = (string) Settings::get('notify_mode');
    echo "        celebration emails: " . strtoupper($mode) . " mode; daily check at " . Settings::get('send_time') . ' ' . Settings::get('timezone') . "\n";
    $transport = (string) App::config('mail.transport', 'log');
    $line($transport === 'smtp', "mail transport is '$transport'" . ($transport === 'log' ? ' (messages are written to storage/logs/mail.log, not sent)' : ''), true);
    $failed = (int) Db::value("SELECT COUNT(*) FROM notifications WHERE status = 'failed'");
    $line($failed === 0, "$failed failed messages");
} catch (Throwable $e) {
    $line(false, $e->getMessage());
}

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--send-test=')) {
        $to = substr($arg, 12);
        echo "\nSending a test email to $to…\n";
        $id = Dwell\Notifications\Notifier::system('test', 'test:cli:' . bin2hex(random_bytes(4)), $to, $to, 'Dwell Central test email', [
            'heading' => 'Email delivery works', 'paragraphs' => ['This test was sent from the server with bin/check.php.'],
        ]);
        $n = Db::one('SELECT status, last_error FROM notifications WHERE id = ?', [$id]);
        $line($n['status'] === 'sent', $n['status'] === 'sent' ? 'sent' : 'not sent: ' . $n['last_error']);
    }
}

echo $problems ? "\n$problems problem(s) found.\n" : "\nEverything looks good.\n";
exit($problems ? 1 : 0);
