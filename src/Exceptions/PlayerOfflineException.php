<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

final class PlayerOfflineException extends Exception
{
    public function __construct(string $userId)
    {
        parent::__construct("Player with userId [{$userId}] is offline.");
    }
}
