<?php
declare(strict_types=1);

namespace Dwell\Core;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Outbox-based email. Every message is written to `notifications` first (with a unique
 * idempotency key), then delivered. Failures are retried with back-off; after the last attempt
 * the row is marked failed and administrators are alerted (Jobs::alertFailures).
 */
final class Mailer
{
    public const MAX_ATTEMPTS = 5;

    /**
     * Queue a message. Returns [id, created]: created=false means a message with the same
     * idempotency key already exists, so nothing new was queued.
     *
     * @param array{kind:string, key:string, mode:string, to_email:?string, to_name?:?string,
     *   intended_email?:?string, intended_name?:?string, recipient_employee_id?:?int,
     *   subject:string, body:array{0:string,1:string}, event_date?:?string, items?:array} $m
     */
    public static function queue(array $m): array
    {
        $existing = Db::value('SELECT id FROM notifications WHERE idem_key = ?', [$m['key']]);
        if ($existing) {
            return [(int) $existing, false];
        }
        try {
            $id = Db::insert('notifications', [
                'kind'                  => $m['kind'],
                'idem_key'              => mb_substr($m['key'], 0, 190),
                'mode'                  => $m['mode'],
                'to_email'              => $m['to_email'],
                'to_name'               => isset($m['to_name']) ? Util::cleanText($m['to_name'], 160) : null,
                'intended_email'        => $m['intended_email'] ?? $m['to_email'],
                'intended_name'         => isset($m['intended_name']) ? Util::cleanText($m['intended_name'], 160) : ($m['to_name'] ?? null),
                'recipient_employee_id' => $m['recipient_employee_id'] ?? null,
                'subject'               => Util::cleanText($m['subject'], 255),
                'body_html'             => $m['body'][0],
                'body_text'             => $m['body'][1],
                'status'                => $m['to_email'] ? 'pending' : 'held',
                'send_after'            => Time::nowDb(),
                'event_date'            => $m['event_date'] ?? null,
                'created_at'            => Time::nowDb(),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') { // a concurrent run inserted the same key first
                return [(int) Db::value('SELECT id FROM notifications WHERE idem_key = ?', [$m['key']]), false];
            }
            throw $e;
        }
        foreach ($m['items'] ?? [] as $item) {
            Db::run(
                'INSERT IGNORE INTO notification_items (notification_id, employee_id, type, date) VALUES (?, ?, ?, ?)',
                [$id, $item['employee_id'], $item['type'], $item['date']]
            );
        }
        return [$id, true];
    }

    /** Deliver specific queued messages right away. */
    public static function sendNow(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }
        foreach (Db::all("SELECT * FROM notifications WHERE status = 'pending' AND id IN (" . Db::in($ids) . ')', $ids) as $row) {
            self::attempt($row);
        }
    }

    /** Deliver everything that is due. Returns [sent, failed]. */
    public static function sendDue(int $limit = 100): array
    {
        $rows = Db::all(
            "SELECT * FROM notifications WHERE status = 'pending' AND send_after <= ? ORDER BY id LIMIT " . (int) $limit,
            [Time::nowDb()]
        );
        $sent = $failed = 0;
        foreach ($rows as $row) {
            self::attempt($row) ? $sent++ : $failed++;
        }
        return [$sent, $failed];
    }

    public static function retry(int $id): void
    {
        $n = Db::one('SELECT status, to_email FROM notifications WHERE id = ?', [$id]);
        if (!$n || !in_array($n['status'], ['failed', 'pending'], true) || !$n['to_email']) {
            throw new AppError('Only failed messages can be retried.');
        }
        Db::run("UPDATE notifications SET status = 'pending', attempts = 0, send_after = ?, alerted_at = NULL WHERE id = ?", [Time::nowDb(), $id]);
        self::sendNow([$id]);
    }

    private static function attempt(array $row): bool
    {
        // Claim the row so a concurrent cron run can't send it twice.
        $claimed = Db::run(
            "UPDATE notifications SET attempts = attempts + 1, send_after = ? WHERE id = ? AND status = 'pending' AND attempts = ?",
            [Time::now()->modify('+15 minutes')->format('Y-m-d H:i:s'), $row['id'], $row['attempts']]
        )->rowCount();
        if (!$claimed) {
            return false;
        }
        $attempts = (int) $row['attempts'] + 1;
        try {
            self::deliver($row);
            Db::update('notifications', ['status' => 'sent', 'sent_at' => Time::nowDb(), 'last_error' => null], 'id = :id', ['id' => $row['id']]);
            return true;
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
            App::log("Notification #{$row['id']} ({$row['kind']}) failed (attempt $attempts): $error");
            $final = $attempts >= self::MAX_ATTEMPTS;
            Db::update('notifications', [
                'status'     => $final ? 'failed' : 'pending',
                'last_error' => $error,
                'send_after' => Time::now()->modify('+' . (5 * $attempts * $attempts) . ' minutes')->format('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $row['id']]);
            return false;
        }
    }

    private static function deliver(array $row): void
    {
        if (!$row['to_email']) {
            throw new \RuntimeException('No delivery address.');
        }
        $transport = (string) App::config('mail.transport', 'log');
        if ($transport === 'log') {
            $entry = sprintf("==== %s | to: %s | %s\n%s\n\n", Time::nowDb(), $row['to_email'], $row['subject'], $row['body_text']);
            if (@file_put_contents(DC_ROOT . '/storage/logs/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Could not write storage/logs/mail.log');
            }
            return;
        }
        if ($transport === 'fail') { // tests: simulate an outage
            throw new \RuntimeException('Simulated delivery failure');
        }

        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 20;
        $serverReplies = [];
        if ($transport === 'smtp') {
            $mail->isSMTP();
            // Keep the server's replies so a failure can say why. Lines we send (including the
            // login) are discarded, never stored.
            $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function ($line) use (&$serverReplies): void {
                if (str_starts_with((string) $line, 'SERVER -> CLIENT:')) {
                    $serverReplies[] = trim(substr((string) $line, 17));
                }
            };
            $mail->Host = (string) App::config('mail.host');
            $mail->Port = (int) App::config('mail.port', 587);
            $encryption = (string) App::config('mail.encryption', 'tls');
            $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($encryption === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
            $mail->SMTPAutoTLS = $encryption !== '';
            $username = (string) App::config('mail.username', '');
            if ($username !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $username;
                $mail->Password = (string) App::config('mail.password', '');
            }
        } else {
            $mail->isMail();
        }

        $mail->setFrom((string) App::config('mail.from_email'), (string) App::config('mail.from_name', Settings::get('portal_name')));
        $replyTo = (string) App::config('mail.reply_to', '');
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        $mail->addAddress($row['to_email'], (string) $row['to_name']);
        $mail->Subject = $row['subject'];
        $mail->isHTML(true);
        $mail->Body = $row['body_html'];
        $mail->AltBody = $row['body_text'];
        try {
            $mail->send();
        } catch (\Throwable $e) {
            // PHPMailer's message is generic ("Could not authenticate"); the server's reply says why.
            $reply = '';
            $code = null;
            foreach ($serverReplies as $line) {
                if (preg_match('/^[45]\d\d[ -]/', $line)) {
                    $reply .= ($reply === '' ? '' : ' ') . preg_replace('/^[45]\d\d[ -](\d\.\d\.\d+ )?/', '', $line);
                    $code = $code ?? substr($line, 0, 3);
                }
            }
            throw new \RuntimeException($e->getMessage() . ($reply !== '' ? ' Server said: ' . $code . ' ' . trim($reply) : ''), 0, $e);
        }
    }
}
