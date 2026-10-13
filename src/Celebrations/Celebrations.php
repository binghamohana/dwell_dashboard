<?php
declare(strict_types=1);

namespace Dwell\Celebrations;

use Dwell\Core\Access;
use Dwell\Core\AppError;
use Dwell\Core\Audit;
use Dwell\Core\Db;
use Dwell\Core\Settings;
use Dwell\Core\Time;
use Dwell\Core\Util;
use Dwell\Employees\Employees;
use Dwell\Org\Organization;

/**
 * Birthdays and work anniversaries. Events exist only for people who are Active and whose
 * dates are confirmed: a missing or "needs review" date never produces an event.
 */
final class Celebrations
{
    public const TYPES = ['birthday' => 'Birthday', 'anniversary' => 'Work anniversary'];

    /** Why a person gets no automatic celebrations (null = they do). */
    public static function exclusion(array $r): ?string
    {
        if ($r['status'] !== 'active') {
            return $r['status'] === 'leave' ? 'On leave' : 'Former employee';
        }
        if (!Settings::get('celebrate_contractors') && Employees::isContractor($r)) {
            return 'Contractor / vendor (not celebrated, see Settings)';
        }
        return null;
    }

    /**
     * Every celebration between two dates (inclusive), for everyone (no access filtering).
     * @param int[]|null $onlyIds limit to these employees
     * @return array<int, array{type: string, date: string, employee_id: int, years: ?int}>
     */
    public static function events(string $from, string $to, ?array $onlyIds = null): array
    {
        $only = $onlyIds === null ? null : array_flip($onlyIds);
        $rule = (string) Settings::get('feb29_rule');
        $out = [];
        foreach (Employees::rows() as $r) {
            if (($only !== null && !isset($only[$r['id']])) || self::exclusion($r) !== null) {
                continue;
            }
            if ($r['celebrate_birthday'] && $r['birthday_status'] === 'ok' && $r['birth_month'] && $r['birth_day']) {
                foreach (Rules::birthdayDates($r['birth_month'], $r['birth_day'], $from, $to, $rule) as $d) {
                    if ($r['hire_date'] && $d < $r['hire_date']) {
                        continue; // not yet on the team
                    }
                    $out[] = ['type' => 'birthday', 'date' => $d, 'employee_id' => $r['id'], 'years' => null];
                }
            }
            $start = Rules::serviceStart($r);
            if ($r['celebrate_anniversary'] && $r['hire_status'] === 'ok' && $start) {
                foreach (Rules::anniversaryDates($start, $from, $to, $rule) as $a) {
                    if ($r['hire_date'] && $a['date'] < $r['hire_date']) {
                        continue;
                    }
                    $out[] = ['type' => 'anniversary', 'date' => $a['date'], 'employee_id' => $r['id'], 'years' => $a['years']];
                }
            }
        }
        usort($out, static fn ($a, $b) => [$a['date'], $a['type'], $a['employee_id']] <=> [$b['date'], $b['type'], $b['employee_id']]);
        return $out;
    }

    public static function key(string $type, int $employeeId, string $date): string
    {
        return "$type:$employeeId:$date";
    }

    /** "Brisa's Birthday" / "Jane's 5-Year Work Anniversary" */
    public static function title(array $event, array $r): string
    {
        $name = Employees::greetingName($r);
        return $event['type'] === 'birthday'
            ? "{$name}’s Birthday"
            : $name . '’s ' . Rules::ordinalYears((int) $event['years']) . ' Work Anniversary';
    }

    /**
     * Events this user may see, with person details, owner, notification status and follow-up status.
     * Filters: type, brand_id, entity_id, property_id, department_id, owner (missing).
     */
    public static function forUser(array $user, string $from, string $to, array $f = []): array
    {
        Access::require($user, 'celebrations.view');
        if (!Time::isDate($from) || !Time::isDate($to) || $from > $to || Time::diffDays($from, $to) > 400) {
            throw new AppError('Please choose a valid date range (up to a year).');
        }
        $events = self::events($from, $to, Access::visibleIds($user));
        $rows = Employees::rows();
        $extra = Employees::extraProperties();
        $out = [];
        foreach ($events as $e) {
            $r = $rows[$e['employee_id']];
            if (!empty($f['type']) && $e['type'] !== $f['type']) {
                continue;
            }
            foreach (['entity_id', 'department_id'] as $k) {
                if (!empty($f[$k]) && $r[$k] !== (int) $f[$k]) {
                    continue 2;
                }
            }
            if (!empty($f['property_id']) && $r['property_id'] !== (int) $f['property_id'] && !in_array((int) $f['property_id'], $extra[$r['id']] ?? [], true)) {
                continue;
            }
            if (!empty($f['brand_id']) && Employees::brandId($r) !== (int) $f['brand_id']) {
                continue;
            }
            $owner = Employees::owner($r);
            if (!empty($f['owner']) && $f['owner'] === 'missing' && $owner['id'] !== null) {
                continue;
            }
            $prop = Organization::get('properties', $r['property_id']);
            $brand = Organization::get('brands', Employees::brandId($r));
            $out[] = [
                'key'      => self::key($e['type'], $r['id'], $e['date']),
                'type'     => $e['type'],
                'date'     => $e['date'],
                'years'    => $e['years'],
                'title'    => self::title($e, $r),
                'person'   => [
                    'id'        => $r['id'],
                    'name'      => Employees::displayName($r),
                    'initials'  => Util::initials($r['preferred_name'] ?: $r['first_name'], $r['last_name']),
                    'job_title' => $r['job_title'],
                    'photo'     => Employees::photoUrl($r),
                    'property'  => $prop['name'] ?? null,
                    'brand'     => $brand ? ['name' => $brand['name'], 'color' => $brand['color']] : null,
                ],
                'owner'    => [
                    'id'     => $owner['id'],
                    'name'   => $owner['id'] ? Employees::displayName($rows[$owner['id']]) : null,
                    'source' => $owner['source'],
                    'note'   => $owner['note'],
                ],
            ];
        }
        return self::attachStatus($out);
    }

