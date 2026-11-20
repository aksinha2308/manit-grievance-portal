<?php

namespace App\Controllers;

use App\Libraries\Auth;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * A logged-in user's own account: change password.
 * (Forgotten passwords are reset by the admin; the admin's own is reset from the command line.)
 */
class Account extends BaseController
{
    public function password(): string
    {
        return view('account/password', ['user' => Auth::user()]);
    }

    public function updatePassword(): RedirectResponse
    {
        $user = Auth::user();

        $errors = Auth::changePassword(
            (int) $user['id'],
            (string) $this->request->getPost('current_password'),
            (string) $this->request->getPost('new_password'),
            (string) $this->request->getPost('confirm_password')
        );

        if ($errors !== []) {
            return redirect()->to(site_url('account/password'))->with('errors', $errors);
        }

        return redirect()
            ->to(site_url($user['is_admin'] ? 'admin' : 'authority'))
            ->with('success', 'Your password has been changed.');
    }
}
