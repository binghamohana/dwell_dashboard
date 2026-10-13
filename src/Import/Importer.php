<?php
declare(strict_types=1);

namespace Dwell\Import;

use Dwell\Celebrations\Rules;
use Dwell\Core\AppError;
use Dwell\Core\Audit;
use Dwell\Core\Db;
use Dwell\Core\Settings;
use Dwell\Core\Time;
use Dwell\Core\Util;
use Dwell\Employees\Employees;
use Dwell\Org\Organization;

/**
 * Spreadsheet import with preview and explicit approval.
 *
 *  1. upload():  the file is read, then deleted; its rows are held in the database only until the
 *                import is applied or discarded.
 *  2. preview(): every row is matched (HR ID → work email → name) and compared with what is saved.
 *                Nothing is written. Unknown companies/divisions must be matched to records or
 *                created; duplicates and ambiguous matches need a decision; records marked
 *                "verified" are protected: their changes are skipped unless explicitly approved.
 *  3. apply():   only the plan the administrator saw (checked by a hash) is applied, in one
 *                transaction, with every change in the audit log.
 *
 * Blank cells never erase saved data, impossible dates are flagged "needs review" instead of
 * being used, and former employees are skipped unless they are already in Dwell Central.
 */
final class Importer
{
    public const FIELDS = [
        'name'               => ['Full name', ['employee name', 'name', 'full name', 'employee', 'legal name', 'employee full name']],
        'first_name'         => ['First name', ['first name', 'first', 'given name', 'legal first name']],
        'last_name'          => ['Last name', ['last name', 'last', 'surname', 'family name', 'legal last name']],
        'preferred_name'     => ['Preferred name', ['preferred name', 'preferred first name', 'nickname', 'goes by']],
        'hr_id'              => ['Employee ID', ['employee id', 'employee number', 'employee no', 'emp id', 'hr id', 'file number', 'associate id', 'id']],
        'work_email'         => ['Work email', ['work email', 'email', 'email address', 'business email', 'company email']],
        'job_title'          => ['Job title', ['job title', 'title', 'position', 'position title']],
        'department'         => ['Department', ['department', 'dept', 'department name']],
        'status'             => ['Status', ['employee status', 'status', 'employment status']],
        'term_date'          => ['Term date', ['term date', 'termination date', 'separation date', 'end date']],
        'company'            => ['Company / legal entity', ['company name', 'company', 'entity', 'legal entity', 'employer']],
        'division'           => ['Property / division', ['division', 'property', 'location', 'site', 'community', 'property name']],
        'hire_date'          => ['Hire date', ['client hire date', 'hire date', 'most recent hire date', 'rehire date', 'start date', 'date of hire']],
        'original_hire_date' => ['Original hire date', ['original hire date', 'first hire date', 'seniority date']],
        'classification'     => ['Classification', ['classification', 'employment type', 'worker type', 'employee type']],
        'birth'              => ['Birthday', ['birth', 'birth date', 'birthday', 'date of birth', 'dob']],
        'supervisor'         => ['Supervisor', ['supervisor', 'manager', 'reports to', 'supervisor name', 'manager name', 'supervisor email', 'manager email', 'direct supervisor']],
    ];

    private const LABELS = [
        'first_name' => 'First name', 'last_name' => 'Last name', 'preferred_name' => 'Preferred name', 'hr_id' => 'Employee ID',
        'work_email' => 'Work email', 'job_title' => 'Job title', 'department_id' => 'Department', 'entity_id' => 'Legal entity',
        'property_id' => 'Property', 'status' => 'Status', 'term_date' => 'Term date', 'hire_date' => 'Hire date',
        'original_hire_date' => 'Original hire date', 'classification' => 'Classification', 'birthday' => 'Birthday',
        'birth_year' => 'Birth year', 'supervisor_id' => 'Supervisor', 'hire_status' => 'Hire date check',
    ];

    private const VALUE_TABLES = ['entity' => 'entities', 'property' => 'properties', 'department' => 'departments'];

    // ---------------------------------------------------------------- 1. upload

