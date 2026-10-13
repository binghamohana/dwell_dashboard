<?php
declare(strict_types=1);

namespace Dwell\Api;

use Dwell\Celebrations\Celebrations;
use Dwell\Core\Access;
use Dwell\Core\App;
use Dwell\Core\AppError;
use Dwell\Core\Audit;
use Dwell\Core\Auth;
use Dwell\Core\Db;
use Dwell\Core\GoogleSignIn;
use Dwell\Core\Mailer;
use Dwell\Core\Settings;
use Dwell\Core\Time;
use Dwell\Core\Users;
use Dwell\Core\Util;
use Dwell\Employees\Employees;
use Dwell\Employees\Photos;
use Dwell\Import\Importer;
use Dwell\Jobs;
use Dwell\Notifications\Notifier;
use Dwell\Org\Organization;

/**
 * Every API action, its HTTP method and the permission it needs, in one table.
 * Permission checks happen here, before any handler runs; handlers that return people also
 * filter by Access::visibleIds(), so a manager can't reach other teams by changing an ID.
 */
final class Router
{
    /** @return array<string, array{0: string, 1: string, 2: callable}> action => [method, permission, handler] */
    public static function routes(): array
    {
        return [
            // ---- signing in (public)
            'me'               => ['GET', 'public', [self::class, 'me']],
            'login'            => ['POST', 'public', static function ($in) {
                $user = Auth::login((string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
                return ['ok' => true, 'csrf' => Auth::csrfToken(), 'user_id' => (int) $user['id']];
            }],
            'logout'           => ['POST', 'public', static function () {
                Auth::logout();
                return ['ok' => true];
            }],
            'forgot'           => ['POST', 'public', static function ($in) {
                Users::forgot((string) ($in['email'] ?? ''));
                return ['ok' => true];
            }],
            'token.check'      => ['GET', 'public', static function ($in) {
                $purpose = ($in['purpose'] ?? '') === 'reset' ? 'reset' : 'invite';
                $u = Auth::tokenUser((string) ($in['token'] ?? ''), $purpose);
                return $u ? ['ok' => true, 'email' => $u['email'], 'name' => $u['name']] : ['ok' => false];
            }],
            'token.redeem'     => ['POST', 'public', static function ($in) {
                $purpose = ($in['purpose'] ?? '') === 'reset' ? 'reset' : 'invite';
                Auth::redeemToken((string) ($in['token'] ?? ''), $purpose, (string) ($in['password'] ?? ''));
                return ['ok' => true, 'csrf' => Auth::csrfToken()];
            }],
            'password.change'  => ['POST', 'auth', static function ($in, $user) {
                if (!$user['password_hash'] || !password_verify((string) ($in['current'] ?? ''), $user['password_hash'])) {
                    throw new AppError('Your current password isn’t right.', 400, 'current');
                }
                Auth::setPassword((int) $user['id'], (string) ($in['password'] ?? ''));
                Auth::issueCookie(Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]));
                Audit::log('password_change', 'user', (int) $user['id'], $user['email'] . ' changed their password');
                return ['ok' => true, 'csrf' => Auth::csrfToken()];
            }],

            // ---- people
            'people.list'      => ['GET', 'people.view', static fn ($in, $user) => ['people' => Employees::list($user, $in)]],
            'people.get'       => ['GET', 'people.view', static fn ($in, $user) => ['person' => Employees::get($user, (int) ($in['id'] ?? 0))]],
            'people.options'   => ['GET', 'people.edit', static fn () => ['people' => Employees::options()]],
            'people.save'      => ['POST', 'people.edit', static function ($in, $user) {
                $id = Employees::save($in);
                return ['ok' => true, 'person' => Employees::get($user, $id)];
            }],
            'people.verify'    => ['POST', 'people.edit', static function ($in, $user) {
                $id = (int) ($in['id'] ?? 0);
                if (!Employees::row($id)) {
                    throw AppError::notFound();
                }
                Employees::setVerified($id, !empty($in['verified']));
                return ['ok' => true, 'person' => Employees::get($user, $id)];
            }],
            'people.delete'    => ['POST', 'people.edit', static function ($in) {
                Employees::delete((int) ($in['id'] ?? 0));
                return ['ok' => true];
            }],
            'people.photo'     => ['POST', 'people.edit', static function ($in, $user) {
                $id = (int) ($in['id'] ?? 0);
                Photos::upload($id, $_FILES['photo'] ?? []);
                return ['ok' => true, 'person' => Employees::get($user, $id)];
            }],
            'people.photo_remove' => ['POST', 'people.edit', static function ($in, $user) {
                $id = (int) ($in['id'] ?? 0);
                Photos::remove($id);
                return ['ok' => true, 'person' => Employees::get($user, $id)];
            }],
            'people.history'   => ['GET', 'audit.view', static fn ($in) => ['history' => Audit::list(['subject_type' => 'employee', 'subject_id' => (int) ($in['id'] ?? 0)], 100)]],

            // ---- organization
            'org.lists'        => ['GET', 'auth', static fn ($in, $user) => self::orgLists($user)],
            'org.save'         => ['POST', 'org.manage', static function ($in) {
                $id = Organization::save((string) ($in['table'] ?? ''), (array) ($in['record'] ?? []));
                return ['ok' => true, 'id' => $id, 'lists' => Organization::all()];
            }],
            'org.delete'       => ['POST', 'org.manage', static function ($in) {
                Organization::delete((string) ($in['table'] ?? ''), (int) ($in['id'] ?? 0));
                return ['ok' => true, 'lists' => Organization::all()];
            }],

            // ---- celebrations & dashboard
            'dashboard'        => ['GET', 'celebrations.view', [self::class, 'dashboard']],
            'celebrations.range' => ['GET', 'celebrations.view', static fn ($in, $user) => [
                'events' => Celebrations::forUser($user, (string) ($in['from'] ?? ''), (string) ($in['to'] ?? ''), $in),
            ]],
            'celebrations.status' => ['POST', 'celebrations.status', static function ($in, $user) {
                Celebrations::setStatus($user, (int) ($in['employee_id'] ?? 0), (string) ($in['type'] ?? ''), (string) ($in['date'] ?? ''), $in['status'] ?? null, $in['note'] ?? null);
                return ['ok' => true];
            }],

            // ---- notifications
            'notifications.list' => ['GET', 'notifications.view', static fn ($in, $user) => self::notifications($in, $user)],
            'notifications.get'  => ['GET', 'notifications.view', static function ($in, $user) {
                $n = Db::one('SELECT * FROM notifications WHERE id = ?', [(int) ($in['id'] ?? 0)]);
                if (!$n || ($n['kind'] === 'account' && !Access::can($user, 'users.manage'))) {
                    throw AppError::notFound();
                }
                // Sign-in links in invitation/reset emails are never shown again, to anyone.
                foreach (['body_html', 'body_text'] as $k) {
                    $n[$k] = preg_replace('#/\#/(welcome|reset)/[a-f0-9]{48}#', '/#/$1/[link hidden]', (string) $n[$k]);
                }
                $n['items'] = Db::all('SELECT employee_id, type, date FROM notification_items WHERE notification_id = ?', [$n['id']]);
                return ['notification' => $n];
            }],
            'notifications.retry' => ['POST', 'notifications.manage', static function ($in) {
                Mailer::retry((int) ($in['id'] ?? 0));
                Audit::log('notification_retry', 'notification', (int) $in['id'], 'Retried notification #' . (int) $in['id']);
                return ['ok' => true, 'status' => Db::value('SELECT status FROM notifications WHERE id = ?', [(int) $in['id']])];
            }],
            'notifications.cancel' => ['POST', 'notifications.manage', static function ($in) {
                $n = Db::run("UPDATE notifications SET status = 'cancelled' WHERE id = ? AND status IN ('pending','failed','held')", [(int) ($in['id'] ?? 0)])->rowCount();
                if ($n) {
                    Audit::log('notification_cancel', 'notification', (int) $in['id'], 'Cancelled notification #' . (int) $in['id']);
                }
                return ['ok' => true];
            }],
            'notifications.run' => ['POST', 'notifications.manage', static function () {
                $r = Notifier::runDaily(true);
                Audit::log('notification_run', 'settings', null, 'Ran today’s celebration check by hand (' . Notifier::mode() . ' mode)', $r);
                return ['ok' => true, 'result' => $r];
            }],
            'notifications.preview' => ['GET', 'notifications.manage', static fn ($in) => ['messages' => Notifier::preview((string) ($in['date'] ?? Time::today())), 'mode' => Notifier::mode()]],
            'notifications.mode' => ['POST', 'notifications.manage', static function ($in, $user) {
                Notifier::setMode($user, (string) ($in['mode'] ?? ''), (string) ($in['confirm'] ?? ''));
                return ['ok' => true, 'health' => Jobs::health()];
            }],
            'notifications.test' => ['POST', 'notifications.manage', static function ($in, $user) {
                $id = Notifier::system('test', 'test:' . Util::token(6), $user['email'], $user['name'], 'Dwell Central test email', [
                    'heading'    => 'Email delivery works',
                    'paragraphs' => ['This test message was sent from Dwell Central at ' . Time::nowLocal()->format('g:i A') . ' on ' . Time::fmtDate(Time::today()) . '.'],
                ]);
                $n = Db::one('SELECT status, last_error FROM notifications WHERE id = ?', [$id]);
                return ['ok' => $n['status'] === 'sent', 'status' => $n['status'], 'error' => $n['last_error'], 'transport' => App::config('mail.transport', 'log')];
            }],

            // ---- import
            'import.list'      => ['GET', 'import.run', static fn () => ['imports' => Importer::list()]],
            'import.upload'    => ['POST', 'import.run', static function ($in, $user) {
                $id = Importer::upload($user, $_FILES['file'] ?? []);
                return ['ok' => true, 'id' => $id, 'plan' => Importer::strip(Importer::preview($id))];
            }],
            'import.get'       => ['GET', 'import.run', static fn ($in) => ['plan' => Importer::strip(Importer::preview((int) ($in['id'] ?? 0)))]],
            'import.preview'   => ['POST', 'import.run', static fn ($in) => ['plan' => Importer::strip(Importer::preview((int) ($in['id'] ?? 0), (array) ($in['decisions'] ?? [])))]],
            'import.apply'     => ['POST', 'import.run', static function ($in, $user) {
                return ['ok' => true, 'summary' => Importer::apply($user, (int) ($in['id'] ?? 0), (array) ($in['decisions'] ?? []), (string) ($in['hash'] ?? ''))];
            }],
            'import.discard'   => ['POST', 'import.run', static function ($in) {
                Importer::discard((int) ($in['id'] ?? 0));
                return ['ok' => true];
            }],

            // ---- administration
            'users.list'       => ['GET', 'users.manage', static fn () => ['users' => Users::list()]],
            'users.save'       => ['POST', 'users.manage', static function ($in, $user) {
                $id = Users::save($user, $in);
                $out = ['ok' => true, 'id' => $id, 'users' => Users::list()];
                if (!empty($in['send_invite'])) {
                    $out['link'] = Users::link($id, true);
                }
                return $out;
            }],
            'users.link'       => ['POST', 'users.manage', static fn ($in) => ['ok' => true] + Users::link((int) ($in['id'] ?? 0), !empty($in['email']))],
            'settings.get'     => ['GET', 'settings.manage', static fn () => ['settings' => Settings::editable(), 'admins' => Db::all("SELECT id, name, email FROM users WHERE role = 'system_admin' AND is_active = 1 ORDER BY name")]],
            'settings.save'    => ['POST', 'settings.manage', static function ($in) {
                $changes = Settings::saveMany((array) ($in['settings'] ?? []));
                if (isset($changes['test_user_id']) && $changes['test_user_id'] && !Db::value("SELECT id FROM users WHERE id = ? AND role = 'system_admin' AND is_active = 1", [$changes['test_user_id']])) {
                    Settings::set('test_user_id', 0);
                    throw new AppError('Test emails can only go to an active system administrator.', 400, 'test_user_id');
                }
                if ($changes) {
                    Audit::log('settings_update', 'settings', null, 'Settings changed: ' . implode(', ', array_keys($changes)),
                        array_map(static fn ($v) => is_string($v) && strlen($v) > 80 ? 'updated' : $v, $changes));
                }
                if (isset($changes['store_birth_year']) && !$changes['store_birth_year']) {
                    $n = Db::run('UPDATE employees SET birth_year = NULL WHERE birth_year IS NOT NULL')->rowCount();
                    Audit::log('birth_years_erased', 'settings', null, "Birth years erased from $n records (setting turned off)");
                    Employees::flush();
                }
                return ['ok' => true, 'settings' => Settings::editable()];
            }],
            'audit.list'       => ['GET', 'audit.view', static fn ($in) => ['entries' => Audit::list($in, 100, max(0, (int) ($in['offset'] ?? 0)))]],
            'health'           => ['GET', 'notifications.view', static fn () => ['health' => Jobs::health()]],
        ];
    }

