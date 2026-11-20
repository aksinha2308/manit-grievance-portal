<?php

namespace App\Controllers\Admin;

use App\Libraries\PostCatalogService;
use CodeIgniter\HTTP\RedirectResponse;

class Posts extends AdminController
{
    public function index(): string
    {
        return view('admin/posts/index', [
            'posts' => PostCatalogService::all(),
            'scopes'       => PostCatalogService::SCOPES,
        ]);
    }

    public function add(): RedirectResponse
    {
        $name  = $this->post('name');
        $scope = $this->post('scope');

        $errors = PostCatalogService::validate($name, $scope);
        if ($errors !== []) {
            return $this->failed('admin/posts', $errors);
        }

        return $this->attempt('admin/posts', function () use ($name, $scope) {
            PostCatalogService::create($name, $scope);

            return "'{$name}' was added. Add it to a route under Grievance types, then assign its holder under Post holders.";
        });
    }

    public function update(string $id): RedirectResponse
    {
        $id    = (int) $id;
        $name  = $this->post('name');
        $scope = $this->post('scope');

        $errors = PostCatalogService::validate($name, $scope, $id);
        if ($errors !== []) {
            return $this->failed('admin/posts', $errors, false);
        }

        return $this->attempt('admin/posts', function () use ($id, $name, $scope) {
            PostCatalogService::update($id, $name, $scope);

            return 'Saved.';
        });
    }

    public function toggle(string $id): RedirectResponse
    {
        return $this->attempt('admin/posts', function () use ($id) {
            $d      = PostCatalogService::find((int) $id);
            $active = ! is_true($d['active']);
            PostCatalogService::setActive((int) $id, $active);

            return "'{$d['name']}' was " . ($active ? 'activated.' : 'deactivated.');
        });
    }

    public function delete(string $id): RedirectResponse
    {
        return $this->attempt('admin/posts', function () use ($id) {
            $d = PostCatalogService::find((int) $id);
            PostCatalogService::delete((int) $id);

            return "'{$d['name']}' was deleted.";
        });
    }
}
