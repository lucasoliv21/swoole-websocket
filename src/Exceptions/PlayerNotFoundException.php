<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

final class PlayerNotFoundException extends Exception
{
    public function __construct(string|int $identifier, string $identifierType)
    {
        parent::__construct("Player with {$identifierType} [{$identifier}] not found.");
    }
}
