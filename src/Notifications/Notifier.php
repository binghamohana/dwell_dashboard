<?php
declare(strict_types=1);

namespace Dwell\Notifications;

use Dwell\Celebrations\Celebrations;
use Dwell\Celebrations\Rules;
use Dwell\Core\App;
use Dwell\Core\AppError;
use Dwell\Core\Audit;
use Dwell\Core\Db;
use Dwell\Core\EmailTemplate;
use Dwell\Core\Mailer;
use Dwell\Core\Settings;
use Dwell\Core\Time;
use Dwell\Core\Util;
use Dwell\Employees\Employees;
use Dwell\Employees\Owners;
use Dwell\Org\Organization;

/**
 * Celebration emails.
 *
 * Runs once a day at the configured time (America/New_York, default 8:00 AM) from cron:
 *  - on the 1st of the month (catching up for a few days if the server was down): a summary to
 *    every celebration owner, and an organization-wide report to opted-in executives/admins;
 *  - an advance reminder N days before each celebration (default 3);
 *  - a day-of email;
 *  - an alert to the designated administrators for any upcoming celebration with no valid owner.
 *
 * Safety: every message has an idempotency key (mode + kind + celebration + recipient), stored
 * with a UNIQUE index, so repeating a run can never send twice. In TEST mode (the default) messages
 * are built exactly as in live mode but delivered only to the chosen administrator's test inbox
 * (or just recorded). LIVE mode must be switched on by a system administrator and is audited.
 */
final class Notifier
{
    public const KINDS = [
        'monthly'       => 'Monthly team summary',
        'org_report'    => 'Organization-wide monthly report',
        'advance'       => 'Advance reminder',
        'day_of'        => 'Day-of email',
        'owner_missing' => 'No-owner alert',
        'failure_alert' => 'Delivery failure alert',
        'account'       => 'Account email',
        'test'          => 'Test email',
    ];

    public static function mode(): string
    {
        $m = (string) Settings::get('notify_mode');
        return in_array($m, ['off', 'test', 'live'], true) ? $m : 'test';
    }

    /** Change off / test / live. Going live needs the typed confirmation and is audited. */
    public static function setMode(array $user, string $mode, string $confirm = ''): void
    {
        if (!in_array($mode, ['off', 'test', 'live'], true)) {
            throw new AppError('Unknown mode.');
        }
        $old = self::mode();
        if ($mode === $old) {
            return;
        }
        if ($mode === 'live' && trim(strtoupper($confirm)) !== 'GO LIVE') {
            throw new AppError('Type GO LIVE to confirm.', 400, 'confirm');
        }
        Settings::set('notify_mode', $mode);
        Settings::set('notify_mode_changed', Util::json(['by' => $user['email'], 'at' => Time::nowDb(), 'from' => $old]));
        Audit::log('notify_mode', 'settings', null, "Celebration emails switched from $old to $mode", ['from' => $old, 'to' => $mode]);
    }

    // ---------------------------------------------------------------- the daily job

