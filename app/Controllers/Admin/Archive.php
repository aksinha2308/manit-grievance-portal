<?php

namespace App\Controllers\Admin;

use App\Libraries\ArchiveService;
use CodeIgniter\HTTP\RedirectResponse;

/** Archive old disposed grievances in one go (with a preview first). */
class Archive extends AdminController
{
    public function index(): string
    {
        return view('admin/archive', [
            'years'    => ArchiveService::years(),
            'count'    => ArchiveService::eligibleCount(),
            'preview'  => ArchiveService::eligible(200),
        ]);
    }

    public function run(): RedirectResponse
    {
        $expected = $this->postInt('expected');

        return $this->attempt('admin/archive', static function () use ($expected): string {
            $n = ArchiveService::archiveEligible($expected);

            return $n === 0 ? 'Nothing was eligible for archiving.' : "{$n} grievance(s) archived. They remain searchable under Grievances with the \"Archived\" filter.";
        });
    }
}
