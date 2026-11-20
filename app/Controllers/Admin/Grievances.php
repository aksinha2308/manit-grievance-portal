<?php

namespace App\Controllers\Admin;

use App\Libraries\ArchiveService;
use App\Libraries\ExportService;
use App\Libraries\GrievanceSearch;
use App\Libraries\SubmissionService;
use App\Libraries\WorkflowService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * All grievances for the admin: search, read-only detail (including the names of the authorities who acted),
 * attachments, export, archive / restore. The admin never acts on a grievance.
 */
class Grievances extends AdminController
{
    private const PER_PAGE = 25;

    public function index(): string
    {
        $filters = GrievanceSearch::clean($this->request->getGet() ?? []);
        $page    = max(1, (int) $this->request->getGet('page'));
        $list    = GrievanceSearch::search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return view('admin/grievances/index', [
            'filters' => $filters,
            'rows'    => $list['rows'],
            'total'   => $list['total'],
            'page'    => $page,
            'pages'   => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'options' => GrievanceSearch::options(),
        ]);
    }

    public function show(int $id): string
    {
        $g = WorkflowService::find($id);
        if ($g === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $related = WorkflowService::related($id, 0);
        foreach ($related as &$r) {
            $r['can_open'] = true;   // the admin may open every grievance
            $r['subject']  ??= (string) \Config\Database::connect()->query('SELECT subject FROM grievances WHERE id = ?', [$r['id']])->getRow('subject');
        }
        unset($r);

        return view('authority/grievance', [
            'g'           => $g,
            'history'     => WorkflowService::history($id),
            'attachments' => array_values(array_filter(WorkflowService::attachments($id), static fn ($a) => $a['action_id'] === null)),
            'canAct'      => false,
            'next'        => null,
            'related'     => $related,
            'isDirector'  => false,
            'adminView'   => true,
            'base'        => 'admin/grievances',
            'back'        => 'admin/grievances',
            'archivable'  => $g['status'] === 'disposed' && ! is_true($g['archived']),
        ]);
    }

    public function attachment(int $id, int $attachmentId): DownloadResponse
    {
        $a    = WorkflowService::attachment($id, $attachmentId);
        $path = $a === null ? null : SubmissionService::path((string) $a['stored_name']);
        if ($path === null || ! is_file($path)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->download($path, null)
            ->setFileName((string) $a['original_name'])
            ->setContentType((string) $a['mime_type'])
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store, private');
    }

    /** Export the grievances matching the current filters (GET: kind=grievances|history, format=csv|xlsx). */
    public function export(): ResponseInterface|RedirectResponse
    {
        $filters = GrievanceSearch::clean($this->request->getGet() ?? []);
        $kind    = (string) $this->request->getGet('kind');
        $format  = (string) $this->request->getGet('format');

        try {
            $ids = GrievanceSearch::ids($filters, ExportService::MAX_ROWS);
            [$body, $name, $type] = ExportService::build($ids, $kind, $format);
        } catch (RuntimeException $e) {
            return redirect()->to(site_url('admin/grievances') . '?' . http_build_query($filters))->with('errors', [$e->getMessage()]);
        }
        ExportService::log('admin', $kind, $format, count($ids), $filters);

        return $this->response
            ->setHeader('Content-Type', $type)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setHeader('Cache-Control', 'no-store, private')
            ->setBody($body);
    }

    public function archive(int $id): RedirectResponse
    {
        return $this->attempt("admin/grievances/{$id}", static fn () => ArchiveService::archive($id));
    }

    public function restore(int $id): RedirectResponse
    {
        return $this->attempt("admin/grievances/{$id}", static fn () => ArchiveService::restore($id));
    }
}
