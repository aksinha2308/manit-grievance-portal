<?php

namespace App\Commands;

use App\Libraries\WorkflowService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Logs overdue reminders (no e-mail is sent in v1; the events wait in notification_events).
 *
 *   php spark portal:reminders
 *
 * Run it once a day (Windows Task Scheduler or cron). Running it more often is harmless: a grievance gets
 * one reminder when it becomes overdue and another every <overdue days of its level> while it stays overdue.
 * Time spent waiting for the submitter is not counted.
 */
class Reminders extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:reminders';
    protected $description = 'Logs a reminder event for every overdue grievance (safe to run repeatedly).';

    public function run(array $params)
    {
        $n = WorkflowService::logOverdueReminders();
        CLI::write($n === 0 ? 'No new overdue reminders.' : "{$n} overdue reminder(s) logged.", 'green');

        return EXIT_SUCCESS;
    }
}
