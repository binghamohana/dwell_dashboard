<?php
// Background tasks: the daily celebration check, email delivery and retries, housekeeping.
// Run every 5 minutes, e.g. in /etc/cron.d/dwell-central:
//   */5 * * * * www-data php /var/www/dwell-central/bin/cron.php >> /var/www/dwell-central/storage/logs/cron.log 2>&1
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

$lock = fopen(DC_ROOT . '/storage/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still going
}

try {
    $r = Dwell\Jobs::run();
    $created = $r['daily']['created'] ?? [];
    if ($created || $r['sent'] || $r['failed'] || $r['alerted']) {
        printf("[%s] mode=%s created=%s sent=%d failed=%d alerted=%d\n", gmdate('Y-m-d H:i:s'), Dwell\Notifications\Notifier::mode(),
            json_encode($created), $r['sent'] + ($r['daily']['sent'] ?? 0), $r['failed'] + ($r['daily']['failed'] ?? 0), $r['alerted']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . '] cron error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}
