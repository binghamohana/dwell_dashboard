<?php
declare(strict_types=1);

namespace Dwell\Celebrations;

use Dwell\Core\Settings;
use Dwell\Core\Time;

/**
 * Celebration date rules (documented in docs/POLICIES.md):
 *
 * - February 29 birthdays and hire dates are celebrated on February 28 in non-leap years
 *   (Settings → feb29_rule can switch this to March 1).
 * - Work anniversaries count completed years from the most recent hire date, so a rehire starts
 *   again from the rehire date (Settings → anniversary_from can use the original hire date instead).
 *   The first celebration is the 1-year anniversary; the hire date itself is not an anniversary.
 * - Service years are completed years: they change on the day the anniversary is celebrated.
 * - Dates are calendar dates in the organization's time zone; no year of birth or age is ever used.
 */
final class Rules
{
    public static function validMonthDay(int $month, int $day): bool
    {
        // 2000 is a leap year, so February 29 is accepted.
        return $month >= 1 && $month <= 12 && $day >= 1 && checkdate($month, $day, 2000);
    }

    /** The date a month/day falls on in a given year, applying the February 29 rule. */
    public static function occurrence(int $month, int $day, int $year, ?string $feb29Rule = null): string
    {
        if ($month === 2 && $day === 29 && !Time::isLeapYear($year)) {
            return ($feb29Rule ?? (string) Settings::get('feb29_rule')) === 'mar1'
                ? sprintf('%04d-03-01', $year)
                : sprintf('%04d-02-28', $year);
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** The date service is counted from, per policy. */
    public static function serviceStart(array $employee): ?string
    {
        $hire = $employee['hire_date'] ?? null;
        if (Settings::get('anniversary_from') === 'original_hire_date' && !empty($employee['original_hire_date'])) {
            return $employee['original_hire_date'];
        }
        return $hire ?: null;
    }

    /** Completed years of service on $on (0 before the first anniversary; null before the start date). */
    public static function serviceYears(string $start, string $on, ?string $feb29Rule = null): ?int
    {
        if ($on < $start) {
            return null;
        }
        $sy = (int) substr($start, 0, 4);
        $sm = (int) substr($start, 5, 2);
        $sd = (int) substr($start, 8, 2);
        $oy = (int) substr($on, 0, 4);
        $years = $oy - $sy;
        if ($years > 0 && self::occurrence($sm, $sd, $oy, $feb29Rule) > $on) {
            $years--;
        }
        return max(0, $years);
    }

    /**
     * Birthday dates between $from and $to (inclusive) for a month/day.
     * @return string[]
     */
    public static function birthdayDates(int $month, int $day, string $from, string $to, ?string $feb29Rule = null): array
    {
        $out = [];
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            $d = self::occurrence($month, $day, $y, $feb29Rule);
            if ($d >= $from && $d <= $to) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * Work anniversaries between $from and $to (inclusive) for a service start date.
     * @return array<int, array{date: string, years: int}>
     */
    public static function anniversaryDates(string $start, string $from, string $to, ?string $feb29Rule = null): array
    {
        $out = [];
        $sy = (int) substr($start, 0, 4);
        $sm = (int) substr($start, 5, 2);
        $sd = (int) substr($start, 8, 2);
        for ($y = max($sy + 1, (int) substr($from, 0, 4)); $y <= (int) substr($to, 0, 4); $y++) {
            $d = self::occurrence($sm, $sd, $y, $feb29Rule);
            if ($d >= $from && $d <= $to) {
                $out[] = ['date' => $d, 'years' => $y - $sy];
            }
        }
        return $out;
    }

    public static function ordinalYears(int $years): string
    {
        return $years . '-Year';
    }
}