    /** Add notification and follow-up status to a list of events. */
    private static function attachStatus(array $events): array
    {
        if (!$events) {
            return $events;
        }
        $ids = array_values(array_unique(array_map(static fn ($e) => $e['person']['id'], $events)));
        $dates = array_map(static fn ($e) => $e['date'], $events);
        $from = min($dates);
        $to = max($dates);
        $notes = [];
        foreach (Db::all(
            'SELECT ni.employee_id, ni.type, ni.date, n.id, n.kind, n.mode, n.status, n.sent_at, n.created_at, n.intended_name
             FROM notification_items ni JOIN notifications n ON n.id = ni.notification_id
             WHERE ni.employee_id IN (' . Db::in($ids) . ') AND ni.date BETWEEN ? AND ? ORDER BY n.id',
            array_merge($ids, [$from, $to])
        ) as $n) {
            $notes[self::key($n['type'], (int) $n['employee_id'], $n['date'])][] = [
                'id' => (int) $n['id'], 'kind' => $n['kind'], 'mode' => $n['mode'], 'status' => $n['status'],
                'sent_at' => $n['sent_at'], 'created_at' => $n['created_at'], 'to' => $n['intended_name'],
            ];
        }
        $status = [];
        foreach (Db::all(
            'SELECT employee_id, type, date, status, note, updated_at FROM celebration_status
             WHERE employee_id IN (' . Db::in($ids) . ') AND date BETWEEN ? AND ?',
            array_merge($ids, [$from, $to])
        ) as $s) {
            $status[self::key($s['type'], (int) $s['employee_id'], $s['date'])] = ['status' => $s['status'], 'note' => $s['note'], 'updated_at' => $s['updated_at']];
        }
        foreach ($events as &$e) {
            $e['notifications'] = $notes[$e['key']] ?? [];
            $e['follow_up'] = $status[$e['key']] ?? null;
        }
        return $events;
    }

    public static function setStatus(array $user, int $employeeId, string $type, string $date, ?string $status, ?string $note): void
    {
        Access::require($user, 'celebrations.status');
        if (!Access::canSee($user, $employeeId) || !isset(self::TYPES[$type]) || !Time::isDate($date)) {
            throw AppError::notFound();
        }
        $r = Employees::row($employeeId);
        // Only real celebrations can be marked.
        $real = array_filter(self::events($date, $date, [$employeeId]), static fn ($e) => $e['type'] === $type);
        if (!$r || !$real) {
            throw new AppError('There is no such celebration.');
        }
        if ($status === null || $status === '') {
            Db::run('DELETE FROM celebration_status WHERE employee_id = ? AND type = ? AND date = ?', [$employeeId, $type, $date]);
        } else {
            if (!in_array($status, ['planned', 'completed', 'skipped'], true)) {
                throw new AppError('Unknown status.');
            }
            Db::run(
                'INSERT INTO celebration_status (employee_id, type, date, status, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                [$employeeId, $type, $date, $status, Util::nullable(Util::cleanText($note ?? '', 255)), $user['id'], Time::nowDb()]
            );
        }
        Audit::log('celebration_status', 'employee', $employeeId, self::TYPES[$type] . " on $date marked " . ($status ?: 'not tracked') . ': ' . Employees::displayName($r));
    }

    /** Executive dashboard numbers, scoped to what the user may see. */
    public static function kpis(array $user): array
    {
        Access::require($user, 'celebrations.view');
        $today = Time::today();
        $visible = Access::visibleIds($user);
        $people = array_filter(Employees::rows(), static fn ($r) => $visible === null || in_array($r['id'], $visible, true));
        $active = array_filter($people, static fn ($r) => $r['status'] === 'active');
        $monthEvents = self::events(Time::monthStart($today), Time::monthEnd($today), $visible);
        $next30 = self::events($today, Time::addDays($today, 29), $visible);
        $next7 = array_filter($next30, static fn ($e) => $e['date'] <= Time::addDays($today, 6));
        $count = static fn (callable $fn) => count(array_filter($active, $fn));
        $rows = Employees::rows();
        $noOwnerSoon = count(array_filter($next30, static fn ($e) => Employees::owner($rows[$e['employee_id']])['id'] === null));

        return [
            'active'               => count($active),
            'birthdays_month'      => count(array_filter($monthEvents, static fn ($e) => $e['type'] === 'birthday')),
            'anniversaries_month'  => count(array_filter($monthEvents, static fn ($e) => $e['type'] === 'anniversary')),
            'next_7'               => count($next7),
            'next_30'              => count($next30),
            'birthday_missing'     => $count(static fn ($r) => $r['birthday_status'] === 'missing'),
            'birthday_review'      => $count(static fn ($r) => $r['birthday_status'] === 'needs_review'),
            'hire_missing'         => $count(static fn ($r) => $r['hire_status'] !== 'ok'),
            'no_owner'             => $count(static fn ($r) => Employees::owner($r)['id'] === null),
            'no_owner_next_30'     => $noOwnerSoon,
            'no_supervisor'        => $count(static fn ($r) => !$r['supervisor_id']),
            'excluded'             => $count(static fn ($r) => self::exclusion($r) !== null),
            'month'                => Time::monthName($today),
        ];
    }
}
