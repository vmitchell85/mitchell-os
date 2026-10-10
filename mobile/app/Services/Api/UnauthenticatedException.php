<?php

namespace App\Services\Api;

use RuntimeException;

class UnauthenticatedException extends RuntimeException
{
    public function __construct(string $message = 'The API rejected the sign-in token.')
    {
        parent::__construct($message, 401);
    }
}
