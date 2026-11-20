<?php

namespace App\Commands;

use App\Libraries\Erp\ErpSync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

/**
 * php spark portal:erp-sync            shows what a sync would do (changes nothing)
 * php spark portal:erp-sync --apply    does it (can be run by a scheduled task)
 * Add --confirm-mass-off to allow switching off a large share of people at once.
 */
class ErpSyncCommand extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:erp-sync';
    protected $description = 'Compares the portal with the ERP and (with --apply) updates and switches off people. Accounts are only created at first sign-in.';
    protected $usage       = 'portal:erp-sync [--apply] [--confirm-mass-off]';

    public function run(array $params)
    {
        $scan = ErpSync::scan();
        if ($scan['error'] !== null) {
            CLI::error($scan['error']);

            return EXIT_ERROR;
        }
        CLI::write("ERP: {$scan['students']} students, {$scan['staff']} staff");
        CLI::write('  no account yet (created at first sign-in): ' . count($scan['not_yet']) . ', taken over: ' . count($scan['adopted']) . ', changed: ' . count($scan['changed'])
            . ', back: ' . count($scan['reactivated']) . ', to switch off: ' . count($scan['missing']) . ', up to date: ' . $scan['unchanged']);
        CLI::write('  skipped: ' . count($scan['conflicts']) . ' problem records, ' . array_sum($scan['blocked_titles']) . ' staff with unlisted titles');
        if (CLI::getOption('apply') === null) {
            CLI::write('Nothing was changed. Add --apply to do it.', 'yellow');

            return EXIT_SUCCESS;
        }
        try {
            $n = ErpSync::apply(CLI::getOption('confirm-mass-off') !== null, 'command line');
        } catch (RuntimeException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }
        CLI::write("Done: {$n['adopted']} taken over, {$n['changed']} updated, {$n['reactivated']} back on, {$n['switched_off']} switched off.", 'green');

        return EXIT_SUCCESS;
    }
}
