<?php

namespace App\Exceptions;

/**
 * An action the CRM refuses in the current state (e.g. a revert with no earlier note to
 * revert to, a CV for a vacancy that is closed or full). Its message is written for the
 * user and is always shown, whatever APP_DEBUG says; the action's transaction is rolled
 * back and the reply is a 4xx, not a server error.
 */
class ActionRefusedException extends \RuntimeException
{
}
