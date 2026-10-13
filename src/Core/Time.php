<?php
declare(strict_types=1);

namespace Dwell\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Timestamps are stored in UTC. Celebration dates are calendar dates evaluated in the
 * organization's time zone (America/New_York), so "today" means today in Atlanta.
 */
final class Time
{
    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** Tests can freeze "now" by setting this. */
    public static ?DateTimeImmutable $frozenNow = null;

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone((string) (Settings::get('timezone') ?: 'America/New_York'));
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public static function now(): DateTimeImmutable
    {
        return self::$frozenNow ?? new DateTimeImmutable('now', self::utc());
    }

    public static function nowDb(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function nowLocal(): DateTimeImmutable
    {
        return self::now()->setTimezone(self::tz());
    }

    public static function today(): string
    {
        return self::nowLocal()->format('Y-m-d');
    }

    /** Minutes after local midnight right now. */
    public static function nowMinutes(): int
    {
        $n = self::nowLocal();
        return (int) $n->format('G') * 60 + (int) $n->format('i');
    }

    public static function isDate(?string $date): bool
    {
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    public static function diffDays(string $from, string $to): int
    {
        return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
    }

    public static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    public static function monthStart(string $date): string
    {
        return substr($date, 0, 8) . '01';
    }

    public static function monthEnd(string $date): string
    {
        return (new DateTimeImmutable(self::monthStart($date)))->modify('last day of this month')->format('Y-m-d');
    }

    public static function toMinutes(string $time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    // ---- Human formatting (emails) ----

    /** "Thursday, October 9" (year added when not this year). */
    public static function fmtDate(string $date, bool $withYear = false): string
    {
        $d = new DateTimeImmutable($date);
        $sameYear = $d->format('Y') === self::nowLocal()->format('Y');
        return $d->format(($withYear || !$sameYear) ? 'l, F j, Y' : 'l, F j');
    }

    /** "Oct 9" */
    public static function fmtShort(string $date): string
    {
        return (new DateTimeImmutable($date))->format('M j');
    }

    /** "October 9" from month/day only, never a year. */
    public static function fmtMonthDay(?int $month, ?int $day): string
    {
        if (!$month || !$day) {
            return '';
        }
        return self::MONTHS[$month - 1] . ' ' . $day;
    }

    public static function monthName(string $date): string
    {
        return self::MONTHS[(int) substr($date, 5, 2) - 1];
    }

    public static function fmtTime(int $minutes): string
    {
        $h = intdiv($minutes, 60) % 24;
        $m = $minutes % 60;
        return sprintf('%d:%02d %s', $h % 12 === 0 ? 12 : $h % 12, $m, $h >= 12 ? 'PM' : 'AM');
    }
}
