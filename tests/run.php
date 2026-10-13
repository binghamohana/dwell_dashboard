<?php
// Dwell Central acceptance tests. Needs a separate, disposable test database:
//   DC_DB_NAME=dwell_test php tests/run.php
// Every run drops and recreates all tables in that database.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
if (!str_contains((string) getenv('DC_DB_NAME'), 'test')) {
    exit("Refusing to run: set DC_DB_NAME to a database whose name contains \"test\" (it is wiped).\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Dwell\Api\Router;
use Dwell\Celebrations\Celebrations;
use Dwell\Celebrations\Rules;
use Dwell\Core\Access;
use Dwell\Core\App;
use Dwell\Core\AppError;
use Dwell\Core\Auth;
use Dwell\Core\Db;
use Dwell\Core\Mailer;
use Dwell\Core\Migrations;
use Dwell\Core\Settings;
use Dwell\Core\Time;
use Dwell\Employees\Employees;
use Dwell\Import\Importer;
use Dwell\Import\SpreadsheetReader;
use Dwell\Notifications\Notifier;
use Dwell\Org\Organization;

App::setConfig('mail.transport', 'log');
$pass = 0;
$fail = 0;
$section = '';
function section(string $name): void { global $section; $section = $name; echo "\n$name\n"; }
function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label\n"; }
}
function eq($a, $b, string $label): void
{
    ok($a === $b, $label . ($a === $b ? '' : ' (got ' . var_export($a, true) . ', expected ' . var_export($b, true) . ')'));
}
function throws(callable $fn, string $label, ?int $status = null): void
{
    try {
        $fn();
        ok(false, $label . ' (no error)');
    } catch (AppError $e) {
        ok($status === null || $e->status === $status, $label . ($status !== null && $e->status !== $status ? " (status {$e->status})" : ''));
    }
}
function reset_db(): void
{
    $pdo = Db::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $sql = (string) file_get_contents(DC_ROOT . '/sql/schema.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $st) {
        $pdo->exec($st);
    }
    Migrations::run();
    Settings::flush();
    Organization::flush();
    Employees::flush();
}
function at(string $localDateTime): void
{
    Time::$frozenNow = (new DateTimeImmutable($localDateTime, new DateTimeZone('America/New_York')))->setTimezone(new DateTimeZone('UTC'));
}
function user(string $email, string $role, ?int $employeeId = null, array $extra = []): array
{
    $id = Db::insert('users', ['email' => $email, 'name' => ucfirst(strtok($email, '@')), 'role' => $role, 'employee_id' => $employeeId,
        'password_hash' => password_hash('correct horse battery', PASSWORD_DEFAULT), 'created_at' => Time::nowDb(), 'updated_at' => Time::nowDb()] + $extra);
    Access::flush();
    return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
}
function person(array $in): int
{
    static $n = 0;
    $n++;
    $in += ['first_name' => 'Person' . $n, 'last_name' => 'Test', 'status' => 'active', 'hire_date' => '2020-01-15', 'classification' => 'full_time'];
    $id = Employees::save($in, 'test');
    Employees::flush();
    return $id;
}
function owner_of(int $id): ?int
{
    Employees::flush();
    return Employees::owner(Employees::row($id))['id'];
}
function count_n(string $where = '1=1', array $p = []): int
{
    return (int) Db::value("SELECT COUNT(*) FROM notifications WHERE $where", $p);
}

reset_db();
at('2026-10-13 10:00');
$admin = user('admin@test.dev', 'system_admin', null, ['alerts' => 1]);
Auth::actAs($admin);

// ===================================================================== date rules
section('Birthday, anniversary and service-year rules');
eq(Rules::occurrence(2, 29, 2027, 'feb28'), '2027-02-28', 'Feb 29 birthday is celebrated on Feb 28 in a non-leap year');
eq(Rules::occurrence(2, 29, 2027, 'mar1'), '2027-03-01', '…or on Mar 1 when the policy says so');
eq(Rules::occurrence(2, 29, 2028, 'feb28'), '2028-02-29', 'Feb 29 is used in leap years');
eq(Rules::serviceYears('2020-03-15', '2026-03-14'), 5, 'Service years: day before the anniversary');
eq(Rules::serviceYears('2020-03-15', '2026-03-15'), 6, 'Service years: on the anniversary');
eq(Rules::serviceYears('2026-11-01', '2026-10-13'), null, 'No service years before the start date');
eq(Rules::serviceYears('2024-02-29', '2025-02-27', 'feb28'), 0, 'Leap-day hire: not yet a year on Feb 27');
eq(Rules::serviceYears('2024-02-29', '2025-02-28', 'feb28'), 1, 'Leap-day hire: one year on Feb 28 of a non-leap year');
eq(Rules::anniversaryDates('2021-10-21', '2026-10-01', '2026-10-31'), [['date' => '2026-10-21', 'years' => 5]], 'Anniversary date and years');
eq(Rules::anniversaryDates('2026-10-05', '2026-10-01', '2026-10-31'), [], 'The hire date itself is not an anniversary');
eq(Rules::birthdayDates(1, 2, '2026-12-20', '2027-01-10'), ['2027-01-02'], 'Birthday ranges cross the new year');
ok(Rules::validMonthDay(2, 29) && !Rules::validMonthDay(2, 30) && !Rules::validMonthDay(13, 1), 'Month/day validation (Feb 29 allowed, Feb 30 not)');

// ===================================================================== organization & people
$brand = (int) Db::value("SELECT id FROM brands WHERE name = 'Dwell Communities'");
$entity = Organization::save('entities', ['name' => 'Dwell Communities, LLC', 'brand_id' => $brand]);
$contractors = Organization::save('entities', ['name' => 'Independent contractors (1099)', 'kind' => 'contractor']);
$maple = Organization::save('properties', ['name' => 'Maple Commons', 'aliases' => "MAPLE\nMaple Cmns"]);
$oak = Organization::save('properties', ['name' => 'Oak Hill']);
eq(Organization::match('properties', 'maple cmns'), $maple, 'Property spellings match through aliases');
eq(Organization::match('properties', '  MAPLE  '), $maple, 'Matching ignores case and spacing');

$ceo = person(['first_name' => 'Cara', 'last_name' => 'Chief', 'work_email' => 'cara@test.dev', 'entity_id' => $entity, 'property_id' => $oak]);
$rm = person(['first_name' => 'Rhea', 'last_name' => 'Regional', 'work_email' => 'rhea@test.dev', 'supervisor_id' => $ceo, 'entity_id' => $entity]);
$pm = person(['first_name' => 'Pat', 'last_name' => 'Manager', 'work_email' => 'pat@test.dev', 'supervisor_id' => $rm, 'property_id' => $maple, 'entity_id' => $entity]);
$tech = person(['first_name' => 'Tess', 'last_name' => 'Tech', 'supervisor_id' => $pm, 'property_id' => $maple, 'birth_month' => 10, 'birth_day' => 16, 'birthday_status' => 'ok', 'hire_date' => '2021-10-20', 'entity_id' => $entity]);
$other = person(['first_name' => 'Otto', 'last_name' => 'Other', 'supervisor_id' => $rm, 'property_id' => $oak, 'birth_month' => 10, 'birth_day' => 20, 'birthday_status' => 'ok', 'entity_id' => $entity]);
Db::update('properties', ['manager_id' => $pm], 'id = :id', ['id' => $maple]);
Organization::flush();

section('Celebrations: only real, confirmed dates for active people');
$ev = Celebrations::events('2026-10-01', '2026-10-31');
$has = static fn (array $events, int $id, string $type) => (bool) array_filter($events, static fn ($e) => $e['employee_id'] === $id && $e['type'] === $type);
ok($has($ev, $tech, 'birthday') && $has($ev, $tech, 'anniversary'), 'Active person with confirmed dates gets a birthday and an anniversary');
eq(array_values(array_filter($ev, static fn ($e) => $e['employee_id'] === $tech && $e['type'] === 'anniversary'))[0]['years'] ?? null, 5, 'Anniversary counts completed years (5)');
$noBday = person(['first_name' => 'Nora', 'last_name' => 'Nodate', 'supervisor_id' => $pm, 'hire_date' => null]);
$review = person(['first_name' => 'Rex', 'last_name' => 'Review', 'supervisor_id' => $pm, 'birth_month' => 10, 'birth_day' => 18, 'birthday_status' => 'needs_review']);
$ev = Celebrations::events('2026-01-01', '2026-12-31');
ok(!array_filter($ev, static fn ($e) => $e['employee_id'] === $noBday), 'Missing birthday and hire date never create an event');
ok(!$has($ev, $review, 'birthday'), 'A birthday marked “needs review” is not celebrated');
$gone = person(['first_name' => 'Gina', 'last_name' => 'Gone', 'status' => 'terminated', 'term_date' => '2026-06-01', 'birth_month' => 10, 'birth_day' => 14, 'birthday_status' => 'ok']);
$leave = person(['first_name' => 'Lee', 'last_name' => 'Leave', 'status' => 'leave', 'birth_month' => 10, 'birth_day' => 14, 'birthday_status' => 'ok']);
$ev = Celebrations::events('2026-10-01', '2026-10-31');
ok(!$has($ev, $gone, 'birthday') && !$has($ev, $leave, 'birthday'), 'Former employees and people on leave are excluded');
$vendor = person(['first_name' => 'Vic', 'last_name' => 'Vendor', 'entity_id' => $contractors, 'classification' => 'contractor', 'birth_month' => 10, 'birth_day' => 22, 'birthday_status' => 'ok', 'supervisor_id' => $pm]);
ok(!$has(Celebrations::events('2026-10-01', '2026-10-31'), $vendor, 'birthday'), 'Contractors are not celebrated by default');
Settings::set('celebrate_contractors', true);
ok($has(Celebrations::events('2026-10-01', '2026-10-31'), $vendor, 'birthday'), '…and are once the setting allows it');
Settings::set('celebrate_contractors', false);
$newbie = person(['first_name' => 'Nia', 'last_name' => 'New', 'hire_date' => '2026-10-20', 'birth_month' => 10, 'birth_day' => 15, 'birthday_status' => 'ok']);
ok(!$has(Celebrations::events('2026-10-01', '2026-10-31'), $newbie, 'birthday'), 'No birthday before someone’s start date');
$rehire = person(['first_name' => 'Rita', 'last_name' => 'Rehire', 'hire_date' => '2024-10-25', 'original_hire_date' => '2015-10-25', 'supervisor_id' => $pm]);
$ann = array_values(array_filter(Celebrations::events('2026-10-01', '2026-10-31'), static fn ($e) => $e['employee_id'] === $rehire));
eq($ann[0]['years'] ?? null, 2, 'Rehire: anniversary counts from the most recent hire date');
Settings::set('anniversary_from', 'original_hire_date');
$ann = array_values(array_filter(Celebrations::events('2026-10-01', '2026-10-31'), static fn ($e) => $e['employee_id'] === $rehire));
eq($ann[0]['years'] ?? null, 11, '…or from the original hire date when the policy says so');
Settings::set('anniversary_from', 'hire_date');

// ===================================================================== owners
section('Celebration owners and routing');
eq(owner_of($tech), $pm, 'Owner defaults to the direct supervisor');
eq(owner_of($pm), $rm, 'A property manager’s owner is their supervisor');
Employees::save(['id' => $tech, 'supervisor_id' => $rm]);
eq(owner_of($tech), $rm, 'Changing the supervisor changes the owner');
$specs = Notifier::eventSpecs('day_of', '2026-10-16', '2026-10-16', '2026-10-16');
ok((bool) array_filter($specs, static fn ($s) => $s['to']['email'] === 'rhea@test.dev' && $s['items'][0]['employee_id'] === $tech), 'Reassignment updates email routing');
Employees::save(['id' => $tech, 'supervisor_id' => $pm]);
Employees::save(['id' => $pm, 'work_email' => null]);
eq(owner_of($tech), $rm, 'Supervisor without email: the next manager up (property manager is the same person)');
Employees::save(['id' => $pm, 'work_email' => 'pat@test.dev']);
$sup2 = person(['first_name' => 'Sam', 'last_name' => 'Sup', 'status' => 'terminated', 'term_date' => '2026-09-01', 'supervisor_id' => $rm, 'work_email' => 'sam@test.dev']);
$orphan = person(['first_name' => 'Ola', 'last_name' => 'Orphan', 'supervisor_id' => $sup2, 'property_id' => $maple, 'birth_month' => 10, 'birth_day' => 17, 'birthday_status' => 'ok']);
eq(owner_of($orphan), $pm, 'Vacant supervisor: the property manager takes over');
Db::update('properties', ['manager_id' => null], 'id = :id', ['id' => $maple]);
Organization::flush();
eq(owner_of($orphan), $rm, 'No property manager: the next manager up the chain');
Settings::set('owner_escalate', false);
eq(owner_of($orphan), null, 'With escalation off and nobody else: no owner');
Settings::set('owner_escalate', true);
Db::update('properties', ['manager_id' => $pm], 'id = :id', ['id' => $maple]);
Organization::flush();
Employees::save(['id' => $other, 'celebration_owner_id' => $pm]);
eq(owner_of($other), $pm, 'An assigned owner overrides the supervisor');
Employees::save(['id' => $pm, 'status' => 'leave']);
eq(owner_of($other), $rm, 'An assigned owner who is unavailable falls back to the supervisor');
Employees::save(['id' => $pm, 'status' => 'active']);
$lonely = person(['first_name' => 'Lou', 'last_name' => 'Lonely', 'birth_month' => 10, 'birth_day' => 15, 'birthday_status' => 'ok']);
eq(owner_of($lonely), null, 'Nobody to celebrate them: no owner');

// ===================================================================== notifications
section('Notifications: test mode, idempotency, alerts, retries');
eq(Notifier::mode(), 'test', 'Celebration emails start in TEST mode');
at('2026-10-13 07:30');
ok(isset(Notifier::runDaily()['skipped']), 'Nothing happens before the send time (8:00 AM Eastern)');
at('2026-10-13 08:05');
$r = Notifier::runDaily();
ok(!isset($r['skipped']) && $r['mode'] === 'test', 'The daily check runs after the send time');
$first = count_n();
ok($first > 0, "It prepared messages ($first)");
eq(count_n("mode = 'live'"), 0, 'Test mode never creates live messages');
eq(count_n("mode = 'test' AND to_email IS NOT NULL"), 0, 'With no test inbox chosen, nothing is delivered (recorded only)');
eq(count_n("status = 'held'"), $first, 'Every test message is held');
ok(Db::value("SELECT COUNT(*) FROM notifications WHERE subject NOT LIKE '[TEST]%'") === 0, 'Test messages are labelled [TEST]');
ok(isset(Notifier::runDaily()['skipped']), 'A second run the same day does nothing');
Notifier::runDaily(true);
eq(count_n(), $first, 'A forced re-run creates no duplicates');
$missing = Db::one("SELECT * FROM notifications WHERE kind = 'owner_missing'");
ok($missing && $missing['intended_email'] === 'admin@test.dev', 'Celebrations without an owner alert the designated administrator');
ok(str_contains($missing['body_text'], 'Lou Lonely'), 'The alert names the person');
at('2026-10-14 08:05');
Notifier::runDaily();
eq((int) Db::value("SELECT COUNT(*) FROM notification_items ni JOIN notifications n ON n.id = ni.notification_id WHERE n.kind = 'owner_missing' AND ni.employee_id = ?", [$lonely]), 1, 'The same missing owner is not alerted again the next day');
$dayOf = Db::one("SELECT * FROM notifications WHERE kind = 'advance' AND intended_email = 'pat@test.dev' AND idem_key LIKE ?", ['%birthday:' . $tech . ':2026-10-16%']);
ok((bool) $dayOf, 'Advance reminder (3 days before) goes to the owner');
ok(!preg_match('/\b(19|20)\d\d\b/', (string) Db::value("SELECT GROUP_CONCAT(body_text) FROM notifications WHERE kind IN ('advance','day_of') AND idem_key LIKE '%birthday%'")), 'Birthday emails contain no year of birth');

Settings::set('test_user_id', (int) $admin['id']);
at('2026-10-16 08:05');
Notifier::runDaily();
$t = Db::one("SELECT * FROM notifications WHERE kind = 'day_of' AND idem_key LIKE ?", ['test:day_of:birthday:' . $tech . ':2026-10-16%']);
ok($t && $t['to_email'] === 'admin@test.dev' && $t['intended_email'] === 'pat@test.dev', 'With a test inbox: delivered to the administrator, addressed to the real owner');
ok($t && $t['status'] === 'sent' && str_contains($t['body_html'], 'NOT sent to'), 'The test copy says who it would have gone to');
eq($t['subject'] ?? '', '[TEST] Today Is Tess’s Birthday!', 'Day-of subject');

throws(static fn () => Notifier::setMode($admin, 'live', 'yes'), 'Going live needs the typed confirmation');
Notifier::setMode($admin, 'live', 'GO LIVE');
eq(Notifier::mode(), 'live', 'An administrator can switch to live');
ok((bool) Db::value("SELECT id FROM audit_log WHERE action = 'notify_mode'"), 'Switching modes is audited');
Notifier::runDaily(true);
$live = Db::one("SELECT * FROM notifications WHERE mode = 'live' AND kind = 'day_of' AND intended_email = 'pat@test.dev'");
ok($live && $live['to_email'] === 'pat@test.dev', 'Live messages go to the real owner');
$liveCount = count_n("mode = 'live'");
Notifier::runDaily(true);
eq(count_n("mode = 'live'"), $liveCount, 'Live runs are idempotent too');

// Failures and retries
App::setConfig('mail.transport', 'fail');
at('2026-10-17 08:05');
Notifier::runDaily();
$f = Db::one("SELECT * FROM notifications WHERE mode = 'live' AND status = 'pending' AND attempts > 0 ORDER BY id DESC");
ok($f && str_contains((string) $f['last_error'], 'Simulated'), 'A failed delivery stays queued with the error');
for ($i = 0; $i < 6; $i++) {
    at('2026-10-17 ' . sprintf('%02d', 9 + $i * 2) . ':05');
    Mailer::sendDue();
}
eq(Db::value('SELECT status FROM notifications WHERE id = ?', [$f['id']]), 'failed', 'After ' . Mailer::MAX_ATTEMPTS . ' attempts it is marked failed');
App::setConfig('mail.transport', 'log');
eq(Notifier::alertFailures() > 0, true, 'Administrators are alerted about persistent failures');
eq((int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'failure_alert'"), 1, 'One alert per batch');
eq(Notifier::alertFailures(), 0, 'The same failure is not alerted twice');
Mailer::retry((int) $f['id']);
eq(Db::value('SELECT status FROM notifications WHERE id = ?', [$f['id']]), 'sent', 'Retry delivers once the mail server works');

// Monthly summary
$nova = person(['first_name' => 'Nova', 'last_name' => 'November', 'supervisor_id' => $pm, 'property_id' => $maple, 'birth_month' => 11, 'birth_day' => 5, 'birthday_status' => 'ok']);
Notifier::setMode($admin, 'test');
Settings::set('test_user_id', 0);
Db::run('UPDATE users SET org_report = 1 WHERE id = ?', [$admin['id']]);
$exec = user('exec@test.dev', 'executive', null, ['org_report' => 1]);
$mgrNoReport = user('pat@test.dev', 'manager', $pm, ['org_report' => 1]);
at('2026-11-01 08:10');
Notifier::runDaily();
$monthly = Db::all("SELECT * FROM notifications WHERE kind = 'monthly' AND mode = 'test'");
ok(count($monthly) > 0, 'Monthly summaries are prepared on the 1st');
eq($monthly[0]['subject'], '[TEST] November Employee Celebrations - Dwell Communities', 'Monthly subject matches the brief');
$orgReports = array_column(Db::all("SELECT intended_email FROM notifications WHERE kind = 'org_report'"), 'intended_email');
sort($orgReports);
eq($orgReports, ['admin@test.dev', 'exec@test.dev'], 'Organization-wide report only goes to opted-in executives and administrators');
foreach ($monthly as $m) {
    $items = Db::all('SELECT employee_id FROM notification_items WHERE notification_id = ?', [$m['id']]);
    $ownerEmp = (int) $m['recipient_employee_id'];
    $allOwned = array_reduce($items, static fn ($c, $i) => $c && owner_of((int) $i['employee_id']) === $ownerEmp, true);
    if (!$allOwned) {
        ok(false, 'Monthly summary lists only the recipient’s own people');
        break;
    }
}
ok(isset($allOwned) && $allOwned, 'Each monthly summary lists only people the recipient is responsible for');
$patMonthly = Db::one("SELECT * FROM notifications WHERE kind = 'monthly' AND intended_email = 'pat@test.dev'");
ok($patMonthly && str_contains($patMonthly['body_text'], 'Nova November') && !str_contains($patMonthly['body_text'], '2026-11'), 'The supervisor’s summary lists their person, with no year of birth');

// ===================================================================== access control
section('Access: server-enforced, per role');
Settings::set('test_user_id', 0);
$mgr = Db::one('SELECT * FROM users WHERE email = ?', ['pat@test.dev']);
$vis = Access::visibleIds($mgr);
ok(in_array($tech, $vis, true) && in_array($orphan, $vis, true) && in_array($pm, $vis, true), 'A manager sees their team and their property');
ok(!in_array($ceo, $vis, true) && !in_array($rm, $vis, true), 'A manager does not see other teams or their own boss');
ok(in_array($other, $vis, true), 'A manager sees people they are the assigned celebration owner for');
ok(!in_array($gone, $vis, true), 'Managers never see former employees');
throws(static fn () => Router::dispatch('people.get', 'GET', ['id' => $ceo], $mgr), 'Changing the ID in the URL is refused (manager → CEO)', 404);
throws(static fn () => Router::dispatch('people.list', 'GET', [], null), 'Signed-out requests are refused', 401);
$execUser = Db::one('SELECT * FROM users WHERE email = ?', ['exec@test.dev']);
eq(count(Router::dispatch('people.list', 'GET', ['status' => 'all'], $execUser)['people']), count(Employees::rows()), 'Executives see the whole organization');
$execView = Router::dispatch('people.get', 'GET', ['id' => $tech], $execUser)['person'];
ok(!array_key_exists('notes', $execView) && !array_key_exists('birth_year', $execView), 'Executives don’t receive internal notes or birth years');
$emp = user('emp@test.dev', 'employee', $tech);
$empView = Router::dispatch('people.get', 'GET', ['id' => $pm], $emp)['person'];
ok(!array_key_exists('birthday', $empView) && !array_key_exists('hire_date', $empView), 'Employees see basic directory fields only');
throws(static fn () => Router::dispatch('dashboard', 'GET', [], $emp), 'Employees can’t open the celebrations dashboard', 403);
$adminOnly = 0;
$blocked = 0;
foreach (Router::routes() as $action => [$method, $perm]) {
    if ($perm === 'public') {
        continue;
    }
    try {
        Router::dispatch($action, $method, [], null);
    } catch (AppError $e) {
        $blocked += $e->status === 401 ? 1 : 0;
    }
    if (!in_array($perm, ['auth', 'people.view', 'people.dates', 'celebrations.view', 'celebrations.status'], true)) {
        $adminOnly++;
        try {
            Router::dispatch($action, $method, [], $mgr);
            ok(false, "Manager reached admin action $action");
        } catch (AppError $e) {
            if ($e->status !== 403) {
                ok(false, "Manager on $action got {$e->status}");
            }
        }
    }
}
eq($blocked, count(array_filter(Router::routes(), static fn ($r) => $r[1] !== 'public')), 'Every non-public API action refuses signed-out requests');
ok($adminOnly > 15, "Managers are refused all $adminOnly administrator actions");
$link = \Dwell\Core\Users::link((int) $mgr['id'], true);
$acct = (int) Db::value("SELECT id FROM notifications WHERE kind = 'account' ORDER BY id DESC LIMIT 1");
throws(static fn () => Router::dispatch('notifications.get', 'GET', ['id' => $acct], $execUser), 'Executives can’t open invitation or reset emails', 404);
ok(!array_filter(Router::dispatch('notifications.list', 'GET', [], $execUser)['notifications'], static fn ($n) => $n['kind'] === 'account'), 'Account emails are hidden from executives’ history');
$shown = Router::dispatch('notifications.get', 'GET', ['id' => $acct], $admin)['notification'];
ok(!str_contains($shown['body_html'], substr($link['url'], -48)) && str_contains($shown['body_html'], '[link hidden]'), 'Sign-in links are never shown again, even to administrators');
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'forged';
throws(static fn () => Auth::checkCsrf(), 'Changes without the CSRF token are refused', 403);
throws(static fn () => Auth::login('admin@test.dev', 'wrong password'), 'Wrong password is refused', 401);
throws(static fn () => Employees::save(['id' => $rm, 'supervisor_id' => $tech]), 'Reporting loops are refused');

// ===================================================================== audit & birth year
section('Audit log and personal data');
Employees::save(['id' => $tech, 'birth_month' => 10, 'birth_day' => 19, 'birthday_status' => 'ok', 'job_title' => 'Lead Tech']);
$log = json_decode((string) Db::value("SELECT details FROM audit_log WHERE action = 'person_update' AND subject_id = ? ORDER BY id DESC LIMIT 1", [$tech]), true);
ok(($log['birth_day'] ?? null) === ['changed' => true] && ($log['job_title']['to'] ?? null) === 'Lead Tech', 'Audit log records changes but not birthday values');
Employees::save(['id' => $tech, 'birth_year' => 1990]);
eq(Employees::row($tech)['birth_year'], null, 'Birth year is not stored unless the setting allows it');
Settings::set('store_birth_year', true);
Employees::save(['id' => $tech, 'birth_year' => 1990]);
Employees::flush();
eq(Employees::row($tech)['birth_year'], 1990, 'With the setting on, the year is kept');
Router::dispatch('settings.save', 'POST', ['settings' => ['store_birth_year' => false]], $admin);
Employees::flush();
eq(Employees::row($tech)['birth_year'], null, 'Turning the setting off erases stored years');

// ===================================================================== spreadsheet import
section('Spreadsheet import');
$zip = class_exists(ZipArchive::class);
if (!$zip) {
    echo "  (no zip extension in this PHP: the same import tests run from .csv files instead)\n";
}
function make_xlsx(array $rows): string
{
    if (!class_exists(ZipArchive::class)) {
        $path = tempnam(sys_get_temp_dir(), 'dcc') . '.csv';
        $fh = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fh, array_values($row));
        }
        fclose($fh);
        return $path;
    }
    $strings = [];
    $sheet = '';
    foreach ($rows as $ri => $row) {
        $sheet .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($row) as $ci => $v) {
            if ($v === null) {
                continue;
            }
            $ref = chr(65 + $ci) . ($ri + 1);
            if (is_int($v) || is_float($v)) {
                $sheet .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } else {
                $strings[] = htmlspecialchars((string) $v, ENT_XML1);
                $sheet .= '<c r="' . $ref . '" t="s"><v>' . (count($strings) - 1) . '</v></c>';
            }
        }
        $sheet .= '</row>';
    }
    $path = tempnam(sys_get_temp_dir(), 'dcx') . '.xlsx';
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Staff" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . implode('', array_map(static fn ($s) => "<si><t>$s</t></si>", $strings)) . '</sst>');
    $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet . '</sheetData></worksheet>');
    $z->close();
    return $path;
}
function serial(string $date): int { return (int) ((new DateTimeImmutable($date))->diff(new DateTimeImmutable('1899-12-30'))->days); }
function upload(array $rows): int
{
    global $admin;
    $path = make_xlsx($rows);
    return Importer::upload($admin, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => 'staff.' . pathinfo($path, PATHINFO_EXTENSION), 'size' => filesize($path)]);
}
function plan_rows(array $plan): array
{
    $out = [];
    foreach ($plan['rows'] as $r) {
        $out[$r['name']] = $out[$r['name']] ?? $r; // first row with that name
    }
    return $out;
}

