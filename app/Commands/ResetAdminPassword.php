<?php

namespace App\Commands;

use App\Libraries\AuditLogger;
use App\Libraries\PasswordGenerator;
use App\Libraries\Settings;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Recovery path for a forgotten admin password (there is no self-service reset).
 *
 *   php spark portal:reset-admin-password
 *
 * Sets a temporary password, clears any lockout, and forces a change at next login.
 */
class ResetAdminPassword extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:reset-admin-password';
    protected $description = 'Resets the admin password to a temporary one.';
    protected $usage       = 'portal:reset-admin-password [--password <temp password>]';
    protected $options     = [
        '--password' => 'Temporary password (default: generated and displayed once)',
    ];

    public function run(array $params)
    {
        $users = new UserModel();
        $admin = $users->where('is_admin', true)->first();

        if ($admin === null) {
            CLI::error('No admin account exists. Create one with: php spark portal:create-admin');

            return EXIT_ERROR;
        }

        $password  = (string) (CLI::getOption('password') ?? '');
        $generated = $password === '';
        if ($generated) {
            $password = PasswordGenerator::make();
        }

        $min = Settings::int('password_min_length', 8);
        if (mb_strlen($password) < $min) {
            CLI::error("The password must be at least {$min} characters long.");

            return EXIT_ERROR;
        }

        $users->touch((int) $admin['id'], [
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => true,
            'active'               => true,
            'failed_attempts'      => 0,
            'locked_until'         => null,
        ]);

        AuditLogger::log('password_reset', 'user', $admin['id'], null, ['username' => $admin['username'], 'via' => 'cli']);

        CLI::write("Password reset for admin '{$admin['username']}'.", 'green');
        if ($generated) {
            CLI::write("Temporary password (shown once): {$password}", 'yellow');
        }
        CLI::write('The admin must change it at next login.');

        return EXIT_SUCCESS;
    }
}
