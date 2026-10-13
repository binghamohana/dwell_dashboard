<?php
declare(strict_types=1);

namespace Dwell\Employees;

use Dwell\Celebrations\Rules;
use Dwell\Core\Access;
use Dwell\Core\AppError;
use Dwell\Core\Audit;
use Dwell\Core\Db;
use Dwell\Core\Settings;
use Dwell\Core\Time;
use Dwell\Core\Util;
use Dwell\Org\Organization;

/**
 * Employee records: reading (always through Access, so people only see who they may see,
 * and only the fields their role allows) and maintenance by system administrators.
 */
final class Employees
{
    public const CLASSIFICATIONS = [
        'full_time'  => 'Full time',
        'part_time'  => 'Part time',
        'contractor' => 'Contractor / vendor',
        'temporary'  => 'Temporary',
        'other'      => 'Other',
    ];
    public const STATUSES = ['active' => 'Active', 'leave' => 'On leave', 'terminated' => 'Former employee'];

    private static ?array $rows = null;
    private static ?array $extraProps = null;

    public static function flush(): void
    {
        self::$rows = null;
        self::$extraProps = null;
        Access::flush();
    }

    /** Every employee row keyed by id (cached for the request). */
    public static function rows(): array
    {
        if (self::$rows === null) {
            self::$rows = [];
            foreach (Db::all('SELECT * FROM employees ORDER BY last_name, first_name, id') as $r) {
                foreach (['id', 'department_id', 'entity_id', 'brand_id', 'property_id', 'supervisor_id', 'dotted_line_id',
                    'celebration_owner_id', 'birth_month', 'birth_day', 'birth_year', 'verified_by'] as $k) {
                    $r[$k] = $r[$k] === null ? null : (int) $r[$k];
                }
                $r['celebrate_birthday'] = (bool) $r['celebrate_birthday'];
                $r['celebrate_anniversary'] = (bool) $r['celebrate_anniversary'];
                self::$rows[$r['id']] = $r;
            }
        }
        return self::$rows;
    }

    public static function row(int $id): ?array
    {
        return self::rows()[$id] ?? null;
    }

    /** employee id => [property ids] for additional assignments. */
    public static function extraProperties(): array
    {
        if (self::$extraProps === null) {
            self::$extraProps = [];
            foreach (Db::all('SELECT employee_id, property_id FROM employee_properties') as $r) {
                self::$extraProps[(int) $r['employee_id']][] = (int) $r['property_id'];
            }
        }
        return self::$extraProps;
    }

    public static function displayName(array $r): string
    {
        return trim(($r['preferred_name'] ?: $r['first_name']) . ' ' . $r['last_name']);
    }

    public static function legalName(array $r): string
    {
        return trim($r['first_name'] . ' ' . $r['last_name']);
    }

    public static function greetingName(array $r): string
    {
        return $r['preferred_name'] ?: $r['first_name'];
    }

    /** Brand shown for a person: their own, else their entity's, else their property's. */
    public static function brandId(array $r): ?int
    {
        if ($r['brand_id']) {
            return $r['brand_id'];
        }
        $entity = Organization::get('entities', $r['entity_id']);
        if ($entity && $entity['brand_id']) {
            return $entity['brand_id'];
        }
        $prop = Organization::get('properties', $r['property_id']);
        return $prop['brand_id'] ?? null;
    }

    public static function isContractor(array $r): bool
    {
        if ($r['classification'] === 'contractor') {
            return true;
        }
        $entity = Organization::get('entities', $r['entity_id']);
        return $entity !== null && $entity['kind'] === 'contractor';
    }

    public static function owner(array $r): array
    {
        return Owners::resolve($r, self::rows(), Organization::byId('properties'));
    }

    /** Data-quality flags for one record. */
    public static function issues(array $r): array
    {
        $issues = [];
        if ($r['status'] === 'terminated') {
            return $issues;
        }
        if ($r['birthday_status'] === 'missing') {
            $issues[] = 'birthday_missing';
        } elseif ($r['birthday_status'] === 'needs_review') {
            $issues[] = 'birthday_review';
        }
        if ($r['hire_status'] !== 'ok') {
            $issues[] = $r['hire_status'] === 'missing' ? 'hire_missing' : 'hire_review';
        }
        if (!$r['supervisor_id']) {
            $issues[] = 'no_supervisor';
        }
        if (!$r['work_email']) {
            $issues[] = 'no_email';
        }
        if (!$r['property_id']) {
            $issues[] = 'no_property';
        }
        if ($r['status'] === 'active' && self::owner($r)['id'] === null) {
            $issues[] = 'no_owner';
        }
        if (!$r['verified_at']) {
            $issues[] = 'unverified';
        }
        return $issues;
    }

