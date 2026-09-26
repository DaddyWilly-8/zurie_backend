<?php

namespace App\Modules\Support\Exceptions;

use Exception;

/**
 * Thrown by SupportTicketService/SupportMessageService when a requested
 * action violates the ticket lifecycle (new -> active -> closed, no
 * reopening): activating a non-new ticket, reassigning/closing a
 * non-active one, closing by anyone other than the current handler, or
 * sending a message on a ticket that isn't active. Caught globally in
 * bootstrap/app.php and turned into a 422 — an expected, preventable
 * action, not a real server error.
 */
class InvalidTicketTransitionException extends Exception {}
