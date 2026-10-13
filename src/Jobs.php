<?php
declare(strict_types=1);

namespace Dwell;

use Dwell\Core\Db;
use Dwell\Core\Mailer;
use Dwell\Core\RateLimit;
use Dwell\Core\Settings;
use Dwell\Core\Time;
use Dwell\Import\Importer;
use Dwell\Notifications\Notifier;

/** Everything cron does (bin/cron.php, every 5 minutes). */
final class Jobs
{
    public static function run(): array
    {
        Settings::set('cron_last_run', Time::nowDb());
        $daily = Notifier::runDaily();
        [$sent, $failed] = Mailer::sendDue();
        $alerted = Notifier::alertFailures();

        // Housekeeping, roughly hourly
        $purged = 0;
        if ((int) Time::nowLocal()->format('i') < 5) {
            RateLimit::prune();
            Db::run('DELETE FROM user_tokens WHERE expires_at < ?', [Time::now()->modify('-30 days')->format('Y-m-d H:i:s')]);
            $purged = Importer::purge();
            $keep = (int) Settings::get('notification_retention_days');
            Db::run('DELETE FROM notifications WHERE created_at < ?', [Time::now()->modify("-$keep days")->format('Y-m-d H:i:s')]);
        }
        return ['daily' => $daily, 'sent' => $sent, 'failed' => $failed, 'alerted' => $alerted, 'purged_imports' => $purged];
    }

    /** Health information for the dashboard and bin/check.php. */
    public static function health(): array
    {
        $last = (string) Settings::get('cron_last_run');
        $cronOk = $last !== '' && strtotime($last . ' UTC') > time() - 20 * 60;
        $lastRun = Db::one("SELECT run_date, mode, started_at, finished_at, result FROM job_runs WHERE job = 'daily' ORDER BY started_at DESC LIMIT 1");
        return [
            'cron_ok'         => $cronOk,
            'cron_last_run'   => $last ?: null,
            'mail_transport'  => (string) \Dwell\Core\App::config('mail.transport', 'log'),
            'mode'            => Notifier::mode(),
            'mode_changed'    => \Dwell\Core\Util::decode((string) Settings::get('notify_mode_changed'), null),
            'test_recipient'  => Notifier::testRecipient(),
            'last_daily'      => $lastRun ? $lastRun + ['result' => \Dwell\Core\Util::decode($lastRun['result'], null)] : null,
            'failed'          => (int) Db::value("SELECT COUNT(*) FROM notifications WHERE status = 'failed'"),
            'pending'         => (int) Db::value("SELECT COUNT(*) FROM notifications WHERE status = 'pending'"),
            'send_time'       => Settings::get('send_time'),
            'timezone'        => Settings::get('timezone'),
        ];
    }
}
