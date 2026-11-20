<?php

namespace App\Controllers\Admin;

use App\Libraries\Auth;
use App\Libraries\BulkUserImporter;
use App\Libraries\PostService;
use App\Libraries\UserService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use RuntimeException;

/** Authority accounts (they sign in with Google): list, create, edit, activate/deactivate, delete, bulk upload. */
class Users extends AdminController
{
    public function index(): string
    {
        $db = Database::connect();
        $q  = trim((string) $this->request->getGet('q'));
        $status = (string) $this->request->getGet('status');

        $where = ['NOT u.is_admin'];
        $args  = [];
        if ($q !== '') {
            $where[] = '(u.username ILIKE ? OR u.full_name ILIKE ? OR u.email ILIKE ?)';
            $like    = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            array_push($args, $like, $like, $like);
        }
        if ($status === 'active') {
            $where[] = 'u.active';
        } elseif ($status === 'inactive') {
            $where[] = 'NOT u.active';
        }

        $users = $db->query(
            'SELECT u.*, (u.locked_until IS NOT NULL AND u.locked_until > now()) AS is_locked FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY lower(u.full_name) LIMIT 500',
            $args
        )->getResultArray();

        $posts = [];
        foreach ($db->query(
            'SELECT a.user_id, d.name, d.scope_type, a.scope_id, a.hostel FROM post_holders a JOIN posts d ON d.id = a.post_id WHERE a.active ORDER BY d.name'
        )->getResultArray() as $r) {
            $scopeValue = $r['scope_type'] === 'hostel' ? $r['hostel'] : ($r['scope_id'] !== null ? (int) $r['scope_id'] : null);
            $posts[(int) $r['user_id']][] = PostService::label($r['name'], $r['scope_type'], $scopeValue);
        }

        foreach ($users as &$u) {
            $u['posts']     = $posts[(int) $u['id']] ?? [];
            $u['deletable'] = UserService::isDeletable((int) $u['id']);
        }
        unset($u);

        return view('admin/users/index', ['users' => $users, 'q' => $q, 'status' => $status]);
    }

    public function create(): string
    {
        return view('admin/users/form', ['user' => null]);
    }

    public function store(): ResponseInterface|RedirectResponse
    {
        $username = $this->post('username');
        $name     = $this->post('full_name');
        $email    = $this->post('email');

        $errors = UserService::validate($username, $name, $email);
        if ($errors !== []) {
            return $this->failed('admin/users/new', $errors);
        }

        try {
            $id = UserService::create($username, $name, $email);
        } catch (RuntimeException $e) {
            return $this->failed('admin/users/new', $e->getMessage());
        }

        return $this->done("admin/users/{$id}/edit", "Account created. {$name} signs in with Google using " . strtolower($email) . '.');
    }

    public function edit(string $id): string|RedirectResponse
    {
        try {
            $user = UserService::find((int) $id);
        } catch (RuntimeException $e) {
            return $this->failed('admin/users', $e->getMessage(), false);
        }

        $posts = Database::connect()->query(
            'SELECT d.name, d.scope_type, a.scope_id, a.hostel, a.assigned_at FROM post_holders a
               JOIN posts d ON d.id = a.post_id WHERE a.user_id = ? AND a.active ORDER BY d.name',
            [(int) $id]
        )->getResultArray();
        foreach ($posts as &$p) {
            $scopeValue = $p['scope_type'] === 'hostel' ? $p['hostel'] : ($p['scope_id'] !== null ? (int) $p['scope_id'] : null);
            $p['label'] = PostService::label($p['name'], $p['scope_type'], $scopeValue);
        }
        unset($p);

        return view('admin/users/form', ['user' => $user, 'posts' => $posts, 'deletable' => UserService::isDeletable((int) $id)]);
    }

    public function update(string $id): RedirectResponse
    {
        $id     = (int) $id;
        $errors = [];
        try {
            UserService::find($id);
            $errors = UserService::validate('', $this->post('full_name'), $this->post('email'), $id, false);
        } catch (RuntimeException $e) {
            return $this->failed('admin/users', $e->getMessage(), false);
        }
        if ($errors !== []) {
            return $this->failed("admin/users/{$id}/edit", $errors);
        }

        return $this->attempt("admin/users/{$id}/edit", function () use ($id) {
            UserService::update($id, $this->post('full_name'), $this->post('email'));

            return 'Account saved.';
        });
    }

    public function toggle(string $id): RedirectResponse
    {
        $id = (int) $id;

        return $this->attempt('admin/users', function () use ($id) {
            $user   = UserService::find($id);
            $active = ! is_true($user['active']);
            $vacated = UserService::setActive($id, $active);

            return $active
                ? "{$user['full_name']} is active again. Posts must be assigned again under Post holders."
                : "{$user['full_name']} was deactivated." . ($vacated > 0 ? " {$vacated} post(s) are now vacant." : '');
        });
    }

    public function unlock(string $id): RedirectResponse
    {
        $id = (int) $id;

        return $this->attempt("admin/users/{$id}/edit", static function () use ($id) {
            UserService::unlock($id);

            return 'Account unlocked.';
        });
    }

    public function delete(string $id): RedirectResponse
    {
        $id = (int) $id;

        return $this->attempt('admin/users', static function () use ($id) {
            $user = UserService::find($id);
            UserService::delete($id);

            return "Account {$user['username']} deleted.";
        });
    }

    // ---------------------------------------------------------------------
    // Bulk upload
    // ---------------------------------------------------------------------

    public function import(): string
    {
        return view('admin/users/import');
    }

    public function template(): ResponseInterface
    {
        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="authority-upload-template.xlsx"')
            ->setBody(BulkUserImporter::template());
    }

    public function upload(): string|RedirectResponse
    {
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid() || $file->getSize() === 0) {
            return $this->failed('admin/users/import', 'Choose a file to upload.', false);
        }
        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true) || $file->getSize() > 5 * 1024 * 1024) {
            return $this->failed('admin/users/import', 'Upload an .xlsx or .csv file up to 5 MB.', false);
        }

        try {
            $result = BulkUserImporter::parse($file->getTempName(), $ext, Auth::id());
        } catch (RuntimeException $e) {
            return $this->failed('admin/users/import', $e->getMessage(), false);
        } catch (\Throwable $e) {
            log_message('error', 'Bulk upload failed: ' . $e);

            return $this->failed('admin/users/import', 'The file could not be read. Is it a valid .xlsx or .csv file?', false);
        }

        return view('admin/users/import_preview', $result);
    }

    public function confirm(): ResponseInterface|RedirectResponse
    {
        $token = $this->post('token');
        try {
            $r = BulkUserImporter::commit($token, Auth::id());
        } catch (RuntimeException $e) {
            return $this->failed('admin/users/import', $e->getMessage(), false);
        }

        return $this->done('admin/users', "{$r['created']} account(s) and {$r['posts']} post assignment(s) were created. They sign in with Google using their e-mail.");
    }

    public function cancel(): RedirectResponse
    {
        BulkUserImporter::discard($this->post('token'));

        return $this->done('admin/users/import', 'Upload discarded. Nothing was saved.');
    }

}
