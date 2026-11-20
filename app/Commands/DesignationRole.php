<?php

namespace App\Commands;

use App\Libraries\DesignationRoleService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

/**
 * Tells the portal whether an ERP job title means faculty or non-teaching staff:
 *   php spark portal:designation-role --list
 *   php spark portal:designation-role "Assistant Registrar" non_teaching
 *   php spark portal:designation-role "Assistant Registrar" --remove
 * (An admin screen for this comes in the next step.)
 */
class DesignationRole extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:designation-role';
    protected $description = 'Lists, adds or removes the ERP job titles that mean faculty or non-teaching staff.';
    protected $usage       = 'portal:designation-role --list | "<job title>" faculty|non_teaching | "<job title>" --remove';

    public function run(array $params)
    {
        try {
            if (CLI::getOption('list') !== null || $params === []) {
                foreach (DesignationRoleService::all() as $r) {
                    CLI::write(str_pad($r['designation_text'], 40) . $r['role']);
                }

                return EXIT_SUCCESS;
            }
            if (CLI::getOption('remove') !== null) {
                DesignationRoleService::remove((string) $params[0]);
                CLI::write('Removed.', 'green');

                return EXIT_SUCCESS;
            }
            DesignationRoleService::set((string) $params[0], (string) ($params[1] ?? ''));
            CLI::write('Saved.', 'green');

            return EXIT_SUCCESS;
        } catch (RuntimeException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }
    }
}
