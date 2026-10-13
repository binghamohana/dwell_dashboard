<?php
declare(strict_types=1);

namespace Dwell\Employees;

use Dwell\Core\Settings;

/**
 * Who is responsible for celebrating each person ("celebration owner").
 *
 * In order:
 *   1. The owner named on their record (an explicit override), if still valid.
 *   2. Their direct supervisor.
 *   3. The manager of their primary property.
 *   4. If the supervisor's position is vacant or they can't receive email, the next manager
 *      up the reporting chain (Settings → owner_escalate).
 *   5. Nobody: the celebration is flagged and the designated administrators are alerted.
 *
 * A valid owner is an active employee with a work email who is not the person being celebrated.
 * Owners are worked out at send time, so changing a supervisor changes routing immediately.
 */
final class Owners
{
    public static function isValid(?array $candidate, array $person): bool
    {
        return $candidate !== null
            && $candidate['status'] === 'active'
            && !empty($candidate['work_email'])
            && (int) $candidate['id'] !== (int) $person['id'];
    }

    /**
     * @param array $person   employee row
     * @param array $byId     all employee rows keyed by id
     * @param array $properties property rows keyed by id
     * @return array{id: ?int, source: ?string, note: ?string}
     */
    public static function resolve(array $person, array $byId, array $properties): array
    {
        $notes = [];
        $get = static fn ($id) => $id ? ($byId[(int) $id] ?? null) : null;
        $why = static function (?array $c) {
            if (!$c) {
                return 'is not in the system';
            }
            if ($c['status'] !== 'active') {
                return 'is no longer active';
            }
            return 'has no work email';
        };

        if ($person['celebration_owner_id']) {
            $o = $get($person['celebration_owner_id']);
            if (self::isValid($o, $person)) {
                return ['id' => (int) $o['id'], 'source' => 'assigned', 'note' => null];
            }
            $notes[] = 'Assigned owner ' . ($o ? Employees::displayName($o) . ' ' : '') . $why($o);
        }

        $sup = $get($person['supervisor_id']);
        if ($sup && self::isValid($sup, $person)) {
            return ['id' => (int) $sup['id'], 'source' => 'supervisor', 'note' => $notes ? implode('; ', $notes) : null];
        }
        if ($person['supervisor_id']) {
            $notes[] = 'Supervisor ' . ($sup ? Employees::displayName($sup) . ' ' : '') . $why($sup);
        }

        $prop = $person['property_id'] ? ($properties[(int) $person['property_id']] ?? null) : null;
        if ($prop && $prop['manager_id']) {
            $pm = $get($prop['manager_id']);
            if (self::isValid($pm, $person)) {
                return ['id' => (int) $pm['id'], 'source' => 'property_manager', 'note' => $notes ? implode('; ', $notes) : null];
            }
        }

        if ($sup && Settings::get('owner_escalate')) {
            $seen = [(int) $person['id'] => true];
            $cur = $sup;
            for ($i = 0; $cur && $i < 15; $i++) {
                if (isset($seen[(int) $cur['id']])) {
                    break; // cycle guard
                }
                $seen[(int) $cur['id']] = true;
                if (self::isValid($cur, $person)) {
                    return ['id' => (int) $cur['id'], 'source' => 'escalated', 'note' => implode('; ', $notes)];
                }
                $cur = $get($cur['supervisor_id']);
            }
        }

        if (!$person['supervisor_id'] && !$person['celebration_owner_id']) {
            $notes[] = 'No supervisor recorded';
        }
        return ['id' => null, 'source' => null, 'note' => implode('; ', $notes) ?: 'No owner could be found'];
    }

    public const SOURCES = [
        'assigned'         => 'Assigned owner',
        'supervisor'       => 'Direct supervisor',
        'property_manager' => 'Property manager',
        'escalated'        => 'Next manager up (supervisor unavailable)',
    ];
}
