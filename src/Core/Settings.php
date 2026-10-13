<?php
declare(strict_types=1);

namespace Dwell\Core;

/**
 * Admin-editable settings, stored in the `settings` table.
 * Defaults live here, so adding a setting needs no database migration.
 */
final class Settings
{
    public const MONTHLY_INTRO = "Here are the birthdays and work anniversaries coming up for your team this month. "
        . "A handwritten card, a shout-out at the team meeting, or a simple \"happy birthday\" goes a long way. "
        . "Thank you for helping everyone here feel they belong.";

    public const DAY_OF_NOTE = "Take a moment today to celebrate them — a quick note, a call, or a word at the team huddle. "
        . "Small gestures make a big difference.";

    /** name => [type, default, extra, group] */
    private const SCHEMA = [
        // General
        'org_name'             => ['string', 'Dwell Communities'],
        'portal_name'          => ['string', 'Dwell Central'],
        'timezone'             => ['timezone', 'America/New_York'],

        // Celebration policy
        'feb29_rule'           => ['enum', 'feb28', ['feb28', 'mar1']],                    // Feb 29 dates in non-leap years
        'anniversary_from'     => ['enum', 'hire_date', ['hire_date', 'original_hire_date']], // most recent hire, or first hire for rehires
        'celebrate_contractors' => ['bool', false],                                        // 1099 / vendor records
        'store_birth_year'     => ['bool', false],                                         // keep the year of birth (off = month/day only)

        // Notifications (the mode itself changes only through Notifier::setMode)
        'send_time'            => ['time', '08:00'],
        'monthly_enabled'      => ['bool', true],
        'monthly_send_empty'   => ['bool', false],
        'monthly_intro'        => ['text', self::MONTHLY_INTRO],
        'advance_enabled'      => ['bool', true],
        'advance_days'         => ['int', 3, [1, 30]],
        'day_of_enabled'       => ['bool', true],
        'day_of_note'          => ['text', self::DAY_OF_NOTE],
        'notify_dotted_line'   => ['bool', false],
        'owner_escalate'       => ['bool', true],     // vacant supervisor → the next manager up
        'test_user_id'         => ['int', 0, [0, 4294967295]], // administrator who receives test-mode email (0 = record only)
        'monthly_catchup_days' => ['int', 3, [0, 10]],

        // Data
        'import_retention_days'       => ['int', 30, [1, 365]],
        'notification_retention_days' => ['int', 730, [30, 3650]],

        // Internal
        'notify_mode'          => ['internal', 'test'],   // off | test | live
        'notify_mode_changed'  => ['internal', ''],
        'cron_last_run'        => ['internal', ''],
        'next_employee_number' => ['internal', '1'],
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $values = [];
            foreach (self::SCHEMA as $name => $def) {
                $values[$name] = $def[1];
            }
            foreach (Db::all('SELECT name, value FROM settings') as $row) {
                if (isset(self::SCHEMA[$row['name']])) {
                    $values[$row['name']] = self::cast($row['name'], $row['value']);
                }
            }
            self::$cache = $values;
        }
        return self::$cache;
    }

    public static function get(string $name)
    {
        return self::all()[$name] ?? null;
    }

    /** Settings an admin may edit (everything except internal values). */
    public static function editable(): array
    {
        return array_filter(
            self::all(),
            static fn ($name) => self::SCHEMA[$name][0] !== 'internal',
            ARRAY_FILTER_USE_KEY
        );
    }

    public static function set(string $name, $value): void
    {
        Db::run(
            'INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, is_bool($value) ? ($value ? '1' : '0') : (string) $value, Time::nowDb()]
        );
        self::$cache = null;
    }

    /** Validate and save a batch of admin edits. Unknown and internal keys are ignored. Returns the changes. */
    public static function saveMany(array $input): array
    {
        $current = self::all();
        $clean = [];
        foreach ($input as $name => $raw) {
            if (!isset(self::SCHEMA[$name]) || self::SCHEMA[$name][0] === 'internal') {
                continue;
            }
            $value = self::validate($name, $raw);
            if ($value !== $current[$name]) {
                $clean[$name] = $value;
            }
        }
        foreach ($clean as $name => $value) {
            self::set($name, $value);
        }
        return $clean;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function validate(string $name, $raw)
    {
        [$type, , $extra] = self::SCHEMA[$name] + [2 => null];
        $label = ucfirst(str_replace('_', ' ', $name));
        switch ($type) {
            case 'bool':
                return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            case 'int':
                if (!is_numeric($raw)) {
                    throw new AppError("$label must be a number.", 400, $name);
                }
                $int = (int) $raw;
                if ($extra && ($int < $extra[0] || $int > $extra[1])) {
                    throw new AppError("$label must be between {$extra[0]} and {$extra[1]}.", 400, $name);
                }
                return $int;
            case 'enum':
                $raw = (string) $raw;
                if (!in_array($raw, $extra, true)) {
                    throw new AppError("$label has an invalid value.", 400, $name);
                }
                return $raw;
            case 'time':
                $raw = trim((string) $raw);
                $min = Time::toMinutes($raw);
                if ($min === null) {
                    throw new AppError('Use a time like 08:00.', 400, $name);
                }
                return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
            case 'timezone':
                if (!in_array($raw, \DateTimeZone::listIdentifiers(), true)) {
                    throw new AppError('Unknown time zone.', 400, $name);
                }
                return $raw;
            case 'text':
                return Util::cleanText((string) $raw, 2000, true);
            default:
                $value = Util::cleanText((string) $raw, 190);
                if ($value === '') {
                    throw new AppError("$label can’t be empty.", 400, $name);
                }
                return $value;
        }
    }

    private static function cast(string $name, ?string $value)
    {
        return match (self::SCHEMA[$name][0]) {
            'bool' => $value === '1',
            'int'  => (int) $value,
            default => (string) $value,
        };
    }
}
