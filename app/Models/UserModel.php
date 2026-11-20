<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Authorities and the single admin. Submitters are never users.
 *
 * Timestamps are maintained by PostgreSQL (DEFAULT now()) and by touch() below, so that
 * they are always true UTC instants regardless of PHP/server timezone.
 */
class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'username', 'full_name', 'email', 'password_hash', 'is_admin', 'active',
        'must_change_password', 'failed_attempts', 'locked_until', 'last_login_at',
    ];

    public function findByUsername(string $username): ?array
    {
        return $this->where('lower(username)', mb_strtolower(trim($username)))->first();
    }

    /** Update columns and bump updated_at with the database clock. */
    public function touch(int $id, array $data): void
    {
        $builder = $this->builder()->where('id', $id);
        foreach ($data as $column => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $builder->set($column, $value);
        }
        $builder->set('updated_at', 'now()', false)->update();
    }
}
