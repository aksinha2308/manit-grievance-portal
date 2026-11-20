<?php

namespace App\Controllers\Admin;

use App\Libraries\Auth;
use App\Libraries\MasterListImporter;
use App\Libraries\MasterListService;
use App\Libraries\PostService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use RuntimeException;

/** The people who may submit grievances: students, faculty, non-teaching staff. */
class MasterList extends AdminController
{
    public function index(string $role = 'student'): string
    {
        $this->guard($role);
        $result = MasterListService::search(
            $role,
            trim((string) $this->request->getGet('q')),
            (int) $this->request->getGet('department'),
            (string) $this->request->getGet('status'),
            (int) $this->request->getGet('page')
        );

        return view('admin/people/index', $result + [
            'role'   => $role,
            'counts' => MasterListService::counts(),
            'depts'  => $this->departments(),
            'q'      => trim((string) $this->request->getGet('q')),
            'dept'   => (int) $this->request->getGet('department'),
            'status' => (string) $this->request->getGet('status'),
        ]);
    }

    public function create(string $role): string
    {
        $this->guard($role);

        return view('admin/people/form', $this->formData($role, null));
    }

    public function store(string $role): RedirectResponse
    {
        $this->guard($role);
        $sem  = $this->postInt('semester_id') ?: null;
        $host = trim($this->post('hostel')) ?: null;
        $room = trim($this->post('room')) ?: null;

        $errors = MasterListService::validate($role, $this->post('id_code'), $this->post('name'), $this->postInt('department_id'), $sem, $host, $this->post('contact'), null, $this->post('email'), $room);
        if ($errors !== []) {
            return $this->failed("admin/people/{$role}/new", $errors);
        }

        return $this->attempt("admin/people/{$role}", function () use ($role, $sem, $host, $room) {
            MasterListService::create($role, $this->post('id_code'), $this->post('name'), $this->postInt('department_id'), $sem, $host, $this->post('contact'), $this->post('email'), $room);

            return 'Person added.';
        });
    }

    public function edit(string $role, string $id): string|RedirectResponse
    {
        $this->guard($role);
        try {
            $p = MasterListService::find((int) $id);
        } catch (RuntimeException $e) {
            return $this->failed("admin/people/{$role}", $e->getMessage(), false);
        }
        if ($p['role'] !== $role) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('admin/people/form', $this->formData($role, $p));
    }

    public function update(string $role, string $id): RedirectResponse
    {
        $this->guard($role);
        $id   = (int) $id;
        $sem  = $this->postInt('semester_id') ?: null;
        $host = trim($this->post('hostel')) ?: null;
        $room = trim($this->post('room')) ?: null;

        try {
            $p = MasterListService::find($id);
            $errors = MasterListService::validate($p['role'], $p['id_code'], $this->post('name'), $this->postInt('department_id'), $sem, $host, $this->post('contact'), $id, $this->post('email'), $room);
        } catch (RuntimeException $e) {
            return $this->failed("admin/people/{$role}", $e->getMessage(), false);
        }
        if ($errors !== []) {
            return $this->failed("admin/people/{$role}/{$id}/edit", $errors);
        }

        return $this->attempt("admin/people/{$role}/{$id}/edit", function () use ($id, $sem, $host, $room) {
            MasterListService::update($id, $this->post('name'), $this->postInt('department_id'), $sem, $host, $this->post('contact'), $this->post('email'), $room);

            return 'Saved.';
        });
    }

    public function toggle(string $role, string $id): RedirectResponse
    {
        $this->guard($role);
        $id = (int) $id;

        return $this->attempt("admin/people/{$role}", static function () use ($id) {
            $p      = MasterListService::find($id);
            $active = ! is_true($p['active']);
            MasterListService::setActive($id, $active);

            return "{$p['id_code']} " . ($active ? 'can submit grievances again.' : 'was deactivated and can no longer submit grievances.');
        });
    }

    public function delete(string $role, string $id): RedirectResponse
    {
        $this->guard($role);
        $id = (int) $id;

        return $this->attempt("admin/people/{$role}", static function () use ($id) {
            $p = MasterListService::find($id);
            MasterListService::delete($id);

            return "{$p['id_code']} was deleted.";
        });
    }

    // ---------------------------------------------------------------------
    // Bulk upload
    // ---------------------------------------------------------------------

    public function import(string $role): string
    {
        $this->guard($role);

        return view('admin/people/import', ['role' => $role, 'counts' => MasterListService::counts()]);
    }

    public function template(string $role): ResponseInterface
    {
        $this->guard($role);

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', "attachment; filename=\"master-list-{$role}-template.xlsx\"")
            ->setBody(MasterListImporter::template($role));
    }

    public function upload(string $role): string|RedirectResponse
    {
        $this->guard($role);
        $to   = "admin/people/{$role}/import";
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid() || $file->getSize() === 0) {
            return $this->failed($to, 'Choose a file to upload.', false);
        }
        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true) || $file->getSize() > 15 * 1024 * 1024) {
            return $this->failed($to, 'Upload an .xlsx or .csv file up to 15 MB.', false);
        }

        try {
            $result = MasterListImporter::parse($file->getTempName(), $ext, $role, Auth::id());
        } catch (RuntimeException $e) {
            return $this->failed($to, $e->getMessage(), false);
        } catch (\Throwable $e) {
            log_message('error', 'Master list upload failed: ' . $e);

            return $this->failed($to, 'The file could not be read. Is it a valid .xlsx or .csv file?', false);
        }

        return view('admin/people/import_preview', $result);
    }

    public function confirm(): RedirectResponse
    {
        try {
            $r = MasterListImporter::commit($this->post('token'), Auth::id());
        } catch (RuntimeException $e) {
            return $this->failed($this->importPage(), $e->getMessage(), false);
        } catch (\Throwable $e) {
            log_message('error', 'Master list save failed: ' . $e);

            return $this->failed($this->importPage(), 'The upload could not be saved and nothing was changed.', false);
        }

        return $this->done("admin/people/{$r['role']}", "Upload saved: {$r['rows']} row(s) added or updated.");
    }

    public function cancel(): RedirectResponse
    {
        $to = $this->importPage();
        \App\Libraries\ImportStore::discard($this->post('token'));

        return $this->done($to, 'Upload discarded. Nothing was saved.');
    }

    // ---------------------------------------------------------------------

    /** The upload page of the role a stored preview belongs to (student when unknown). */
    private function importPage(): string
    {
        try {
            $role = \App\Libraries\ImportStore::load($this->post('token'), Auth::id())['role'] ?? 'student';
        } catch (\Throwable) {
            $role = 'student';
        }

        return 'admin/people/' . (in_array($role, PostService::ROLES, true) ? $role : 'student') . '/import';
    }

    /** @return array<string,mixed> */
    private function formData(string $role, ?array $person): array
    {
        $db = Database::connect();

        return [
            'role'      => $role,
            'person'    => $person,
            'depts'     => $this->departments(),
            'semesters' => $db->query('SELECT id, name FROM semesters WHERE active ORDER BY sort_order')->getResultArray(),
            'grievances' => $person !== null ? MasterListService::grievanceCount($person) : 0,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function departments(): array
    {
        return Database::connect()->query(
            'SELECT dept AS id, depname AS name FROM establishment.dpcode ORDER BY lower(depname)'
        )->getResultArray();
    }

    private function guard(string $role): void
    {
        if (! in_array($role, PostService::ROLES, true)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
