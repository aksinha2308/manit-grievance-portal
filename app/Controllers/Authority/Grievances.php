<?php

namespace App\Controllers\Authority;

use App\Controllers\BaseController;
use App\Libraries\Auth;
use App\Libraries\SubmissionService;
use App\Libraries\WorkflowService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

/**
 * One grievance as an authority sees it, and the three actions (dispose / transfer / request information).
 * A grievance the authority may not see answers "not found", so its existence is not revealed.
 */
class Grievances extends BaseController
{
    public function show(int $id): string
    {
        $userId = Auth::id();
        if (! WorkflowService::canView($userId, $id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $g = WorkflowService::find($id);

        return view('authority/grievance', [
            'g'           => $g,
            'history'     => WorkflowService::history($id),
            'attachments' => array_values(array_filter(WorkflowService::attachments($id), static fn ($a) => $a['action_id'] === null)),
            'canAct'      => WorkflowService::canAct($userId, $id),
            'related'     => WorkflowService::related($id, $userId),
            'isDirector'  => WorkflowService::isDirector($userId),
            'targets'     => WorkflowService::transferTargets($g),
            'next'        => $g['status'] !== 'disposed' && $g['level_no'] !== null ? WorkflowService::nextLevelLabel($g) : null,
        ]);
    }

    public function act(int $id): RedirectResponse
    {
        $userId = Auth::id();
        if (! WorkflowService::canView($userId, $id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $back    = "authority/grievances/{$id}";
        $version = $this->request->getPost('version');

        try {
            $message = WorkflowService::act(
                $id,
                $userId,
                (string) $this->request->getPost('action'),
                (string) $this->request->getPost('remark'),
                $version === null || $version === '' ? null : (int) $version,
                $this->request->getPost('confirm_waiting') === '1',
                ($t = $this->request->getPost('target_level')) === null || $t === '' ? null : (int) $t
            );
        } catch (RuntimeException $e) {
            return redirect()->to(site_url($back))->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('authority'))->with('success', $message);
    }

    /** Download an attachment (submission or submitter reply). Files are never served from the web root. */
    public function attachment(int $id, int $attachmentId): DownloadResponse
    {
        if (! WorkflowService::canView(Auth::id(), $id)) {
            throw PageNotFoundException::forPageNotFound();
        }
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
}
