<?php

namespace App\Libraries;

use Config\Services;
use RuntimeException;
use Throwable;

/**
 * "Sign in with Google" (OpenID Connect, authorization-code flow), with no extra library.
 *
 *  1. authUrl() sends the browser to Google with a random state and nonce kept in the session.
 *  2. Google sends the browser back to auth/google/callback with a one-time code.
 *  3. emailFromCallback() checks the state, exchanges the code for an ID token directly with Google over
 *     HTTPS (using the client secret), and checks the token's claims: issuer, audience, expiry, nonce,
 *     verified e-mail, and the Google Workspace domain ("hd"). Because the token comes straight from Google's
 *     token endpoint over TLS, its signature does not need to be checked separately (OpenID Connect Core 3.1.3.7).
 *
 * Which person or account the e-mail belongs to is decided by Auth::loginWithEmail().
 */
class GoogleAuth
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const ISSUERS   = ['accounts.google.com', 'https://accounts.google.com'];
    private const STATE_KEY = 'google_oauth';

    /** True when the client id and secret are set in .env. */
    public static function configured(): bool
    {
        $c = config('Google');

        return trim($c->clientId) !== '' && trim($c->clientSecret) !== '';
    }

    /** The test login (type an e-mail, no Google) is on only in development/testing AND when switched on. */
    public static function devLoginEnabled(): bool
    {
        return in_array(ENVIRONMENT, ['development', 'testing'], true) && config('Google')->devLogin;
    }

    /** 'student', 'staff' or null for an e-mail outside the institute domains. */
    public static function domainKind(string $email): ?string
    {
        $c      = config('Google');
        $email  = strtolower(trim($email));
        $at     = strrpos($email, '@');
        if ($at === false || $at === 0 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $domain = substr($email, $at + 1);

        return match ($domain) {
            strtolower($c->studentDomain) => 'student',
            strtolower($c->staffDomain)   => 'staff',
            default                        => null,
        };
    }

    /** @return list<string> Google Workspace domains whose accounts may sign in */
    public static function hostedDomains(): array
    {
        $c    = config('Google');
        $list = trim($c->hostedDomains) === '' ? [$c->studentDomain, $c->staffDomain] : explode(',', $c->hostedDomains);

        return array_values(array_unique(array_filter(array_map(static fn ($d) => strtolower(trim($d)), $list))));
    }

    public static function redirectUri(): string
    {
        $uri = trim(config('Google')->redirectUri);

        return $uri !== '' ? $uri : site_url('auth/google/callback');
    }

    /** Where to send the browser to sign in. */
    public static function authUrl(string $flow = 'user'): string
    {
        if (! in_array($flow, ['user', 'admin'], true)) {
            throw new RuntimeException('Invalid Google sign-in flow.');
        }
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        Services::session()->set(self::STATE_KEY, ['state' => $state, 'nonce' => $nonce, 'at' => time(), 'flow' => $flow]);

        $params = [
            'client_id'     => config('Google')->clientId,
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'nonce'         => $nonce,
            'prompt'        => 'select_account',
        ];
        $domains = self::hostedDomains();
        // "hd" only pre-selects accounts in Google's chooser; the real check is done on the returned token
        $params['hd'] = count($domains) === 1 ? $domains[0] : '*';

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public static function pendingFlow(): string
    {
        $saved = Services::session()->get(self::STATE_KEY);

        return is_array($saved) && ($saved['flow'] ?? null) === 'admin' ? 'admin' : 'user';
    }

    /**
     * Handle Google's redirect back. Returns the verified institute e-mail.
     *
     * @throws RuntimeException with a message that can be shown to the person
     */
    public static function emailFromCallback(?string $state, ?string $code, ?string $error): string
    {
        $saved = Services::session()->get(self::STATE_KEY);
        Services::session()->remove(self::STATE_KEY);

        if ($error !== null && $error !== '') {
            throw new RuntimeException($error === 'access_denied' ? 'Google sign-in was cancelled.' : 'Google sign-in failed. Please try again.');
        }
        if (! is_array($saved) || ! is_string($state) || ! hash_equals((string) $saved['state'], $state)
            || time() - (int) $saved['at'] > 600) {
            throw new RuntimeException('The sign-in link expired or was opened in another browser. Please try again.');
        }
        if (! is_string($code) || $code === '') {
            throw new RuntimeException('Google sign-in failed. Please try again.');
        }

        $claims = self::claimsFromToken(self::exchangeCode($code));

        return self::checkClaims($claims, (string) $saved['nonce'], time());
    }

    /**
     * Validate ID-token claims and return the e-mail (lower case). Public so the self-test can exercise it.
     *
     * @param array<string,mixed> $claims
     *
     * @throws RuntimeException
     */
    public static function checkClaims(array $claims, string $nonce, int $now): string
    {
        $c = config('Google');

        if (! in_array($claims['iss'] ?? '', self::ISSUERS, true)
            || ($claims['aud'] ?? '') !== $c->clientId
            || (int) ($claims['exp'] ?? 0) < $now - 60
            || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('Google sign-in could not be verified. Please try again.');
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
        $hd = strtolower((string) ($claims['hd'] ?? ''));

        if ($email === '' || ! $verified || ! in_array($hd, self::hostedDomains(), true) || self::domainKind($email) === null) {
            throw new RuntimeException("Please sign in with your institute Google account (@{$c->studentDomain} for students, @{$c->staffDomain} for staff), not a personal one.");
        }

        return $email;
    }

    // ---------------------------------------------------------------------

    private static function exchangeCode(string $code): string
    {
        $c = config('Google');
        try {
            $response = Services::curlrequest(['timeout' => 15, 'http_errors' => false])->post(self::TOKEN_URL, [
                'form_params' => [
                    'code'          => $code,
                    'client_id'     => $c->clientId,
                    'client_secret' => $c->clientSecret,
                    'redirect_uri'  => self::redirectUri(),
                    'grant_type'    => 'authorization_code',
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Google token request failed: ' . $e->getMessage());

            throw new RuntimeException('The portal could not reach Google. Please try again in a minute.');
        }

        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || ! is_array($body) || ! isset($body['id_token'])) {
            log_message('error', 'Google token response ' . $response->getStatusCode() . ': ' . substr((string) $response->getBody(), 0, 500));

            throw new RuntimeException('Google sign-in failed. Please try again.');
        }

        return (string) $body['id_token'];
    }

    /** @return array<string,mixed> */
    private static function claimsFromToken(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Google sign-in could not be verified. Please try again.');
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $claims = $json === false ? null : json_decode($json, true);
        if (! is_array($claims)) {
            throw new RuntimeException('Google sign-in could not be verified. Please try again.');
        }

        return $claims;
    }
}
