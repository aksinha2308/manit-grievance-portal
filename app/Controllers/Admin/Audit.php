<?php

namespace App\Controllers\Admin;

use Config\Database;

/** Read-only view of the append-only audit log. */
class Audit extends AdminController
{
    private const PER_PAGE = 50;

    public function index(): string
    {
        $db     = Database::connect();
        $entity = trim((string) $this->request->getGet('entity'));
        $action = trim((string) $this->request->getGet('action'));
        $who    = trim((string) $this->request->getGet('who'));
        $page   = max(1, (int) $this->request->getGet('page'));

        $where = [];
        $args  = [];
        if ($entity !== '') {
            $where[] = 'entity = ?';
            $args[]  = $entity;
        }
        if ($action !== '') {
            $where[] = 'action = ?';
            $args[]  = $action;
        }
        if ($who !== '') {
            $where[] = 'username ILIKE ?';
            $args[]  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $who) . '%';
        }
        $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) $db->query("SELECT count(*) AS n FROM audit_log{$w}", $args)->getRow('n');
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);

        $rows = $db->query(
            "SELECT * FROM audit_log{$w} ORDER BY id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $args
        )->getResultArray();

        return view('admin/audit', [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'entity' => $entity, 'action' => $action, 'who' => $who,
            'entities' => array_column($db->query('SELECT DISTINCT entity FROM audit_log ORDER BY entity')->getResultArray(), 'entity'),
            'actions'  => array_column($db->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->getResultArray(), 'action'),
        ]);
    }
}
