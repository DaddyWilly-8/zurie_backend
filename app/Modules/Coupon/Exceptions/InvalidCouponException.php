<?php

namespace App\Modules\Coupon\Exceptions;

use Exception;

/**
 * Covers every reason a coupon code can't be applied right now — unknown
 * code, inactive, outside its valid date range, exhausted its max uses, or
 * the order doesn't meet its minimum amount. One exception type, a
 * specific message per case, so the caller doesn't need to distinguish
 * them structurally — this is always a 422, preventable-by-the-customer
 * situation, never a server error.
 */
class InvalidCouponException extends Exception
{
}
