<?php
declare(strict_types=1);

namespace Dwell\Core;

/**
 * Schema changes after the first release go here, so `php bin/install.php` brings any
 * existing database up to date. Each step must be safe to run more than once.
 */
final class Migrations
{
    public static function run(): array
    {
        $done = [];
        // Seed the two brands named in the brief (editable under Organization).
        if (!Db::value('SELECT COUNT(*) FROM brands')) {
            foreach ([['Dwell Communities', '#1F3D4F', 1], ['58 Place', '#8A4B6E', 2]] as [$name, $color, $sort]) {
                Db::insert('brands', ['name' => $name, 'color' => $color, 'sort' => $sort, 'created_at' => Time::nowDb(), 'updated_at' => Time::nowDb()]);
                $done[] = "added company $name";
            }
        }
        Db::run("INSERT IGNORE INTO settings (name, value) VALUES ('next_employee_number', '1')");
        return $done;
    }
}
