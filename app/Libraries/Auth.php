<?php

namespace App\Libraries;

use App\Libraries\Erp\ErpDirectory;

use App\Models\UserModel;
use Config\Database;
use Config\Services;

/**
 * Authentication.
 *
 * Everyone signs in with their institute Google account (see GoogleAuth). One Google sign-in can give:
 *  - a submitter identity: the master list person with that e-mail (session key person_id), and/or
 *  - an authority / admin account: the users row with that e-mail (session key user_id).
 * A staff member who is also an authority gets both. Only the admin can still use a username + password,
 * as an emergency login in case Google sign-in is not working.
 *
 * Rules (all configurable in the settings table):
 *  - lockout_attempts failed logins lock the account for lockout_minutes
 *  - session_idle_minutes of inactivity ends the session
 *  - password_min_length for new passwords
 *  - admin-created / admin-reset accounts must change the password at next login
 */
class Auth
{
    public const GENERIC_FAILURE = 'Invalid username or password.';

    private const SESSION_KEYS = ['user_id', 'username', 'full_name', 'last_activity', 'person_id', 'person_role', 'email', 'login_via', 'receipt', 'erp_profile'];

    /**
     * Sign in the person who owns a (verified) institute e-mail address.
     * Returns [true, null] or [false, 'message for the person'].
     *
     * @return array{0:bool,1:?string}
     */
    public static function loginWithEmail(string $email, string $via = 'google'): array
    {
        $email = strtolower(trim($email));
        $kind  = GoogleAuth::domainKind($email);
        if ($kind === null) {
            self::logout();
            $cfg = config('Google');

            return [false, "Please sign in with your institute Google account (@{$cfg->studentDomain} for students, @{$cfg->staffDomain} for staff)."];
        }
        $db         = Database::connect();
        $roles      = $kind === 'student' ? ['student'] : ['faculty', 'non_teaching'];
        $erpMessage = null;
        if (ErpDirectory::enabled()) {
            //echo "ERP directory is enabled. Attempting to sign in via ERP for email: {$email}\n";
            // People come from the institute ERP: it decides who this e-mail belongs to (and what they are)            
            $found      = ErpDirectory::signIn($email, $kind);
            //print_r($found);
            $person     = $found['person'];
            $erpMessage = $found['message'];
        } else {
            $person = $db->query(
                'SELECT id, role, name FROM master_people WHERE lower(email) = ? AND active AND role IN ? LIMIT 1',
                [$email, $roles]
            )->getRowArray();
            //echo "ERP directory is disabled. Attempting to sign in via master_people table for email: {$email}\n";
            //print_r($person);
        }
        $user = $kind === 'staff'
            ? $db->query('SELECT id, username, full_name FROM users WHERE lower(email) = ? AND active AND NOT is_admin', [$email])->getRowArray()
            : null;
            //echo "Checking user table for email: {$email}\n";
            //print_r($user);
            

        if ($person === null && $user === null) {
            //echo "No matching person or user found for email: {$email}\n";
            //die;
            self::logout();   // a failed sign-in never leaves an earlier one active

            return [false, $erpMessage ?? "{$email} is not registered in the grievance portal, or has been deactivated. Please contact the administration."];
        }

        $session = Services::session();
        $session->remove(self::SESSION_KEYS);
        self::newSessionId();   // new session id on login: prevents session fixation
        $session->set([
            'email'         => $email,
            'username'      => $user['username'] ?? $email,
            'full_name'     => $user['full_name'] ?? $person['name'],
            'login_via'     => $via,
            'last_activity' => time(),
        ]);
        if ($person !== null) {
            $session->set(['person_id' => (int) $person['id'], 'person_role' => $person['role']]);
        }
        if ($user !== null) {
            $session->set('user_id', (int) $user['id']);
            $db->query('UPDATE users SET last_login_at = now(), updated_at = now() WHERE id = ?', [$user['id']]);
        }
        AuditLogger::log('login', $user !== null ? 'user' : 'master_person', $user['id'] ?? $person['id'], null, ['via' => $via, 'email' => $email]);

        return [true, null];
    }

