<?php

namespace App\Exceptions;

use LogicException;

/** Raised when a policy-required analytic dimension cannot be proven valid. */
final class AccountingDimensionUnavailableException extends LogicException {}
