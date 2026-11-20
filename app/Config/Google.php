<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * "Sign in with Google" settings. Set the secret values in .env, never in this file:
 *
 *   google.clientId     = '1234-abc.apps.googleusercontent.com'
 *   google.clientSecret = 'GOCSPX-...'
 *   google.redirectUri  = 'https://grievance.manit.ac.in/auth/google/callback'   (optional; default: baseURL + auth/google/callback)
 *
 * Who may sign in:
 *   - an e-mail ending in @<studentDomain> can only be a student;
 *   - an e-mail ending in @<staffDomain> can be faculty / non-teaching (master list) and/or an authority account.
 * The account must also be a Google Workspace account of one of the hostedDomains (Google's "hd" claim), so a
 * private Gmail account can never get in.
 */
class Google extends BaseConfig
{
    public string $clientId     = '';
    public string $clientSecret = '';
    public string $redirectUri  = '';

    public string $studentDomain = 'stu.manit.ac.in';
    public string $staffDomain   = 'manit.ac.in';

    /** Allowed Google Workspace domains (comma separated). Empty = the student and staff domains above. */
    public string $hostedDomains = '';

    /**
     * Test login without Google: a page where you type any institute e-mail and are signed in as that person.
     * Works ONLY when CI_ENVIRONMENT is "development" or "testing" AND this is true. Never in production.
     *   google.devLogin = true
     */
    public bool $devLogin = false;
}
