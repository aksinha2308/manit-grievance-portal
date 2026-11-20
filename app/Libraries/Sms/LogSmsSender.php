<?php

namespace App\Libraries\Sms;

use App\Libraries\OtpService;

/** "Sends" the SMS by writing it to writable/logs/otp-YYYY-MM-DD.log (until an SMS gateway is added). */
class LogSmsSender implements SmsSender
{
    public function send(string $mobile, string $message): bool
    {
        return OtpService::writeLog('SMS', $mobile, $message);
    }
}
