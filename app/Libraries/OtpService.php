<?php

namespace App\Libraries;

use App\Libraries\Sms\LogSmsSender;
use App\Libraries\Sms\SmsSender;
use Config\Database;
use Config\Services;
use DateTime;
use RuntimeException;
use Throwable;

/**
 * One-time password (OTP) checked before every grievance submission.
 *
 * One 6-digit code is sent both to the person's institute e-mail and to the mobile number on the master list
 * (NOT the contact number typed on the form, which is only stored with the grievance). Entering the code
 * verifies the person. Rules (Admin > Settings > Security):
 *   otp_valid_minutes   the code expires after this many minutes            (default 10)
 *   otp_max_attempts    wrong entries allowed before the code stops working (default 5)
 *   otp_resend_seconds  wait before a new code can be requested             (default 60)
 *   otp_max_per_hour    codes per person per hour                           (default 5)
 * In development, no code is sent and the fixed code 1234 is used. Requesting a new code replaces the old one.
 * A code works for one submission only. Only a hash is stored.
 */
class OtpService
{
    /**
     * Create and send a new code.
     *
     * @param array<string,mixed> $person master list row (needs id, email, contact)
     *
     * @return array{email:?string,mobile:?string} masked destinations it was sent to
     *
     * @throws RuntimeException message for the person
     */
    public static function send(array $person): array
    {
        $db       = Database::connect();
        $personId = (int) $person['id'];
        $testMode = self::testMode();

        $recent = $db->query(
            "SELECT count(*) FILTER (WHERE created_at > now() - interval '1 hour') AS hour,
                    COALESCE(EXTRACT(EPOCH FROM (now() - max(created_at))), 999999)::int AS since_last
               FROM otp_codes WHERE person_id = ?",
            [$personId]
        )->getRowArray();
        $wait = Settings::int('otp_resend_seconds', 60) - (int) $recent['since_last'];
        if ($wait > 0) {
            throw new RuntimeException("Please wait {$wait} seconds before asking for a new OTP.");
        }
        if ((int) $recent['hour'] >= max(1, Settings::int('otp_max_per_hour', 5))) {
            throw new RuntimeException('Too many OTPs were requested in the last hour. Please try again later.');
        }

        $email  = trim((string) ($person['email'] ?? ''));
        $mobile = trim((string) ($person['contact'] ?? ''));
        if (! $testMode && $email === '' && $mobile === '') {
            throw new RuntimeException('No e-mail or mobile number is recorded for you, so an OTP cannot be sent. Please contact the administration.');
        }

        $code    = $testMode ? '1234' : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = max(1, Settings::int('otp_valid_minutes', 10));
        $text    = "{$code} is your OTP to submit a grievance on the " . institute_name() . " Grievance Portal. It is valid for {$minutes} minutes. Do not share it with anyone.";

        $smsDriver  = trim((string) config('Otp')->smsDriver);
        $sentEmail  = ! $testMode && $email !== '' && self::sendEmail($email, $code, $text);
        $sentMobile = ! $testMode && $mobile !== '' && $smsDriver !== '' && self::smsSender($smsDriver)->send($mobile, $text);
        // Until a real SMS gateway is added, the "SMS" only goes to the log file. On the live portal that must not
        // be presented to the person as sent to their phone, nor count as a successful delivery.
        if ($sentMobile && ! self::smsReachesPhone()) {
            $sentMobile = false;
        }
        if (! $testMode && ! $sentEmail && ! $sentMobile) {
            throw new RuntimeException('The OTP could not be sent. Please try again in a few minutes, or contact the administration.');
        }

        // The newest code replaces any earlier unused one
        $db->query('UPDATE otp_codes SET used_at = now() WHERE person_id = ? AND used_at IS NULL', [$personId]);
        $db->query(
            "INSERT INTO otp_codes (person_id, code_hash, sent_email, sent_mobile, expires_at) VALUES (?, ?, ?, ?, now() + (? * interval '1 minute'))",
            [$personId, password_hash($code, PASSWORD_DEFAULT), $sentEmail ? $email : null, $sentMobile ? $mobile : null, $minutes]
        );

        return ['email' => $sentEmail ? self::maskEmail($email) : null, 'mobile' => $sentMobile ? self::maskMobile($mobile) : null];
    }