eq(SpreadsheetReader::toDate(45000), '2023-03-15', 'Excel date serials are read correctly');
eq(SpreadsheetReader::toDate('10/13/2026'), '2026-10-13', 'US-style text dates are read');
eq(SpreadsheetReader::toDate('Oct 9'), '0000-10-09', 'Month/day-only text keeps no year');
$header = [['Name', 'Employment Data'], ['Employee Name', 'Employee Status', 'Term Date', 'Company Name', 'Division', 'Client Hire Date', 'Classification', 'Birth', 'Supervisor']];
$rows = array_merge($header, [
    ['WANDA WELLS', 'ACTIVE', null, 'DWELL COMMUNITIES, LLC', 'Maple', serial('2019-04-01'), 'Full Time', serial('1988-05-12'), 'Pat Manager'],
    ['Bea "Bee" Baker', 'ACTIVE', null, 'DWELL COMMUNITIES, LLC', 'Brand New Place', serial('2024-11-11'), 'Full Time', serial('2025-03-31'), null],
    ['Fred Former', 'Terminated', serial('2024-01-05'), 'DWELL COMMUNITIES, LLC', 'Maple', serial('2018-01-01'), 'Full Time', serial('1970-02-02'), null],
    ['Tess Tech', 'ACTIVE', null, 'DWELL COMMUNITIES, LLC', 'Maple', serial('2021-10-20'), 'Full Time', null, null],
    ['Wanda Wells', 'ACTIVE', null, 'DWELL COMMUNITIES, LLC', 'Maple', serial('2019-04-01'), 'Full Time', serial('1988-05-12'), null],
]);
$id = upload($rows);
$plan = Importer::preview($id);
$pr = plan_rows($plan);
eq($pr['Wanda Wells']['action'] ?? null, 'create', 'New person is created (ALL CAPS name tidied to “Wanda Wells”)');
eq($pr['Bee Baker']['action'] ?? null, 'create', 'Nickname in quotes becomes the preferred name');
ok(in_array('Birth date is on or after the hire date: birthday needs review and won’t be celebrated until fixed', $pr['Bee Baker']['issues'], true), 'Impossible birth date is flagged for review');
eq($pr['Fred Former']['action'] ?? null, 'skip', 'Former employees not already in Dwell Central are skipped');
eq($pr['Tess Tech']['action'] ?? null, 'unchanged', 'Blank cells never erase saved data (Tess keeps her birthday)');
ok((bool) array_filter($plan['rows'], static fn ($r) => $r['action'] === 'duplicate'), 'Duplicate rows in the file are detected');
ok(!$plan['ready'] && $plan['summary']['values_unresolved'] === 1, 'An unknown property must be matched or created before applying');
$dec = $plan['decisions'];
$dec['values']['property'][Organization::key('Brand New Place')] = ['action' => 'new', 'name' => 'Brand New Place', 'kind' => 'property'];
$plan = Importer::preview($id, $dec);
ok($plan['ready'], 'After the choice the import is ready');
Employees::save(['id' => $tech, 'job_title' => 'Edited meanwhile']);
throws(static fn () => Importer::apply($admin, $id, $dec, 'stale-hash'), 'A plan that changed since review can’t be applied', 409);
$plan = Importer::preview($id, $dec);
$sum = Importer::apply($admin, $id, $dec, $plan['hash']);
eq($sum['applied']['created'], 2, 'Apply adds the two new people');
Employees::flush();
$wanda = (int) Db::value("SELECT id FROM employees WHERE first_name = 'Wanda'");
eq(Employees::row($wanda)['supervisor_id'], $pm, 'Supervisor names in the file are linked to records');
eq(Employees::row($wanda)['birth_year'], null, 'The import checks the birth year but does not keep it');
eq(Db::value('SELECT rows_json FROM imports WHERE id = ?', [$id]), null, 'The uploaded rows are erased once applied');
eq(Organization::match('properties', 'Brand New Place') !== null, true, 'The new property was created');

