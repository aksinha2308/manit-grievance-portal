<?php

namespace App\Controllers\Admin;

use App\Libraries\FormFieldService;
use App\Libraries\PostService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/** The submission form of each role: rename, reorder, show/hide, mandatory, custom fields. */
class FormFields extends AdminController
{
    public function index(string $role = 'student'): string
    {
        $this->guard($role);

        return view('admin/fields/index', ['role' => $role, 'fields' => FormFieldService::fields($role), 'types' => FormFieldService::TYPES]);
    }

    public function add(string $role): RedirectResponse
    {
        $this->guard($role);
        $to = "admin/fields/{$role}";

        $errors = FormFieldService::validate($role, $this->post('label'), $this->post('field_type'), (string) $this->request->getPost('options'));
        if ($errors !== []) {
            return $this->failed($to, $errors);
        }

        return $this->attempt($to, function () use ($role) {
            FormFieldService::add($role, $this->post('label'), $this->post('field_type'), (string) $this->request->getPost('options'), $this->postFlag('mandatory'));

            return 'Field added to the form.';
        });
    }

    public function update(string $role, string $id): RedirectResponse
    {
        $this->guard($role);
        $id = (int) $id;

        return $this->attempt("admin/fields/{$role}", function () use ($id) {
            FormFieldService::update($id, $this->post('label'), $this->postFlag('mandatory'), $this->postFlag('visible'), (string) $this->request->getPost('options'));

            return 'Saved.';
        });
    }

    public function move(string $role, string $id): RedirectResponse
    {
        $this->guard($role);
        $id = (int) $id;

        return $this->attempt("admin/fields/{$role}", function () use ($id) {
            FormFieldService::move($id, $this->post('direction'));

            return 'Moved.';
        });
    }

    public function delete(string $role, string $id): RedirectResponse
    {
        $this->guard($role);
        $id = (int) $id;

        return $this->attempt("admin/fields/{$role}", static function () use ($id) {
            FormFieldService::delete($id);

            return 'Field deleted.';
        });
    }

    private function guard(string $role): void
    {
        if (! in_array($role, PostService::ROLES, true)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
