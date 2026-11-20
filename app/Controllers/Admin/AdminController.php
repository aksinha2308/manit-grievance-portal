<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;
use Throwable;

/**
 * Base for admin screens. Access control is done by the 'auth' and 'role:admin' route filters;
 * services throw RuntimeException with a message meant for the admin, which is shown as a flash error.
 */
abstract class AdminController extends BaseController
{
    /** Redirect with a success message. */
    protected function done(string $to, string $message): RedirectResponse
    {
        return redirect()->to(site_url($to))->with('success', $message);
    }

    /**
     * Redirect with one or more error messages, keeping what was typed.
     *
     * @param list<string>|string $errors
     */
    protected function failed(string $to, array|string $errors, bool $keepInput = true): RedirectResponse
    {
        $redirect = redirect()->to(site_url($to))->with('errors', (array) $errors);

        return $keepInput ? $redirect->withInput() : $redirect;
    }

    /**
     * Run an action and turn the admin-facing exceptions into a flash error.
     *
     * @param callable():string $action returns the success message
     */
    protected function attempt(string $to, callable $action): RedirectResponse
    {
        try {
            return $this->done($to, $action());
        } catch (RuntimeException $e) {
            return $this->failed($to, $e->getMessage(), false);
        } catch (Throwable $e) {
            log_message('error', 'Admin action failed: ' . $e);

            return $this->failed($to, 'Something went wrong and nothing was changed. Please try again.', false);
        }
    }

    protected function post(string $key): string
    {
        return trim((string) $this->request->getPost($key));
    }

    protected function postInt(string $key): int
    {
        return (int) $this->request->getPost($key);
    }

    protected function postFlag(string $key): bool
    {
        return $this->request->getPost($key) !== null && $this->request->getPost($key) !== '' && $this->request->getPost($key) !== '0';
    }
}
