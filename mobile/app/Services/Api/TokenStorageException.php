<?php

namespace App\Services\Api;

use RuntimeException;

class TokenStorageException extends RuntimeException
{
    public function __construct(string $message = 'The sign-in token could not be saved to secure storage.')
    {
        parent::__construct($message);
    }
}