    /** Run an action as a user. Throws AppError for unknown actions, wrong methods and missing permissions. */
    public static function dispatch(string $action, string $method, array $in, ?array $user): array
    {
        $routes = self::routes();
        if (!isset($routes[$action])) {
            throw new AppError('Unknown action.', 404, null, 'not_found');
        }
        [$m, $perm, $fn] = $routes[$action];
        if ($m !== $method) {
            throw new AppError('Method not allowed.', 405);
        }
        if ($perm !== 'public') {
            if (!$user) {
                throw new AppError('Please sign in.', 401, null, 'unauthenticated');
            }
            if ($perm !== 'auth') {
                Access::require($user, $perm);
            }
        }
        return $fn($in, $user);
    }

    // ---------------------------------------------------------------- handlers

    public static function me(array $in, ?array $user): array
    {
        $base = [
            'portal_name' => Settings::get('portal_name'),
            'org_name'    => Settings::get('org_name'),
            'google'      => GoogleSignIn::enabled(),
        ];
        if (!$user) {
            return $base + ['user' => null];
        }
        $emp = $user['employee_id'] ? Employees::row((int) $user['employee_id']) : null;
        return $base + [
            'user' => [
                'id'          => (int) $user['id'],
                'name'        => $user['name'],
                'email'       => $user['email'],
                'role'        => $user['role'],
                'role_label'  => Access::ROLES[$user['role']],
                'employee_id' => $emp ? (int) $emp['id'] : null,
                'has_password' => !empty($user['password_hash']),
            ],
            'permissions'    => Access::permissions($user),
            'csrf'           => Auth::csrfToken(),
            'today'          => Time::today(),
            'timezone'       => Settings::get('timezone'),
            'mode'           => Access::can($user, 'notifications.view') ? Notifier::mode() : null,
            'lists'          => self::orgLists($user),
            'classifications' => Employees::CLASSIFICATIONS,
            'statuses'       => Employees::STATUSES,
            'issue_labels'   => Employees::ISSUE_LABELS,
            'roles'          => Access::ROLES,
            'store_birth_year' => (bool) Settings::get('store_birth_year'),
        ];
    }

