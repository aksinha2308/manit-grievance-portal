<?php

namespace App\Controllers;

use App\Libraries\Auth;
use App\Libraries\ReportService;
use App\Libraries\WorkflowService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTime;
use DateTimeZone;

/**
 * Summary report, for the admin (admin/report) and the Director (authority/report). Same content for both.
 * Other authorities get "not found".
 */
class ReportController extends BaseController
{
    public function index(): string
    {
        [$from, $to, $base] = $this->params();

        return view('report/index', ['r' => ReportService::summary($from, $to), 'base' => $base]);
    }

    public function xlsx(): ResponseInterface
    {
        [$from, $to] = $this->params();
        $r = ReportService::summary($from, $to);
        \App\Libraries\AuditLogger::log('export', 'report', null, null, ['from' => $from, 'to' => $to, 'format' => 'xlsx']);

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', "attachment; filename=\"grievance-report-{$from}-to-{$to}.xlsx\"")
            ->setHeader('Cache-Control', 'no-store, private')
            ->setBody(ReportService::xlsx($r));
    }

    /** @return array{0:string,1:string,2:string} from, to, base URL */
    private function params(): array
    {
        $user = Auth::user();
        if (! $user['is_admin'] && ! WorkflowService::isDirector((int) $user['id'])) {
            throw PageNotFoundException::forPageNotFound();
        }
        $base = $user['is_admin'] ? 'admin/report' : 'authority/report';

        $tz    = display_timezone();
        $today = new DateTime('today', $tz);
        $valid = static function (?string $v): ?string {
            $d = DateTime::createFromFormat('!Y-m-d', (string) $v);

            return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
        };
        $to   = $valid($this->request->getGet('to')) ?? $today->format('Y-m-d');
        $from = $valid($this->request->getGet('from')) ?? (clone $today)->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to, $base];
    }
}
