<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * Manual archiving of old disposed grievances.
 *
 *  - Eligible: disposed, not archived, disposed more than <archive_after_years> years ago (setting, default 3).
 *  - Archiving only sets a flag: nothing is ever deleted (attachments included). Archived grievances leave the
 *    authorities' lists and counts; the admin finds them with the "Archived" filter; submitters can still track them.
 *  - The admin can restore (un-archive) a grievance archived by mistake.
 *  - Every archive / restore is written to the audit log.
 */
class ArchiveService
{
    public static function years(): int
    {
        return max(1, Settings::int('archive_after_years', 3));
    }

    private static function eligibleSql(): string
    {
        return "g.status = 'disposed' AND NOT g.archived AND g.disposed_at < now() - make_interval(years => ?)";
    }

    public static function eligibleCount(): int
    {
        return (int) Database::connect()->query('SELECT count(*) AS n FROM grievances g WHERE ' . self::eligibleSql(), [self::years()])->getRow('n');
    }

    /** @return list<array<string,mixed>> the oldest eligible grievances (preview) */
    public static function eligible(int $limit = 200): array
    {
        return Database::connect()->query(
            "SELECT g.id, g.reference_no, g.subject, g.disposed_at, g.created_at, t.name AS type_name
               FROM grievances g JOIN grievance_types t ON t.id = g.type_id
              WHERE " . self::eligibleSql() . ' ORDER BY g.disposed_at, g.id LIMIT ?',
            [self::years(), $limit]
        )->getResultArray();
    }

    /**
     * Archive every eligible grievance.
     *
     * @param int $expected the count the admin saw on the preview; if more have become eligible since, the admin
     *                      is asked to look again (a day boundary may have passed)
     *
     * @return int how many were archived
     */
    public static function archiveEligible(int $expected): int
    {
        if (self::eligibleCount() > $expected) {
            throw new RuntimeException('More grievances have become eligible since you opened this page. Please check the list again.');
        }

        $db = Database::connect();
        $db->transStart();
        $refs = array_column($db->query(
            'UPDATE grievances g SET archived = true, archived_at = now(), updated_at = now() WHERE ' . self::eligibleSql() . ' RETURNING g.reference_no',
            [self::years()]
        )->getResultArray(), 'reference_no');

        if ($refs !== []) {
            sort($refs);
            AuditLogger::log('archive', 'grievance', null, null, [
                'count' => count($refs), 'older_than_years' => self::years(),
                'references' => array_slice($refs, 0, 1000), 'more' => max(0, count($refs) - 1000),
            ]);
        }
        $db->transComplete();

        return count($refs);
    }

    /** Archive one eligible grievance. */
    public static function archive(int $grievanceId): string
    {
        $db = Database::connect();
        $g  = self::grievance($grievanceId);
        if (is_true($g['archived'])) {
            throw new RuntimeException("{$g['reference_no']} is already archived.");
        }
        if ($g['status'] !== 'disposed') {
            throw new RuntimeException("{$g['reference_no']} is still open. Only disposed grievances can be archived.");
        }
        $ok = $db->query('SELECT 1 FROM grievances g WHERE g.id = ? AND ' . self::eligibleSql(), [$grievanceId, self::years()])->getRowArray();
        if (! $ok) {
            throw new RuntimeException("{$g['reference_no']} was disposed less than " . self::years() . ' year(s) ago, so it cannot be archived yet.');
        }

        $db->transStart();
        $db->query('UPDATE grievances SET archived = true, archived_at = now(), updated_at = now() WHERE id = ?', [$grievanceId]);
        AuditLogger::log('archive', 'grievance', $grievanceId, ['archived' => false], ['archived' => true, 'reference_no' => $g['reference_no']]);
        $db->transComplete();

        return "{$g['reference_no']} has been archived.";
    }

    /** Un-archive a grievance (e.g. archived by mistake). */
    public static function restore(int $grievanceId): string
    {
        $db = Database::connect();
        $g  = self::grievance($grievanceId);
        if (! is_true($g['archived'])) {
            throw new RuntimeException("{$g['reference_no']} is not archived.");
        }

        $db->transStart();
        $db->query('UPDATE grievances SET archived = false, archived_at = NULL, updated_at = now() WHERE id = ?', [$grievanceId]);
        AuditLogger::log('restore', 'grievance', $grievanceId, ['archived' => true, 'archived_at' => $g['archived_at']], ['archived' => false, 'reference_no' => $g['reference_no']]);
        $db->transComplete();

        return "{$g['reference_no']} has been restored from the archive.";
    }

    /** @return array<string,mixed> */
    private static function grievance(int $id): array
    {
        $g = Database::connect()->query('SELECT id, reference_no, status, archived, archived_at FROM grievances WHERE id = ?', [$id])->getRowArray();
        if ($g === null) {
            throw new RuntimeException('That grievance does not exist.');
        }

        return $g;
    }
}