    public static function orgLists(array $user): array
    {
        $all = Organization::all();
        if (Access::can($user, 'org.manage')) {
            return $all;
        }
        // Everyone else gets names only (no aliases, managers or counts).
        $slim = static fn ($rows, $keys) => array_map(static fn ($r) => array_intersect_key($r, array_flip($keys)), array_values(array_filter($rows, static fn ($r) => $r['is_active'])));
        return [
            'brands'      => $slim($all['brands'], ['id', 'name', 'color']),
            'entities'    => $slim($all['entities'], ['id', 'name', 'kind', 'brand_id']),
            'properties'  => $slim($all['properties'], ['id', 'name', 'short_name', 'kind', 'brand_id']),
            'departments' => $slim($all['departments'], ['id', 'name']),
        ];
    }

    public static function dashboard(array $in, array $user): array
    {
        $today = Time::today();
        $out = [
            'kpis'     => Celebrations::kpis($user),
            'upcoming' => Celebrations::forUser($user, $today, Time::addDays($today, 29)),
        ];
        if (Access::can($user, 'notifications.view')) {
            $out['health'] = Jobs::health();
            $out['recent'] = Db::all("SELECT id, kind, mode, status, intended_name, subject, created_at, sent_at FROM notifications
                                      WHERE kind NOT IN ('account','test') ORDER BY id DESC LIMIT 8");
        }
        if (Access::can($user, 'people.edit')) {
            $issues = array_fill_keys(array_keys(Employees::ISSUE_LABELS), 0);
            foreach (Employees::rows() as $r) {
                foreach (Employees::issues($r) as $i) {
                    $issues[$i]++;
                }
            }
            $out['issues'] = $issues;
        }
        return $out;
    }

    public static function notifications(array $in, array $user): array
    {
        $where = ['1=1'];
        $params = [];
        if (!Access::can($user, 'users.manage')) {
            $where[] = "n.kind <> 'account'"; // invitations and password resets are for administrators only
        }
        foreach (['kind', 'status', 'mode'] as $k) {
            if (!empty($in[$k])) {
                $where[] = "n.$k = ?";
                $params[] = (string) $in[$k];
            }
        }
        if (!empty($in['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $in['q']) . '%';
            $where[] = '(n.subject LIKE ? OR n.intended_name LIKE ? OR n.intended_email LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (!empty($in['employee_id'])) {
            $where[] = 'n.id IN (SELECT notification_id FROM notification_items WHERE employee_id = ?)';
            $params[] = (int) $in['employee_id'];
        }
        $page = max(0, (int) ($in['page'] ?? 0));
        $rows = Db::all('SELECT n.id, n.kind, n.mode, n.status, n.to_email, n.intended_name, n.intended_email, n.subject, n.attempts,
                                n.last_error, n.event_date, n.created_at, n.sent_at
                         FROM notifications n WHERE ' . implode(' AND ', $where) . ' ORDER BY n.id DESC LIMIT 51 OFFSET ' . ($page * 50), $params);
        return [
            'notifications' => array_slice($rows, 0, 50),
            'more'          => count($rows) > 50,
            'kinds'         => Notifier::KINDS,
            'counts'        => Db::all('SELECT status, COUNT(*) AS n FROM notifications GROUP BY status'),
        ];
    }
}
