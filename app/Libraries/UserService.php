<?php

namespace App\Libraries;

use App\Models\UserModel;
use Config\Database;
use RuntimeException;

/**
 * Authority accounts, managed by the admin. (The admin account itself is never touched here:
 * it is created and recovered from the command line.)
 *
 * Rules:
 *  - authorities sign in with Google, so every account needs its institute e-mail (@staff domain);
 *    they have no password (a random, never-shown hash fills the column)
 *  - deactivating an account ends all the posts it holds (the posts become vacant)
 *  - an account that has ever held a post or acted on a grievance cannot be deleted, only deactivated
 */
class UserService
{
    public const USERNAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{2,59}$/';

    /**
     * Validate account details. Returns a list of error messages (empty = valid).
     *
     * @param int|null $ignoreId a user id to leave out of the duplicate checks (when editing)
     *
     * @return list<string>
     */
    public static function validate(string $username, string $fullName, string $email, ?int $ignoreId = null, bool $checkUsername = true): array
    {
        $errors = [];
        $db     = Database::connect();

        if ($checkUsername) {
            if (! preg_match(self::USERNAME_PATTERN, $username)) {
                $errors[] = 'Username must be 3-60 characters: letters, digits, dot, dash or underscore, starting with a letter or digit.';
            } elseif ($db->query('SELECT 1 FROM users WHERE lower(username) = lower(?) AND id <> ?', [$username, $ignoreId ?? 0])->getRowArray()) {
                $errors[] = "The username '{$username}' is already taken.";
            }
        }

        if (trim($fullName) === '' || mb_strlen($fullName) > 150) {
            $errors[] = 'Full name is required (up to 150 characters).';
        }

        $staff = config('Google')->staffDomain;
        if ($email === '') {
            $errors[] = "Institute e-mail is required (@{$staff}): the authority signs in with this Google account.";
        } else {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                $errors[] = 'The email address is not valid.';
            } elseif (GoogleAuth::domainKind($email) !== 'staff') {
                $errors[] = "The e-mail must be an institute staff address ending in @{$staff}.";
            } elseif ($db->query('SELECT 1 FROM users WHERE lower(email) = lower(?) AND id <> ?', [$email, $ignoreId ?? 0])->getRowArray()) {
                $errors[] = "The email '{$email}' is already used by another account.";
            }
        }

        return $errors;
    }

    /** Create an authority account (signs in with Google using $email). Returns the new id. */
    public static function create(string $username, string $fullName, string $email): int
    {
        $users = new UserModel();
        $users->insert([
            'username'             => $username,
            'full_name'            => trim($fullName),
            'email'                => trim($email) === '' ? null : strtolower(trim($email)),
            'password_hash'        => self::noPassword(),
            'is_admin'             => pg_flag(false),
            'active'               => pg_flag(true),
            'must_change_password' => pg_flag(false),
        ]);
        $id = (int) $users->getInsertID();

        AuditLogger::log('create', 'user', $id, null, ['username' => $username, 'full_name' => trim($fullName), 'email' => $email]);

        return $id;
    }

    /** A password hash nobody knows: authorities never sign in with a password. */
    public static function noPassword(): string
    {
        return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    public static function update(int $id, string $fullName, string $email): void
    {
        $before = self::find($id);
        (new UserModel())->touch($id, ['full_name' => trim($fullName), 'email' => strtolower(trim($email))]);
        AuditLogger::log('update', 'user', $id,
            ['full_name' => $before['full_name'], 'email' => $before['email']],
            ['full_name' => trim($fullName), 'email' => $email]);
    }

    /**
     * Activate or deactivate. Deactivating ends the user's posts (they become vacant).
     *
     * @return int number of posts that became vacant
     */
    public static function setActive(int $id, bool $active): int
    {
        $user = self::find($id);
        $db   = Database::connect();
        $made = 0;

        $db->transStart();
        (new UserModel())->touch($id, ['active' => $active]);
        if (! $active) {
            $made = PostService::endAllForUser($id, 'account deactivated');
        }
        AuditLogger::log($active ? 'activate' : 'deactivate', 'user', $id,
            ['active' => is_true($user['active'])], ['active' => $active, 'posts_vacated' => $made]);
        $db->transComplete();

        return $made;
    }

    public static function unlock(int $id): void
    {
        $user = self::find($id);
        (new UserModel())->touch($id, ['failed_attempts' => 0, 'locked_until' => null]);
        AuditLogger::log('unlock', 'user', $id, null, ['username' => $user['username']]);
    }

    /** True when the account has no history and may be deleted outright. */
    public static function isDeletable(int $id): bool
    {
        $db = Database::connect();

        $posts   = (int) $db->query('SELECT count(*) AS n FROM post_holders WHERE user_id = ?', [$id])->getRow('n');
        $actions = (int) $db->query('SELECT count(*) AS n FROM grievance_actions WHERE actor_user_id = ?', [$id])->getRow('n');

        return $posts === 0 && $actions === 0;
    }

    public static function delete(int $id): void
    {
        $user = self::find($id);
        if (! self::isDeletable($id)) {
            throw new RuntimeException('This account has held a post or handled grievances, so it can only be deactivated.');
        }

        Database::connect()->query('DELETE FROM users WHERE id = ? AND NOT is_admin', [$id]);
        AuditLogger::log('delete', 'user', $id, ['username' => $user['username'], 'full_name' => $user['full_name']], null);
    }

    /** @return array<string,mixed> */
    public static function find(int $id): array
    {
        $user = Database::connect()->query('SELECT * FROM users WHERE id = ? AND NOT is_admin', [$id])->getRowArray();
        if ($user === null) {
            throw new RuntimeException('That account does not exist.');
        }

        return $user;
    }
}
