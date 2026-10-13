<?php
declare(strict_types=1);

namespace Dwell\Core;

use Dwell\Employees\Employees;
use Dwell\Notifications\Notifier;

/** Accounts for people who sign in (administrators, executives, managers). */
final class Users
{
    public static function view(array $u): array
    {
        $emp = $u['employee_id'] ? Employees::row((int) $u['employee_id']) : null;
        return [
            'id'            => (int) $u['id'],
            'email'         => $u['email'],
            'name'          => $u['name'],
            'role'          => $u['role'],
            'role_label'    => Access::ROLES[$u['role']] ?? $u['role'],
            'employee'      => $emp ? ['id' => (int) $emp['id'], 'name' => Employees::displayName($emp)] : null,
            'org_report'    => (bool) $u['org_report'],
            'alerts'        => (bool) $u['alerts'],
            'is_active'     => (bool) $u['is_active'],
            'has_password'  => !empty($u['password_hash']),
            'google_linked' => !empty($u['google_sub']),
            'last_login_at' => $u['last_login_at'],
            'scopes'        => array_map(static fn ($s) => ['scope' => $s['scope'], 'id' => (int) $s['scope_id']],
                Db::all('SELECT scope, scope_id FROM user_scopes WHERE user_id = ? ORDER BY scope, scope_id', [$u['id']])),
        ];
    }

    public static function list(): array
    {
        return array_map([self::class, 'view'], Db::all('SELECT * FROM users ORDER BY is_active DESC, name'));
    }

