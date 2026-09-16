<?php

namespace App\Exceptions;

use LogicException;

/** A posting account mapping was not explicitly owner-approved and resolvable. */
class AccountingAccountMappingUnavailableException extends LogicException {}
