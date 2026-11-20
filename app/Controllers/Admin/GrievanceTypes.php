<?php

namespace App\Controllers\Admin;

use App\Libraries\MatrixService;
use App\Libraries\PostService;
use App\Libraries\TypeService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;
use RuntimeException;

/**
 * Grievance types per role, and each type's escalation matrix.
 */
class GrievanceTypes extends AdminController
{
    public function index(): string
    {
        $types = Database::connect()->query(
            "SELECT t.*, (SELECT count(*) FROM escalation_levels l WHERE l.type_id = t.id) AS level_count,
                    (SELECT count(*) FROM grievances g WHERE g.type_id = t.id) AS grievance_count
               FROM grievance_types t ORDER BY t.role, t.sort_order, lower(t.name)"
        )->getResultArray();

        $groups = [];
        foreach (PostService::ROLES as $role) {
            $groups[$role] = [
                'multiple' => TypeService::multipleAllowed($role),
                'types'    => array_values(array_filter($types, static fn ($t) => $t['role'] === $role)),
            ];
        }

        return view('admin/types/index', ['groups' => $groups]);
    }

    public function add(): RedirectResponse
    {
        $role = $this->post('role');
        $name = $this->post('name');
        $code = TypeService::normaliseCode($this->post('code'));

        $errors = TypeService::validate($role, $name, $code);
        if ($errors !== []) {
            return $this->failed('admin/types', $errors);
        }

        try {
            $id = TypeService::create($role, $name, $code, $this->postFlag('requires_hostel'), $this->postInt('sort_order'));
        } catch (RuntimeException $e) {
            return $this->failed('admin/types', $e->getMessage());
        }

        return $this->done("admin/types/{$id}/matrix", "'{$name}' was added with a route that ends at the Director. Add the levels it needs below.");
    }

    public function update(string $id): RedirectResponse
    {
        $id   = (int) $id;
        $name = $this->post('name');
        $code = TypeService::normaliseCode($this->post('code'));

        try {
            $type   = TypeService::find($id);
            $errors = TypeService::validate($type['role'], $name, $code, $id);
            if ($errors !== []) {
                return $this->failed('admin/types', $errors, false);
            }
            TypeService::update($id, $name, $code, $this->postFlag('requires_hostel'), $this->postInt('sort_order'));
        } catch (RuntimeException $e) {
            return $this->failed('admin/types', $e->getMessage(), false);
        }

        return $this->done('admin/types', 'Saved. A changed short code applies to new grievances only; reference numbers already issued stay as they are.');
    }

    public function toggle(string $id): RedirectResponse
    {
        return $this->attempt('admin/types', function () use ($id) {
            $type   = TypeService::find((int) $id);
            $active = ! is_true($type['active']);
            TypeService::setActive((int) $id, $active);

            return "'{$type['name']}' was " . ($active ? 'activated.' : 'deactivated. It no longer appears on the submission form.');
        });
    }

    public function delete(string $id): RedirectResponse
    {
        return $this->attempt('admin/types', function () use ($id) {
            $type = TypeService::find((int) $id);
            TypeService::delete((int) $id);

            return "'{$type['name']}' was deleted.";
        });
    }

    // ---------------------------------------------------------------------
    // Matrix
    // ---------------------------------------------------------------------

    public function matrix(string $id): string
    {
        $type = $this->typeOrFail((int) $id);
        $db   = Database::connect();

        $levels = MatrixService::levels((int) $id);
        $inRoute = array_column($levels, 'post_id');

        $available = array_values(array_filter(
            $db->query('SELECT id, name, scope_type FROM posts WHERE active AND NOT is_director ORDER BY lower(name)')->getResultArray(),
            static fn ($d) => ! in_array($d['id'], $inRoute, false)
        ));

        return view('admin/types/matrix', [
            'type'      => $type,
            'levels'    => $levels,
            'available' => $available,
            'defaultDays' => (int) \App\Libraries\Settings::int('overdue_default_days', 7),
        ]);
    }

    public function addLevel(string $id): RedirectResponse
    {
        $this->typeOrFail((int) $id);

        return $this->attempt("admin/types/{$id}/matrix", function () use ($id) {
            MatrixService::addLevel((int) $id, $this->postInt('post_id'), $this->postInt('position'), $this->postInt('overdue_days'));

            return 'Level added.';
        });
    }

    public function removeLevel(string $id, string $levelId): RedirectResponse
    {
        $this->typeOrFail((int) $id);

        return $this->attempt("admin/types/{$id}/matrix", function () use ($levelId) {
            $moved = MatrixService::removeLevel((int) $levelId);

            return 'Level removed.' . ($moved > 0
                ? " {$moved} open grievance(s) at that level were moved to the next level and the history records this."
                : '');
        });
    }

    public function moveLevel(string $id, string $levelId): RedirectResponse
    {
        $this->typeOrFail((int) $id);
        $direction = $this->post('direction') === 'up' ? 'up' : 'down';

        return $this->attempt("admin/types/{$id}/matrix", function () use ($levelId, $direction) {
            MatrixService::moveLevel((int) $levelId, $direction);

            return 'Order changed.';
        });
    }

    public function levelDays(string $id, string $levelId): RedirectResponse
    {
        $this->typeOrFail((int) $id);

        return $this->attempt("admin/types/{$id}/matrix", function () use ($levelId) {
            MatrixService::setOverdueDays((int) $levelId, $this->postInt('overdue_days'));

            return 'Saved.';
        });
    }

    public function levelSkip(string $id, string $levelId): RedirectResponse
    {
        $this->typeOrFail((int) $id);
        $allow = $this->post('can_skip') === '1';

        return $this->attempt("admin/types/{$id}/matrix", function () use ($levelId, $allow) {
            MatrixService::setCanSkip((int) $levelId, $allow);

            return $allow ? 'This level may now transfer directly to any later level.' : 'This level can only transfer to the next level.';
        });
    }

    /** @return array<string,mixed> */
    private function typeOrFail(int $id): array
    {
        try {
            return TypeService::find($id);
        } catch (RuntimeException) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