    /**
     * Evaluate today's notifications. Safe to call every few minutes: it does nothing before the
     * send time or once today's run (for this mode) has been claimed.
     * $force (admin "Run now") skips the time and once-a-day checks; idempotency keys still
     * prevent duplicates.
     */
    public static function runDaily(bool $force = false): array
    {
        $mode = self::mode();
        if ($mode === 'off') {
            return ['skipped' => 'Celebration emails are turned off.'];
        }
        $today = Time::today();
        $sendAt = Time::toMinutes((string) Settings::get('send_time')) ?? 480;
        if (!$force && Time::nowMinutes() < $sendAt) {
            return ['skipped' => 'Before today’s send time.'];
        }
        $claimed = Db::run(
            'INSERT IGNORE INTO job_runs (job, run_date, mode, started_at) VALUES (?, ?, ?, ?)',
            ['daily', $today, $mode, Time::nowDb()]
        )->rowCount();
        if (!$claimed && !$force) {
            return ['skipped' => 'Already ran today.'];
        }

        $specs = self::plan($today);
        $created = array_fill_keys(array_keys(self::KINDS), 0);
        foreach ($specs as $spec) {
            [, $new] = self::queue($spec, $mode);
            if ($new) {
                $created[$spec['kind']]++;
            }
        }
        $created = array_filter($created);
        Db::run(
            'UPDATE job_runs SET finished_at = ?, result = ? WHERE job = ? AND run_date = ? AND mode = ?',
            [Time::nowDb(), Util::json(['created' => $created, 'forced' => $force]), 'daily', $today, $mode]
        );
        [$sent, $failed] = Mailer::sendDue();
        return ['mode' => $mode, 'date' => $today, 'created' => $created, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Everything the daily job would produce on $today (real recipients, nothing queued).
     * Messages for the previous days in a catch-up window are included; idempotency drops repeats.
     */
    public static function plan(string $today): array
    {
        $specs = [];
        $catchup = (int) Settings::get('monthly_catchup_days');
        if (Settings::get('monthly_enabled') && (int) substr($today, 8, 2) <= 1 + $catchup) {
            array_push($specs, ...self::monthlySpecs(Time::monthStart($today)));
        }
        $advance = (int) Settings::get('advance_days');
        if (Settings::get('advance_enabled') && $advance > 0) {
            array_push($specs, ...self::eventSpecs('advance', Time::addDays($today, 1), Time::addDays($today, $advance), $today));
        }
        if (Settings::get('day_of_enabled')) {
            array_push($specs, ...self::eventSpecs('day_of', $today, $today, $today));
        }
        array_push($specs, ...self::ownerMissingSpecs($today, Time::addDays($today, max(3, Settings::get('advance_enabled') ? $advance : 3))));
        return $specs;
    }

    /** Render a planned message and put it in the outbox for the given mode. Returns [id, created]. */
    public static function queue(array $spec, string $mode): array
    {
        $to = $spec['to'];
        $model = $spec['model'];
        $deliverTo = $to['email'];
        $deliverName = $to['name'];
        $subject = $spec['subject'];
        if ($mode === 'test') {
            $tester = self::testRecipient();
            $deliverTo = $tester['email'] ?? null;
            $deliverName = $tester['name'] ?? null;
            $subject = '[TEST] ' . $subject;
            $model['banner'] = 'Test mode: this was NOT sent to ' . $to['name'] . ' <' . $to['email'] . '>. '
                . 'Once Dwell Central is switched to live, this message goes to them.';
        }
        return Mailer::queue([
            'kind'                  => $spec['kind'],
            'key'                   => $mode . ':' . $spec['key'],
            'mode'                  => $mode,
            'to_email'              => $deliverTo,
            'to_name'               => $deliverName,
            'intended_email'        => $to['email'],
            'intended_name'         => $to['name'],
            'recipient_employee_id' => $to['employee_id'] ?? null,
            'subject'               => $subject,
            'body'                  => EmailTemplate::render($model),
            'event_date'            => $spec['event_date'],
            'items'                 => $spec['items'],
        ]);
    }

    /** The administrator who receives test-mode email, or null to only record it. */
    public static function testRecipient(): ?array
    {
        $id = (int) Settings::get('test_user_id');
        if (!$id) {
            return null;
        }
        $u = Db::one("SELECT email, name FROM users WHERE id = ? AND is_active = 1 AND role = 'system_admin'", [$id]);
        return $u ?: null;
    }

    // ---------------------------------------------------------------- building messages

    private static function link(string $route): string
    {
        return App::baseUrl() . '/#/' . ltrim($route, '/');
    }

    private static function personSub(array $r, bool $withOwner = false): string
    {
        $parts = array_filter([$r['job_title'], Organization::get('properties', $r['property_id'])['name'] ?? null]);
        if ($withOwner) {
            $owner = Employees::owner($r);
            $parts[] = $owner['id'] ? 'Owner: ' . Employees::displayName(Employees::row($owner['id'])) : '⚠ No celebration owner';
        }
        return implode(' · ', $parts);
    }

    private static function tag(array $e): string
    {
        return $e['type'] === 'birthday' ? 'Birthday' : $e['years'] . ($e['years'] === 1 ? ' year' : ' years');
    }

    /** Group events by date for an email. */
    private static function groups(array $events, bool $withOwner = false): array
    {
        $rows = Employees::rows();
        $groups = [];
        foreach ($events as $e) {
            $r = $rows[$e['employee_id']];
            $groups[$e['date']]['title'] = Time::fmtDate($e['date']);
            $groups[$e['date']]['items'][] = [
                'tag'  => self::tag($e),
                'main' => Employees::displayName($r) . ($e['type'] === 'anniversary' ? ' — work anniversary' : ''),
                'sub'  => self::personSub($r, $withOwner),
            ];
        }
        return array_values($groups);
    }

    private static function items(array $events): array
    {
        return array_map(static fn ($e) => ['employee_id' => $e['employee_id'], 'type' => $e['type'], 'date' => $e['date']], $events);
    }

    /** Monthly summaries for owners, plus the organization-wide report. */
    public static function monthlySpecs(string $monthStart): array
    {
        $month = Time::monthName($monthStart);
        $year = substr($monthStart, 0, 4);
        $ym = substr($monthStart, 0, 7);
        $org = (string) Settings::get('org_name');
        $events = Celebrations::events($monthStart, Time::monthEnd($monthStart));
        $rows = Employees::rows();

        $byOwner = [];
        foreach ($events as $e) {
            $owner = Employees::owner($rows[$e['employee_id']]);
            if ($owner['id']) {
                $byOwner[$owner['id']][] = $e;
            }
        }
        if (Settings::get('monthly_send_empty')) {
            // Everyone who is currently the owner for at least one person gets a message.
            foreach ($rows as $r) {
                if (Celebrations::exclusion($r) === null && ($o = Employees::owner($r)['id'])) {
                    $byOwner[$o] = $byOwner[$o] ?? [];
                }
            }
        }

        $specs = [];
        foreach ($byOwner as $ownerId => $list) {
            $owner = $rows[$ownerId];
            $model = [
                'preheader'  => $list ? count($list) . ' celebration' . (count($list) === 1 ? '' : 's') . " on your team in $month" : "No team celebrations in $month",
                'eyebrow'    => "$month $year",
                'heading'    => $list ? "Celebrations on your team in $month" : "No team celebrations in $month",
                'paragraphs' => $list
                    ? ['Hi ' . Employees::greetingName($owner) . ',', (string) Settings::get('monthly_intro')]
                    : ['Hi ' . Employees::greetingName($owner) . ',', 'Nobody you look after has a birthday or work anniversary this month. We’ll check in again next month.'],
                'groups'     => self::groups($list),
                'buttons'    => ['Open Dwell Central' => self::link('celebrations')],
            ];
            $specs[] = [
                'kind'       => 'monthly',
                'key'        => "monthly:$ym:emp$ownerId",
                'to'         => ['email' => $owner['work_email'], 'name' => Employees::displayName($owner), 'employee_id' => $ownerId],
                'subject'    => "$month Employee Celebrations - $org",
                'model'      => $model,
                'event_date' => $monthStart,
                'items'      => self::items($list),
            ];
        }

        // Organization-wide report: only to opted-in executives and administrators.
        $missing = count(array_filter($events, static fn ($e) => Employees::owner($rows[$e['employee_id']])['id'] === null));
        foreach (Db::all("SELECT id, email, name FROM users WHERE is_active = 1 AND org_report = 1 AND role IN ('system_admin','executive') ORDER BY id") as $u) {
            $b = count(array_filter($events, static fn ($e) => $e['type'] === 'birthday'));
            $paras = ['Hi ' . explode(' ', $u['name'])[0] . ',',
                "Across $org there " . (count($events) === 1 ? 'is 1 celebration' : 'are ' . count($events) . ' celebrations') . " in $month: "
                . "$b birthday" . ($b === 1 ? '' : 's') . ' and ' . (count($events) - $b) . ' work anniversar' . (count($events) - $b === 1 ? 'y' : 'ies') . '.'];
            if ($missing) {
                $paras[] = "$missing of them " . ($missing === 1 ? 'has' : 'have') . ' no celebration owner yet (marked ⚠). Set a supervisor or owner in Dwell Central so someone is reminded.';
            }
            $specs[] = [
                'kind'       => 'org_report',
                'key'        => "org_report:$ym:user{$u['id']}",
                'to'         => ['email' => $u['email'], 'name' => $u['name'], 'employee_id' => null],
                'subject'    => "$month Employee Celebrations - Organization-wide",
                'model'      => [
                    'preheader'  => count($events) . " celebrations across $org in $month",
                    'eyebrow'    => "$month $year · Organization-wide",
                    'heading'    => "$month celebrations across $org",
                    'paragraphs' => $paras,
                    'groups'     => self::groups($events, true),
                    'buttons'    => ['Open the dashboard' => self::link('dashboard')],
                ],
                'event_date' => $monthStart,
                'items'      => self::items($events),
            ];
        }
        return $specs;
    }

    /** Advance reminders or day-of emails for celebrations between two dates. */
    public static function eventSpecs(string $kind, string $from, string $to, string $today): array
    {
        $rows = Employees::rows();
        $specs = [];
        foreach (Celebrations::events($from, $to) as $e) {
            $r = $rows[$e['employee_id']];
            $owner = Employees::owner($r);
            $recipients = [];
            if ($owner['id']) {
                $recipients[] = $rows[$owner['id']];
            }
            if (Settings::get('notify_dotted_line') && $r['dotted_line_id'] && $r['dotted_line_id'] !== $owner['id']) {
                $dl = $rows[$r['dotted_line_id']] ?? null;
                if (Owners::isValid($dl, $r)) {
                    $recipients[] = $dl;
                }
            }
            $title = Celebrations::title($e, $r);
            $days = Time::diffDays($today, $e['date']);
            $when = $days === 1 ? 'tomorrow' : 'in ' . $days . ' days';
            $weekday = (new \DateTimeImmutable($e['date']))->format('l');
            $details = [
                'Who'  => Employees::displayName($r) . ($r['job_title'] ? "\n" . $r['job_title'] : ''),
                'Date' => Time::fmtDate($e['date']),
            ];
            if ($e['type'] === 'anniversary') {
                $details['Milestone'] = $e['years'] . ($e['years'] === 1 ? ' year' : ' years') . ' with the team (since ' . (new \DateTimeImmutable((string) Rules::serviceStart($r)))->format('F Y') . ')';
            }
            if ($prop = Organization::get('properties', $r['property_id'])) {
                $details['Where'] = $prop['name'];
            }
            foreach ($recipients as $rec) {
                $isDotted = (int) $rec['id'] !== (int) $owner['id'];
                $hello = 'Hi ' . Employees::greetingName($rec) . ',';
                $model = $kind === 'advance'
                    ? [
                        'preheader'  => "$title is $when",
                        'eyebrow'    => 'Coming up ' . $weekday,
                        'heading'    => "$title is $when",
                        'paragraphs' => [$hello, 'A quick heads-up so there’s time to plan something: a card, a treat, or a mention at the next huddle.'
                            . ($isDotted ? ' (You’re receiving this as their dotted-line manager.)' : '')],
                        'details'    => $details,
                        'buttons'    => ['Open Dwell Central' => self::link('people/' . $r['id'])],
                    ]
                    : [
                        'preheader'  => "Today is $title",
                        'eyebrow'    => Time::fmtDate($e['date']),
                        'heading'    => 'Today Is ' . $title . '!',
                        'paragraphs' => [$hello, (string) Settings::get('day_of_note') . ($isDotted ? ' (You’re receiving this as their dotted-line manager.)' : '')],
                        'details'    => $details,
                        'buttons'    => ['Open Dwell Central' => self::link('people/' . $r['id'])],
                    ];
                $specs[] = [
                    'kind'       => $kind,
                    'key'        => "$kind:{$e['type']}:{$r['id']}:{$e['date']}:emp{$rec['id']}",
                    'to'         => ['email' => $rec['work_email'], 'name' => Employees::displayName($rec), 'employee_id' => (int) $rec['id']],
                    'subject'    => $kind === 'advance' ? "Coming up $weekday: $title" : "Today Is $title!",
                    'model'      => $model,
                    'event_date' => $e['date'],
                    'items'      => self::items([$e]),
                ];
            }
        }
        return $specs;
    }

    /** One alert per administrator per day listing upcoming celebrations that nobody owns. */
    public static function ownerMissingSpecs(string $today, string $until): array
    {
        $rows = Employees::rows();
        $events = array_values(array_filter(
            Celebrations::events($today, $until),
            static fn ($e) => Employees::owner($rows[$e['employee_id']])['id'] === null
        ));
        if (!$events) {
            return [];
        }
        // Designated administrators (Users → "alerts"); every system administrator if nobody is designated.
        $admins = Db::all("SELECT id, email, name FROM users WHERE is_active = 1 AND role = 'system_admin' AND alerts = 1 ORDER BY id")
            ?: Db::all("SELECT id, email, name FROM users WHERE is_active = 1 AND role = 'system_admin' ORDER BY id");
        $specs = [];
        foreach ($admins as $u) {
            $groups = [];
            foreach ($events as $e) {
                $r = $rows[$e['employee_id']];
                $groups[$e['date']]['title'] = Time::fmtDate($e['date']);
                $groups[$e['date']]['items'][] = [
                    'tag'  => self::tag($e),
                    'main' => Employees::displayName($r),
                    'sub'  => Employees::owner($r)['note'] ?? 'No owner',
                ];
            }
            $n = count($events);
            $specs[] = [
                'kind'       => 'owner_missing',
                'key'        => "owner_missing:$today:user{$u['id']}",
                'to'         => ['email' => $u['email'], 'name' => $u['name'], 'employee_id' => null],
                'subject'    => "Action needed: $n upcoming celebration" . ($n === 1 ? '' : 's') . ' without an owner',
                'model'      => [
                    'preheader'  => "$n upcoming celebration" . ($n === 1 ? ' has' : 's have') . ' nobody to remind',
                    'eyebrow'    => 'Needs attention',
                    'heading'    => 'Nobody is set to celebrate ' . ($n === 1 ? 'this person' : 'these people'),
                    'paragraphs' => ['These celebrations are coming up, but there’s no active supervisor, property manager or assigned owner with a work email to remind. Set a supervisor or a celebration owner and future reminders will route automatically.'],
                    'groups'     => array_values($groups),
                    'buttons'    => ['Fix in Dwell Central' => self::link('admin/people/issue/no_owner')],
                ],
                'event_date' => $today,
                'items'      => self::items($events),
            ];
        }
        return self::dropAlreadyAlerted($specs);
    }

    /** Remove celebrations that an earlier no-owner alert (same mode) already listed. */
    private static function dropAlreadyAlerted(array $specs): array
    {
        $mode = self::mode();
        $out = [];
        foreach ($specs as $spec) {
            $fresh = [];
            foreach ($spec['items'] as $i => $item) {
                $done = Db::value(
                    "SELECT 1 FROM notification_items ni JOIN notifications n ON n.id = ni.notification_id
                     WHERE n.kind = 'owner_missing' AND n.mode = ? AND n.idem_key <> ? AND ni.employee_id = ? AND ni.type = ? AND ni.date = ? LIMIT 1",
                    [$mode, $mode . ':' . $spec['key'], $item['employee_id'], $item['type'], $item['date']]
                );
                if (!$done) {
                    $fresh[] = $i;
                }
            }
            if (!$fresh) {
                continue;
            }
            if (count($fresh) < count($spec['items'])) {
                // Rebuild the list with only the new celebrations.
                $keep = array_map(static fn ($i) => $spec['items'][$i], $fresh);
                $rows = Employees::rows();
                $groups = [];
                foreach ($keep as $item) {
                    $r = $rows[$item['employee_id']];
                    $groups[$item['date']]['title'] = Time::fmtDate($item['date']);
                    $groups[$item['date']]['items'][] = [
                        'tag'  => $item['type'] === 'birthday' ? 'Birthday' : 'Anniversary',
                        'main' => Employees::displayName($r),
                        'sub'  => Employees::owner($r)['note'] ?? 'No owner',
                    ];
                }
                $spec['items'] = $keep;
                $spec['model']['groups'] = array_values($groups);
                $n = count($keep);
                $spec['subject'] = "Action needed: $n upcoming celebration" . ($n === 1 ? '' : 's') . ' without an owner';
            }
            $out[] = $spec;
        }
        return $out;
    }

    // ---------------------------------------------------------------- previews & one-offs

    /**
     * What the daily job would send on a given date, rendered, without queueing anything.
     * Each message says whether it already exists in the outbox for the current mode.
     */
    public static function preview(string $date): array
    {
        if (!Time::isDate($date)) {
            throw new AppError('Please choose a date.');
        }
        $mode = self::mode();
        $out = [];
        foreach (self::plan($date) as $spec) {
            [$html, $text] = EmailTemplate::render($spec['model']);
            $out[] = [
                'kind'     => $spec['kind'],
                'label'    => self::KINDS[$spec['kind']],
                'to'       => $spec['to']['name'] . ' <' . $spec['to']['email'] . '>',
                'subject'  => $spec['subject'],
                'html'     => $html,
                'text'     => $text,
                'count'    => count($spec['items']),
                'existing' => (bool) Db::value('SELECT id FROM notifications WHERE idem_key = ?', [$mode . ':' . $spec['key']]),
            ];
        }
        return $out;
    }

    /** Send a one-off system email (account links, test messages). Delivered immediately. */
    public static function system(string $kind, string $key, string $email, string $name, string $subject, array $model): int
    {
        [$id] = Mailer::queue([
            'kind'     => $kind,
            'key'      => 'system:' . $key,
            'mode'     => 'system',
            'to_email' => $email,
            'to_name'  => $name,
            'subject'  => $subject,
            'body'     => EmailTemplate::render($model),
        ]);
        Mailer::sendNow([$id]);
        return $id;
    }

    /** Tell administrators about messages that failed every retry (once per batch). */
    public static function alertFailures(): int
    {
        $failed = Db::all("SELECT id, kind, intended_name, intended_email, subject, last_error FROM notifications
                           WHERE status = 'failed' AND alerted_at IS NULL AND kind NOT IN ('failure_alert') ORDER BY id LIMIT 50");
        if (!$failed) {
            return 0;
        }
        $ids = array_map(static fn ($f) => (int) $f['id'], $failed);
        Db::run('UPDATE notifications SET alerted_at = ? WHERE id IN (' . Db::in($ids) . ')', array_merge([Time::nowDb()], $ids));
        $admins = Db::all("SELECT id, email, name FROM users WHERE is_active = 1 AND role = 'system_admin' AND alerts = 1")
            ?: Db::all("SELECT id, email, name FROM users WHERE is_active = 1 AND role = 'system_admin'");
        $items = array_map(static fn ($f) => [
            'tag'  => self::KINDS[$f['kind']] ?? $f['kind'],
            'main' => $f['subject'],
            'sub'  => 'For ' . ($f['intended_name'] ?: $f['intended_email']) . ' · ' . mb_substr((string) $f['last_error'], 0, 160),
        ], $failed);
        foreach ($admins as $u) {
            self::system('failure_alert', 'failure_alert:' . sha1(implode(',', $ids)) . ':user' . $u['id'], $u['email'], $u['name'],
                'Dwell Central: ' . count($failed) . ' email' . (count($failed) === 1 ? '' : 's') . ' could not be delivered', [
                    'eyebrow'    => 'Delivery problem',
                    'heading'    => 'Some emails could not be delivered',
                    'paragraphs' => ['These messages failed after ' . Mailer::MAX_ATTEMPTS . ' attempts. Check the mail settings in config/config.php (or the server error shown), then use Retry in Notifications.'],
                    'groups'     => [['title' => 'Failed messages', 'items' => $items]],
                    'buttons'    => ['Open notifications' => self::link('notifications')],
                ]);
        }
        return count($failed);
    }
}
