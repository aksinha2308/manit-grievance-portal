<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * How the one-time password (OTP) is delivered before a grievance is submitted. The same code is sent by each
 * enabled channel; a blank smsDriver disables SMS delivery.
 *
 *   otp.emailMode = 'smtp'   send real e-mail (configure the email.* settings in .env, see the setup guide)
 *   otp.emailMode = 'log'    write the e-mail OTP to writable/logs/otp-YYYY-MM-DD.log instead (testing)
 *   otp.smsDriver = 'log'    SMS: write to the same log file. (A real SMS gateway is added later.)
 *   otp.smsDriver = ''       disable SMS delivery
 *
 * The timing rules (validity, tries, resend wait, sends per hour) are in Admin > Settings > Security.
 */
class Otp extends BaseConfig
{
    public string $emailMode = 'log';
    public string $smsDriver = 'log';

    /** Sender shown on the OTP e-mail. Empty = the email.fromEmail setting. */
    public string $fromEmail = '';
    public string $fromName  = 'Grievance Portal';
}
