<?php

namespace App\Libraries;

use RuntimeException;

/** A submission that cannot be accepted; carries every problem so the form can list them all. */
class SubmissionException extends RuntimeException
{
    /** @var list<string> */
    public array $errors;

    /** @param list<string> $errors */
    public function __construct(array $errors, public ?array $duplicates = null)
    {
        $this->errors = $errors;
        parent::__construct(implode(' ', $errors));
    }
}