    public const ISSUE_LABELS = [
        'birthday_missing' => 'No birthday',
        'birthday_review'  => 'Birthday needs review',
        'hire_missing'     => 'No hire date',
        'hire_review'      => 'Hire date needs review',
        'no_supervisor'    => 'No supervisor',
        'no_email'         => 'No work email',
        'no_property'      => 'No property / office',
        'no_owner'         => 'No celebration owner',
        'unverified'       => 'Not yet verified',
    ];

    public static function photoUrl(array $r): ?string
    {
        return $r['photo'] ? 'photo.php?id=' . $r['id'] . '&v=' . substr($r['photo'], 0, 8) : null;
    }

    // ---------------------------------------------------------------- reading

    /** The shape of a person as this user may see it. */
    public static function view(array $r, array $user, bool $detail = false): array
    {
        $rows = self::rows();
        $orgName = static function (string $table, ?int $id) {
            $o = Organization::get($table, $id);
            return $o ? ['id' => $o['id'], 'name' => $o['name']] : null;
        };
        $person = static fn (?int $id) => ($id && isset($rows[$id])) ? ['id' => $id, 'name' => self::displayName($rows[$id])] : null;
        $brand = Organization::get('brands', self::brandId($r));
        $prop = Organization::get('properties', $r['property_id']);

        $v = [
            'id'             => $r['id'],
            'code'           => $r['code'],
            'name'           => self::displayName($r),
            'first_name'     => $r['first_name'],
            'last_name'      => $r['last_name'],
            'preferred_name' => $r['preferred_name'],
            'initials'       => Util::initials($r['preferred_name'] ?: $r['first_name'], $r['last_name']),
            'job_title'      => $r['job_title'],
            'work_email'     => $r['work_email'],
            'department'     => $orgName('departments', $r['department_id']),
            'property'       => $prop ? ['id' => $prop['id'], 'name' => $prop['name'], 'kind' => $prop['kind']] : null,
            'entity'         => $orgName('entities', $r['entity_id']),
            'brand'          => $brand ? ['id' => $brand['id'], 'name' => $brand['name'], 'color' => $brand['color']] : null,
            'supervisor'     => $person($r['supervisor_id']),
            'dotted_line'    => $person($r['dotted_line_id']),
            'photo'          => self::photoUrl($r),
            'status'         => $r['status'],
        ];
        if (Access::can($user, 'people.dates')) {
            $today = Time::today();
            $start = Rules::serviceStart($r);
            $v += [
                'classification' => $r['classification'],
                'birthday'       => [
                    'month'  => $r['birthday_status'] === 'missing' ? null : $r['birth_month'],
                    'day'    => $r['birthday_status'] === 'missing' ? null : $r['birth_day'],
                    'label'  => $r['birthday_status'] === 'missing' ? null : Time::fmtMonthDay($r['birth_month'], $r['birth_day']),
                    'status' => $r['birthday_status'],
                ],
                'hire_date'          => $r['hire_date'],
                'original_hire_date' => $r['original_hire_date'],
                'hire_status'        => $r['hire_status'],
                'service_years'      => ($start && $r['hire_status'] === 'ok' && $r['status'] !== 'terminated') ? Rules::serviceYears($start, $today) : null,
                'celebrate_birthday' => $r['celebrate_birthday'],
                'celebrate_anniversary' => $r['celebrate_anniversary'],
                'is_contractor'      => self::isContractor($r),
            ];
            $owner = self::owner($r);
            $v['owner'] = [
                'id'     => $owner['id'],
                'name'   => $owner['id'] ? self::displayName($rows[$owner['id']]) : null,
                'source' => $owner['source'],
                'label'  => $owner['source'] ? Owners::SOURCES[$owner['source']] : null,
                'note'   => $owner['note'],
            ];
            $v['issues'] = self::issues($r);
        }
        if (Access::can($user, 'people.all')) {
            $v['term_date'] = $r['term_date'];
        }
        if (Access::can($user, 'people.private')) {
            $v += [
                'hr_id'                => $r['hr_id'],
                'notes'                => $r['notes'],
                'birth_year'           => $r['birth_year'],
                'verified_at'          => $r['verified_at'],
                'source'               => $r['source'],
                'brand_id'             => $r['brand_id'],
                'celebration_owner_id' => $r['celebration_owner_id'],
                'updated_at'           => $r['updated_at'],
            ];
        }
        if ($detail) {
            $visible = Access::visibleIds($user);
            $canSee = static fn ($id) => $visible === null || in_array($id, $visible, true);
            $v['additional_properties'] = array_values(array_filter(array_map(
                static fn ($pid) => $orgName('properties', $pid),
                self::extraProperties()[$r['id']] ?? []
            )));
            $v['reports'] = [];
            foreach ($rows as $o) {
                if ($o['supervisor_id'] === $r['id'] && $o['status'] !== 'terminated' && $canSee($o['id'])) {
                    $v['reports'][] = ['id' => $o['id'], 'name' => self::displayName($o), 'job_title' => $o['job_title']];
                }
            }
            $v['dotted_reports'] = [];
            foreach ($rows as $o) {
                if ($o['dotted_line_id'] === $r['id'] && $o['status'] !== 'terminated' && $canSee($o['id'])) {
                    $v['dotted_reports'][] = ['id' => $o['id'], 'name' => self::displayName($o), 'job_title' => $o['job_title']];
                }
            }
            if (Access::can($user, 'people.dates')) {
                $today = Time::today();
                $v['upcoming'] = array_map(static fn ($e) => ['type' => $e['type'], 'date' => $e['date'], 'years' => $e['years']],
                    array_slice(\Dwell\Celebrations\Celebrations::events($today, Time::addDays($today, 365), [$r['id']]), 0, 4));
                $v['excluded'] = \Dwell\Celebrations\Celebrations::exclusion($r);
            }
            // Hide names of people outside this user's view (e.g. a manager's own supervisor).
            foreach (['supervisor', 'dotted_line'] as $k) {
                if ($v[$k] && !$canSee($v[$k]['id'])) {
                    $v[$k]['id'] = null;
                }
            }
        }
        return $v;
    }