    /** Sign in only the admin account explicitly associated with the verified Google e-mail. */
    public static function loginAdminWithEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if (GoogleAuth::domainKind($email) !== 'staff') {
            self::logout();

            return [false, 'Administrator sign-in requires the configured institute staff Google account.'];
        }

        $db = Database::connect();
        $sql    = 'SELECT id, username, full_name FROM users WHERE lower(email) = ? AND active AND is_admin';
        $binds  = [$email];
        $result = $db->query($sql, $binds);
        log_message('debug', 'Admin Google lookup SQL: ' . $db->showLastQuery() . '; bind data: ' . json_encode($binds, JSON_UNESCAPED_SLASHES));
        $user = $result->getRowArray();
        log_message('debug', 'Admin Google lookup result: ' . json_encode($user, JSON_UNESCAPED_SLASHES));
        if ($user === null) {
            self::logout();

            return [false, 'This Google account is not configured for administrator access.'];
        }

        $session = Services::session();
        $session->remove(self::SESSION_KEYS);
        self::newSessionId();
        $session->set([
            'user_id'       => (int) $user['id'],
            'username'      => $user['username'],
            'full_name'     => $user['full_name'],
            'email'         => $email,
            'login_via'     => 'google-admin',
            'last_activity' => time(),
        ]);
        $db->query('UPDATE users SET last_login_at = now(), updated_at = now() WHERE id = ?', [$user['id']]);
        AuditLogger::log('login', 'user', $user['id'], null, ['via' => 'google-admin', 'email' => $email]);

