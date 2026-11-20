<?php

namespace App\Controllers\Admin;

use App\Libraries\DesignationRoleService;
use App\Libraries\Erp\ErpDirectory;
use App\Libraries\Erp\ErpSync;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;
use RuntimeException;

/**
 * ERP screens: connection check, job titles, people read from the ERP, and "Sync from ERP".
 */
class ErpAdmin extends AdminController
{
    private const PER_PAGE = 50;

    public function index(): string
    {
        $enabled  = ErpDirectory::enabled();
        $ping     = null;
        $unmapped = null;
        try {
            $ping = ErpDirectory::source()->ping();
        } catch (\Throwable $e) {
            $ping = ['main' => ['ok' => false, 'detail' => 'check failed'], 'hostel' => ['ok' => false, 'detail' => 'check failed']];
        }
        if ($ping['main']['ok']) {
            $unmapped = ErpSync::unmapped();
        }

        return view('admin/erp/index', [
            'enabled'     => $enabled,
            'ping'        => $ping,
            'unmapped'    => $unmapped,
            'titles'      => DesignationRoleService::all(),
            'roles'       => DesignationRoleService::ROLES,
        ]);
    }

    public function setTitle(): RedirectResponse
    {
        $title = $this->post('title');
        $role  = $this->post('role');

        return $this->attempt('admin/erp', static function () use ($title, $role) {
            DesignationRoleService::set($title, $role);
            ErpDirectory::forgetMemo();

            return "Job title '{$title}' is now treated as " . (DesignationRoleService::ROLES[$role] ?? $role) . '. People with this title can sign in.';
        });
    }

    public function removeTitle(): RedirectResponse
    {
        $title = $this->post('title');

        return $this->attempt('admin/erp', static function () use ($title) {
            DesignationRoleService::remove($title);

            return "Job title '{$title}' was removed from the list. People with this title cannot sign in until it is listed again.";
        });
    }

    // ----- people (read only) -----------------------------------------

    public function people(): string
    {
        $db     = Database::connect();
        $role   = (string) $this->request->getGet('role');
        $status = (string) $this->request->getGet('status');
        $q      = trim((string) $this->request->getGet('q'));
        $page   = max(1, (int) $this->request->getGet('page'));

        $where  = ["source = 'erp'"];
        $binds  = [];
        if (in_array($role, ['student', 'faculty', 'non_teaching'], true)) {
            $where[] = 'role = ?';
            $binds[] = $role;
        }
        if ($status === 'active') {
            $where[] = 'active';
        } elseif ($status === 'inactive') {
            $where[] = 'NOT active';
        }
        if ($q !== '') {
            $where[]  = '(id_code ILIKE ? OR name ILIKE ? OR email ILIKE ?)';
            $like     = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            array_push($binds, $like, $like, $like);
        }
        $w     = implode(' AND ', $where);
        $total = (int) $db->query("SELECT count(*) AS n FROM master_people WHERE {$w}", $binds)->getRow('n');
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);
        $rows  = $db->query(
            "SELECT id, role, id_code, name, email, active, erp_synced_at FROM master_people WHERE {$w} ORDER BY role, lower(id_code) LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $binds
        )->getResultArray();

        return view('admin/erp/people', compact('rows', 'role', 'status', 'q', 'page', 'pages', 'total') + ['enabled' => ErpDirectory::enabled()]);
    }

    // ----- sync ---------------------------------------------------------

    public function sync(): string
    {
        return view('admin/erp/sync', ['scan' => ErpSync::scan(), 'enabled' => ErpDirectory::enabled()]);
    }

    public function runSync(): RedirectResponse
    {
        $confirm = $this->postFlag('confirm_mass_off');

        return $this->attempt('admin/erp/sync', static function () use ($confirm) {
            $n = ErpSync::apply($confirm, 'admin');

            return "Sync done: {$n['adopted']} taken over from the old master list, {$n['changed']} updated, "
                 . "{$n['reactivated']} switched back on, {$n['switched_off']} switched off" . ($n['skipped'] > 0 ? ", {$n['skipped']} skipped" : '') . '.';
        });
    }

}
