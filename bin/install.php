<?php
// Creates or updates the database tables. Safe to run again after every update.
//   php bin/install.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Dwell\Core\Db;
use Dwell\Core\Migrations;

$sql = file_get_contents(DC_ROOT . '/sql/schema.sql');
$statements = array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql))));
try {
    foreach ($statements as $statement) {
        Db::pdo()->exec($statement);
    }
    foreach (Migrations::run() as $step) {
        echo "  - $step\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
$users = (int) Db::value('SELECT COUNT(*) FROM users');
echo "Database is ready.\n";
if ($users === 0) {
    echo "\nNext, create the first administrator:\n  php bin/admin.php create you@example.com \"Your Name\"\n";
}
