<?php
declare(strict_types=1);

namespace Dwell\Core;

/**
 * Who changed what, when. Visible to system administrators only. Birthdays are recorded as
 * "changed" without their values, so the log never becomes a second copy of personal data.
 */
final class Audit
{
    public const REDACT = ['birth_month', 'birth_day', 'birth_year', 'notes', 'password_hash'];

    public static function log(string $action, ?string $subjectType, ?int $subjectId, string $summary, ?array $details = null): void
    {
        $user = Auth::current();
        Db::insert('audit_log', [
            'user_id'      => $user['id'] ?? null,
            'actor'        => mb_substr($user ? $user['email'] : (PHP_SAPI === 'cli' ? 'system' : 'anonymous'), 0, 190),
            'action'       => mb_substr($action, 0, 64),
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'summary'      => mb_substr($summary, 0, 255),
            'details'      => $details === null ? null : Util::json($details),
            'ip'           => PHP_SAPI === 'cli' ? null : Http::ip(),
            'created_at'   => Time::nowDb(),
        ]);
    }

    /** Field-level diff of two rows, with sensitive values redacted. */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;
            if ((string) $old === (string) $value && ($old === null) === ($value === null)) {
                continue;
            }
            $changes[$field] = in_array($field, self::REDACT, true)
                ? ['changed' => true]
                : ['from' => $old, 'to' => $value];
        }
        return $changes;
    }

    public static function list(array $filters, int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['subject_type'])) {
            $where[] = 'subject_type = ?';
            $params[] = $filters['subject_type'];
        }
        if (!empty($filters['subject_id'])) {
            $where[] = 'subject_id = ?';
            $params[] = (int) $filters['subject_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(summary LIKE ? OR actor LIKE ? OR action LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']) . '%';
            array_push($params, $like, $like, $like);
        }
        $sql = 'SELECT id, actor, action, subject_type, subject_id, summary, details, created_at FROM audit_log WHERE '
            . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
        return array_map(static function ($r) {
            $r['details'] = Util::decode($r['details'], null);
            return $r;
        }, Db::all($sql, $params));
    }
}