    /**
     * People this user may see.
     * Filters: q, status (active|leave|terminated|current|all), brand_id, entity_id, property_id,
     * department_id, issue.
     */
    public static function list(array $user, array $f = []): array
    {
        Access::require($user, 'people.view');
        $visible = Access::visibleIds($user);
        $status = (string) ($f['status'] ?? 'current');
        if (!Access::can($user, 'people.all') && !in_array($status, ['active', 'leave', 'current'], true)) {
            $status = 'current';
        }
        $q = Util::nameKey((string) ($f['q'] ?? ''));
        $out = [];
        foreach (self::rows() as $r) {
            if ($visible !== null && !in_array($r['id'], $visible, true)) {
                continue;
            }
            if ($status === 'current' ? $r['status'] === 'terminated' : ($status !== 'all' && $r['status'] !== $status)) {
                continue;
            }
            foreach (['entity_id', 'department_id'] as $k) {
                if (!empty($f[$k]) && $r[$k] !== (int) $f[$k]) {
                    continue 2;
                }
            }
            if (!empty($f['property_id'])) {
                $pid = (int) $f['property_id'];
                if ($r['property_id'] !== $pid && !in_array($pid, self::extraProperties()[$r['id']] ?? [], true)) {
                    continue;
                }
            }
            if (!empty($f['brand_id']) && self::brandId($r) !== (int) $f['brand_id']) {
                continue;
            }
            if ($q !== '') {
                $hay = Util::nameKey(self::legalName($r) . ' ' . ($r['preferred_name'] ?? '') . ' ' . ($r['job_title'] ?? '') . ' ' . ($r['work_email'] ?? '') . ' ' . $r['code']);
                foreach (explode(' ', $q) as $word) {
                    if (!str_contains($hay, $word)) {
                        continue 2;
                    }
                }
            }
            if (!empty($f['issue']) && !in_array($f['issue'], self::issues($r), true)) {
                continue;
            }
            $out[] = self::view($r, $user);
        }
        return $out;
    }

    public static function get(array $user, int $id): array
    {
        Access::require($user, 'people.view');
        $r = self::row($id);
        if (!$r || !Access::canSee($user, $id) || ($r['status'] === 'terminated' && !Access::can($user, 'people.all'))) {
            throw AppError::notFound();
        }
        return self::view($r, $user, true);
    }

