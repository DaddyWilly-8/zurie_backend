<?php

namespace App\Modules\CashierSession\Exceptions;

use Exception;

class CashierSessionAlreadyClosedException extends Exception
{
    public function __construct(int $sessionId)
    {
        parent::__construct("Cashier session #{$sessionId} is already closed.");
    }
}
