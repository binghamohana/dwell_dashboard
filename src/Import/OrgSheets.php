<?php
declare(strict_types=1);

namespace Dwell\Import;

use Dwell\Core\Util;
use Dwell\Org\Organization;

/**
 * The organization sheets of the Dwell Central import template ("Companies", "Legal entities",
 * "Properties", "Departments"). They are read together with the People sheet, so a single upload
 * can set up the whole organization: records are matched by name (or any "Also known as"
 * spelling), created or updated, and the People sheet's choices resolve to them automatically.
 * Blank cells never erase saved values. Property managers are resolved by Importer once people
 * are known.
 */
final class OrgSheets
{
    /** table => [sheet name, first-column header, columns: header => field] */
    public const SHEETS = [
        'brands'      => ['Companies', 'company', ['company' => 'name', 'color' => 'color']],
        'entities'    => ['Legal entities', 'legal entity', ['legal entity' => 'name', 'full legal name' => 'legal_name', 'type' => 'kind', 'company' => 'brand', 'also known as' => 'aliases']],
        'properties'  => ['Properties', 'property office', ['property office' => 'name', 'property' => 'name', 'short name' => 'short_name', 'type' => 'kind', 'company' => 'brand', 'address' => 'address', 'property manager' => 'manager', 'also known as' => 'aliases']],
        'departments' => ['Departments', 'department', ['department' => 'name', 'also known as' => 'aliases']],
    ];

    private const COLORS = ['#2E6B4F', '#9A5B2E', '#4B4E8A', '#6B6F2E', '#2B4F86', '#8A4B6E'];
    /** The first release's default for Dwell Communities; older workbook copies still carry it. */
    private const OLD_DEFAULT = '#1F3D4F';

