<?php

namespace App\Libraries\Sms;

/**
 * Sends a text message. To add a real SMS gateway later (MSG91, Fast2SMS, ...): write a class that implements
 * this interface, add it to OtpService::smsSender(), and set otp.smsDriver in .env. Indian gateways need the
 * institute's DLT-registered sender ID and OTP message template first.
 */
interface SmsSender
{
    /** True when the message was accepted for delivery. */
    public function send(string $mobile, string $message): bool;
}
