<?php

namespace App\Libraries;

/**
 * Generates temporary passwords (admin creation / reset). Uses a cryptographically secure
 * source and leaves out look-alike characters (0/O, 1/l/I) so it can be read out or typed easily.
 */
class PasswordGenerator
{
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function make(int $length = 14): string
    {
        $max      = strlen(self::ALPHABET) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
