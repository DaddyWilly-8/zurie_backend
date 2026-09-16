<?php

namespace App\Modules\CashierSession\Exceptions;

use Exception;

class CashierSessionAlreadyOpenException extends Exception
{
    public function __construct(int $outletId, int $existingSessionId)
    {
        parent::__construct(
            "Outlet #{$outletId} already has an open cashier session (#{$existingSessionId}). Close it before opening a new one."
        );
    }
}
