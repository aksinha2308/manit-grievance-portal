<?php

namespace App\Controllers;

use App\Libraries\Auth;
use App\Libraries\Settings;
use App\Libraries\SubmissionException;
use App\Libraries\SubmissionService;
use App\Libraries\TrackingService;
use App\Libraries\WorkflowService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/**
 * "My grievances" for the signed-in student / staff member (route filter 'submitter'). Replaces tracking by
 * reference number: a person sees exactly the grievances submitted under their Scholar Number / Employee Code.
 * Somebody else's grievance is answered with 404, as if it did not exist.
 */
class My extends BaseController
{
    private const REPLY_LIMIT = 60;   // per IP per 10 minutes

    public function index(): string
    {
        $person = Auth::person();
        $rows   = Database::connect()->query(
            'SELECT id FROM grievances WHERE role = ? AND lower(id_code) = lower(?) ORDER BY id DESC',
            [$person['role'], $person['id_code']]
        )->getResultArray();

        $list = [];
        foreach ($rows as $r) {
            $g      = WorkflowService::find((int) $r['id']);
            $reply  = WorkflowService::replyState((int) $r['id']);
            $list[] = $g + ['reply_mode' => $reply['mode']];
        }

        return view('my/index', [
            'person'  => $person,
            'list'    => $list,
            'canSubmit' => SubmissionService::types((string) $person['role']) !== [],
        ]);
    }

    public function show(string $id): string
    {
        $id = $this->owned($id);

        return view('my/view', TrackingService::view($id) + [
            'maxFiles'   => Settings::int('attach_max_count', 3),
            'maxMb'      => Settings::int('attach_max_mb', 5),
            'allowedExt' => array_filter(explode(',', (string) Settings::get('attach_allowed_ext', ''))),
        ]);
    }

    public function reply(string $id): RedirectResponse
    {
        $id = $this->owned($id);
        if (! $this->withinRateLimit('my_reply', self::REPLY_LIMIT)) {
            return redirect()->to("my/{$id}")->withInput()->with('error', 'Too many attempts. Please wait a few minutes and try again.');
        }

        $g = WorkflowService::find($id);
        try {
            $mode = WorkflowService::submitterReply((string) $g['reference_no'], (string) $g['id_code'],
                (string) $this->request->getPost('reply'), $this->uploadedFiles('attachments'));
        } catch (SubmissionException $e) {
            return redirect()->to("my/{$id}")->withInput()->with('errors', $e->errors);
        }

        $post = WorkflowService::find($id)['level_label'];

        return redirect()->to("my/{$id}")->with('success', $mode === 'reply'
            ? "Your reply has been sent to {$post}."
            : "Your answer has been added to your grievance for {$post}, who is handling it now.");
    }

    public function attachment(string $id, string $attachmentId): DownloadResponse
    {
        $id   = $this->owned($id);
        $a    = TrackingService::attachment($id, (int) $attachmentId);
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

    /** The grievance id if it belongs to the signed-in person; 404 otherwise. */
    private function owned(string $id): int
    {
        $person = Auth::person();
        $row    = Database::connect()->query(
            'SELECT id FROM grievances WHERE id = ? AND role = ? AND lower(id_code) = lower(?)',
            [(int) $id, $person['role'], $person['id_code']]
        )->getRowArray();
        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return (int) $row['id'];
    }
}
