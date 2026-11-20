<?php

namespace App\Controllers\Authority;

use App\Controllers\BaseController;
use App\Libraries\Auth;
use App\Libraries\ExportService;
use App\Libraries\WorkflowService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use RuntimeException;

/**
 * Authority home: the grievances at the posts the logged-in authority holds.
 * Tabs: needs action / waiting for the submitter / earlier (handled at my posts, since moved on or disposed),
 * plus "All grievances" (view only) for the Director. Search by reference or name, filter by type / overdue.
 */
class Dashboard extends BaseController
{
    private const PER_PAGE = 25;

    public function index(): string
    {
        $user = Auth::user();
        [$tab, $filters, $director] = $this->listParams((int) $user['id']);
        $page = max(1, (int) $this->request->getGet('page'));

        $posts = Database::connect()->query(
            "SELECT d.name AS post_name,
                    CASE d.scope_type
                        WHEN 'department' THEN (SELECT dep.depname FROM establishment.dpcode dep WHERE dep.dept = a.scope_id)
                        WHEN 'hostel'     THEN a.hostel
                    END AS scope_name
               FROM post_holders a
               JOIN posts d ON d.id = a.post_id
              WHERE a.user_id = ? AND a.active AND d.active
              ORDER BY d.name",
            [$user['id']]
        )->getResultArray();

        $list  = WorkflowService::inbox((int) $user['id'], $tab, self::PER_PAGE, ($page - 1) * self::PER_PAGE, $filters);
        $types = Database::connect()->query('SELECT id, name, role FROM grievance_types ORDER BY role, sort_order, name')->getResultArray();

        return view('authority/dashboard', [
            'user'   => $user,
            'posts'  => $posts,
            'tab'    => $tab,
            'page'   => $page,
            'pages'  => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'rows'   => $list['rows'],
            'total'  => $list['total'],
            'counts' => WorkflowService::inboxCounts((int) $user['id']),
            'director'   => $director,
            'filters'    => $filters,
            'types'      => $types,
            'postCounts' => count($posts) > 1 ? WorkflowService::postCounts((int) $user['id']) : [],
        ]);
    }

    /**
     * Export the list being viewed (same tab and filters), as CSV or Excel. Each authority exports only their own
     * lists; the Director's "All grievances" tab exports everything.
     */
    public function export(): ResponseInterface|RedirectResponse
    {
        $userId = Auth::id();
        [$tab, $filters] = $this->listParams($userId);
        $kind   = (string) $this->request->getGet('kind');
        $format = (string) $this->request->getGet('format');

        try {
            $ids = array_map('intval', array_column(WorkflowService::inbox($userId, $tab, ExportService::MAX_ROWS + 1, 0, $filters)['rows'], 'id'));
            [$body, $name, $type] = ExportService::build($ids, $kind, $format);
        } catch (RuntimeException $e) {
            return redirect()->to(site_url('authority') . '?' . http_build_query(['tab' => $tab]))->with('error', $e->getMessage());
        }
        ExportService::log('authority:' . $tab, $kind, $format, count($ids), $filters);

        return $this->response
            ->setHeader('Content-Type', $type)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setHeader('Cache-Control', 'no-store, private')
            ->setBody($body);
    }

    /** @return array{0:string,1:array{q:string,type_id:int,overdue:bool},2:bool} tab, filters, is Director */
    private function listParams(int $userId): array
    {
        $director = WorkflowService::isDirector($userId);
        $tabs     = $director ? ['action', 'waiting', 'earlier', 'all'] : ['action', 'waiting', 'earlier'];
        $tab      = (string) $this->request->getGet('tab');

        return [
            in_array($tab, $tabs, true) ? $tab : 'action',
            [
                'q'       => mb_substr(trim((string) $this->request->getGet('q')), 0, 100),
                'type_id' => (int) $this->request->getGet('type'),
                'overdue' => $this->request->getGet('overdue') === '1',
            ],
            $director,
        ];
    }
}
