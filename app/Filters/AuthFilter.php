<?php

namespace App\Filters;

use App\Libraries\Auth;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a logged-in, active user. Also enforces:
 *  - idle timeout (session_idle_minutes)
 *  - forced password change after an admin-set temporary password
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = Auth::user();

        if ($user === null) {
            if (Auth::person() !== null) {
                // Signed in as a student / staff member without an authority account
                return service('response')->setStatusCode(403)->setBody(view('errors/html/forbidden'));
            }

            return redirect()->to(site_url('login'))->with('error', 'Please log in to continue.');
        }

        if (Auth::isIdleExpired()) {
            Auth::logout();

            return redirect()->to(site_url('login'))->with('error', 'You were logged out after a period of inactivity. Please log in again.');
        }

        Auth::touchActivity();

        $path = trim($request->getUri()->getPath(), '/');
        if ($user['must_change_password'] && $path !== 'account/password' && $path !== 'logout') {
            return redirect()->to(site_url('account/password'))
                ->with('error', 'You must set a new password before continuing.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Pages behind login must never be cached by the browser or a proxy
        return $response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setHeader('Pragma', 'no-cache');
    }
}
