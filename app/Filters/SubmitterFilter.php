<?php

namespace App\Filters;

use App\Libraries\Auth;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Pages for people on the master list (submit a grievance, My grievances). Needs a Google sign-in whose
 * e-mail is on the master list; also enforces the idle timeout.
 */
class SubmitterFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! Auth::signedIn()) {
            return redirect()->to(site_url('login'))->with('error', 'Please sign in with your institute Google account to continue.');
        }

        if (Auth::isIdleExpired()) {
            Auth::logout();

            return redirect()->to(site_url('login'))->with('error', 'You were logged out after a period of inactivity. Please sign in again.');
        }
        Auth::touchActivity();

        if (Auth::person() === null) {
            // An authority (or the admin) who is not on the master list
            return service('response')->setStatusCode(403)->setBody(view('errors/html/forbidden', [
                'message' => 'Your e-mail is not on the master list of students and staff, so you cannot submit or view grievances as a submitter. Please contact the administration if this is wrong.',
            ]));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setHeader('Pragma', 'no-cache');
    }
}
