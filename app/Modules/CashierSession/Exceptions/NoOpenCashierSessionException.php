<?php

namespace App\Modules\CashierSession\Exceptions;

use Exception;

class NoOpenCashierSessionException extends Exception
{
    public function __construct(int $outletId)
    {
        parent::__construct("Outlet #{$outletId} has no open cashier session.");
    }
}
