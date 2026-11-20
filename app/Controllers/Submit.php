<?php

namespace App\Controllers;

use App\Libraries\Auth;
use App\Libraries\OtpService;
use App\Libraries\Settings;
use App\Libraries\SubmissionException;
use App\Libraries\SubmissionService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * Grievance submission for the signed-in student / staff member (route filter 'submitter').
 *
 * Name, ID, department, semester and hostel come from the master list. Before the grievance is saved the person
 * must enter the one-time password (OTP) sent to their institute e-mail and to the mobile number on the master
 * list. The contact number on the form may be changed; it is stored with the grievance only.
 */
class Submit extends BaseController
{
    /**
     * Attempts allowed per IP address in 10 minutes. Generous on purpose: a whole campus can sit behind one
     * address. This only stops a runaway script.
     */
    private const OTP_LIMIT    = 60;
    private const SUBMIT_LIMIT = 60;

    /** The form, pre-filled from the master list. */
    public function form(): RedirectResponse|string
    {
        $person = Auth::person();
        $role   = (string) $person['role'];
        if (SubmissionService::types($role) === []) {
            return redirect()->to('my')->with('error', role_label($role) . ' grievances cannot be submitted yet.');
        }

        return view('submit/form', [
            'role'       => $role,
            'person'     => $person,
            'fields'     => SubmissionService::formFields($role),
            'types'      => SubmissionService::types($role),
            'maxFiles'   => Settings::int('attach_max_count', 3),
            'maxMb'      => Settings::int('attach_max_mb', 5),
            'allowedExt' => array_filter(explode(',', (string) Settings::get('attach_allowed_ext', ''))),
            'duplicates' => session()->getFlashdata('duplicates'),
            'otpSentTo'  => session()->getFlashdata('otp_sent_to'),
            'otpMinutes' => Settings::int('otp_valid_minutes', 10),
            'otpTestMode' => OtpService::testMode(),
            'otpMasked'  => [
                'email'  => ($person['email'] ?? '') !== '' ? OtpService::maskEmail((string) $person['email']) : null,
                'mobile' => ($person['contact'] ?? '') !== '' && OtpService::smsReachesPhone() ? OtpService::maskMobile((string) $person['contact']) : null,
            ],
        ]);
    }

    /**
     * "Send OTP". Normally called by the page's script in the background (AJAX), so nothing on the form is lost,
     * including the chosen files; it then answers with JSON and the new CSRF token. Without JavaScript the button
     * posts the form and the page comes back with the typed text kept (files must then be chosen again).
     */
    public function sendOtp(): RedirectResponse|ResponseInterface
    {
        $ajax = $this->request->isAJAX();
        $fail = function (string $message) use ($ajax) {
            return $ajax
                ? $this->response->setJSON(['ok' => false, 'message' => $message, 'csrf' => csrf_hash()])
                : redirect()->to('submit')->withInput()->with('error', $message);
        };

        if (! $this->withinRateLimit('otp', self::OTP_LIMIT)) {
            return $fail('Too many attempts. Please wait a few minutes and try again.');
        }
        $blockers = SubmissionService::erpBlockers((array) Auth::person());
        if ($blockers !== []) {
            return $fail($blockers[0]);
        }
        try {
            $to = OtpService::send(Auth::person());
        } catch (RuntimeException $e) {
            return $fail($e->getMessage());
        }

        $where = implode(' and ', array_filter([
            $to['email'] !== null ? "your e-mail {$to['email']}" : null,
            $to['mobile'] !== null ? "your mobile {$to['mobile']}" : null,
        ]));
        $message = OtpService::testMode()
            ? 'Development OTP mode is on: no OTP was sent. Enter 1234 and submit your grievance.'
            : "An OTP has been sent to {$where}. Enter it below and submit your grievance.";

        if ($ajax) {
            return $this->response->setJSON([
                'ok' => true, 'message' => $message, 'csrf' => csrf_hash(),
                'resendAfter' => Settings::int('otp_resend_seconds', 60),
            ]);
        }

        return redirect()->to('submit')->withInput()->with('success', $message)->with('otp_sent_to', $where);
    }

    /** Check the OTP, then save the grievance. */
    public function submit(): RedirectResponse
    {
        $person = Auth::person();
        if (! $this->withinRateLimit('submit', self::SUBMIT_LIMIT)) {
            return redirect()->to('submit')->withInput()->with('error', 'Too many attempts. Please wait a few minutes and try again.');
        }

        try {
            $otpId = OtpService::consume((int) $person['id'], (string) $this->request->getPost('otp'));
        } catch (RuntimeException $e) {
            return redirect()->to('submit')->withInput()->with('errors', [$e->getMessage()]);
        }

        try {
            $receipt = SubmissionService::submit($person, $this->request->getPost() ?? [], $this->uploadedFiles('attachments'));
        } catch (SubmissionException $e) {
            OtpService::release($otpId);   // nothing was saved: the same OTP can be used again
            $r = redirect()->to('submit')->withInput()->with('errors', $e->errors);
            if ($e->duplicates !== null) {
                $r = $r->with('duplicates', $e->duplicates);
            }

            return $r;
        }

        session()->set('receipt', $receipt);

        return redirect()->to('submit/done');
    }

    /** The receipt page; stays available if the page is refreshed. */
    public function done(): RedirectResponse|string
    {
        $receipt = session()->get('receipt');
        if (! is_array($receipt)) {
            return redirect()->to('my');
        }

        return view('submit/done', ['receipt' => $receipt]);
    }
}
