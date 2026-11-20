<?php

namespace App\Commands;

use App\Libraries\AuditLogger;
use App\Libraries\GoogleAuth;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Lets the admin also sign in with Google: php spark portal:admin-email name@manit.ac.in
 * (php spark portal:admin-email --clear removes it). The admin's password login keeps working either way.
 */
class AdminEmail extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:admin-email';
    protected $description = "Sets (or clears) the admin's institute e-mail for Google sign-in.";
    protected $usage       = 'portal:admin-email <name@staff-domain> | --clear';

    public function run(array $params)
    {
        $db    = Database::connect();
        $admin = $db->query('SELECT id, username, email FROM users WHERE is_admin')->getRowArray();
        if ($admin === null) {
            CLI::error('There is no admin account yet. Run: php spark portal:create-admin');

            return EXIT_ERROR;
        }

        $email = CLI::getOption('clear') !== null ? '' : strtolower(trim((string) ($params[0] ?? '')));
        if ($email === '' && CLI::getOption('clear') === null) {
            CLI::write('Current admin e-mail: ' . ($admin['email'] ?? '(none)'));
            CLI::write('Usage: php spark ' . $this->usage);

            return EXIT_SUCCESS;
        }
        if ($email !== '' && GoogleAuth::domainKind($email) !== 'staff') {
            CLI::error('The e-mail must be an institute staff address ending in @' . config('Google')->staffDomain . '.');

            return EXIT_ERROR;
        }
        if ($email !== '' && $db->query('SELECT 1 FROM users WHERE lower(email) = ? AND id <> ?', [$email, $admin['id']])->getRowArray() !== null) {
            CLI::error('That e-mail already belongs to an authority account.');

            return EXIT_ERROR;
        }

        $db->query('UPDATE users SET email = ?, updated_at = now() WHERE id = ?', [$email === '' ? null : $email, $admin['id']]);
        AuditLogger::log('update', 'user', $admin['id'], ['email' => $admin['email']], ['email' => $email === '' ? null : $email]);
        CLI::write($email === '' ? 'Admin e-mail cleared. The admin signs in with the password only.' : "The admin can now also sign in with Google as {$email}.", 'green');

        return EXIT_SUCCESS;
    }
}
