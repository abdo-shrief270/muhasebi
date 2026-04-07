<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when attempting to modify a journal entry in a closed fiscal period.
 */
class FiscalPeriodLockedException extends HttpException
{
    public function __construct(string $message = 'This fiscal period is locked. No modifications allowed.')
    {
        parent::__construct(423, $message); // 423 Locked
    }
}
