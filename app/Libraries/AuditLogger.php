<?php

namespace App\Libraries;

use Config\Database;
use Config\Services;

/**
 * Writes to the append-only audit_log table.
 *
 * Every configuration change in the portal goes through here:
 *   AuditLogger::log('update', 'escalation_level', 12, $before, $after);
 *
 * The acting user and IP are taken from the session/request. In CLI commands the
 * actor is recorded as "cli".
 */
class AuditLogger
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function log(
        string $action,
        string $entity,
        string|int|null $entityId = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        $userId   = null;
        $username = 'system';
        $ip       = null;

        if (is_cli()) {
            $username = 'cli';
        } else {
            $session  = Services::session();
            $userId   = $session->get('user_id');
            $username = $session->get('username') ?? 'anonymous';
            $ip       = Services::request()->getIPAddress();
        }

        // Never write secrets into the log, even by accident
        foreach (['password', 'password_hash'] as $secret) {
            if (is_array($before) && array_key_exists($secret, $before)) {
                $before[$secret] = '[hidden]';
            }
            if (is_array($after) && array_key_exists($secret, $after)) {
                $after[$secret] = '[hidden]';
            }
        }

        Database::connect()->table('audit_log')->insert([
            'user_id'     => $userId,
            'username'    => $username,
            'action'      => $action,
            'entity'      => $entity,
            'entity_id'   => $entityId === null ? null : (string) $entityId,
            'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_data'  => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'ip_address'  => $ip,
        ]);
    }
}
