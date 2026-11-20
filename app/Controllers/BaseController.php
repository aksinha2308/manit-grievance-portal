<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    // protected $session;

    /**
     * @param CLIRequest|IncomingRequest $request
     *
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        $this->helpers = ['form', 'url', 'portal'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        // $this->session = service('session');
    }

    /**
     * Files uploaded in a multiple-file input (name="<field>[]"), one entry per file.
     *
     * @return list<array<string,mixed>>
     */
    protected function uploadedFiles(string $field): array
    {
        $raw = $_FILES[$field] ?? null;
        if (! is_array($raw) || ! is_array($raw['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($raw['name'] as $i => $name) {
            $out[] = ['name' => $name, 'tmp_name' => $raw['tmp_name'][$i], 'size' => $raw['size'][$i], 'error' => $raw['error'][$i]];
        }

        return $out;
    }

    /** Simple per-IP rate limit: $max attempts per 10 minutes for one kind of request. */
    protected function withinRateLimit(string $what, int $max): bool
    {
        return service('throttler')->check($what . '_' . md5($this->request->getIPAddress()), $max, 600);
    }
}
