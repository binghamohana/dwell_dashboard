<?php
declare(strict_types=1);

namespace Dwell\Org;

use Dwell\Core\AppError;
use Dwell\Core\Audit;
use Dwell\Core\Db;
use Dwell\Core\Time;
use Dwell\Core\Util;

/**
 * Shared organization records: companies (brands), legal entities, properties/offices and
 * departments. Every module links to these by ID; spellings from spreadsheets are matched
 * through each record's aliases, so "EastGAte" and "EastGate" are the same property.
 */
final class Organization
{
    public const KINDS = [
        'brands'      => ['label' => 'Company', 'fields' => ['name', 'color', 'sort', 'is_active']],
        'entities'    => ['label' => 'Legal entity', 'fields' => ['name', 'legal_name', 'kind', 'brand_id', 'aliases', 'sort', 'is_active']],
        'properties'  => ['label' => 'Property', 'fields' => ['name', 'short_name', 'kind', 'brand_id', 'address', 'manager_id', 'aliases', 'sort', 'is_active']],
        'departments' => ['label' => 'Department', 'fields' => ['name', 'aliases', 'sort', 'is_active']],
    ];

    private const EMPLOYEE_COLUMN = ['brands' => 'brand_id', 'entities' => 'entity_id', 'properties' => 'property_id', 'departments' => 'department_id'];

    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** Everything, keyed by type, each list ordered for display. */
    public static function all(): array
    {
        if (self::$cache === null) {
            $out = [];
            foreach (array_keys(self::KINDS) as $table) {
                $col = self::EMPLOYEE_COLUMN[$table];
                $rows = Db::all("SELECT t.*, (SELECT COUNT(*) FROM employees e WHERE e.$col = t.id AND e.status <> 'terminated') AS people
                                 FROM $table t ORDER BY t.is_active DESC, t.sort, t.name");
                foreach ($rows as &$r) {
                    $r['id'] = (int) $r['id'];
                    $r['is_active'] = (bool) $r['is_active'];
                    $r['people'] = (int) $r['people'];
                    $r['sort'] = (int) $r['sort'];
                    foreach (['brand_id', 'manager_id'] as $fk) {
                        if (array_key_exists($fk, $r)) {
                            $r[$fk] = $r[$fk] === null ? null : (int) $r[$fk];
                        }
                    }
                    unset($r['created_at'], $r['updated_at']);
                }
                $out[$table] = $rows;
            }
            self::$cache = $out;
        }
        return self::$cache;
    }

    public static function byId(string $table): array
    {
        $out = [];
        foreach (self::all()[$table] as $r) {
            $out[$r['id']] = $r;
        }
        return $out;
    }

    public static function get(string $table, ?int $id): ?array
    {
        return $id ? (self::byId($table)[$id] ?? null) : null;
    }

    public static function save(string $table, array $input): int
    {
        if (!isset(self::KINDS[$table])) {
            throw new AppError('Unknown record type.');
        }
        $id = Util::intOrNull($input['id'] ?? null);
        $before = $id ? Db::one("SELECT * FROM $table WHERE id = ?", [$id]) : null;
        if ($id && !$before) {
            throw AppError::notFound();
        }
        $data = [];
        foreach (self::KINDS[$table]['fields'] as $f) {
            if (!array_key_exists($f, $input) && $before) {
                continue;
            }
            $v = $input[$f] ?? null;
            switch ($f) {
                case 'name':
                    $data[$f] = Util::cleanText($v, $table === 'brands' || $table === 'departments' ? 120 : 160);
                    if ($data[$f] === '') {
                        throw new AppError('Please enter a name.', 400, 'name');
                    }
                    $dupe = Db::value("SELECT id FROM $table WHERE name = ? AND id <> ?", [$data[$f], $id ?? 0]);
                    if ($dupe) {
                        throw new AppError('There is already one with that name.', 400, 'name');
                    }
                    break;
                case 'color':
                    $v = strtoupper(trim((string) $v));
                    if (!preg_match('/^#[0-9A-F]{6}$/', $v)) {
                        throw new AppError('Pick a color like #113263.', 400, 'color');
                    }
                    $data[$f] = $v;
                    break;
                case 'kind':
                    $allowed = $table === 'entities' ? ['employer', 'contractor', 'other'] : ['property', 'office', 'team', 'other'];
                    $data[$f] = in_array($v, $allowed, true) ? $v : $allowed[0];
                    break;
                case 'brand_id':
                    $data[$f] = Util::intOrNull($v);
                    if ($data[$f] && !Db::value('SELECT id FROM brands WHERE id = ?', [$data[$f]])) {
                        throw new AppError('Unknown company.', 400, 'brand_id');
                    }
                    break;
                case 'manager_id':
                    $data[$f] = Util::intOrNull($v);
                    if ($data[$f] && !Db::value('SELECT id FROM employees WHERE id = ?', [$data[$f]])) {
                        throw new AppError('Unknown person.', 400, 'manager_id');
                    }
                    break;
                case 'aliases':
                    $lines = array_filter(array_map(static fn ($l) => Util::cleanText($l, 120), preg_split('/\n+/', (string) $v) ?: []));
                    $data[$f] = $lines ? mb_substr(implode("\n", array_unique($lines)), 0, 1000) : null;
                    break;
                case 'sort':
                    $data[$f] = max(-999, min(999, (int) $v));
                    break;
                case 'is_active':
                    $data[$f] = $v === null ? 1 : (filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
                    break;
                default:
                    $data[$f] = Util::nullable(Util::cleanText($v, $f === 'address' ? 255 : 190));
            }
        }
        $data['updated_at'] = Time::nowDb();
        if ($id) {
            Db::update($table, $data, 'id = :id', ['id' => $id]);
            Audit::log('org_update', rtrim($table, 's'), $id, self::KINDS[$table]['label'] . ' updated: ' . ($data['name'] ?? $before['name']), Audit::diff($before, $data));
        } else {
            $data['created_at'] = Time::nowDb();
            $id = Db::insert($table, $data);
            Audit::log('org_create', rtrim($table, 's'), $id, self::KINDS[$table]['label'] . ' added: ' . $data['name']);
        }
        self::flush();
        return $id;
    }

    public static function delete(string $table, int $id): void
    {
        if (!isset(self::KINDS[$table])) {
            throw new AppError('Unknown record type.');
        }
        $row = Db::one("SELECT * FROM $table WHERE id = ?", [$id]);
        if (!$row) {
            throw AppError::notFound();
        }
        $col = self::EMPLOYEE_COLUMN[$table];
        $used = (int) Db::value("SELECT COUNT(*) FROM employees WHERE $col = ?", [$id]);
        if ($table === 'brands') {
            $used += (int) Db::value('SELECT COUNT(*) FROM entities WHERE brand_id = ?', [$id]) + (int) Db::value('SELECT COUNT(*) FROM properties WHERE brand_id = ?', [$id]);
        }
        if ($table === 'properties') {
            $used += (int) Db::value('SELECT COUNT(*) FROM employee_properties WHERE property_id = ?', [$id]);
        }
        if ($used) {
            throw new AppError('This is still linked to people or other records. Turn off “Active” instead, so history is kept.');
        }
        Db::run("DELETE FROM $table WHERE id = ?", [$id]);
        Audit::log('org_delete', rtrim($table, 's'), $id, self::KINDS[$table]['label'] . ' deleted: ' . $row['name']);
        self::flush();
    }

    /** Comparable form of a spelling: "GROVE PARK RENEWAL,INC." → "groveparkrenewalinc". */
    public static function key(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (preg_match('/^\d+\.0+$/', $value)) {
            $value = (string) (int) $value; // 750.0 from a spreadsheet number cell
        }
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }

    /** Find a record by name, short name or alias. */
    public static function match(string $table, ?string $value): ?int
    {
        $k = self::key((string) $value);
        if ($k === '') {
            return null;
        }
        foreach (self::all()[$table] as $r) {
            $names = array_merge([$r['name'], $r['short_name'] ?? '', $r['legal_name'] ?? ''], preg_split('/\n/', (string) ($r['aliases'] ?? '')) ?: []);
            foreach ($names as $n) {
                if ($n !== '' && self::key($n) === $k) {
                    return $r['id'];
                }
            }
        }
        return null;
    }

    /** Remember an extra spelling for a record (used when an import maps a new value). */
    public static function addAlias(string $table, int $id, string $alias): void
    {
        $row = Db::one("SELECT name, aliases FROM $table WHERE id = ?", [$id]);
        if (!$row || self::match($table, $alias) === $id) {
            return;
        }
        $aliases = array_filter(preg_split('/\n/', (string) $row['aliases']) ?: []);
        $aliases[] = Util::cleanText($alias, 120);
        Db::update($table, ['aliases' => mb_substr(implode("\n", array_unique($aliases)), 0, 1000), 'updated_at' => Time::nowDb()], 'id = :id', ['id' => $id]);
        self::flush();
    }
}
