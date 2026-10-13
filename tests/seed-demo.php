<?php
// Fills a database with FICTIONAL people for demos, screenshots and training.
// Never run this against the production database.
//   DC_DB_NAME=dwell_demo php tests/seed-demo.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Dwell\Core\Auth;
use Dwell\Core\Db;
use Dwell\Core\Settings;
use Dwell\Core\Time;
use Dwell\Employees\Employees;
use Dwell\Org\Organization;

if (!\Dwell\Core\App::config('debug')) {
    exit("Demo data is only for development copies: set 'debug' => true in config/config.php first.\n");
}
if ((int) Db::value('SELECT COUNT(*) FROM employees') > 0 && !in_array('--force', $argv, true)) {
    exit("This database already has people in it. Use --force to add demo data anyway.\n");
}

$now = Time::nowDb();
$admin = Db::value("SELECT id FROM users WHERE email = 'admin@example.com'");
if (!$admin) {
    $admin = Db::insert('users', ['email' => 'admin@example.com', 'name' => 'Avery Admin', 'role' => 'system_admin', 'password_hash' => Auth::hashPassword('dwell-demo-2026'),
        'alerts' => 1, 'org_report' => 1, 'created_at' => $now, 'updated_at' => $now]);
}
Auth::actAs(Db::one('SELECT * FROM users WHERE id = ?', [$admin]));

$brands = [];
foreach (Db::all('SELECT id, name FROM brands') as $b) {
    $brands[$b['name']] = (int) $b['id'];
}
$dwell = $brands['Dwell Communities'] ?? Organization::save('brands', ['name' => 'Dwell Communities', 'color' => '#1F3D4F']);
$place = $brands['58 Place'] ?? Organization::save('brands', ['name' => '58 Place', 'color' => '#8A4B6E']);

$ent = [
    'dwell' => Organization::save('entities', ['name' => 'Dwell Communities, LLC', 'kind' => 'employer', 'brand_id' => $dwell]),
    'place' => Organization::save('entities', ['name' => '58 Place, LLC', 'kind' => 'employer', 'brand_id' => $place]),
    '1099'  => Organization::save('entities', ['name' => 'Independent contractors (1099)', 'kind' => 'contractor']),
];
$prop = [];
foreach ([
    ['Corporate Office', 'office', $dwell], ['Maple Commons', 'property', $dwell], ['Riverside Lofts', 'property', $dwell],
    ['Oak Hill Apartments', 'property', $dwell], ['The Foundry', 'property', $place], ['Parkview Flats', 'property', $place], ['Landscape Crew', 'team', $dwell],
] as [$name, $kind, $brand]) {
    $prop[$name] = Organization::save('properties', ['name' => $name, 'kind' => $kind, 'brand_id' => $brand]);
}
$dept = [];
foreach (['Leadership', 'Property Management', 'Maintenance', 'Leasing', 'Accounting', 'Landscape'] as $d) {
    $dept[$d] = Organization::save('departments', ['name' => $d]);
}

// Dates are relative to today so the demo always has celebrations coming up.
$today = Time::today();
$md = static function (int $offsetDays) use ($today): array {
    $d = Time::addDays($today, $offsetDays);
    return [(int) substr($d, 5, 2), (int) substr($d, 8, 2)];
};
$hire = static function (int $years, int $offsetDays) use ($today): string {
    $d = new DateTimeImmutable(Time::addDays($today, $offsetDays));
    return $d->modify("-$years years")->format('Y-m-d');
};