// Verified records are protected
Employees::setVerified($wanda, true);
$rows2 = array_merge($header, [['Wanda Wells', 'ACTIVE', null, 'DWELL COMMUNITIES, LLC', 'Oak Hill', serial('2019-04-01'), 'Part Time', serial('1988-05-12'), 'Pat Manager']]);
$id2 = upload($rows2);
$plan2 = Importer::preview($id2, ['values' => ['property' => [Organization::key('Oak Hill') => ['action' => 'map', 'id' => $oak]]]]);
$w = plan_rows($plan2)['Wanda Wells'];
eq($w['action'], 'protected', 'Changes to a verified record are held back');
Importer::apply($admin, $id2, $plan2['decisions'], $plan2['hash']);
Employees::flush();
eq(Employees::row($wanda)['classification'], 'full_time', 'Applying without approval leaves the verified record unchanged');
$id3 = upload($rows2);
$dec3 = ['rows' => [(string) $w['row'] => ['overwrite' => true]]];
$plan3 = Importer::preview($id3, $dec3);
eq(plan_rows($plan3)['Wanda Wells']['action'], 'update', 'Ticking “Approve changes” turns it into an update');
Importer::apply($admin, $id3, $dec3, $plan3['hash']);
Employees::flush();
eq(Employees::row($wanda)['classification'], 'part_time', 'Approved changes are applied');
eq(Employees::row($wanda)['property_id'], $oak, 'Remembered spelling (“Oak Hill”) matched automatically the second time');

// Ambiguous names need a decision
person(['first_name' => 'Wanda', 'last_name' => 'Wells', 'hire_date' => '2010-01-01']);
$id4 = upload(array_merge($header, [['Wanda Wells', 'ACTIVE', null, 'DWELL COMMUNITIES, LLC', 'Maple', serial('2019-04-01'), 'Part Time', null, null]]));
$plan4 = Importer::preview($id4);
eq(plan_rows($plan4)['Wanda Wells']['action'], 'ambiguous', 'Two saved people with the same name: the import asks which one');
ok(!$plan4['ready'], 'It can’t be applied until someone chooses');
Importer::discard($id4);
eq(Db::value('SELECT rows_json FROM imports WHERE id = ?', [$id4]), null, 'Discarding erases the uploaded rows');

echo "\n" . ($fail ? "FAILED: $fail failed, $pass passed\n" : "All $pass tests passed.\n");
exit($fail ? 1 : 0);
