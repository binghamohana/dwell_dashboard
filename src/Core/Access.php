<?php
declare(strict_types=1);

namespace Dwell\Core;

/**
 * Role-based access, enforced on the server for every request.
 *
 * - System administrator: everything.
 * - Executive leadership: the whole organization, read-only; organization-wide reports.
 * - Property manager / supervisor: only the people they are responsible for (see visibleIds()).
 * - Employee (future): the directory and org chart with basic fields only.
 */
final class Access
{
    public const ROLES = [
        'system_admin' => 'System administrator',
        'executive'    => 'Executive leadership',
        'manager'      => 'Property manager / supervisor',
        'employee'     => 'Employee',
    ];

    /**
     * people.view          see people (scoped by visibleIds)
     * people.dates         see birthdays, hire dates, service years, celebration owner
     * people.all           see everyone, including former employees
     * people.edit          add / edit / deactivate people, photos
     * people.private       internal notes, HR IDs, birth year (when stored)
     * celebrations.view    dashboard and celebrations calendar
     * celebrations.status  mark a celebration planned / completed
     * notifications.view   notification history
     * notifications.manage run jobs, retry, change test/live mode
     * import.run           spreadsheet imports
     * org.manage           brands, entities, properties, departments
     * users.manage         accounts and roles
     * settings.manage      settings
     * audit.view           audit log
     */
    private const PERMISSIONS = [
        'system_admin' => ['*'],
        'executive'    => ['people.view', 'people.dates', 'people.all', 'celebrations.view', 'celebrations.status', 'notifications.view'],
        'manager'      => ['people.view', 'people.dates', 'celebrations.view', 'celebrations.status'],
        'employee'     => ['people.view'],
    ];

    /** @var array<int, int[]|null> */
    private static array $visibleCache = [];

    public static function can(?array $user, string $permission): bool
    {
        if (!$user || empty($user['is_active'])) {
            return false;
        }
        $perms = self::PERMISSIONS[$user['role']] ?? [];
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public static function require(?array $user, string $permission): void
    {
        if (!$user) {
            throw new AppError('Please sign in.', 401, null, 'unauthenticated');
        }
        if (!self::can($user, $permission)) {
            throw AppError::forbidden();
        }
    }

    public static function permissions(array $user): array
    {
        $all = ['people.view', 'people.dates', 'people.all', 'people.edit', 'people.private', 'celebrations.view', 'celebrations.status',
            'notifications.view', 'notifications.manage', 'import.run', 'org.manage', 'users.manage', 'settings.manage', 'audit.view'];
        return array_values(array_filter($all, static fn ($p) => self::can($user, $p)));
    }

    public static function flush(): void
    {
        self::$visibleCache = [];
    }

    /**
     * Employee IDs this user may see, or null for "everyone".
     * Managers see: themselves; everyone below them in the reporting chain; their dotted-line
     * reports; people at properties they manage or are scoped to; people they are the named
     * celebration owner for; and people employed by entities they are scoped to.
     */
    public static function visibleIds(array $user): ?array
    {
        if (self::can($user, 'people.all')) {
            return null;
        }
        $uid = (int) $user['id'];
        if (array_key_exists($uid, self::$visibleCache)) {
            return self::$visibleCache[$uid];
        }

        if ($user['role'] === 'employee') {
            $ids = array_map('intval', array_column(Db::all("SELECT id FROM employees WHERE status <> 'terminated' ORDER BY id"), 'id'));
            return self::$visibleCache[$uid] = $ids;
        }

        $ids = [];
        $own = $user['employee_id'] ? (int) $user['employee_id'] : null;
        $rows = Db::all("SELECT id, supervisor_id, dotted_line_id, celebration_owner_id, property_id, entity_id FROM employees WHERE status <> 'terminated'");
        $children = [];
        foreach ($rows as $r) {
            if ($r['supervisor_id']) {
                $children[(int) $r['supervisor_id']][] = (int) $r['id'];
            }
        }
        $propertyIds = [];
        $entityIds = [];
        foreach (Db::all('SELECT scope, scope_id FROM user_scopes WHERE user_id = ?', [$uid]) as $s) {
            if ($s['scope'] === 'property') {
                $propertyIds[] = (int) $s['scope_id'];
            } else {
                $entityIds[] = (int) $s['scope_id'];
            }
        }
        if ($own) {
            $ids[$own] = true;
            // Everyone below them (iterative, cycle-safe)
            $stack = [$own];
            while ($stack) {
                $id = array_pop($stack);
                foreach ($children[$id] ?? [] as $child) {
                    if (!isset($ids[$child])) {
                        $ids[$child] = true;
                        $stack[] = $child;
                    }
                }
            }
            foreach ($rows as $r) {
                if ((int) $r['dotted_line_id'] === $own || (int) $r['celebration_owner_id'] === $own) {
                    $ids[(int) $r['id']] = true;
                }
            }
            foreach (Db::all('SELECT id FROM properties WHERE manager_id = ?', [$own]) as $p) {
                $propertyIds[] = (int) $p['id'];
            }
        }
        if ($propertyIds) {
            $propertyIds = array_values(array_unique($propertyIds));
            foreach ($rows as $r) {
                if (in_array((int) $r['property_id'], $propertyIds, true)) {
                    $ids[(int) $r['id']] = true;
                }
            }
            foreach (Db::all('SELECT employee_id FROM employee_properties WHERE property_id IN (' . Db::in($propertyIds) . ')', $propertyIds) as $ep) {
                $ids[(int) $ep['employee_id']] = true;
            }
        }
        if ($entityIds) {
            foreach ($rows as $r) {
                if (in_array((int) $r['entity_id'], $entityIds, true)) {
                    $ids[(int) $r['id']] = true;
                }
            }
        }
        // Only people still on the team (terminated rows were excluded above)
        $active = array_flip(array_map(static fn ($r) => (int) $r['id'], $rows));
        $ids = array_values(array_filter(array_keys($ids), static fn ($id) => isset($active[$id])));
        sort($ids);
        return self::$visibleCache[$uid] = $ids;
    }

    public static function canSee(array $user, int $employeeId): bool
    {
        $ids = self::visibleIds($user);
        return $ids === null || in_array($employeeId, $ids, true);
    }

    /** SQL fragment + params restricting an employees query (alias e) to what the user may see. */
    public static function scopeSql(array $user, string $alias = 'e'): array
    {
        $ids = self::visibleIds($user);
        if ($ids === null) {
            return ['1=1', []];
        }
        if (!$ids) {
            return ['1=0', []];
        }
        return ["$alias.id IN (" . Db::in($ids) . ')', $ids];
    }
}