    /** Minimal list for pickers (supervisor, owner, property manager). Admin only. */
    public static function options(): array
    {
        $out = [];
        foreach (self::rows() as $r) {
            if ($r['status'] === 'terminated') {
                continue;
            }
            $out[] = ['id' => $r['id'], 'name' => self::displayName($r), 'job_title' => $r['job_title'], 'has_email' => (bool) $r['work_email']];
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    // ---------------------------------------------------------------- maintenance (admins)

    private const FIELDS = ['hr_id', 'first_name', 'last_name', 'preferred_name', 'work_email', 'job_title', 'department_id', 'entity_id',
        'brand_id', 'property_id', 'supervisor_id', 'dotted_line_id', 'celebration_owner_id', 'classification', 'status', 'hire_date',
        'original_hire_date', 'term_date', 'birth_month', 'birth_day', 'birth_year', 'birthday_status', 'celebrate_birthday',
        'celebrate_anniversary', 'notes'];

    /**
     * Validate input into a clean column => value array. Only keys present in $in are touched
     * (on update); new records get every field.
     */
    public static function clean(array $in, ?array $before): array
    {
        $d = [];
        $has = static fn ($k) => array_key_exists($k, $in) || $before === null;
        $text = static fn ($k, $max) => Util::nullable(Util::cleanText($in[$k] ?? '', $max));

        if ($has('first_name')) {
            $d['first_name'] = Util::cleanText($in['first_name'] ?? '', 80);
            if ($d['first_name'] === '') {
                throw new AppError('Please enter a first name.', 400, 'first_name');
            }
        }
        if ($has('last_name')) {
            $d['last_name'] = Util::cleanText($in['last_name'] ?? '', 120);
        }
        if ($has('preferred_name')) {
            $d['preferred_name'] = $text('preferred_name', 80);
        }
        if ($has('job_title')) {
            $d['job_title'] = $text('job_title', 160);
        }
        if ($has('hr_id')) {
            $d['hr_id'] = $text('hr_id', 64);
            if ($d['hr_id'] && Db::value('SELECT id FROM employees WHERE hr_id = ? AND id <> ?', [$d['hr_id'], $before['id'] ?? 0])) {
                throw new AppError('Another person already has that HR ID.', 400, 'hr_id');
            }
        }
        if ($has('work_email')) {
            $d['work_email'] = Util::normalizeEmail($in['work_email'] ?? null);
            if ($d['work_email'] !== null) {
                if (!Util::isValidEmail($d['work_email'])) {
                    throw new AppError('Please enter a valid work email.', 400, 'work_email');
                }
                if (Db::value('SELECT id FROM employees WHERE work_email = ? AND id <> ?', [$d['work_email'], $before['id'] ?? 0])) {
                    throw new AppError('Another person already has that work email.', 400, 'work_email');
                }
            }
        }
        foreach (['department_id' => 'departments', 'entity_id' => 'entities', 'brand_id' => 'brands', 'property_id' => 'properties'] as $k => $table) {
            if ($has($k)) {
                $d[$k] = Util::intOrNull($in[$k] ?? null);
                if ($d[$k] && !Organization::get($table, $d[$k])) {
                    throw new AppError('Please pick from the list.', 400, $k);
                }
            }
        }
        foreach (['supervisor_id', 'dotted_line_id', 'celebration_owner_id'] as $k) {
            if ($has($k)) {
                $d[$k] = Util::intOrNull($in[$k] ?? null);
                if ($d[$k] && !self::row($d[$k])) {
                    throw new AppError('Please pick a person from the list.', 400, $k);
                }
                if ($d[$k] && $before && $d[$k] === (int) $before['id']) {
                    throw new AppError('A person can’t be their own ' . ($k === 'supervisor_id' ? 'supervisor' : ($k === 'dotted_line_id' ? 'dotted-line manager' : 'celebration owner')) . '.', 400, $k);
                }
            }
        }
        if (!empty($d['supervisor_id']) && $before) {
            // No loops: walking up from the new supervisor must never reach this person.
            $cur = $d['supervisor_id'];
            for ($i = 0; $cur && $i < 100; $i++) {
                if ($cur === (int) $before['id']) {
                    throw new AppError('That would create a loop: this person is already above them in the reporting chain.', 400, 'supervisor_id');
                }
                $cur = self::row($cur)['supervisor_id'] ?? null;
            }
        }
        if ($has('classification')) {
            $c = (string) ($in['classification'] ?? '');
            $d['classification'] = isset(self::CLASSIFICATIONS[$c]) ? $c : null;
        }
        if ($has('status')) {
            $s = (string) ($in['status'] ?? 'active');
            if (!isset(self::STATUSES[$s])) {
                throw new AppError('Unknown status.', 400, 'status');
            }
            $d['status'] = $s;
        }
        foreach (['hire_date', 'original_hire_date', 'term_date'] as $k) {
            if ($has($k)) {
                $v = Util::nullable($in[$k] ?? null);
                if ($v !== null && !Time::isDate($v)) {
                    throw new AppError('Please enter a valid date.', 400, $k);
                }
                $d[$k] = $v;
            }
        }
        $hire = array_key_exists('hire_date', $d) ? $d['hire_date'] : ($before['hire_date'] ?? null);
        $orig = array_key_exists('original_hire_date', $d) ? $d['original_hire_date'] : ($before['original_hire_date'] ?? null);
        if ($hire && $orig && $orig > $hire) {
            throw new AppError('The original hire date must be before the most recent hire date.', 400, 'original_hire_date');
        }
        if (array_key_exists('hire_date', $d)) {
            $d['hire_status'] = $d['hire_date'] ? (($in['hire_status'] ?? 'ok') === 'needs_review' ? 'needs_review' : 'ok') : 'missing';
        } elseif (array_key_exists('hire_status', $in) && $before && $before['hire_date']) {
            $d['hire_status'] = $in['hire_status'] === 'needs_review' ? 'needs_review' : 'ok';
        }
        $status = $d['status'] ?? ($before['status'] ?? 'active');
        if (array_key_exists('status', $d) && $status !== 'terminated' && ($before['status'] ?? null) === 'terminated' && !array_key_exists('term_date', $in)) {
            $d['term_date'] = null; // back on the team (rehire): the old term date no longer applies
        }
        $term = array_key_exists('term_date', $d) ? $d['term_date'] : ($before['term_date'] ?? null);
        if ($status === 'terminated' && !$term && array_key_exists('status', $d)) {
            $d['term_date'] = $term = Time::today();
        }
        if ($status === 'terminated' && $term && $hire && $term < $hire) {
            throw new AppError('The term date is before the hire date.', 400, 'term_date');
        }

        // Birthday: month + day (and year only when allowed). Never stored partially.
        if ($has('birth_month') || $has('birth_day')) {
            $m = Util::intOrNull($in['birth_month'] ?? null);
            $day = Util::intOrNull($in['birth_day'] ?? null);
            if (($m === null) !== ($day === null)) {
                throw new AppError('Enter both the month and the day, or neither.', 400, $m === null ? 'birth_month' : 'birth_day');
            }
            if ($m !== null && !Rules::validMonthDay($m, $day)) {
                throw new AppError('That isn’t a real date.', 400, 'birth_day');
            }
            $d['birth_month'] = $m;
            $d['birth_day'] = $day;
            $status = (string) ($in['birthday_status'] ?? 'ok');
            $d['birthday_status'] = $m === null ? 'missing' : ($status === 'needs_review' ? 'needs_review' : 'ok');
        } elseif (array_key_exists('birthday_status', $in) && $before && $before['birth_month']) {
            $d['birthday_status'] = $in['birthday_status'] === 'needs_review' ? 'needs_review' : 'ok';
        }
        if ($has('birth_year')) {
            $y = Util::intOrNull($in['birth_year'] ?? null);
            if ($y !== null && !Settings::get('store_birth_year')) {
                $y = null; // not authorized to keep it
            }
            if ($y !== null) {
                $thisYear = (int) substr(Time::today(), 0, 4);
                if ($y < 1900 || $y > $thisYear - 14) {
                    throw new AppError('That birth year doesn’t look right.', 400, 'birth_year');
                }
                $bm = $d['birth_month'] ?? ($before['birth_month'] ?? null);
                $bd = $d['birth_day'] ?? ($before['birth_day'] ?? null);
                if ($bm === 2 && $bd === 29 && !Time::isLeapYear($y)) {
                    throw new AppError('February 29 doesn’t exist in that year.', 400, 'birth_year');
                }
            }
            $d['birth_year'] = $y;
        }
        foreach (['celebrate_birthday', 'celebrate_anniversary'] as $k) {
            if ($has($k)) {
                $d[$k] = filter_var($in[$k] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if ($has('notes')) {
            $d['notes'] = Util::nullable(Util::cleanText($in['notes'] ?? '', 4000, true));
        }
        return $d;
    }

    public static function nextCode(): string
    {
        return Db::transaction(static function () {
            Db::run("INSERT IGNORE INTO settings (name, value) VALUES ('next_employee_number', '1')");
            $n = (int) Db::value("SELECT value FROM settings WHERE name = 'next_employee_number' FOR UPDATE");
            $n = max(1, $n);
            Db::run("UPDATE settings SET value = ? WHERE name = 'next_employee_number'", [(string) ($n + 1)]);
            Settings::flush();
            return sprintf('DC-%05d', $n);
        });
    }

    /** Create or update a person. Returns the id. */
    public static function save(array $in, string $source = 'manual'): int
    {
        $id = Util::intOrNull($in['id'] ?? null);
        $before = $id ? self::row($id) : null;
        if ($id && !$before) {
            throw AppError::notFound();
        }
        $d = self::clean($in, $before);
        $d['updated_at'] = Time::nowDb();
        $extra = null;
        if (array_key_exists('additional_property_ids', $in)) {
            $extra = array_values(array_unique(array_filter(array_map([Util::class, 'intOrNull'], (array) $in['additional_property_ids']))));
            $primary = $d['property_id'] ?? ($before['property_id'] ?? null);
            $extra = array_values(array_filter($extra, static fn ($p) => $p !== $primary && Organization::get('properties', $p)));
        }

        $code = $before ? null : self::nextCode();
        Db::transaction(static function () use (&$id, $before, $d, $extra, $source, $in, $code) {
            if ($before) {
                Db::update('employees', $d, 'id = :id', ['id' => $id]);
                $changes = Audit::diff($before, $d);
                unset($changes['updated_at']);
                if ($changes) {
                    Audit::log('person_update', 'employee', $id, 'Updated ' . self::displayName(array_merge($before, $d)), $changes);
                }
            } else {
                $d['code'] = $code;
                $d['source'] = $source;
                $d['created_at'] = Time::nowDb();
                $d['hire_status'] = $d['hire_status'] ?? 'missing';
                $id = Db::insert('employees', $d);
                Audit::log('person_create', 'employee', $id, 'Added ' . trim($d['first_name'] . ' ' . $d['last_name']) . ' (' . $d['code'] . ')');
            }
            if ($extra !== null) {
                Db::run('DELETE FROM employee_properties WHERE employee_id = ?', [$id]);
                foreach ($extra as $pid) {
                    Db::run('INSERT INTO employee_properties (employee_id, property_id) VALUES (?, ?)', [$id, $pid]);
                }
            }
            if (array_key_exists('verified', $in)) {
                self::setVerified((int) $id, filter_var($in['verified'], FILTER_VALIDATE_BOOLEAN), false);
            }
        });
        self::flush();
        Organization::flush();
        return (int) $id;
    }

    public static function setVerified(int $id, bool $verified, bool $log = true): void
    {
        $user = \Dwell\Core\Auth::current();
        Db::update('employees', [
            'verified_at' => $verified ? Time::nowDb() : null,
            'verified_by' => $verified ? ($user['id'] ?? null) : null,
        ], 'id = :id', ['id' => $id]);
        if ($log) {
            $r = self::row($id);
            Audit::log($verified ? 'person_verified' : 'person_unverified', 'employee', $id, ($verified ? 'Marked verified: ' : 'Verification removed: ') . ($r ? self::displayName($r) : "#$id"));
        }
        self::flush();
    }

    /** Remove a record entirely. Only for records created by mistake (no history to keep). */
    public static function delete(int $id): void
    {
        $r = self::row($id);
        if (!$r) {
            throw AppError::notFound();
        }
        $refs = (int) Db::value('SELECT COUNT(*) FROM employees WHERE supervisor_id = ? OR dotted_line_id = ? OR celebration_owner_id = ?', [$id, $id, $id])
            + (int) Db::value('SELECT COUNT(*) FROM notification_items WHERE employee_id = ?', [$id]);
        if ($refs) {
            throw new AppError('Other records point to this person (reports, owners or sent notifications). Mark them as a former employee instead.');
        }
        Db::run('UPDATE properties SET manager_id = NULL WHERE manager_id = ?', [$id]);
        Db::run('UPDATE users SET employee_id = NULL WHERE employee_id = ?', [$id]);
        Photos::remove($id);
        Db::run('DELETE FROM employees WHERE id = ?', [$id]);
        Audit::log('person_delete', 'employee', $id, 'Deleted ' . self::displayName($r) . ' (' . $r['code'] . ')');
        self::flush();
        Organization::flush();
    }
}
