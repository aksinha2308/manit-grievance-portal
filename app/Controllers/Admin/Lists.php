<?php

namespace App\Controllers\Admin;

use App\Libraries\ListService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The read-only department list and portal-managed semesters.
 */
class Lists extends AdminController
{
    public function index(string $entity): string
    {
        $this->guard($entity);

        return view('admin/lists/index', [
            'entity' => $entity,
            'def'    => ListService::ENTITIES[$entity],
            'items'  => ListService::all($entity),
        ]);
    }

    public function add(string $entity): RedirectResponse
    {
        $this->guard($entity);
        $to   = "admin/lists/{$entity}";
        $name = $this->post('name');

        $errors = ListService::validate($entity, $name);
        if ($errors !== []) {
            return $this->failed($to, $errors);
        }

        return $this->attempt($to, function () use ($entity, $name) {
            ListService::create($entity, $name, null, $this->postInt('sort_order'));

            return "'{$name}' was added.";
        });
    }

    public function rename(string $entity, string $id): RedirectResponse
    {
        $this->guard($entity);
        $to   = "admin/lists/{$entity}";
        $id   = (int) $id;
        $name = $this->post('name');

        return $this->attempt($to, function () use ($entity, $id, $name) {
            ListService::find($entity, $id);
            $errors = ListService::validate($entity, $name, null, $id);
            if ($errors !== []) {
                throw new \RuntimeException(implode(' ', $errors));
            }
            ListService::rename($entity, $id, $name, $this->postInt('sort_order'));

            return 'Saved.';
        });
    }

    public function toggle(string $entity, string $id): RedirectResponse
    {
        $this->guard($entity);

        return $this->attempt("admin/lists/{$entity}", function () use ($entity, $id) {
            $item   = ListService::find($entity, (int) $id);
            $active = ! is_true($item['active']);
            ListService::setActive($entity, (int) $id, $active);

            return "'{$item['name']}' was " . ($active ? 'activated.' : 'deactivated. It no longer appears on new forms and mappings.');
        });
    }

    public function delete(string $entity, string $id): RedirectResponse
    {
        $this->guard($entity);

        return $this->attempt("admin/lists/{$entity}", function () use ($entity, $id) {
            $item = ListService::find($entity, (int) $id);
            ListService::delete($entity, (int) $id);

            return "'{$item['name']}' was deleted.";
        });
    }

    private function guard(string $entity): void
    {
        if (! ListService::isEntity($entity)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
