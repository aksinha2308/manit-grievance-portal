<?php

namespace App\Commands;

use App\Libraries\AuditLogger;
use App\Libraries\Settings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Chooses where people come from: php spark portal:person-source erp   (the institute ERP)
 *                                  php spark portal:person-source manual (the portal's own master list)
 */
class PersonSource extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:person-source';
    protected $description = 'Shows or sets where students and staff come from: erp or manual.';
    protected $usage       = 'portal:person-source [erp|manual]';

    public function run(array $params)
    {
        $current = Settings::get('person_source', 'manual');
        $new     = strtolower(trim((string) ($params[0] ?? '')));
        if ($new === '') {
            CLI::write("People come from: {$current}");
            CLI::write('Change with: php spark ' . $this->usage);

            return EXIT_SUCCESS;
        }
        if (! in_array($new, ['erp', 'manual'], true)) {
            CLI::error('Say erp or manual.');

            return EXIT_ERROR;
        }
        Settings::set('person_source', $new);
        AuditLogger::log('update', 'setting', 'person_source', ['value' => $current], ['value' => $new]);
        CLI::write("People now come from: {$new}. Everyone must sign in again.", 'green');

        return EXIT_SUCCESS;
    }
}
