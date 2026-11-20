<?php

namespace App\Controllers;

use App\Libraries\Auth;
use App\Libraries\GoogleAuth;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

/**
 * Sign-in and sign-out.
 *  - Submitters and authorities: institute Google account.
 *  - The admin only: a separate password or configured Google account sign-in page.
 *  - Development only: auth/dev, sign in by typing an institute e-mail (when google.devLogin = true).
 */
class AuthController extends BaseController
{
    private const LOGIN_LIMIT = 100;   // per IP per 10 minutes (a whole campus can share one address)

    public function login(): string|RedirectResponse
    {
        if (Auth::signedIn()) {
            return $this->landing();
        }

        return view('auth/login', [
            'googleReady' => GoogleAuth::configured(),
            'devLogin'    => GoogleAuth::devLoginEnabled(),
        ]);
    }

    public function adminLogin(): string|RedirectResponse
    {
        if (Auth::signedIn()) {
            return $this->landing();
        }

        return view('auth/admin_login', ['googleReady' => GoogleAuth::configured()]);
    }

    /** Start "Sign in with Google". */
    public function google(): RedirectResponse
    {
        if (! GoogleAuth::configured()) {
            return redirect()->to(site_url('login'))->with('error', 'Google sign-in has not been set up yet. Please contact the administration.');
        }

        return redirect()->to(GoogleAuth::authUrl());
    }

    public function adminGoogle(): RedirectResponse
    {
        if (! GoogleAuth::configured()) {
            return redirect()->to(site_url('admin-login'))->with('error', 'Google sign-in has not been set up yet. Please contact the administration.');
        }

        return redirect()->to(GoogleAuth::authUrl('admin'));
    }

    /** Google sends the browser back here. */
    public function googleCallback(): RedirectResponse
    {
        $flow = GoogleAuth::pendingFlow();
        $loginUrl = $flow === 'admin' ? 'admin-login' : 'login';
        if (! $this->withinRateLimit($flow === 'admin' ? 'admin-login' : 'login', self::LOGIN_LIMIT)) {
            return redirect()->to(site_url($loginUrl))->with('error', 'Too many attempts. Please wait a few minutes and try again.');
        }
        try {
            $email = GoogleAuth::emailFromCallback(
                $this->request->getGet('state'),
                $this->request->getGet('code'),
                $this->request->getGet('error')
            );
        } catch (RuntimeException $e) {
            return redirect()->to(site_url($loginUrl))->with('error', $e->getMessage());
        }

        if ($flow === 'admin') {
            [$ok, $error] = Auth::loginAdminWithEmail($email);
            if (! $ok) {
                return redirect()->to(site_url('admin-login'))->with('error', $error);
            }

            return $this->landing();
        }

        return $this->signIn($email, 'google');
    }

    /** Development-only test login: type an institute e-mail, no Google involved. */
    public function devForm(): string
    {
        $this->devOnly();

        return view('auth/dev', []);
    }

    public function devLogin(): RedirectResponse
    {
        $this->devOnly();

        return $this->signIn((string) $this->request->getPost('email'), 'dev');
    }

    /** Admin username + password (emergency login). */
    /*public function adminAttempt(): RedirectResponse
    {
        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        if ($username === '' || $password === '') {
            return redirect()->to(site_url('admin-login'))->withInput()->with('error', 'Enter your username and password.');
        }

        [$ok, $error] = Auth::attempt($username, $password);

        if (! $ok) {
            return redirect()->to(site_url('admin-login'))->withInput()->with('error', $error);
        }

        return $this->landing();
    }*/

    public function logout(): RedirectResponse
    {
        Auth::logout();

        return redirect()->to(site_url('login'))->with('success', 'You have been logged out.');
    }

    // ---------------------------------------------------------------------

    private function signIn(string $email, string $via): RedirectResponse
    {
        [$ok, $error] = Auth::loginWithEmail($email, $via);
        if (! $ok) {
            return redirect()->to(site_url('login'))->with('error', $error);
        }

        return $this->landing();
    }

    /** Send a signed-in person to the right place: admin, authority dashboard, or My grievances. */
    private function landing(): RedirectResponse
    {
        $user = Auth::user();

        if ($user !== null) {
            if ($user['must_change_password']) {
                return redirect()->to(site_url('account/password'));
            }

            return redirect()->to(site_url($user['is_admin'] ? 'admin' : 'authority'));
        }

        return redirect()->to(site_url('my'));
    }

    private function devOnly(): void
    {
        if (! GoogleAuth::devLoginEnabled()) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