        return [true, null];
    }

    /**
     * The signed-in submitter (master list person), freshly read so deactivation takes effect at once.
     *
     * @return array<string,mixed>|null includes department, semester and hostel names
     */
    public static function person(): ?array
    {
        $id   = Services::session()->get('person_id');
        $role = (string) Services::session()->get('person_role');
        if ($id === null || $role === '') {
            return null;
        }

        return SubmissionService::personById($role, (int) $id);
    }

    /**
     * Try to log in. Returns [true, null] on success, or [false, 'message'].
     *
     * @return array{0:bool,1:?string}
     */
    public static function attempt(string $username, string $password): array
    {
        $users = new UserModel();
        $user  = $users->findByUsername($username);

        // Always do a hash comparison so response time does not reveal whether the username exists
        if ($user === null) {
            password_verify($password, self::dummyHash());

            return [false, self::GENERIC_FAILURE];
        }

        $db   = Database::connect();
        $lock = $db->query(
            'SELECT (locked_until IS NOT NULL AND locked_until > now()) AS locked FROM users WHERE id = ?',
            [$user['id']]
        )->getRowArray();

        if (self::truthy($lock['locked'] ?? false)) {
            return [false, 'Too many failed attempts. Please try again later.'];
        }

        // Only the admin signs in with a password; authorities use Google
        if (! self::truthy($user['is_admin']) || ! self::truthy($user['active']) || ! password_verify($password, $user['password_hash'])) {
            self::registerFailure((int) $user['id']);

            return [false, self::GENERIC_FAILURE];
        }

        // Success
        $db->query(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = now(), updated_at = now() WHERE id = ?',
            [$user['id']]
        );

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $users->touch((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        $session = Services::session();
        $session->remove(self::SESSION_KEYS);
        self::newSessionId();   // new session id on login: prevents session fixation
        $session->set([
            'user_id'       => (int) $user['id'],
            'username'      => $user['username'],
            'full_name'     => $user['full_name'],
            'login_via'     => 'password',
            'last_activity' => time(),
        ]);

        return [true, null];
    }

    /**
     * End the login. The session is emptied and given a new id rather than destroyed outright,
     * so that a "you have been logged out" flash message can still be shown on the next page.
     */
    public static function logout(): void
    {
        $session = Services::session();
        $session->remove(self::SESSION_KEYS);
        self::newSessionId();
    }

    /** The logged-in user, freshly read from the database (so deactivation takes effect at once). */
    public static function user(): ?array
    {
        // Cached for the request, but re-read when the signed-in id changes (login/logout in the same request)
        static $loadedFor = false;
        static $user      = null;

        $id = Services::session()->get('user_id');
        if ($loadedFor !== false && $loadedFor === $id) {
            return $user;
        }
        $loadedFor = $id;

        if ($id === null) {
            return $user = null;
        }

        $row = (new UserModel())->find((int) $id);

        if ($row === null || ! self::truthy($row['active'])) {
            return $user = null;
        }

        $row['is_admin']             = self::truthy($row['is_admin']);
        // Authorities have no password any more, so only the admin can be asked to change one
        $row['must_change_password'] = $row['is_admin'] && self::truthy($row['must_change_password'])
            && Services::session()->get('login_via') === 'password';

        return $user = $row;
    }

    /** True when anyone (authority, admin or submitter) is signed in. */
    public static function signedIn(): bool
    {
        return self::user() !== null || self::person() !== null;
    }

    /** Id of the logged-in user (0 when nobody is logged in). */
    public static function id(): int
    {
        return (int) (Services::session()->get('user_id') ?? 0);
    }

    /** True when the session has been idle longer than the configured limit. */
    public static function isIdleExpired(): bool
    {
        $last = Services::session()->get('last_activity');
        $max  = Settings::int('session_idle_minutes', 30) * 60;

        return $last === null || (time() - (int) $last) > $max;
    }

    public static function touchActivity(): void
    {
        Services::session()->set('last_activity', time());
    }

    /**
     * Change the logged-in user's own password.
     *
     * @return list<string> validation errors (empty = success)
     */
    public static function changePassword(int $userId, string $current, string $new, string $confirm): array
    {
        $users = new UserModel();
        $user  = $users->find($userId);
        $errors = [];

        if ($user === null || ! password_verify($current, $user['password_hash'])) {
            $errors[] = 'Your current password is not correct.';
        }

        $min = Settings::int('password_min_length', 8);
        if (mb_strlen($new) < $min) {
            $errors[] = "The new password must be at least {$min} characters long.";
        }
        if ($new !== $confirm) {
            $errors[] = 'The new password and its confirmation do not match.';
        }
        if ($current !== '' && $new === $current) {
            $errors[] = 'The new password must be different from the current one.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $users->touch($userId, [
            'password_hash'        => password_hash($new, PASSWORD_DEFAULT),
            'must_change_password' => false,
            'failed_attempts'      => 0,
        ]);
        AuditLogger::log('password_change', 'user', $userId);

        // Rotate the session id after a credential change
        Services::session()->regenerate(true);

        return [];
    }

    /** Count a failed login and lock the account once the limit is reached. */
    private static function registerFailure(int $userId): void
    {
        $max     = max(1, Settings::int('lockout_attempts', 5));
        $minutes = max(1, Settings::int('lockout_minutes', 15));

        Database::connect()->query(
            "UPDATE users
                SET failed_attempts = CASE WHEN failed_attempts + 1 >= ? THEN 0 ELSE failed_attempts + 1 END,
                    locked_until    = CASE WHEN failed_attempts + 1 >= ? THEN now() + (? * interval '1 minute') ELSE locked_until END,
                    updated_at      = now()
              WHERE id = ?",
            [$max, $max, $minutes, $userId]
        );
    }

    /** New session id after login/logout (prevents session fixation). Not possible on the command line. */
    private static function newSessionId(): void
    {
        if (! is_cli()) {
            Services::session()->regenerate(true);
        }
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
    }

    /** PostgreSQL booleans can arrive as true/false, 't'/'f' or '1'/'0' depending on the driver path. */
    public static function truthy(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }
}