// [key, first, last, preferred, title, property, department, entity, supervisor key, hire, birthday offset, extras]
$people = [
    ['ceo', 'Margaret', 'Holloway', null, 'Chief Executive Officer', 'Corporate Office', 'Leadership', 'dwell', null, $hire(14, 40), 12],
    ['coo', 'Daniel', 'Okafor', 'Dan', 'Chief Operating Officer', 'Corporate Office', 'Leadership', 'dwell', 'ceo', $hire(9, 3), 75],
    ['cfo', 'Priya', 'Raman', null, 'Chief Financial Officer', 'Corporate Office', 'Leadership', 'dwell', 'ceo', $hire(6, -120), 160],
    ['vp58', 'Jordan', 'Ellis', null, 'Vice President, 58 Place', 'Corporate Office', 'Leadership', 'place', 'ceo', $hire(4, 18), -30],
    ['ctrl', 'Helen', 'Marsh', null, 'Controller', 'Corporate Office', 'Accounting', 'dwell', 'cfo', $hire(5, 9), 4],
    ['ap', 'Luis', 'Navarro', null, 'Accounts Payable Specialist', 'Corporate Office', 'Accounting', 'dwell', 'ctrl', $hire(2, 60), 21],
    ['rm', 'Grace', 'Whitfield', null, 'Regional Manager', 'Corporate Office', 'Property Management', 'dwell', 'coo', $hire(7, 25), 33],
    ['pm1', 'Samuel', 'Brooks', 'Sam', 'Property Manager', 'Maple Commons', 'Property Management', 'dwell', 'rm', $hire(3, 1), 90],
    ['pm2', 'Elena', 'Vasquez', null, 'Property Manager', 'Riverside Lofts', 'Property Management', 'dwell', 'rm', $hire(5, 45), 2],
    ['pm3', 'Marcus', 'Bell', null, 'Property Manager', 'Oak Hill Apartments', 'Property Management', 'dwell', 'rm', $hire(1, 14), 110],
    ['pm4', 'Naomi', 'Fischer', null, 'Property Manager', 'The Foundry', 'Property Management', 'place', 'vp58', $hire(2, 5), 27],
    ['pm5', 'Theo', 'Lindqvist', null, 'Property Manager', 'Parkview Flats', 'Property Management', 'place', 'vp58', $hire(1, 70), 8],
    ['ms1', 'Ray', 'Dominguez', null, 'Maintenance Supervisor', 'Maple Commons', 'Maintenance', 'dwell', 'pm1', $hire(8, 6), 15],
    ['mt1', 'Kofi', 'Mensah', null, 'Maintenance Technician', 'Maple Commons', 'Maintenance', 'dwell', 'ms1', $hire(2, 0), 50],
    ['mt2', 'Andre', 'Silva', null, 'Maintenance Technician', 'Maple Commons', 'Maintenance', 'dwell', 'ms1', $hire(1, 11), 3],
    ['la1', 'Tessa', 'Morgan', null, 'Leasing Consultant', 'Maple Commons', 'Leasing', 'dwell', 'pm1', $hire(1, 19), 140],
    ['mt3', 'Wesley', 'Grant', null, 'Maintenance Technician', 'Riverside Lofts', 'Maintenance', 'dwell', 'pm2', $hire(4, 2), 22],
    ['la2', 'Isabel', 'Cho', null, 'Leasing Manager', 'Riverside Lofts', 'Leasing', 'dwell', 'pm2', $hire(3, 28), 7],
    ['mt4', 'Omar', 'Haddad', null, 'Maintenance Technician', 'Oak Hill Apartments', 'Maintenance', 'dwell', 'pm3', $hire(6, 4), 300, ['birthday_status' => 'needs_review']],
    ['la3', 'Bethany', 'Price', 'Beth', 'Leasing Consultant', 'Oak Hill Apartments', 'Leasing', 'dwell', 'pm3', $hire(1, 3), null],
    ['mt5', 'Felix', 'Romero', null, 'Maintenance Lead', 'The Foundry', 'Maintenance', 'place', 'pm4', $hire(3, 16), 19],
    ['la4', 'Chloe', 'Adeyemi', null, 'Leasing Consultant', 'The Foundry', 'Leasing', 'place', 'pm4', $hire(2, 33), 5],
    ['mt6', 'Victor', 'Petrov', null, 'Maintenance Technician', 'Parkview Flats', 'Maintenance', 'place', 'pm5', $hire(1, 8), 26],
    ['la5', 'Hannah', 'Kim', null, 'Assistant Property Manager', 'Parkview Flats', 'Property Management', 'place', 'pm5', $hire(2, 2), 1],
    ['ls1', 'Mateo', 'Ruiz', null, 'Landscape Lead', 'Landscape Crew', 'Landscape', 'dwell', 'coo', $hire(5, 12), 9],
    ['ls2', 'Javier', 'Ortega', null, 'Groundskeeper', 'Landscape Crew', 'Landscape', 'dwell', 'ls1', $hire(1, 24), 37],
    ['ls3', 'Pedro', 'Alves', null, 'Groundskeeper', 'Landscape Crew', 'Landscape', '1099', 'ls1', $hire(2, 6), 12, ['classification' => 'contractor']],
    ['mt7', 'Calvin', 'Reed', null, 'HVAC Technician', 'Riverside Lofts', 'Maintenance', '1099', null, $hire(1, 30), 44, ['classification' => 'contractor']],
    ['hr', 'Rosa', 'Delgado', null, 'People & Culture Manager', 'Corporate Office', 'Leadership', 'dwell', 'coo', $hire(3, 7), 18],
    ['new', 'Imani', 'Carter', null, 'Leasing Consultant', 'Riverside Lofts', 'Leasing', 'dwell', null, Time::addDays($today, 6), 62],
];

