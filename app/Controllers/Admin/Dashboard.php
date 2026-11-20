<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Auth;
use App\Libraries\PostService;
use App\Libraries\WorkflowService;
use Config\Database;

/** Admin home: configuration counters, grievance totals, and warnings about vacant posts. */
class Dashboard extends BaseController
{
    public function index(): string
    {
        $db = Database::connect();
        $n  = static fn (string $sql) => (int) $db->query($sql)->getRow('n');

        return view('admin/dashboard', [
            'user'   => Auth::user(),
            'counts' => [
                'Active grievance types' => $n('SELECT count(*) AS n FROM grievance_types WHERE active'),
                'Posts'           => $n('SELECT count(*) AS n FROM posts WHERE active'),
                'Authority accounts'     => $n('SELECT count(*) AS n FROM users WHERE active AND NOT is_admin'),
                'Departments'            => $n('SELECT count(*) AS n FROM establishment.dpcode'),
            ],
            'vacancies' => PostService::vacancies(),
            'workflow'  => WorkflowService::adminCounts(),
            'stuck'     => WorkflowService::waitingAtVacantPosts(50),
        ]);
    }
}
