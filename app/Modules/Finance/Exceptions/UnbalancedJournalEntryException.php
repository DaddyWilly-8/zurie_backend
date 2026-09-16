<?php

namespace App\Modules\Finance\Exceptions;

use Exception;

/**
 * The one invariant that makes a ledger trustworthy: every journal entry's
 * debits must equal its credits. Thrown before any row is written — never
 * a partially-posted entry. Caught globally in bootstrap/app.php, same
 * treatment as InsufficientStockException/InvalidOrderTransitionException.
 */
class UnbalancedJournalEntryException extends Exception
{
    public function __construct(float $totalDebits, float $totalCredits)
    {
        parent::__construct(
            "Journal entry does not balance: debits {$totalDebits} != credits {$totalCredits}."
        );
    }
}
