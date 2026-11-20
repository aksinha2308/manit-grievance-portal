<?php

namespace App\Controllers;

use App\Libraries\Auth;
use App\Libraries\SubmissionService;

/**
 * Public landing page: sign in, or (once signed in) submit a grievance / open My grievances / the dashboard.
 */
class Home extends BaseController
{
    public function index(): string
    {
        $person = Auth::person();

        return view('home', [
            'user'      => Auth::user(),
            'person'    => $person,
            'canSubmit' => $person !== null && SubmissionService::types((string) $person['role']) !== [],
        ]);
    }
}