    public static function save(array $actor, array $in): int
    {
        $id = Util::intOrNull($in['id'] ?? null);
        $before = $id ? Db::one('SELECT * FROM users WHERE id = ?', [$id]) : null;
        if ($id && !$before) {
            throw AppError::notFound();
        }
        $email = Util::normalizeEmail($in['email'] ?? '') ?? '';
        if (!Util::isValidEmail($email)) {
            throw new AppError('Please enter a valid email address.', 400, 'email');
        }
        if (Db::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id ?? 0])) {
            throw new AppError('Someone already has an account with that email.', 400, 'email');
        }
        $name = Util::cleanText($in['name'] ?? '', 120);
        if ($name === '') {
            throw new AppError('Please enter their name.', 400, 'name');
        }
        $role = (string) ($in['role'] ?? '');
        if (!isset(Access::ROLES[$role])) {
            throw new AppError('Please choose a role.', 400, 'role');
        }
        $employeeId = Util::intOrNull($in['employee_id'] ?? null);
        if ($employeeId && !Employees::row($employeeId)) {
            throw new AppError('Please pick a person from the list.', 400, 'employee_id');
        }
        if ($employeeId && Db::value('SELECT id FROM users WHERE employee_id = ? AND id <> ?', [$employeeId, $id ?? 0])) {
            throw new AppError('Another account is already linked to that person.', 400, 'employee_id');
        }
        if ($role === 'manager' && !$employeeId && empty($in['scopes'])) {
            throw new AppError('A manager needs to be linked to their own record (or given properties) so the app knows whose information they can see.', 400, 'employee_id');
        }
        $active = array_key_exists('is_active', $in) ? (filter_var($in['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1;
        if ($before && (int) $before['id'] === (int) $actor['id'] && ($role !== 'system_admin' || !$active)) {
            throw new AppError('You can’t remove your own administrator access. Ask another administrator.', 400, 'role');
        }
        $data = [
            'email'       => $email,
            'name'        => $name,
            'role'        => $role,
            'employee_id' => $employeeId,
            'org_report'  => !empty($in['org_report']) && in_array($role, ['system_admin', 'executive'], true) ? 1 : 0,
            'alerts'      => !empty($in['alerts']) && $role === 'system_admin' ? 1 : 0,
            'is_active'   => $active,
            'updated_at'  => Time::nowDb(),
        ];
        Db::transaction(static function () use (&$id, $before, $data, $in) {
            if ($before) {
                // A change of role, email or access signs them out everywhere.
                $bump = $before['role'] !== $data['role'] || $before['email'] !== $data['email'] || (int) $before['is_active'] !== $data['is_active'];
                Db::update('users', $data, 'id = :id', ['id' => $id]);
                if ($bump) {
                    Db::run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
                }
                if ($before['email'] !== $data['email']) {
                    Db::run('UPDATE users SET google_sub = NULL WHERE id = ?', [$id]);
                }
                Audit::log('user_update', 'user', $id, 'Account updated: ' . $data['email'], Audit::diff($before, $data));
            } else {
                $data['created_at'] = Time::nowDb();
                $id = Db::insert('users', $data);
                Audit::log('user_create', 'user', $id, 'Account created: ' . $data['email'] . ' (' . Access::ROLES[$data['role']] . ')');
            }
            if (array_key_exists('scopes', $in)) {
                Db::run('DELETE FROM user_scopes WHERE user_id = ?', [$id]);
                foreach ((array) $in['scopes'] as $s) {
                    $scope = ($s['scope'] ?? '') === 'entity' ? 'entity' : 'property';
                    $sid = Util::intOrNull($s['id'] ?? null);
                    if ($sid && Db::value($scope === 'entity' ? 'SELECT id FROM entities WHERE id = ?' : 'SELECT id FROM properties WHERE id = ?', [$sid])) {
                        Db::run('INSERT IGNORE INTO user_scopes (user_id, scope, scope_id) VALUES (?, ?, ?)', [$id, $scope, $sid]);
                    }
                }
            }
        });
        Access::flush();
        if ($data['role'] !== 'system_admin' && (int) Settings::get('test_user_id') === (int) $id) {
            Settings::set('test_user_id', 0);
        }
        return (int) $id;
    }

    /** Create an invitation (or password reset) link. Optionally email it. Returns the URL. */
    public static function link(int $id, bool $email): array
    {
        $u = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$id]);
        if (!$u) {
            throw new AppError('That account is disabled or doesn’t exist.');
        }
        $purpose = $u['password_hash'] ? 'reset' : 'invite';
        $raw = Auth::createToken($id, $purpose, $purpose === 'invite' ? 24 * 7 : 24);
        $url = App::baseUrl() . '/#/' . ($purpose === 'invite' ? 'welcome' : 'reset') . '/' . $raw;
        Audit::log($purpose === 'invite' ? 'invite_link' : 'reset_link', 'user', $id, ($purpose === 'invite' ? 'Invitation' : 'Password reset') . ' link created for ' . $u['email'] . ($email ? ' (emailed)' : ''));
        if ($email) {
            $portal = (string) Settings::get('portal_name');
            Notifier::system('account', "$purpose:$id:" . substr(hash('sha256', $raw), 0, 12), $u['email'], $u['name'],
                $purpose === 'invite' ? "You’re invited to $portal" : "Reset your $portal password", [
                    'heading'    => $purpose === 'invite' ? "Welcome to $portal" : 'Reset your password',
                    'paragraphs' => [
                        'Hi ' . explode(' ', $u['name'])[0] . ',',
                        $purpose === 'invite'
                            ? "You’ve been given access to $portal, the " . Settings::get('org_name') . ' team portal. Choose a password to get started. The link works for 7 days.'
                            : 'Use the button below to choose a new password. The link works for 24 hours. If you didn’t ask for this, you can ignore this email.',
                    ],
                    'buttons'    => [$purpose === 'invite' ? 'Choose a password' : 'Reset password' => $url],
                ]);
        }
        return ['url' => $url, 'purpose' => $purpose];
    }

    /** "Forgot password": always answers the same way, so it can't reveal who has an account. */
    public static function forgot(string $email): void
    {
        $email = Util::normalizeEmail($email) ?? '';
        if (!RateLimit::hit('forgot:' . Http::ip(), 5, 3600) || !RateLimit::hit('forgot:' . $email, 3, 3600)) {
            return;
        }
        $u = Db::one('SELECT id, password_hash FROM users WHERE email = ? AND is_active = 1', [$email]);
        if ($u && $u['password_hash']) {
            self::link((int) $u['id'], true);
        }
    }
}
