<?php

namespace App\Controllers\Admin;

use App\Libraries\PostService;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/** Who holds which post (HOD per department, Warden per hostel, Dean, Registrar...). */
class PostHolders extends AdminController
{
    public function index(): string
    {
        $db    = Database::connect();
        $desgs = $db->query('SELECT * FROM posts WHERE active ORDER BY is_director, name')->getResultArray();
        $vacant = [];
        foreach (PostService::vacancies() as $v) {
            $vacant[$v['post_id']] = ($vacant[$v['post_id']] ?? 0) + 1;
        }
        foreach ($desgs as &$d) {
            $seats      = PostService::seats((int) $d['id']);
            $d['total'] = count($seats);
            $d['filled'] = count(array_filter($seats, static fn ($p) => $p['user_id'] !== null));
            $d['used']  = (int) $db->query('SELECT count(*) AS n FROM escalation_levels WHERE post_id = ?', [$d['id']])->getRow('n') > 0;
            $d['vacant_in_use'] = $vacant[(int) $d['id']] ?? 0;
        }
        unset($d);

        return view('admin/post_holders/index', ['posts' => $desgs]);
    }

    public function show(string $id): string|RedirectResponse
    {
        $db   = Database::connect();
        $desc = $db->query('SELECT * FROM posts WHERE id = ?', [(int) $id])->getRowArray();
        if ($desc === null) {
            return $this->failed('admin/post-holders', 'That post does not exist.', false);
        }

        $users = $db->query('SELECT id, full_name, username FROM users WHERE active AND NOT is_admin ORDER BY lower(full_name)')->getResultArray();

        return view('admin/post_holders/show', ['post' => $desc, 'seats' => PostService::seats((int) $id), 'users' => $users]);
    }

    public function assign(string $id): RedirectResponse
    {
        $id     = (int) $id;
        $userId = $this->postInt('user_id');

        return $this->attempt("admin/post-holders/{$id}", function () use ($id, $userId) {
            if ($userId <= 0) {
                throw new \RuntimeException('Choose a person.');
            }
            $scopeType = Database::connect()->query('SELECT scope_type FROM posts WHERE id = ?', [$id])->getRow('scope_type');
            $raw = $scopeType === 'hostel' ? trim($this->post('hostel')) : $this->request->getPost('scope_id');
            $scopeValue = match ($scopeType) {
                'department' => ($raw === null || $raw === '') ? null : (int) $raw,
                'hostel' => $raw === '' ? null : (string) $raw,
                default => null,
            };
            $messages = PostService::assign($id, $scopeValue, $userId);

            return implode(' ', $messages) ?: 'Nothing changed.';
        });
    }

    public function vacate(string $id): RedirectResponse
    {
        $id = (int) $id;

        return $this->attempt("admin/post-holders/{$id}", function () use ($id) {
            $scopeType = Database::connect()->query('SELECT scope_type FROM posts WHERE id = ?', [$id])->getRow('scope_type');
            $raw = $scopeType === 'hostel' ? trim($this->post('hostel')) : $this->request->getPost('scope_id');
            $scopeValue = match ($scopeType) {
                'department' => ($raw === null || $raw === '') ? null : (int) $raw,
                'hostel' => $raw === '' ? null : (string) $raw,
                default => null,
            };
            PostService::vacate($id, $scopeValue);

            return 'The post is now vacant. Grievances reaching it will wait until someone is assigned.';
        });
    }
}
