<?php

namespace App\Filters;

use App\Libraries\Auth;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route filter: 'role:admin' or 'role:authority'.
 * The admin has no grievance-handling powers, and authorities have no configuration powers.
 */
class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = Auth::user();
        if ($user === null) {
            return redirect()->to(site_url('login'));
        }

        $needed = $arguments[0] ?? '';
        $allowed = ($needed === 'admin' && $user['is_admin'])
                || ($needed === 'authority' && ! $user['is_admin']);

        if (! $allowed) {
            return service('response')
                ->setStatusCode(403)
                ->setBody(view('errors/html/forbidden'));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
