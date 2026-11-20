<?php

namespace App\Commands;

use App\Libraries\Erp\ErpDirectory;
use App\Libraries\GoogleAuth;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Checks the ERP connection. Read-only: nothing is written anywhere.
 *   php spark portal:erp-test                      tests both ERP connections
 *   php spark portal:erp-test someone@stu.manit.ac.in   also shows what the portal would read for that e-mail
 */
class ErpTest extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:erp-test';
    protected $description = 'Tests the connection to the ERP databases; with an e-mail, shows what the portal reads for that person.';
    protected $usage       = 'portal:erp-test [email]';

    public function run(array $params)
    {
        $ok = true;
        foreach (ErpDirectory::source()->ping() as $name => $r) {
            CLI::write(str_pad($name === 'main' ? 'ERP main database' : 'ERP hostel database', 22) . ($r['ok'] ? 'OK    ' : 'FAILED ') . $r['detail'], $r['ok'] ? 'green' : 'red');
            $ok = $ok && $r['ok'];
        }
        CLI::write('Setting person_source = ' . (ErpDirectory::enabled() ? 'erp' : 'manual (the portal does not read the ERP for sign-in yet; see php spark portal:person-source)'));

        $email = strtolower(trim((string) ($params[0] ?? '')));
        if ($email !== '') {
            $kind = GoogleAuth::domainKind($email);
            if ($kind === null) {
                CLI::error("{$email} is not an institute address.");

                return EXIT_ERROR;
            }
            $res = $kind === 'student' ? ErpDirectory::lookupStudent((string) strstr($email, '@', true)) : ErpDirectory::lookupStaff($email);
            CLI::write('');
            CLI::write("Lookup for {$email}: " . $res['status'] . ($res['detail'] ? " ({$res['detail']})" : ''), $res['status'] === 'ok' ? 'green' : 'yellow');
            foreach ($res['profile'] ?? [] as $k => $v) {
                CLI::write('  ' . str_pad($k, 15) . ($v === null || $v === '' ? '-' : (string) $v));
            }
        }

        return $ok ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