$ids = [];
foreach ($people as $p) {
    [$key, $first, $last, $pref, $title, $propName, $deptName, $entKey, , $hireDate, $bOffset] = $p;
    $extra = $p[11] ?? [];
    $in = [
        'first_name' => $first, 'last_name' => $last, 'preferred_name' => $pref, 'job_title' => $title,
        'work_email' => strtolower(($pref ?: $first) . '.' . $last) . '@example.com',
        'property_id' => $prop[$propName], 'department_id' => $dept[$deptName], 'entity_id' => $ent[$entKey],
        'classification' => $extra['classification'] ?? 'full_time', 'status' => 'active', 'hire_date' => $hireDate,
    ];
    if ($bOffset !== null) {
        [$m, $d] = $md($bOffset);
        $in += ['birth_month' => $m, 'birth_day' => $d, 'birthday_status' => $extra['birthday_status'] ?? 'ok'];
    }
    if (in_array($key, ['ls2', 'ls3', 'mt7'], true)) {
        unset($in['work_email']);
    }
    $ids[$key] = Employees::save($in, 'demo data');
}
foreach ($people as $p) {
    if ($p[8]) {
        Employees::save(['id' => $ids[$p[0]], 'supervisor_id' => $ids[$p[8]]], 'demo data');
    }
}
// Property managers are the fallback celebration owners.
foreach (['Maple Commons' => 'pm1', 'Riverside Lofts' => 'pm2', 'Oak Hill Apartments' => 'pm3', 'The Foundry' => 'pm4', 'Parkview Flats' => 'pm5', 'Corporate Office' => 'coo', 'Landscape Crew' => 'ls1'] as $pn => $k) {
    Db::update('properties', ['manager_id' => $ids[$k]], 'id = :id', ['id' => $prop[$pn]]);
}
Employees::save(['id' => $ids['hr'], 'dotted_line_id' => $ids['cfo']], 'demo data');
foreach (['ceo', 'coo', 'cfo', 'ctrl', 'rm', 'pm1', 'pm2'] as $k) {
    Employees::setVerified($ids[$k], true, false);
}

$mk = static function (string $email, string $name, string $role, ?int $emp, array $extra = []) use ($now) {
    if (!Db::value('SELECT id FROM users WHERE email = ?', [$email])) {
        Db::insert('users', ['email' => $email, 'name' => $name, 'role' => $role, 'employee_id' => $emp, 'password_hash' => Auth::hashPassword('dwell-demo-2026'),
            'created_at' => $now, 'updated_at' => $now] + $extra);
    }
};
$mk('margaret.holloway@example.com', 'Margaret Holloway', 'executive', $ids['ceo'], ['org_report' => 1]);
$mk('sam.brooks@example.com', 'Sam Brooks', 'manager', $ids['pm1']);
$mk('grace.whitfield@example.com', 'Grace Whitfield', 'manager', $ids['rm']);
Settings::set('test_user_id', (int) $admin);
Employees::flush();
echo 'Demo data added: ' . count($people) . " fictional people.\n";
echo "Sign in as admin@example.com / dwell-demo-2026 (also margaret.holloway@, sam.brooks@ and grace.whitfield@example.com).\n";