    public static function upload(array $user, array $file): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new AppError(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is larger than the server allows.' : 'The upload didn’t arrive. Please try again.');
        }
        $tmp = (string) $file['tmp_name'];
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw new AppError('The upload didn’t arrive. Please try again.');
        }
        $name = Util::cleanText(basename((string) ($file['name'] ?? 'import.xlsx')), 190);
        try {
            $book = SpreadsheetReader::read($tmp, $name);
            $hash = hash_file('sha256', $tmp);
        } finally {
            @unlink($tmp); // the file itself is never kept
        }
        $sheets = [];
        $first = null;
        foreach ($book['sheets'] as $sheetName => $rows) {
            $h = self::detectHeader($rows);
            $sheets[] = ['name' => (string) $sheetName, 'header_row' => $h === null ? null : $h + 1, 'rows' => $h === null ? 0 : count(array_filter(array_slice($rows, $h + 1), [self::class, 'hasData']))];
            if ($first === null && $h !== null) {
                $first = (string) $sheetName;
            }
        }
        if ($first === null) {
            throw new AppError('No sheet in that file has a header row with an employee name column (for example “Employee Name” or “First Name” + “Last Name”).');
        }
        $id = Db::insert('imports', [
            'user_id'    => $user['id'],
            'file_name'  => $name,
            'file_hash'  => $hash,
            'sheets'     => Util::json($sheets),
            'sheet'      => $first,
            'rows_json'  => Util::json(['date1904' => $book['date1904'], 'sheets' => $book['sheets']]),
            'decisions'  => Util::json(['sheet' => $first]),
            'status'     => 'preview',
            'created_at' => Time::nowDb(),
        ]);
        Audit::log('import_upload', 'import', $id, "Uploaded $name for import (preview only)");
        return $id;
    }

    public static function hasData(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function headerKey(mixed $v): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $v)) ?? '');
    }

    /** Index of the header row (the row with the most recognizable column names). */
    public static function detectHeader(array $rows): ?int
    {
        $best = null;
        $bestScore = 1;
        foreach (array_slice($rows, 0, 15, true) as $i => $row) {
            $map = self::mapColumns($row);
            if (!isset($map['name']) && !isset($map['first_name'])) {
                continue;
            }
            if (count($map) > $bestScore) {
                $best = $i;
                $bestScore = count($map);
            }
        }
        return $best;
    }

    /** field => column index for a header row. */
    public static function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $i => $cell) {
            $k = self::headerKey($cell);
            if ($k === '') {
                continue;
            }
            foreach (self::FIELDS as $field => [, $synonyms]) {
                if (!isset($map[$field]) && in_array($k, $synonyms, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        if (isset($map['first_name'], $map['last_name'])) {
            unset($map['name']);
        }
        return $map;
    }

    // ---------------------------------------------------------------- 2. preview

    private static function load(int $id): array
    {
        $imp = Db::one('SELECT * FROM imports WHERE id = ?', [$id]);
        if (!$imp) {
            throw AppError::notFound();
        }
        return $imp;
    }

    /** Build the plan for an import with the given decisions. Nothing is written except the decisions. */
    public static function preview(int $id, ?array $decisions = null): array
    {
        $imp = self::load($id);
        if ($imp['status'] !== 'preview' || $imp['rows_json'] === null) {
            throw new AppError('This import has already been ' . ($imp['status'] === 'applied' ? 'applied' : 'closed') . '.');
        }
        $decisions = $decisions ?? Util::decode($imp['decisions'], []);
        $plan = self::plan($imp, $decisions);
        Db::update('imports', ['decisions' => Util::json($decisions), 'sheet' => $plan['sheet'], 'summary' => Util::json($plan['summary'])], 'id = :id', ['id' => $id]);
        return $plan;
    }

    /**
     * The full plan. Rows: create | update | unchanged | protected | skip | error | duplicate | ambiguous.
     */
    public static function plan(array $imp, array $decisions): array
    {
        $book = Util::decode($imp['rows_json'], []);
        $sheets = Util::decode($imp['sheets'], []);
        $sheet = (string) ($decisions['sheet'] ?? $imp['sheet']);
        if (!isset($book['sheets'][$sheet])) {
            $sheet = (string) $imp['sheet'];
        }
        $rows = $book['sheets'][$sheet] ?? [];
        $date1904 = !empty($book['date1904']);
        $headerIndex = self::detectHeader($rows);
        if ($headerIndex === null) {
            throw new AppError('No header row with an employee name column was found on “' . $sheet . '”. Choose another sheet.');
        }
        $header = $rows[$headerIndex];
        $columns = self::mapColumns($header);
        foreach ((array) ($decisions['columns'] ?? []) as $field => $col) {
            if (isset(self::FIELDS[$field])) {
                if ($col === '' || $col === null) {
                    unset($columns[$field]);
                } elseif (isset($header[(int) $col])) {
                    $columns[$field] = (int) $col;
                }
            }
        }
        $includeTerminated = !empty($decisions['include_terminated']);
        $rowDecisions = (array) ($decisions['rows'] ?? []);
        $storeYear = (bool) Settings::get('store_birth_year');
        $today = Time::today();

        // ---- saved people, for matching
        $byKey = [];
        $byEmail = [];
        $byHr = [];
        foreach (Employees::rows() as $r) {
            foreach (array_unique([Util::nameKey($r['first_name'] . ' ' . $r['last_name']), $r['preferred_name'] ? Util::nameKey($r['preferred_name'] . ' ' . $r['last_name']) : '']) as $k) {
                if ($k !== '') {
                    $byKey[$k][] = $r['id'];
                }
            }
            if ($r['work_email']) {
                $byEmail[$r['work_email']] = $r['id'];
            }
            if ($r['hr_id']) {
                $byHr[$r['hr_id']] = $r['id'];
            }
        }

        // ---- pass 1: read and normalize every row
        $parsed = [];
        $values = ['entity' => [], 'property' => [], 'department' => []];
        foreach ($rows as $i => $row) {
            if ($i <= $headerIndex || !self::hasData($row)) {
                continue;
            }
            $cell = static fn ($f) => isset($columns[$f]) ? ($row[$columns[$f]] ?? null) : null;
            $p = self::normalizeRow($cell, $columns, $date1904, $storeYear, $today);
            $p['row'] = $i + 1;
            // Only values on rows that will be imported need a decision (not skipped former employees).
            $known = (!empty($p['data']['hr_id']) && isset($byHr[$p['data']['hr_id']]))
                || (!empty($p['data']['work_email']) && isset($byEmail[$p['data']['work_email']]))
                || isset($byKey[$p['key']]) || ($p['alt_key'] && isset($byKey[$p['alt_key']]));
            $relevant = !$p['error'] && ($p['data']['status'] !== 'terminated' || $includeTerminated || $known);
            foreach ($relevant ? ['entity' => 'company', 'property' => 'division', 'department' => 'department'] : [] as $kind => $f) {
                $raw = $p['raw'][$f] ?? null;
                if ($raw !== null && $raw !== '') {
                    $k = Organization::key((string) $raw);
                    $values[$kind][$k]['spellings'][(string) $raw] = ($values[$kind][$k]['spellings'][(string) $raw] ?? 0) + 1;
                    $values[$kind][$k]['rows'] = ($values[$kind][$k]['rows'] ?? 0) + 1;
                }
            }
            $parsed[] = $p;
        }

        // ---- values: match to saved records, or use the administrator's choice
        $valueDecisions = (array) ($decisions['values'] ?? []);
        $valueList = [];
        $resolved = ['entity' => [], 'property' => [], 'department' => []];
        $unresolved = 0;
        foreach ($values as $kind => $list) {
            $table = self::VALUE_TABLES[$kind];
            // Spellings that differ only by "LLC", "Inc." or punctuation share one suggestion (the most used one).
            $groups = [];
            foreach ($list as $k => $info) {
                $base = self::baseKey((string) $k);
                $groups[$base] = isset($groups[$base]) && $groups[$base]['rows'] >= $info['rows'] ? $groups[$base] : ['rows' => $info['rows'], 'spelling' => (string) array_key_first($info['spellings'])];
            }
            foreach ($list as $k => $info) {
                arsort($info['spellings']);
                $spelling = (string) array_key_first($info['spellings']);
                $match = Organization::match($table, $spelling);
                $choice = $valueDecisions[$kind][$k] ?? null;
                $entry = ['kind' => $kind, 'key' => (string) $k, 'value' => $spelling, 'rows' => $info['rows'], 'matched' => $match,
                    'suggestion' => self::suggest($kind, $groups[self::baseKey((string) $k)]['spelling']), 'choice' => $choice];
                if ($match && (!$choice || ($choice['action'] ?? '') === 'auto')) {
                    $resolved[$kind][$k] = ['id' => $match, 'name' => Organization::get($table, $match)['name']];
                    $entry['status'] = 'matched';
                } elseif (($choice['action'] ?? '') === 'map' && Organization::get($table, (int) ($choice['id'] ?? 0))) {
                    $resolved[$kind][$k] = ['id' => (int) $choice['id'], 'name' => Organization::get($table, (int) $choice['id'])['name']];
                    $entry['status'] = 'mapped';
                } elseif (($choice['action'] ?? '') === 'new' && trim((string) ($choice['name'] ?? '')) !== '') {
                    $resolved[$kind][$k] = ['id' => 'new:' . $kind . ':' . $k, 'name' => Util::cleanText($choice['name'], 160)];
                    $entry['status'] = 'new';
                } elseif (($choice['action'] ?? '') === 'none') {
                    $resolved[$kind][$k] = ['id' => null, 'name' => null];
                    $entry['status'] = 'ignored';
                } else {
                    $entry['status'] = 'needs_choice';
                    $unresolved++;
                }
                $valueList[] = $entry;
            }
        }

        // ---- pass 2: match people
        $seenInFile = [];
        $planRows = [];
        $matchedIds = [];
        foreach ($parsed as $p) {
            $dec = (array) ($rowDecisions[(string) $p['row']] ?? []);
            $out = ['row' => $p['row'], 'name' => $p['display'], 'issues' => $p['issues'], 'action' => null, 'employee_id' => null,
                'match_by' => null, 'changes' => [], 'fields' => [], 'decision' => $dec ?: null];
            if ($p['error']) {
                $out['action'] = 'error';
                $out['issues'][] = $p['error'];
                $planRows[] = $out;
                continue;
            }
            $fileKey = $p['data']['hr_id'] ?? $p['data']['work_email'] ?? $p['key'];
            if (isset($seenInFile[$fileKey]) && ($dec['match'] ?? null) !== 'new') {
                $out['action'] = 'duplicate';
                $out['issues'][] = 'Same person as row ' . $seenInFile[$fileKey] . ' (only the first row is used)';
                $planRows[] = $out;
                continue;
            }
            $seenInFile[$fileKey] = $p['row'];

            // Who is this?
            $candidates = [];
            if (!empty($p['data']['hr_id']) && isset($byHr[$p['data']['hr_id']])) {
                $candidates = [$byHr[$p['data']['hr_id']]];
                $out['match_by'] = 'Employee ID';
            } elseif (!empty($p['data']['work_email']) && isset($byEmail[$p['data']['work_email']])) {
                $candidates = [$byEmail[$p['data']['work_email']]];
                $out['match_by'] = 'work email';
            } else {
                $candidates = array_values(array_unique(array_merge($byKey[$p['key']] ?? [], $p['alt_key'] ? ($byKey[$p['alt_key']] ?? []) : [])));
                $out['match_by'] = $candidates ? 'name' : null;
            }
            if (isset($dec['match'])) {
                if ($dec['match'] === 'new') {
                    $candidates = [];
                    $out['match_by'] = null;
                } elseif (Employees::row((int) $dec['match'])) {
                    $candidates = [(int) $dec['match']];
                    $out['match_by'] = 'your choice';
                }
            }
            if (count($candidates) > 1) {
                $out['action'] = 'ambiguous';
                $out['candidates'] = array_map(static fn ($cid) => ['id' => $cid, 'name' => Employees::displayName(Employees::row($cid)), 'code' => Employees::row($cid)['code']], $candidates);
                $out['issues'][] = 'More than one saved person has this name. Choose who this is, or create a new person.';
                $planRows[] = $out;
                continue;
            }

            // Resolve linked values for this row
            $data = $p['data'];
            $display = $p['display_fields'];
            $pending = false;
            foreach (['entity' => ['company', 'entity_id'], 'property' => ['division', 'property_id'], 'department' => ['department', 'department_id']] as $kind => [$f, $col]) {
                $raw = $p['raw'][$f] ?? null;
                if ($raw === null || $raw === '') {
                    continue;
                }
                $k = Organization::key((string) $raw);
                if (!array_key_exists($k, $resolved[$kind])) {
                    $pending = true;
                    continue;
                }
                if ($resolved[$kind][$k]['id'] !== null) {
                    $data[$col] = $resolved[$kind][$k]['id'];
                    $display[$col] = $resolved[$kind][$k]['name'];
                }
            }
            $out['fields'] = $display;
            $existingId = $candidates[0] ?? null;

            if (!$existingId) {
                if ($data['status'] === 'terminated' && !$includeTerminated) {
                    $out['action'] = 'skip';
                    $out['skip_reason'] = 'Former employee who isn’t in Dwell Central (not imported)';
                } elseif (!empty($dec['skip'])) {
                    $out['action'] = 'skip';
                    $out['skip_reason'] = 'Skipped by you';
                } else {
                    $out['action'] = 'create';
                    $out['data'] = $data;
                    if ($pending) {
                        $out['issues'][] = 'Waiting for a choice above (company, property or department)';
                    }
                }
                $planRows[] = $out;
                continue;
            }

            // Compare with the saved record. Blank cells never erase saved values.
            $before = Employees::row($existingId);
            $matchedIds[$existingId] = true;
            $out['employee_id'] = $existingId;
            $out['code'] = $before['code'];
            $changes = [];
            $set = [];
            foreach ($data as $field => $value) {
                if (in_array($field, ['birth_month', 'birth_day', 'birthday_status', 'birth_year', 'hire_status'], true) || $value === null || $value === '') {
                    continue;
                }
                $isNew = is_string($value) && str_starts_with($value, 'new:');
                if (!$isNew && (string) $before[$field] === (string) $value) {
                    continue;
                }
                if (in_array($field, ['first_name', 'last_name', 'preferred_name'], true) && mb_strtolower((string) $before[$field]) === mb_strtolower((string) $value)) {
                    continue; // only letter case differs; keep the saved spelling
                }
                $set[$field] = $value;
                $changes[] = ['field' => $field, 'label' => self::LABELS[$field] ?? $field, 'from' => self::show($field, $before[$field]), 'to' => $display[$field] ?? self::show($field, $value)];
            }
            // Hire date check: a new date carries its own check; for the same date an administrator's "ok" is kept.
            if (isset($set['hire_date'])) {
                $set['hire_status'] = $data['hire_status'];
            } elseif ($data['hire_date'] && $before['hire_status'] !== 'ok' && $data['hire_status'] === 'ok') {
                $set['hire_date'] = $data['hire_date'];
                $set['hire_status'] = 'ok';
                $changes[] = ['field' => 'hire_status', 'label' => 'Hire date check', 'from' => 'needs review', 'to' => 'ok'];
            }
            if ($data['hire_date'] && $before['hire_date'] && $data['hire_date'] < $before['hire_date']) {
                $out['issues'][] = 'The file has an earlier hire date than the saved record: it may be an older copy. Check before applying.';
            }
            // Rehire: a former employee is active again with a later hire date.
            if ($before['status'] === 'terminated' && $data['status'] !== 'terminated' && isset($set['hire_date'])
                && $before['hire_date'] && $before['hire_date'] < $data['hire_date'] && !$before['original_hire_date'] && empty($data['original_hire_date'])) {
                $set['original_hire_date'] = $before['hire_date'];
                $changes[] = ['field' => 'original_hire_date', 'label' => 'Original hire date', 'from' => '—', 'to' => $before['hire_date']];
                $out['issues'][] = 'Looks like a rehire: the earlier hire date is kept as the original hire date';
            }
            // Birthday
            if (isset($data['birth_month'])) {
                $same = $before['birth_month'] === $data['birth_month'] && $before['birth_day'] === $data['birth_day'];
                if ($data['birthday_status'] === 'needs_review' && $before['birthday_status'] === 'ok') {
                    $out['issues'][] = 'The file’s birthday looks wrong; the saved birthday is kept';
                } elseif (!$same || $before['birthday_status'] !== $data['birthday_status']) {
                    $set['birth_month'] = $data['birth_month'];
                    $set['birth_day'] = $data['birth_day'];
                    $set['birthday_status'] = $data['birthday_status'];
                    $changes[] = ['field' => 'birthday', 'label' => 'Birthday',
                        'from' => $before['birth_month'] ? Time::fmtMonthDay($before['birth_month'], $before['birth_day']) . ($before['birthday_status'] === 'needs_review' ? ' (needs review)' : '') : '—',
                        'to' => Time::fmtMonthDay($data['birth_month'], $data['birth_day']) . ($data['birthday_status'] === 'needs_review' ? ' (needs review)' : '')];
                }
                if ($storeYear && isset($data['birth_year']) && $data['birthday_status'] === 'ok' && $before['birth_year'] !== $data['birth_year']) {
                    $set['birth_year'] = $data['birth_year'];
                    $changes[] = ['field' => 'birth_year', 'label' => 'Birth year', 'from' => $before['birth_year'] ? 'saved' : '—', 'to' => 'updated'];
                }
            }
            if ($pending) {
                $out['issues'][] = 'Waiting for a choice above (company, property or department)';
            }
            $out['changes'] = $changes;
            $out['set'] = $set;
            if (!$changes) {
                $out['action'] = 'unchanged';
            } elseif (!empty($dec['skip'])) {
                $out['action'] = 'skip';
                $out['skip_reason'] = 'Skipped by you';
            } elseif ($before['verified_at'] && empty($dec['overwrite'])) {
                $out['action'] = 'protected';
                $out['issues'][] = 'This person’s record is verified. Tick “Approve changes” to apply them.';
            } else {
                $out['action'] = 'update';
                if ($before['verified_at']) {
                    $out['issues'][] = 'Verified record: changes approved by you';
                }
            }
            $planRows[] = $out;
        }

        // ---- supervisors (by email or name, within the file or saved records)
        $supervisorMissing = 0;
        if (isset($columns['supervisor'])) {
            $fileByKey = [];
            foreach ($parsed as $p) {
                if (!$p['error']) {
                    $fileByKey[$p['key']][] = $p['row'];
                    if ($p['alt_key']) {
                        $fileByKey[$p['alt_key']][] = $p['row'];
                    }
                    if (!empty($p['data']['work_email'])) {
                        $fileByKey[$p['data']['work_email']][] = $p['row'];
                    }
                }
            }
            $rowIndex = [];
            foreach ($planRows as $idx => $pr) {
                $rowIndex[$pr['row']] = $idx;
            }
            foreach ($parsed as $p) {
                $ref = $p['raw']['supervisor'] ?? null;
                if (!isset($rowIndex[$p['row']]) || $ref === null || trim((string) $ref) === '') {
                    continue;
                }
                $idx = $rowIndex[$p['row']];
                if (!in_array($planRows[$idx]['action'], ['create', 'update', 'unchanged', 'protected'], true)) {
                    continue;
                }
                $ref = trim((string) $ref);
                $k = str_contains($ref, '@') ? strtolower($ref) : Util::nameKey($ref);
                $target = null;
                $fileRows = array_values(array_unique($fileByKey[$k] ?? []));
                if (count($fileRows) === 1) {
                    $t = $planRows[$rowIndex[$fileRows[0]]] ?? null;
                    if ($t && in_array($t['action'], ['create', 'update', 'unchanged', 'protected'], true)) {
                        $target = $t['employee_id'] ? (int) $t['employee_id'] : 'row:' . $t['row'];
                    }
                } elseif (!$fileRows) {
                    $ids = str_contains($k, '@') ? (isset($byEmail[$k]) ? [$byEmail[$k]] : []) : array_values(array_unique($byKey[$k] ?? []));
                    if (count($ids) === 1) {
                        $target = $ids[0];
                    }
                }
                if ($target === null) {
                    $planRows[$idx]['issues'][] = "Supervisor “{$ref}” wasn’t found (or matches more than one person)";
                    $supervisorMissing++;
                    continue;
                }
                $planRows[$idx]['supervisor_ref'] = $target;
                $supName = is_int($target) ? Employees::displayName(Employees::row($target)) : $planRows[$rowIndex[(int) substr($target, 4)]]['name'];
                $planRows[$idx]['fields']['supervisor_id'] = $supName;
                $current = $planRows[$idx]['employee_id'] ? Employees::row((int) $planRows[$idx]['employee_id'])['supervisor_id'] : null;
                if ($target !== $current && $planRows[$idx]['employee_id'] !== null) {
                    $planRows[$idx]['changes'][] = ['field' => 'supervisor_id', 'label' => 'Supervisor',
                        'from' => $current ? Employees::displayName(Employees::row($current)) : '—', 'to' => $supName];
                    if ($planRows[$idx]['action'] === 'unchanged') {
                        $planRows[$idx]['action'] = Employees::row((int) $planRows[$idx]['employee_id'])['verified_at'] && empty($planRows[$idx]['decision']['overwrite']) ? 'protected' : 'update';
                    }
                }
            }
        }

        // ---- people saved as current employees who are not in this file
        $missing = [];
        if (isset($columns['status'])) {
            $terminate = array_map('intval', (array) ($decisions['terminate_missing'] ?? []));
            foreach (Employees::rows() as $r) {
                if ($r['status'] !== 'terminated' && !isset($matchedIds[$r['id']])) {
                    $missing[] = ['id' => $r['id'], 'code' => $r['code'], 'name' => Employees::displayName($r), 'terminate' => in_array($r['id'], $terminate, true)];
                }
            }
        }

        $counts = array_fill_keys(['create', 'update', 'unchanged', 'protected', 'skip', 'error', 'duplicate', 'ambiguous'], 0);
        foreach ($planRows as $pr) {
            $counts[$pr['action']]++;
        }
        $review = 0;
        foreach ($planRows as $pr) {
            if (in_array($pr['action'], ['create', 'update'], true) && preg_grep('/needs review|looks wrong/i', $pr['issues'])) {
                $review++;
            }
        }
        $summary = $counts + [
            'rows'               => count($planRows),
            'values_unresolved'  => $unresolved,
            'missing_from_file'  => count($missing),
            'terminate_missing'  => count(array_filter($missing, static fn ($m) => $m['terminate'])),
            'needs_review'       => $review,
            'supervisor_missing' => $supervisorMissing,
        ];
        $ready = $unresolved === 0 && $counts['ambiguous'] === 0;
        $hashable = array_map(static fn ($pr) => [$pr['row'], $pr['action'], $pr['employee_id'], $pr['data'] ?? null, $pr['set'] ?? null, $pr['supervisor_ref'] ?? null], $planRows);
        $previous = Db::one("SELECT id, created_at, status FROM imports WHERE file_hash = ? AND id <> ? AND status = 'applied' ORDER BY id DESC LIMIT 1", [$imp['file_hash'], $imp['id']]);

        return [
            'id'          => (int) $imp['id'],
            'file_name'   => $imp['file_name'],
            'sheet'       => $sheet,
            'sheets'      => $sheets,
            'header_row'  => $headerIndex + 1,
            'columns'     => array_map(static fn ($f, $i) => ['field' => $f, 'label' => self::FIELDS[$f][0], 'index' => $i, 'header' => (string) $header[$i]], array_keys($columns), array_values($columns)),
            'headers'     => array_map(static fn ($h) => (string) $h, $header),
            'ignored'     => array_values(array_filter(array_map(static fn ($h, $i) => in_array($i, $columns, true) || trim((string) $h) === '' ? null : (string) $h, $header, array_keys($header)))),
            'values'      => $valueList,
            'rows'        => array_map(static function ($pr) {
                unset($pr['data'], $pr['set']);
                return $pr;
            }, $planRows),
            'missing'     => $missing,
            'summary'     => $summary,
            'ready'       => $ready,
            'decisions'   => $decisions,
            'hash'        => hash('sha256', Util::json([$hashable, $missing, $resolved])),
            'previously_applied' => $previous,
            '_rows'       => $planRows,
            '_resolved'   => $resolved,
        ];
    }

    /** Turn one spreadsheet row into clean values, issues and a matching key. */
    private static function normalizeRow(callable $cell, array $columns, bool $date1904, bool $storeYear, string $today): array
    {
        $issues = [];
        $raw = [];
        foreach (array_keys(self::FIELDS) as $f) {
            $v = $cell($f);
            if (is_float($v) && floor($v) === $v && $f !== 'birth' && !str_contains($f, 'date')) {
                $v = (string) (int) $v; // 750.0 → "750", 1099.0 → "1099"
            }
            $raw[$f] = is_string($v) ? Util::cleanText($v, 190) : $v;
        }

        // Names: "Brizeida "Brisa" Gonzalez Esquivel" → first Brizeida, preferred Brisa, last Gonzalez Esquivel
        $preferred = Util::nullable($raw['preferred_name'] ?? null);
        if (isset($columns['first_name'])) {
            $first = (string) ($raw['first_name'] ?? '');
            $last = (string) ($raw['last_name'] ?? '');
        } else {
            $full = (string) ($raw['name'] ?? '');
            if (preg_match('/["“”(]([^"“”()]+)["“”)]/u', $full, $m)) {
                $preferred = $preferred ?? trim($m[1]);
                $full = trim(preg_replace('/\s*["“”(][^"“”()]+["“”)]\s*/u', ' ', $full) ?? $full);
            }
            $full = preg_replace('/\s+/u', ' ', trim($full)) ?? '';
            $parts = explode(' ', $full, 2);
            $first = $parts[0] ?? '';
            $last = $parts[1] ?? '';
        }
        $caps = $first !== '' && mb_strtoupper($first . $last) === $first . $last && preg_match('/\p{L}/u', $first . $last);
        if ($caps) {
            $first = Util::titleCase($first);
            $last = Util::titleCase($last);
            if ($preferred) {
                $preferred = Util::titleCase($preferred);
            }
            $issues[] = 'Name was in capitals; saved as “' . trim("$first $last") . '”';
        }
        $display = trim(($preferred ?: $first) . ' ' . $last);
        $error = $first === '' ? 'No name in this row' : null;

        // Status
        $status = 'active';
        if (isset($columns['status'])) {
            $s = strtolower(trim((string) ($raw['status'] ?? '')));
            $status = match (true) {
                $s === '' => 'active',
                in_array($s, ['active', 'a', 'act', 'current', 'employed'], true) => 'active',
                in_array($s, ['terminated', 'term', 'termed', 'inactive', 't', 'separated', 'former'], true) => 'terminated',
                in_array($s, ['leave', 'on leave', 'loa', 'leave of absence'], true) => 'leave',
                default => '?',
            };
            if ($s === '') {
                $issues[] = 'No status; treated as active';
            }
            if ($status === '?') {
                $error = $error ?? 'Unknown status “' . $raw['status'] . '”';
                $status = 'active';
            }
        }

        $data = [
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => $status,
        ];
        if ($preferred) {
            $data['preferred_name'] = $preferred;
        }
        if (!empty($raw['hr_id'])) {
            $data['hr_id'] = (string) $raw['hr_id'];
        }
        if (!empty($raw['work_email'])) {
            $email = Util::normalizeEmail($raw['work_email']);
            if ($email && Util::isValidEmail($email)) {
                $data['work_email'] = $email;
            } else {
                $issues[] = 'Work email “' . $raw['work_email'] . '” isn’t valid (not imported)';
            }
        }
        if (!empty($raw['job_title'])) {
            $data['job_title'] = (string) $raw['job_title'];
        }

        // Classification ("Vendor" and 1099 companies are contractors)
        $class = strtolower(trim((string) ($raw['classification'] ?? '')));
        $company = (string) ($raw['company'] ?? '');
        if ($class !== '') {
            $data['classification'] = match (true) {
                in_array($class, ['full time', 'full-time', 'fulltime', 'ft'], true) => 'full_time',
                in_array($class, ['part time', 'part-time', 'parttime', 'pt'], true) => 'part_time',
                in_array($class, ['vendor', 'contractor', 'independent contractor', '1099'], true) => 'contractor',
                in_array($class, ['temp', 'temporary', 'seasonal'], true) => 'temporary',
                default => 'other',
            };
        } elseif (preg_match('/^1099/', $company)) {
            $data['classification'] = 'contractor';
        }

        // Dates
        $hire = SpreadsheetReader::toDate($raw['hire_date'] ?? null, $date1904);
        $orig = SpreadsheetReader::toDate($raw['original_hire_date'] ?? null, $date1904);
        $term = SpreadsheetReader::toDate($raw['term_date'] ?? null, $date1904);
        foreach (['hire_date' => $hire, 'original_hire_date' => $orig, 'term_date' => $term] as $f => $v) {
            if ($v !== null && str_starts_with($v, '0000')) {
                $issues[] = self::LABELS[$f] . ' has no year (not imported)';
                $$f = null;
            } elseif ($v === null && ($raw[$f] ?? null) !== null && trim((string) $raw[$f]) !== '') {
                $issues[] = self::LABELS[$f] . ' “' . $raw[$f] . '” wasn’t understood (not imported)';
            }
        }
        $hire = $hire !== null && str_starts_with($hire, '0000') ? null : $hire;
        $orig = $orig !== null && str_starts_with($orig, '0000') ? null : $orig;
        $term = $term !== null && str_starts_with($term, '0000') ? null : $term;
        $data['hire_date'] = $hire;
        $data['hire_status'] = $hire ? 'ok' : 'missing';
        if ($hire && $hire > Time::addDays($today, 120)) {
            $data['hire_status'] = 'needs_review';
            $issues[] = 'Hire date is far in the future (needs review)';
        } elseif ($hire && $hire > $today) {
            $issues[] = 'Starts on ' . $hire . ' (celebrations begin after the start date)';
        }
        if ($hire && $hire < '1950-01-01') {
            $data['hire_status'] = 'needs_review';
            $issues[] = 'Hire date is before 1950 (needs review)';
        }
        if ($orig && $hire && $orig < $hire) {
            $data['original_hire_date'] = $orig;
        }
        if ($term) {
            $data['term_date'] = $term;
            if ($status === 'active') {
                $issues[] = 'Marked active but has a term date ' . $term . ' (check with HR)';
                unset($data['term_date']);
            }
        }

        // Birthday: month and day are kept; the year only checks plausibility (and is kept only if allowed)
        $birthRaw = $raw['birth'] ?? null;
        $birth = SpreadsheetReader::toDate($birthRaw, $date1904);
        if ($birth !== null) {
            [$by, $bm, $bd] = array_map('intval', explode('-', $birth));
            $data['birth_month'] = $bm;
            $data['birth_day'] = $bd;
            $data['birthday_status'] = 'ok';
            if ($by > 0) {
                $problem = null;
                if ($birth > $today) {
                    $problem = 'Birth date is in the future';
                } elseif ($hire && $birth >= $hire) {
                    $problem = 'Birth date is on or after the hire date';
                } elseif ($hire && Rules::serviceYears($birth, $hire) < 14) {
                    $problem = 'Would have been ' . Rules::serviceYears($birth, $hire) . ' when hired';
                } elseif (Rules::serviceYears($birth, $today) > 100) {
                    $problem = 'Would be over 100 years old';
                }
                if ($problem) {
                    $data['birthday_status'] = 'needs_review';
                    $issues[] = $problem . ': birthday needs review and won’t be celebrated until fixed';
                } elseif ($storeYear) {
                    $data['birth_year'] = $by;
                }
            }
        } elseif ($birthRaw !== null && trim((string) $birthRaw) !== '') {
            $issues[] = 'Birthday “' . $birthRaw . '” wasn’t understood (not imported)';
        } elseif (isset($columns['birth']) && $status !== 'terminated') {
            $issues[] = 'No birthday in the file';
        }

        $display_fields = [];
        foreach ($data as $f => $v) {
            $display_fields[$f] = self::show($f, $v);
        }
        if (isset($data['birth_month'])) {
            unset($display_fields['birth_month'], $display_fields['birth_day'], $display_fields['birthday_status'], $display_fields['birth_year']);
            $display_fields['birthday'] = Time::fmtMonthDay($data['birth_month'], $data['birth_day']) . ($data['birthday_status'] === 'needs_review' ? ' (needs review)' : '');
        }
        unset($display_fields['hire_status']);

        return [
            'data'           => $data,
            'raw'            => $raw,
            'display'        => $display,
            'display_fields' => $display_fields,
            'issues'         => $issues,
            'error'          => $error,
            'key'            => Util::nameKey($first . ' ' . $last),
            'alt_key'        => $preferred ? Util::nameKey($preferred . ' ' . $last) : null,
        ];
    }

    private static function show(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        return match ($field) {
            'status'         => Employees::STATUSES[$value] ?? (string) $value,
            'classification' => Employees::CLASSIFICATIONS[$value] ?? (string) $value,
            'entity_id'      => is_int($value) ? (Organization::get('entities', $value)['name'] ?? '?') : (string) $value,
            'property_id'    => is_int($value) ? (Organization::get('properties', $value)['name'] ?? '?') : (string) $value,
            'department_id'  => is_int($value) ? (Organization::get('departments', $value)['name'] ?? '?') : (string) $value,
            'supervisor_id'  => is_int($value) ? (Employees::row($value) ? Employees::displayName(Employees::row($value)) : '?') : (string) $value,
            default          => (string) $value,
        };
    }

    private static function baseKey(string $key): string
    {
        $base = preg_replace('/(llc|inc|corp|corporation|co|lp|llp|ltd|incorporated)$/', '', $key) ?? $key;
        return preg_match('/^1099/', $key) ? '1099' : ($base !== '' ? $base : $key);
    }

    /** A suggested record for a value nobody has matched yet. */
    private static function suggest(string $kind, string $value): array
    {
        $name = trim(preg_replace('/\s+/', ' ', preg_replace('/,(?=\S)/', ', ', $value) ?? $value) ?? $value);
        if ($kind === 'entity') {
            if (preg_match('/^1099/', $name)) {
                return ['name' => 'Independent contractors (1099)', 'kind' => 'contractor', 'brand_id' => null];
            }
            if (mb_strtoupper($name) === $name) {
                $name = preg_replace_callback('/\b(Llc|Inc|Lp|Llp|Pc|Co)\b\.?/', static fn ($m) => strtoupper($m[1]) === 'INC' ? 'Inc.' : strtoupper($m[1]), Util::titleCase($name)) ?? $name;
                $name = str_replace('Inc..', 'Inc.', $name);
            }
            $brand = null;
            foreach (Organization::all()['brands'] as $b) {
                if (str_contains(Organization::key($name), Organization::key($b['name']))) {
                    $brand = $b['id'];
                }
            }
            return ['name' => $name, 'kind' => 'employer', 'brand_id' => $brand];
        }
        if ($kind === 'property') {
            $k = Organization::key($name);
            $type = $k === 'corporate' ? 'office' : (in_array($k, ['landscape', 'landscaping', 'maintenance'], true) ? 'team' : 'property');
            return ['name' => $k === 'corporate' ? 'Corporate Office' : $name, 'kind' => $type, 'brand_id' => null];
        }
        return ['name' => $name];
    }

    // ---------------------------------------------------------------- 3. apply

    public static function apply(array $user, int $id, array $decisions, string $hash): array
    {
        $imp = self::load($id);
        if ($imp['status'] !== 'preview' || $imp['rows_json'] === null) {
            throw new AppError('This import has already been ' . ($imp['status'] === 'applied' ? 'applied' : 'closed') . '.');
        }
        $plan = self::plan($imp, $decisions);
        if (!hash_equals($plan['hash'], $hash)) {
            throw AppError::conflict('Something changed since you reviewed this import (a record was edited, or the choices changed). Please review the updated preview and apply again.', ['plan' => self::strip($plan)]);
        }
        if (!$plan['ready']) {
            throw new AppError('Some choices are still needed before this import can be applied.');
        }

        $result = Db::transaction(static function () use ($plan, $imp, $user, $decisions) {
            $source = 'import #' . $imp['id'];
            // New companies / properties / departments the administrator approved
            $created = [];
            $map = [];
            foreach ($plan['values'] as $v) {
                $table = self::VALUE_TABLES[$v['kind']];
                if ($v['status'] === 'new') {
                    $choice = $v['choice'];
                    $input = ['name' => $choice['name']];
                    if ($v['kind'] === 'entity') {
                        $input += ['kind' => $choice['kind'] ?? 'employer', 'brand_id' => $choice['brand_id'] ?? null, 'aliases' => $v['value']];
                    } elseif ($v['kind'] === 'property') {
                        $input += ['kind' => $choice['kind'] ?? 'property', 'brand_id' => $choice['brand_id'] ?? null, 'aliases' => $v['value']];
                    } else {
                        $input += ['aliases' => $v['value']];
                    }
                    $existing = Organization::match($table, $choice['name']);
                    $map['new:' . $v['kind'] . ':' . $v['key']] = $existing ?: Organization::save($table, $input);
                    if ($existing) {
                        Organization::addAlias($table, $existing, $v['value']); // two spellings, one record
                    } else {
                        $created[] = Organization::KINDS[$table]['label'] . ': ' . $choice['name'];
                    }
                } elseif ($v['status'] === 'mapped') {
                    Organization::addAlias($table, (int) $v['choice']['id'], $v['value']);
                }
            }
            $fix = static fn ($value) => is_string($value) && isset($map[$value]) ? $map[$value] : $value;

            $ids = [];
            $counts = ['created' => 0, 'updated' => 0, 'terminated' => 0, 'supervisors' => 0];
            foreach ($plan['_rows'] as $pr) {
                if ($pr['action'] === 'create') {
                    $data = array_map($fix, $pr['data']);
                    $ids[$pr['row']] = Employees::save($data, $source);
                    $counts['created']++;
                } elseif ($pr['action'] === 'update') {
                    $set = array_map($fix, $pr['set'] ?? []);
                    if ($set) {
                        Employees::save(['id' => $pr['employee_id']] + $set, $source);
                        $counts['updated']++;
                    }
                    $ids[$pr['row']] = (int) $pr['employee_id'];
                } elseif (in_array($pr['action'], ['unchanged', 'protected'], true)) {
                    $ids[$pr['row']] = (int) $pr['employee_id'];
                }
            }
            // Supervisors, once everyone exists
            foreach ($plan['_rows'] as $pr) {
                if (!isset($pr['supervisor_ref'], $ids[$pr['row']]) || $pr['action'] === 'protected') {
                    continue;
                }
                $ref = $pr['supervisor_ref'];
                $supId = is_int($ref) ? $ref : ($ids[(int) substr($ref, 4)] ?? null);
                $me = $ids[$pr['row']];
                if ($supId && $supId !== $me && Employees::row($me)['supervisor_id'] !== $supId) {
                    try {
                        Employees::save(['id' => $me, 'supervisor_id' => $supId], $source);
                        $counts['supervisors']++;
                    } catch (AppError $e) {
                        // e.g. a loop in the reporting chain; leave as is and report it
                        $counts['supervisor_errors'][] = $pr['name'] . ': ' . $e->getMessage();
                    }
                }
            }
            // People not in the file, if the administrator chose to mark them as former employees
            foreach ($plan['missing'] as $m) {
                if ($m['terminate']) {
                    Employees::save(['id' => $m['id'], 'status' => 'terminated', 'term_date' => Time::today()], $source);
                    $counts['terminated']++;
                }
            }

            $summary = $plan['summary'] + ['applied' => $counts, 'created_records' => $created];
            Db::update('imports', [
                'status'     => 'applied',
                'applied_at' => Time::nowDb(),
                'applied_by' => $user['id'],
                'summary'    => Util::json($summary),
                'decisions'  => Util::json($decisions),
                'rows_json'  => null, // the personnel rows are not kept
            ], 'id = :id', ['id' => $imp['id']]);
            Audit::log('import_apply', 'import', (int) $imp['id'], sprintf(
                'Applied import of %s: %d added, %d updated, %d marked former',
                $imp['file_name'], $counts['created'], $counts['updated'], $counts['terminated']
            ), $summary);
            return $summary;
        });
        Employees::flush();
        Organization::flush();
        return $result;
    }

    public static function discard(int $id): void
    {
        $imp = self::load($id);
        if ($imp['status'] !== 'preview') {
            return;
        }
        Db::update('imports', ['status' => 'discarded', 'rows_json' => null], 'id = :id', ['id' => $id]);
        Audit::log('import_discard', 'import', $id, 'Discarded import of ' . $imp['file_name']);
    }

    /** Previews nobody applied are erased after the retention period. */
    public static function purge(): int
    {
        $before = Time::now()->modify('-' . (int) Settings::get('import_retention_days') . ' days')->format('Y-m-d H:i:s');
        return Db::run("UPDATE imports SET status = 'discarded', rows_json = NULL WHERE status = 'preview' AND created_at < ?", [$before])->rowCount();
    }

    public static function list(): array
    {
        return array_map(static function ($r) {
            $r['id'] = (int) $r['id'];
            $r['summary'] = Util::decode($r['summary'], null);
            return $r;
        }, Db::all('SELECT i.id, i.file_name, i.sheet, i.status, i.summary, i.created_at, i.applied_at, u.name AS user_name
                    FROM imports i LEFT JOIN users u ON u.id = i.user_id ORDER BY i.id DESC LIMIT 50'));
    }

    /** The plan without internal fields (for API responses). */
    public static function strip(array $plan): array
    {
        unset($plan['_rows'], $plan['_resolved']);
        return $plan;
    }
}