    private static function key(mixed $v): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $v)) ?? '');
    }

    /** Does this workbook contain any of the template's organization sheets? */
    public static function present(array $sheets): bool
    {
        foreach (array_keys($sheets) as $name) {
            foreach (self::SHEETS as [$sheet]) {
                if (self::key($name) === self::key($sheet)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function sheetRows(array $sheets, string $title): ?array
    {
        foreach ($sheets as $name => $rows) {
            if (self::key($name) === self::key($title)) {
                return $rows;
            }
        }
        return null;
    }

    private static function aliases(mixed $v): array
    {
        $parts = preg_split('/[\n;]+/', (string) $v) ?: [];
        return array_values(array_unique(array_filter(array_map(static fn ($a) => Util::cleanText($a, 120), $parts))));
    }

    private static function kind(string $table, mixed $v): ?string
    {
        $k = self::key($v);
        if ($k === '') {
            return null;
        }
        if ($table === 'entities') {
            return str_contains($k, 'contractor') || str_contains($k, '1099') || str_contains($k, 'vendor') ? 'contractor' : ($k === 'employer' ? 'employer' : 'other');
        }
        return in_array($k, ['property', 'office', 'team', 'other'], true) ? $k : 'other';
    }

    /**
     * Plan the organization changes.
     * @return array{present: bool, records: array, lookup: array, counts: array}
     *   records: table => list of rows; lookup: kind (entity|property|department|brand) => key => [id, name]
     */
    public static function plan(array $sheets): array
    {
        $out = ['present' => self::present($sheets), 'records' => [], 'lookup' => ['brand' => [], 'entity' => [], 'property' => [], 'department' => []],
            'counts' => ['create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0]];
        if (!$out['present']) {
            return $out;
        }
        $lookupKind = ['brands' => 'brand', 'entities' => 'entity', 'properties' => 'property', 'departments' => 'department'];
        $colorIndex = 0;
        foreach (self::SHEETS as $table => [$title, $firstHeader, $map]) {
            $rows = self::sheetRows($sheets, $title);
            $out['records'][$table] = [];
            if ($rows === null) {
                continue;
            }
            // Header row: the first of the top 10 rows whose first filled cell is the expected header.
            $headerIndex = null;
            foreach (array_slice($rows, 0, 10, true) as $i => $row) {
                foreach ($row as $cell) {
                    if (trim((string) $cell) === '') {
                        continue;
                    }
                    if (self::key($cell) === $firstHeader || ($table === 'properties' && self::key($cell) === 'property')) {
                        $headerIndex = $i;
                    }
                    break;
                }
                if ($headerIndex !== null) {
                    break;
                }
            }
            if ($headerIndex === null) {
                continue;
            }
            $cols = [];
            foreach ($rows[$headerIndex] as $c => $h) {
                $f = $map[self::key($h)] ?? null;
                if ($f !== null && !isset($cols[$f])) {
                    $cols[$f] = $c;
                }
            }
            $seen = [];
            foreach ($rows as $i => $row) {
                if ($i <= $headerIndex || !Importer::hasData($row)) {
                    continue;
                }
                $get = static fn ($f) => isset($cols[$f]) ? ($row[$cols[$f]] ?? null) : null;
                $raw = $get('name');
                $name = Util::cleanText(is_float($raw) && floor($raw) === $raw ? (string) (int) $raw : (string) $raw, 160);
                $rec = ['sheet' => $title, 'row' => $i + 1, 'name' => $name, 'action' => null, 'id' => null, 'changes' => [], 'issues' => [], 'input' => []];
                if ($name === '') {
                    $rec['action'] = 'error';
                    $rec['issues'][] = 'No name in this row (skipped)';
                    $out['records'][$table][] = $rec;
                    $out['counts']['error']++;
                    continue;
                }
                $aliases = $table === 'brands' ? [] : self::aliases($get('aliases'));
                $keys = array_values(array_unique(array_filter(array_map([Organization::class, 'key'], array_merge([$name], $aliases)))));
                $dupe = array_intersect($keys, array_keys($seen));
                if ($dupe) {
                    $rec['action'] = 'error';
                    $rec['issues'][] = 'Same as row ' . $seen[reset($dupe)] . ' on this sheet (skipped)';
                    $out['records'][$table][] = $rec;
                    $out['counts']['error']++;
                    continue;
                }
                foreach ($keys as $k) {
                    $seen[$k] = $rec['row'];
                }

                // Match a saved record by the name or any spelling listed for it.
                $existingId = null;
                foreach (array_merge([$name], $aliases) as $candidate) {
                    $existingId = Organization::match($table, $candidate);
                    if ($existingId) {
                        break;
                    }
                }
                $before = $existingId ? Organization::get($table, $existingId) : null;

                $input = ['name' => $name];
                if ($table === 'brands') {
                    $color = strtoupper(trim((string) $get('color')));
                    if ($color !== '' && !preg_match('/^#[0-9A-F]{6}$/', $color)) {
                        $rec['issues'][] = 'Color “' . $color . '” isn’t like #113263 (ignored)';
                        $color = '';
                    }
                    if ($color === self::OLD_DEFAULT) {
                        $color = ''; // keep the official blue set since
                    }
                    if ($color !== '') {
                        $input['color'] = $color;
                    } elseif (!$before) {
                        $input['color'] = self::COLORS[$colorIndex++ % count(self::COLORS)];
                    }
                } else {
                    foreach (['legal_name' => 190, 'short_name' => 60, 'address' => 255] as $f => $max) {
                        if (isset($cols[$f])) {
                            $v = Util::cleanText($get($f), $max);
                            if ($v !== '') {
                                $input[$f] = $v;
                            }
                        }
                    }
                    if (isset($cols['kind']) && ($k = self::kind($table, $get('kind'))) !== null) {
                        $input['kind'] = $k;
                    }
                    if (isset($cols['brand']) && trim((string) $get('brand')) !== '') {
                        $ref = Organization::key((string) $get('brand'));
                        $b = $out['lookup']['brand'][$ref] ?? null;
                        $bid = $b['id'] ?? Organization::match('brands', (string) $get('brand'));
                        if ($bid) {
                            $input['brand_id'] = $bid;
                        } else {
                            $rec['issues'][] = 'Company “' . $get('brand') . '” isn’t on the Companies sheet (left blank)';
                        }
                    }
                    $merged = $before ? array_values(array_unique(array_merge(array_filter(explode("\n", (string) $before['aliases'])), $aliases))) : $aliases;
                    if ($aliases && (!$before || count($merged) !== count(array_filter(explode("\n", (string) $before['aliases']))))) {
                        $input['aliases'] = implode("\n", $merged);
                    }
                    if ($table === 'properties' && trim((string) $get('manager')) !== '') {
                        $rec['manager'] = Util::cleanText($get('manager'), 190);
                    }
                }

                if ($before) {
                    $rec['id'] = $existingId;
                    $changes = [];
                    foreach ($input as $f => $v) {
                        $old = $before[$f] ?? null;
                        if ($f === 'name' && Organization::key((string) $old) === Organization::key((string) $v) && $old === $v) {
                            continue;
                        }
                        if ((string) $old === (string) $v) {
                            continue;
                        }
                        $changes[] = ['field' => $f, 'from' => self::show($f, $old), 'to' => self::show($f, $v)];
                    }
                    $rec['changes'] = $changes;
                    $rec['input'] = array_intersect_key($input, array_flip(array_column($changes, 'field')));
                    $rec['action'] = $changes ? 'update' : 'unchanged';
                    $ref = $existingId;
                } else {
                    $rec['input'] = $input;
                    $rec['action'] = 'create';
                    $ref = 'new:tpl:' . $table . ':' . $keys[0];
                    foreach ($input as $f => $v) {
                        if ($f !== 'name' && $v !== null && $v !== '') {
                            $rec['changes'][] = ['field' => $f, 'from' => '—', 'to' => self::show($f, $v)];
                        }
                    }
                }
                $rec['ref'] = $ref;
                foreach ($keys as $k) {
                    $out['lookup'][$lookupKind[$table]][$k] = ['id' => $ref, 'name' => $name];
                }
                $out['counts'][$rec['action']]++;
                $out['records'][$table][] = $rec;
            }
        }
        return $out;
    }

    private static function show(string $field, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        return match ($field) {
            'brand_id' => is_int($v) ? (Organization::get('brands', $v)['name'] ?? '?') : 'new company',
            'kind'     => ['employer' => 'Employer', 'contractor' => 'Contractors (1099)', 'other' => 'Other', 'property' => 'Property', 'office' => 'Office', 'team' => 'Team'][$v] ?? (string) $v,
            'aliases'  => str_replace("\n", ', ', (string) $v),
            default    => (string) $v,
        };
    }

    public const LABELS = ['name' => 'Name', 'color' => 'Color', 'legal_name' => 'Full legal name', 'kind' => 'Type', 'brand_id' => 'Company',
        'short_name' => 'Short name', 'address' => 'Address', 'aliases' => 'Also known as', 'manager_id' => 'Property manager'];

    /** Create/update the planned records. Fills $map with placeholder => id. Returns [created, updated]. */
    public static function apply(array $org, array &$map): array
    {
        $created = $updated = 0;
        foreach (array_keys(self::SHEETS) as $table) {
            foreach ($org['records'][$table] ?? [] as $rec) {
                if (!in_array($rec['action'], ['create', 'update'], true)) {
                    if ($rec['action'] === 'unchanged') {
                        $map[$rec['ref']] = $rec['id'];
                    }
                    continue;
                }
                $input = $rec['input'];
                if (isset($input['brand_id']) && is_string($input['brand_id'])) {
                    $input['brand_id'] = $map[$input['brand_id']] ?? null;
                }
                if ($rec['action'] === 'create') {
                    $id = Organization::save($table, $input + ($table === 'brands' ? [] : ['kind' => $input['kind'] ?? ($table === 'entities' ? 'employer' : 'property')]));
                    $map[$rec['ref']] = $id;
                    $created++;
                } else {
                    Organization::save($table, ['id' => $rec['id']] + $input);
                    $map[$rec['ref']] = $rec['id'];
                    $updated++;
                }
            }
        }
        return [$created, $updated];
    }
}
