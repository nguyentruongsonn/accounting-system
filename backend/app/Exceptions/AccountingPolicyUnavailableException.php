<?php

namespace App\Exceptions;

use LogicException;

/** A posting policy cannot be safely resolved, so posting must not continue. */
class AccountingPolicyUnavailableException extends LogicException {}
