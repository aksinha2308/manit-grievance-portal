<?php

namespace App\Commands;

use App\Libraries\AuditLogger;
use App\Libraries\PasswordGenerator;
use App\Libraries\Settings;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Creates the single admin account (run once at setup).
 *
 *   php spark portal:create-admin --username admin --name "Portal Admin"
 *
 * A temporary password is generated and shown once unless --password is given.
 * The admin must change it at first login.
 */
class CreateAdmin extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:create-admin';
    protected $description = 'Creates the single admin account.';
    protected $usage       = 'portal:create-admin [--username <name>] [--name <full name>] [--password <temp password>]';
    protected $options     = [
        '--username' => 'Login name (default: admin)',
        '--name'     => 'Full name (default: Portal Admin)',
        '--password' => 'Temporary password (default: generated and displayed once)',
    ];

    public function run(array $params)
    {
        $users = new UserModel();

        $existing = $users->where('is_admin', true)->first();
        if ($existing !== null) {
            CLI::error("An admin account already exists ('{$existing['username']}'). There can be only one.");
            CLI::write('To reset its password run: php spark portal:reset-admin-password');

            return EXIT_ERROR;
        }

        $username = trim((string) (CLI::getOption('username') ?? 'admin'));
        $fullName = trim((string) (CLI::getOption('name') ?? 'Portal Admin'));
        $password = (string) (CLI::getOption('password') ?? '');
        $generated = $password === '';

        if ($username === '' || $fullName === '') {
            CLI::error('Username and name must not be empty.');

            return EXIT_ERROR;
        }

        if ($generated) {
            $password = PasswordGenerator::make();
        }

        $min = Settings::int('password_min_length', 8);
        if (mb_strlen($password) < $min) {
            CLI::error("The password must be at least {$min} characters long.");

            return EXIT_ERROR;
        }

        if ($users->findByUsername($username) !== null) {
            CLI::error("The username '{$username}' is already taken.");

            return EXIT_ERROR;
        }

        $users->insert([
            'username'             => $username,
            'full_name'            => $fullName,
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'is_admin'             => 'true',
            'active'               => 'true',
            'must_change_password' => 'true',
        ]);
        $id = (int) $users->getInsertID();

        AuditLogger::log('create', 'user', $id, null, ['username' => $username, 'is_admin' => true, 'via' => 'cli']);

        CLI::write("Admin account created: {$username}", 'green');
        if ($generated) {
            CLI::write("Temporary password (shown once): {$password}", 'yellow');
        }
        CLI::write('The admin must change this password at first login.');

        return EXIT_SUCCESS;
    }
}
