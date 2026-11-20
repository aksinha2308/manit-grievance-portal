<?php

namespace App\Libraries;

use Config\Database;

/**
 * What a submitter sees when tracking a grievance (My grievances, after Google sign-in).
 *
 * Rules agreed for v1:
 *  - every authority remark is shown (questions, escalation reasons, the final decision);
 *  - authorities are shown by POST only ("Head of Department (Civil Engineering)"), never by personal name;
 *  - the submitter can download their own attachments, and reply only when a question is open
 *    (normal reply, or a late answer after an escalation withdrew the question);
 *  - nothing can be done after disposal.
 */
class TrackingService
{
    /** Grievance id for a reference number + Scholar Number / Employee Code pair, or null. Case-insensitive. */
    public static function find(string $referenceNo, string $idCode): ?int
    {
        $ref = strtoupper(trim($referenceNo));
        $id  = trim($idCode);
        if ($ref === '' || $id === '' || mb_strlen($ref) > 40 || mb_strlen($id) > 50) {
            return null;
        }

        $row = Database::connect()->query(
            'SELECT id FROM grievances WHERE reference_no = ? AND lower(id_code) = lower(?)',
            [$ref, $id]
        )->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Everything the tracking page shows.
     *
     * @return array<string,mixed>
     */
    public static function view(int $grievanceId): array
    {
        $g = WorkflowService::find($grievanceId);

        $history = [];
        foreach (WorkflowService::history($grievanceId) as $h) {
            $post = (string) ($h['level_label'] ?? '');
            $to   = (string) ($h['to_level_label'] ?? $post);
            [$title, $by] = match ($h['action']) {
                'submit'          => ['Grievance submitted', 'You · sent to ' . $post],
                'request_info'    => ['Question for you', $post],
                'submitter_reply' => ['Your reply', 'You · to ' . $post],
                'late_reply'      => ['Your late answer', 'You · added for ' . $post],
                'escalate'        => [($h['skipped_levels'] ?? 0) > 0 ? 'Transferred directly to ' . $to : 'Transferred to ' . $to, $post],
                'reroute'         => ['Moved to ' . $to, 'The route for this type of grievance was changed'],
                'dispose'         => ['Disposed', $post],
                default           => [$h['action'], $post],
            };
            $history[] = [
                'action'      => $h['action'],
                'title'       => $title,
                'by'          => $by,
                'remark'      => $h['action'] === 'reroute' ? null : $h['remark'],
                'created_at'  => $h['created_at'],
                'attachments' => $h['attachments'],
            ];
        }

        $status = match ($g['status']) {
            'awaiting_submitter' => 'Waiting for your reply',
            'disposed'           => 'Disposed',
            default              => 'Under review',
        };

        return [
            'g'           => $g,
            'status_text' => $status,
            'history'     => $history,
            'attachments' => array_values(array_filter(WorkflowService::attachments($grievanceId), static fn ($a) => $a['action_id'] === null)),
            'reply'       => WorkflowService::replyState($grievanceId),
        ];
    }

    /** @return array<string,mixed>|null an attachment of this grievance (submission or reply) */
    public static function attachment(int $grievanceId, int $attachmentId): ?array
    {
        return WorkflowService::attachment($grievanceId, $attachmentId);
    }
}