    /**
     * Check a code and use it up. Returns the code's id (pass it to release() if the submission then fails).
     *
     * @throws RuntimeException message for the person
     */
    public static function consume(int $personId, string $code): int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        $expectedLength = self::testMode() ? 4 : 6;
        if (! preg_match('/^\d{' . $expectedLength . '}$/', $code)) {
            $length = self::testMode() ? '4-digit' : '6-digit';
            throw new RuntimeException("Enter the {$length} OTP. Use \"Send OTP\" if you have not requested one.");
        }

        $db  = Database::connect();
        $row = $db->query(
            'SELECT id, code_hash, attempts, (expires_at < now()) AS expired FROM otp_codes
              WHERE person_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1',
            [$personId]
        )->getRowArray();
        $max = max(1, Settings::int('otp_max_attempts', 5));

        if ($row === null || is_true($row['expired'])) {
            throw new RuntimeException('Your OTP has expired or was not requested. Please use "Send OTP" to get a new one.');
        }
        if ((int) $row['attempts'] >= $max) {
            throw new RuntimeException('The OTP was entered wrongly too many times. Please use "Send OTP" to get a new one.');
        }
        if (! password_verify($code, (string) $row['code_hash'])) {
            $db->query('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            $left = $max - (int) $row['attempts'] - 1;

            throw new RuntimeException($left > 0
                ? "The OTP is not correct. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.'
                : 'The OTP was entered wrongly too many times. Please use "Send OTP" to get a new one.');
        }

        // Use it up atomically, so two simultaneous submissions cannot both pass
        $used = $db->query('UPDATE otp_codes SET used_at = now() WHERE id = ? AND used_at IS NULL RETURNING id', [$row['id']])->getRowArray();
        if ($used === null) {
            throw new RuntimeException('This OTP has already been used. Please use "Send OTP" to get a new one.');
        }

        return (int) $row['id'];
    }

    /** Make a consumed code usable again (the grievance it was for was not saved, e.g. a form error). */
    public static function release(int $otpId): void
    {
        Database::connect()->query(
            'UPDATE otp_codes o SET used_at = NULL WHERE o.id = ? AND o.expires_at > now()
                AND NOT EXISTS (SELECT 1 FROM otp_codes n WHERE n.person_id = o.person_id AND n.id > o.id)',
            [$otpId]
        );
    }

    /** False on the live portal while SMS only goes to the log file (no gateway yet); true in development. */
    public static function smsReachesPhone(): bool
    {
        $driver = trim((string) config('Otp')->smsDriver);

        return $driver !== '' && ($driver !== 'log' || ENVIRONMENT !== 'production');
    }

    /** Fixed OTP mode is available only in development, never in production or PHPUnit testing. */
    public static function testMode(): bool
    {
        //return ENVIRONMENT === 'development';
        return false;
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return '***';
        }

        return mb_substr($email, 0, 1) . str_repeat('*', max(3, min(8, $at - 1))) . substr($email, $at);
    }

    public static function maskMobile(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile) ?? '';

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    /** Self-test only: write OTP lines to this file instead of the real log. */
    public static ?string $logFileOverride = null;

    /** Append a line to writable/logs/otp-YYYY-MM-DD.log (India time). */
    public static function writeLog(string $channel, string $to, string $message): bool
    {
        $now  = new DateTime('now', display_timezone());
        $file = self::$logFileOverride ?? WRITEPATH . 'logs/otp-' . $now->format('Y-m-d') . '.log';
        $line = $now->format('Y-m-d H:i:s') . " {$channel} to {$to}: {$message}" . PHP_EOL;

        return @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    // ---------------------------------------------------------------------

    private static function sendEmail(string $to, string $code, string $text): bool
    {
        $cfg = config('Otp');
        if ($cfg->emailMode !== 'smtp') {
            return self::writeLog('EMAIL', $to, $text);
        }

        try {
            $mail = Services::email();
            $from = $cfg->fromEmail !== '' ? $cfg->fromEmail : (string) config('Email')->fromEmail;
            $mail->setFrom($from, $cfg->fromName);
            $mail->setTo($to);
            $mail->setSubject("Your OTP: {$code}");
            $mail->setMessage($text);
            $mail->setMailType('text');
            if ($mail->send(false)) {
                log_message('info', 'OTP e-mail delivery succeeded.');

                return true;
            }
            log_message('error', 'OTP e-mail delivery failed: the email service returned false.');
        } catch (Throwable $e) {
            log_message('error', 'OTP e-mail delivery failed: ' . $e->getMessage());
        }

        return false;
    }

    private static function smsSender(string $driver): SmsSender
    {
        return match ($driver) {
            // Add real gateways here, e.g. 'msg91' => new Msg91SmsSender(),
            default => new LogSmsSender(),
        };
    }
}
